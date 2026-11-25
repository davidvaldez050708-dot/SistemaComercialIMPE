<?php
require __DIR__.'/sql_marketing_lotes_test.php';
class DisponibilidadDbPrueba extends ConexionSqlPrueba {
    public $requests=[],$extensionReads=0,$expirations=0,$onRequestRead=null;
    function __construct($rows=[],$events=[],$requests=[]){parent::__construct($rows,$events);foreach($requests as $r)$this->requests[$r['id']]=$r;}
    function query($sql){
        if(str_starts_with($sql,'UPDATE telefonia_disponibilidad')){$this->queries++;$this->expirations++;return true;}
        return parent::query($sql);
    }
    function execute($sql,$args){
        if(str_contains($sql,'FROM telefonia_extensiones t JOIN usuarios')){
            $this->queries++;$this->extensionReads++;return (int)$args[0]===17?[['usuario_id'=>'17','extension'=>'100','desde'=>'2026-11-24 10:00:00']]:[];
        }
        if(str_contains($sql,'FROM telefonia_disponibilidad WHERE (destino_usuario=')){
            $this->queries++;$rows=array_values(array_filter($this->requests,fn($r)=>((int)$r['destino_usuario']===(int)$args[0]||(int)$r['origen_usuario']===(int)$args[1])&&in_array($r['estado'],['ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR'],true)));usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return array_slice($rows,0,100);
        }
        if(str_starts_with($sql,'SELECT * FROM telefonia_disponibilidad WHERE id=')){
            $this->queries++;if($this->onRequestRead)($this->onRequestRead)($this,(int)$args[0]);$r=$this->requests[$args[0]]??null;
            return $r&&(((int)$r['origen_usuario']===(int)$args[1]&&$r['origen_extension']===$args[2])||((int)$r['destino_usuario']===(int)$args[3]&&$r['destino_extension']===$args[4]))?[$r]:[];
        }
        if(str_starts_with($sql,'SELECT * FROM telefonia_disponibilidad WHERE destino_usuario=')){
            $this->queries++;$rows=array_values(array_filter($this->requests,fn($r)=>(int)$r['destino_usuario']===(int)$args[0]&&$r['destino_extension']===$args[1]&&in_array($r['devolucion'],str_contains($sql,"IN ('PENDIENTE','ATENDIDA')")?['PENDIENTE','ATENDIDA']:['PENDIENTE'],true)));usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return array_slice($rows,0,100);
        }
        if(str_starts_with($sql,'SELECT ruta_json FROM telefonia_disponibilidad')){$this->queries++;return isset($this->requests[$args[0]])?[$this->requests[$args[0]]]:[];}
        if(str_starts_with($sql,'UPDATE telefonia_disponibilidad SET ')){
            $this->queries++;$this->updates++;$id=(int)end($args);preg_match('/SET (.+),updated_at=\? WHERE id=\?/',$sql,$m);foreach(explode(',',$m[1]) as $i=>$assignment)$this->requests[$id][explode('=',$assignment)[0]]=$args[$i];$this->requests[$id]['updated_at']=$args[count($args)-2];return [];
        }
        if(str_starts_with($sql,'SELECT id FROM telefonia_transferencia_operaciones WHERE origen_usuario=')){
            $this->queries++;foreach($this->rows as $r)if((int)$r['origen_usuario']===(int)$args[0]&&strcasecmp($r['referencia'],$args[1])===0)return [['id'=>$r['id']]];return [];
        }
        if(str_starts_with($sql,'SELECT * FROM telefonia_transferencia_operaciones WHERE id=')&&!str_contains($sql,'FOR UPDATE')){
            $this->queries++;$r=$this->rows[$args[0]]??null;return $r&&((int)$r['origen_usuario']===(int)$args[1]||(int)$r['destino_usuario']===(int)$args[2])?[$r]:[];
        }
        return parent::execute($sql,$args);
    }
}
$fixture=$root.'/tests/fixtures/sql_marketing_perdidas_20261125';
foreach(['DisponAntes'=>$fixture,'DisponDespues'=>$root] as $ns=>$dir){
    eval('namespace '.$ns.';class mysqli extends \\DisponibilidadDbPrueba{}function time(){return \\RELOJ_PRUEBA;}function gmdate($format,$t=null){return \\gmdate($format,$t??\\RELOJ_PRUEBA);}');
    cargarClase($ns,$dir.'/app/services/TelefoniaTransferenciaOperacionService.php','TelefoniaTransferenciaOperacionService');
    $s=file_get_contents($dir.'/app/services/TelefoniaDisponibilidadService.php');$s=substr($s,strpos($s,'final class TelefoniaDisponibilidadService'));$s=preg_replace('/require_once[^;]+;/','',$s);
    eval('namespace '.$ns.';use \\DateTimeImmutable;use \\DateTimeZone;use \\DomainException;use \\Throwable;use \\Closure;'.$s);
}
function solicitudPrueba($op,$extra=[]){return array_merge(['id'=>$op['id'],'origen_usuario'=>$op['origen_usuario'],'origen_extension'=>$op['origen_extension'],'destino_usuario'=>$op['destino_usuario'],'destino_extension'=>$op['destino_extension'],'referencia'=>$op['referencia'],'estado'=>'TRANSFERIDA','version'=>'1','devolucion'=>'','numero'=>'5550000000','ruta_json'=>'{"nombre_destino":"Responsable","institucion_nombre":"Institución sintética"}','updated_at'=>'2026-11-24 10:00:00','expires_at'=>'2030-01-01 00:00:00','contacto'=>'Contacto sintético','telefono'=>'5550000000','nota'=>''], $extra);}
function compararDispon($ops,$events,$requests,$setup=null){
 $a=new DisponAntes\mysqli($ops,$events,$requests);$b=new DisponDespues\mysqli($ops,$events,$requests);if($setup){$setup($a);$setup($b);}
 $before=(new DisponAntes\TelefoniaDisponibilidadService($a))->devoluciones(17);
 $after=(new DisponDespues\TelefoniaDisponibilidadService($b))->devolucionesParaAvisos(17);
 check($before===$after&&$a->requests===$b->requests,'Conservar solicitudes, resultado y devoluciones');
 return [$a,$b,$after];
}
$ops=[];$events=[];$requests=[];
for($i=1;$i<=11;$i++){
 $op=rowPrueba($i,['origen_usuario'=>17,'destino_usuario'=>30]);if($i<=3)$op=array_merge($op,['origen_usuario'=>30,'origen_extension'=>'161','destino_usuario'=>17,'destino_extension'=>'100']);
 $ops[]=$op;$req=solicitudPrueba($op);if($i<=3)$req['devolucion']='PENDIENTE';$requests[]=$req;
 $e=eventosPrueba($op);foreach($e as &$event){$event['internal']=$op['destino_extension'];$event['payload_json']=json_encode(['last_internal'=>$op['destino_extension']]);}unset($event);array_push($events,...$e);
}
[$a,$b,$out]=compararDispon($ops,$events,$requests);check(count($out)===3,'Conservar obligaciones pendientes');check($a->extensionReads===12&&$b->extensionReads===1&&$b->expirations===1,'Permisos y expiración una vez en esta llamada');check($b->queries<$a->queries/2,'Reducir consultas de solicitudes sin cambios');
echo 'PASS: 11 solicitudes, mismos pendientes; '.$a->queries.' -> '.$b->queries.' consultas sintéticas.'.PHP_EOL;
// Un evento que llegó tarde debe actualizar; antes de escribir se consulta otra vez la fila actual.
$op=rowPrueba(20,['origen_usuario'=>17,'destino_usuario'=>30,'estado'=>'SOLICITADA','destino_atendida'=>null,'destino_fin'=>null]);$req=solicitudPrueba($op,['estado'=>'ENVIADA']);[$a,$b]=compararDispon([$op],eventosPrueba($op),[$req]);check($b->requests[20]['estado']==='TRANSFERIDA'&&$b->locks>=2,'Actualizar con revalidación antes de escribir');
// Si otro proceso confirmó respuesta después del lote, no crear una devolución falsa.
$op=rowPrueba(21,['origen_usuario'=>17,'destino_usuario'=>30,'estado'=>'SIN_RESPUESTA','destino_atendida'=>null]);$req=solicitudPrueba($op,['estado'=>'ENVIADA']);$events=eventosPrueba($op,false);array_shift($events);
[$a,$b]=compararDispon([$op],$events,[$req],function($db)use($op){$db->onLock=function($db,$id)use($op){$e=eventosPrueba($op);$db->events=$e;};});check($b->requests[21]['estado']==='TRANSFERIDA'&&$b->requests[21]['devolucion']==='','No usar estado antiguo para crear obligación');
echo "PASS: evento tardío y respuesta concurrente, sin devolución falsa.\n";
// Extensiones anteriores no deben dar acceso ni sincronizar operaciones fuera del alcance actual.
$op=rowPrueba(22,['origen_usuario'=>17,'origen_extension'=>'199','destino_usuario'=>30]);$req=solicitudPrueba($op);[$a,$b]=compararDispon([$op],eventosPrueba($op),[$req]);check($b->locks===0,'No procesar la extensión anterior');
// El alcance temporal se restaura; no reutilizar asignación o estados en una llamada posterior.
$db=new DisponDespues\mysqli($ops,[],[]);$svc=new DisponDespues\TelefoniaDisponibilidadService($db);$svc->devolucionesParaAvisos(17);$old=$db->extensionReads;$svc->devoluciones(17);check($db->extensionReads>$old,'Fuera del lote se vuelve a consultar la asignación');
echo "PASS: extensión actual, propiedad y alcance temporal conservados.\n";

