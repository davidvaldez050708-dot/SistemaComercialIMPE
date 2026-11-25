<?php
require __DIR__.'/sql_marketing_lotes_test.php';
class AvisosDbPrueba extends ConexionSqlPrueba {
    public $notificaciones=[],$lists=0;
    function query($sql){if(str_starts_with($sql,'CREATE TABLE')){$this->queries++;return true;}return parent::query($sql);}
    function execute($sql,$args){
        if(str_starts_with($sql,'SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario='))$this->lists++;
        if(str_starts_with($sql,'SELECT id FROM usuarios')){$this->queries++;return [];}
        if(str_starts_with($sql,'SELECT extension FROM telefonia_extensiones')){$this->queries++;return [['extension'=>'100']];}
        if(str_contains($sql,'FROM telefonia_disponibilidad')){$this->queries++;return [];}
        if(str_starts_with($sql,'SELECT id FROM telefonia_transferencia_operaciones WHERE destino_usuario=')){
            $this->queries++;return array_values(array_filter($this->rows,fn($r)=>(int)$r['destino_usuario']===(int)$args[0]&&$r['referencia']===$args[1]&&$r['modo']==='DIRECTA'));
        }
        if(str_starts_with($sql,'INSERT IGNORE INTO telefonia_notificaciones')){
            $this->queries++;[$user,$ext,$key,$num,$date,$title,$message]=[$args[0],$args[1],$args[2],$args[3],$args[6],$args[4],$args[5]];
            if(!isset($this->notificaciones[$key]))$this->notificaciones[$key]=['id'=>count($this->notificaciones)+1,'usuario_id'=>$user,'extension'=>$ext,'llave'=>$key,'numero'=>$num,'titulo'=>$title,'mensaje'=>$message,'created_at'=>$date,'leida'=>0];return [];
        }
        if(str_starts_with($sql,'UPDATE telefonia_notificaciones')){
            $this->queries++;if(str_contains($sql,' JOIN '))return [];
            foreach($this->notificaciones as &$n)if((int)$n['usuario_id']===(int)$args[0]&&$n['extension']===$args[1]&&in_array($n['llave'],array_slice($args,2),true))$n['leida']=1;unset($n);return [];
        }
        if(str_starts_with($sql,'SELECT * FROM telefonia_notificaciones')){
            $this->queries++;$rows=array_values(array_filter($this->notificaciones,function($n)use($sql,$args){
                if((int)$n['usuario_id']!==(int)$args[0]||$n['extension']!==$args[1])return false;
                if(str_contains($sql,'llave IN'))return in_array($n['llave'],array_slice($args,2),true);
                return !$n['leida']&&!str_starts_with($n['llave'],'devolucion:')&&!str_starts_with($n['llave'],'transferencia:');
            }));usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return str_contains($sql,'LIMIT 20')?array_slice($rows,0,20):$rows;
        }
        return parent::execute($sql,$args);
    }
}
$log=tempnam(sys_get_temp_dir(),'avisos-test-');ini_set('error_log',$log);
foreach(['AvisosAnterior'=>$base,'AvisosCandidato'=>$root] as $ns=>$dir){
    eval('namespace '.$ns.';class mysqli extends \\AvisosDbPrueba{} function time(){return \\RELOJ_PRUEBA;} function gmdate($format,$t=null){return \\gmdate($format,$t??\\RELOJ_PRUEBA);}');
    cargarClase($ns,$dir.'/app/services/TelefoniaTransferenciaOperacionService.php','TelefoniaTransferenciaOperacionService');
    cargarClase($ns,$dir.'/app/services/TelefoniaLimpiezaGuard.php','TelefoniaLimpiezaGuard');
    eval('namespace '.$ns.';
        class TelefoniaDisponibilidadService{function __construct($db){}function devoluciones($u){return [];}function devolucionesParaAvisos($u){return [];}}
        class TelefoniaRecepcionMarketingService{
            private $db;function __construct($db){$this->db=$db;}
            function resumen($u){(new TelefoniaTransferenciaOperacionService($this->db))->delUsuario($u);return ["llamadas"=>[]];}
            function resumenParaAvisos($u,$ops){return ["llamadas"=>[]];}
        }
        class TelefoniaEntrantesAnalistaService{function __construct($db){}function listar($u){return ["llamadas"=>[]];}}
    ');
    cargarClase($ns,$dir.'/app/services/TelefoniaNotificacionService.php','TelefoniaNotificacionService');
}
$rows=[];$events=[];
for($i=1;$i<=50;$i++){
    $r=rowPrueba($i,['numero'=>'5550000000']);
    if($i===2||$i===3){$r['estado']='SIN_RESPUESTA';$r['destino_atendida']=null;}
    if($i===4){$r['estado']='SIN_CONFIRMAR';$r['destino_atendida']=null;$r['destino_fin']=null;}
    $rows[]=$r;
    $e=eventosPrueba($r,!in_array($i,[2,3]));
    if(in_array($i,[2,3]))array_shift($e);
    if($i!==4)array_push($events,...$e);
}
$a=new AvisosAnterior\mysqli($rows,$events);$b=new AvisosCandidato\mysqli($rows,$events);
$a->exclusions=$b->exclusions=[['pbx_call_id'=>$rows[2]['referencia'],'extension'=>'100']];
$before=(new AvisosAnterior\TelefoniaNotificacionService($a))->pendientes(30,true);
$after=(new AvisosCandidato\TelefoniaNotificacionService($b))->pendientes(30,true);
check($before===$after&&count($after)===2,'Conservar avisos operativos y exclusiones');
check($a->lists===2&&$b->lists===1,'El lote debe reutilizarse en recepción y campana');
check($b->queries<$a->queries/3,'Reducir el consumo de la campana completa');
check(file_get_contents($log)==='','La prueba no debe ocultar excepciones en catch');unlink($log);
echo 'PASS: campana completa; avisos idénticos; '.$a->queries.' -> '.$b->queries.' consultas sintéticas; una sola lectura del lote.'.PHP_EOL;

$a=new AvisosAnterior\mysqli($rows,$events);$b=new AvisosCandidato\mysqli($rows,$events);
$a->exclusions=$b->exclusions=[['pbx_call_id'=>$rows[2]['referencia'],'extension'=>'100']];
$before=(new AvisosAnterior\TelefoniaNotificacionService($a))->pendientes(30,false);
$after=(new AvisosCandidato\TelefoniaNotificacionService($b))->pendientes(30,false);
check($before===$after&&count($after)===2&&$a->queries===$b->queries,'Conservar rama del analista sin activar lote de Marketing');
echo "PASS: rama del analista conservada, mismos avisos y consultas.\n";

// El método nuevo no deja el lote asociado al objeto fuera de su llamada.
$source=file_get_contents($root.'/app/services/TelefoniaRecepcionMarketingService.php');$source=substr($source,strpos($source,'class TelefoniaRecepcionMarketingService'));
eval('namespace WrapperAvisos;use \\DateTimeImmutable;use \\DateTimeZone;use \\Throwable;'.$source.'
class Probe extends TelefoniaRecepcionMarketingService{
 public bool $fallar=false;public function __construct(){}
 public function resumen(int $u):array{if($this->fallar)throw new \\RuntimeException("Prueba");$p=new \\ReflectionProperty(TelefoniaRecepcionMarketingService::class,"operacionesAvisos");return ["lote"=>$p->getValue($this)];}
}');
$probe=new WrapperAvisos\Probe();check($probe->resumenParaAvisos(30,[['id'=>1]])===['lote'=>[['id'=>1]]],'Entregar lote a la misma llamada');check($probe->resumen(30)===['lote'=>null],'No conservar lote fuera del recolector');
$probe->fallar=true;try{$probe->resumenParaAvisos(30,[['id'=>2]]);}catch(RuntimeException $e){}$probe->fallar=false;check($probe->resumen(30)===['lote'=>null],'Restaurar lote incluso si falla recepción');
echo "PASS: reutilización limitada al objeto y a la llamada; restaura estado ante errores.\n";

check(!is_file($log)||file_get_contents($log)==='','No ocultar excepciones de la rama del analista');if(is_file($log))unlink($log);
