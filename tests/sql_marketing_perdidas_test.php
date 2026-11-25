<?php
require __DIR__.'/sql_marketing_avisos_test.php';
class PerdidasDbPrueba extends AvisosDbPrueba {
    public $inserts=0,$trackedReads=0;
    function execute($sql,$args){
        if(str_starts_with($sql,'SELECT referencia FROM telefonia_transferencia_operaciones')||str_starts_with($sql,'SELECT id FROM telefonia_transferencia_operaciones WHERE destino_usuario=')){
            $this->queries++;$this->trackedReads++;$batch=str_starts_with($sql,'SELECT referencia');$refs=$batch?array_slice($args,1):[$args[1]];$rows=[];
            foreach($this->rows as $r)if((int)$r['destino_usuario']===(int)$args[0]&&$r['modo']==='DIRECTA')foreach($refs as $ref)if(strcasecmp($r['referencia'],(string)$ref)===0){$rows[]=['id'=>$r['id'],'referencia'=>$r['referencia']];break;}
            return $rows;
        }
        if(str_starts_with($sql,'INSERT IGNORE INTO telefonia_notificaciones')){
            $this->queries++;$this->inserts++;
            foreach(array_chunk($args,7) as [$user,$ext,$key,$num,$title,$message,$date]){
                $uk=$user.'|'.$ext.'|'.$key;
                if(!isset($this->notificaciones[$uk]))$this->notificaciones[$uk]=['id'=>count($this->notificaciones)+1,'usuario_id'=>$user,'extension'=>$ext,'llave'=>$key,'numero'=>$num,'titulo'=>$title,'mensaje'=>$message,'created_at'=>$date,'leida'=>0];
            }return [];
        }
        return parent::execute($sql,$args);
    }
}
$fixture=$root.'/tests/fixtures/sql_marketing_perdidas_20261125';
foreach(['PerdidasAntes'=>$fixture,'PerdidasDespues'=>$root] as $ns=>$dir){
    eval('namespace '.$ns.';class mysqli extends \\PerdidasDbPrueba{}
        class TelefoniaDisponibilidadService{function __construct($d){}function devoluciones($u){return [];}function devolucionesParaAvisos($u){return [];}}
        class TelefoniaTransferenciaOperacionService{function __construct($d){}function delUsuario($u){return [];}function delUsuarioParaAvisos($u){return [];}}
        class TelefoniaRecepcionMarketingService{function __construct($d){}function resumen($u){return ["llamadas"=>$GLOBALS["historiaPerdidas"]];}function resumenParaAvisos($u,$o){return $this->resumen($u);}}
        class TelefoniaEntrantesAnalistaService{function __construct($d){}function listar($u){return ["llamadas"=>$GLOBALS["historiaPerdidas"]];}}
    ');
    cargarClase($ns,$root.'/app/services/TelefoniaLimpiezaGuard.php','TelefoniaLimpiezaGuard');
    cargarClase($ns,$dir.'/app/services/TelefoniaNotificacionService.php','TelefoniaNotificacionService');
}
$GLOBALS['historiaPerdidas']=[];
for($i=0;$i<132;$i++)$GLOBALS['historiaPerdidas'][]=['pbx_call_id'=>'missed_'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'tipo'=>'Entrante','estado'=>'Perdida','numero'=>'5550000000','fecha'=>'2026-11-25 09:00:00','estado_pbx'=>'ended','contestada'=>false];
// No duplicar avisos de operaciones, ni confundir salientes con entrantes perdidas.
$GLOBALS['historiaPerdidas'][]=$GLOBALS['historiaPerdidas'][0]+['fuente'=>'OPERACION'];
$GLOBALS['historiaPerdidas'][]=array_replace($GLOBALS['historiaPerdidas'][0],['pbx_call_id'=>'saliente','tipo'=>'Saliente','estado'=>'Fallida']);
$directa=rowPrueba(1,['destino_usuario'=>17,'referencia'=>'MISSED_0001']);
$a=new PerdidasAntes\mysqli([$directa]);$b=new PerdidasDespues\mysqli([$directa]);
// Un aviso ya leído debe permanecer leído. Otro usuario y otra extensión conservan sus filas.
foreach([$a,$b] as $db){
 foreach([[17,'100','llamada:missed_0000',1],[99,'100','llamada:missed_0002',1],[17,'161','llamada:missed_0003',1]] as [$u,$ext,$key,$leida])$db->notificaciones[$u.'|'.$ext.'|'.$key]=['id'=>count($db->notificaciones)+1,'usuario_id'=>$u,'extension'=>$ext,'llave'=>$key,'numero'=>'5550000000','titulo'=>'Llamada perdida','mensaje'=>'Texto previo','created_at'=>'2026-11-24 09:00:00','leida'=>$leida];
}
$before=(new PerdidasAntes\TelefoniaNotificacionService($a))->pendientes(17,true);
$after=(new PerdidasDespues\TelefoniaNotificacionService($b))->pendientes(17,true);
check($before===$after&&$a->notificaciones===$b->notificaciones,'Conservar filas, orden, textos, propietario y lectura');
check($a->trackedReads===132&&$b->trackedReads===2&&$b->inserts===2,'Agrupar lecturas e inserciones de 132 llamadas');
check(!isset($b->notificaciones['17|100|llamada:missed_0001']),'Excluir la misma transferencia directa, incluida diferencia de mayúsculas');
check($b->notificaciones['17|100|llamada:missed_0000']['leida']===1,'No reabrir avisos leídos');
$reads=$b->trackedReads;$inserts=$b->inserts;$prev=$b->notificaciones;(new PerdidasDespues\TelefoniaNotificacionService($b))->pendientes(17,true);check($b->notificaciones===$prev,'Actualización idempotente sin duplicados');
echo 'PASS: 132 llamadas perdidas; 263 consultas individuales -> '.($reads+$inserts).' consultas por lote; mismos avisos y lectura.'.PHP_EOL;
$old=(new ReflectionClass(PerdidasAntes\TelefoniaNotificacionService::class))->getMethod('sincronizar');
check(str_contains(file_get_contents($root.'/app/services/TelefoniaNotificacionService.php'),"else foreach(\$history['llamadas'] as \$c)"),'Conservar rama individual del analista');
echo "PASS: transferencias directas, lectura, extensiones, duplicados y rama del analista conservados.\n";