// Durante una publicación, una petición puede haber cargado todavía la dependencia anterior.
eval('namespace DisponMixto;class mysqli extends \\DisponibilidadDbPrueba{}function time(){return \\RELOJ_PRUEBA;}function gmdate($f,$t=null){return \\gmdate($f,$t??\\RELOJ_PRUEBA);}');
cargarClase('DisponMixto',$fixture.'/app/services/TelefoniaTransferenciaOperacionService.php','TelefoniaTransferenciaOperacionService');
$s=file_get_contents($root.'/app/services/TelefoniaDisponibilidadService.php');$s=substr($s,strpos($s,'final class TelefoniaDisponibilidadService'));$s=preg_replace('/require_once[^;]+;/','',$s);
eval('namespace DisponMixto;use \\DateTimeImmutable;use \\DateTimeZone;use \\DomainException;use \\Throwable;use \\Closure;'.$s);
$db=new DisponMixto\mysqli($ops,[],[]);check((new DisponMixto\TelefoniaDisponibilidadService($db))->devolucionesParaAvisos(17)===[],'Compatibilidad con dependencia anterior durante publicación');
echo "PASS: publicación gradual con dependencia anterior, sin interrumpir solicitudes.\n";

$op=rowPrueba(23,['origen_usuario'=>17,'destino_usuario'=>30]);$req=solicitudPrueba($op);
[$a,$b]=compararDispon([$op],eventosPrueba($op),[$req],function($db){$db->onRequestRead=function($db,$id){$db->requests[$id]['version']='2';$db->requests[$id]['updated_at']='2026-11-25 10:00:00';};});check($b->locks>=1,'Cambio concurrente de solicitud invalida los datos temporales');
echo "PASS: cambio de versión de solicitud obliga a lectura actual.\n";
$mine=rowPrueba(24,['origen_usuario'=>17,'destino_usuario'=>30]);$foreign=rowPrueba(25,['origen_usuario'=>98,'destino_usuario'=>99]);$db=new DisponDespues\mysqli([$mine,$foreign],array_merge(eventosPrueba($mine),eventosPrueba($foreign)),[]);
$rows=(new DisponDespues\TelefoniaTransferenciaOperacionService($db))->porIdsParaAvisos(17,[24,25]);check(array_keys($rows)===[24],'El lote de IDs respeta propiedad del usuario');
echo "PASS: el lote no acepta operaciones de otra cuenta.\n";
