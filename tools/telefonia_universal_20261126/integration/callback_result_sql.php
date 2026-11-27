<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/services/TelefoniaDevolucionService.php';
$_SESSION=['usuario_id'=>42,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes']];
$grants=implode(' ',array_column($db->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM),0));
$assert(!preg_match('/ALL PRIVILEGES|\bCREATE\b|\bALTER\b|\bDROP\b/i',$grants),'Application fixture has no DDL privileges');
$svc=new TelefoniaDevolucionService($db);
$new=static function()use($db,$seed):array{
 $ref='fixture-callback-'.bin2hex(random_bytes(8));$seed($ref,'102');
 $s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,version,expires_at,created_at,updated_at,contacto,telefono,devolucion) VALUES(43,'100',42,'102',?,'5550000101','{}','FALLIDA',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'Contacto sintético','5550000101','PENDIENTE')");
 $s->bind_param('s',$ref);$s->execute();$task=(int)$s->insert_id;$s->close();
 $s=$db->prepare("INSERT INTO telefonia_devolucion_intentos(solicitud_id,version,usuario_id,extension,numero,desde_evento_id,pbx_call_id,estado,created_at,updated_at) VALUES(?,1,42,'102','5550000101',0,?,'EN_CURSO',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $s->bind_param('is',$task,$ref);$s->execute();$id=(int)$s->insert_id;$s->close();return [$id,$task,$ref];
};
$read=static function(int $id)use($db):array{return $db->query('SELECT resultado,observacion FROM telefonia_devolucion_intentos WHERE id='.$id)->fetch_assoc();};
$taskState=static function(int $id)use($db):string{return $db->query('SELECT devolucion FROM telefonia_disponibilidad WHERE id='.$id)->fetch_assoc()['devolucion'];};
[$id,$task,$ref]=$new();
$assert($svc->estado(42,$id)['puede_registrar'],'Actual PBX projection permits completed own callback');
$data=$make($ref,['intento_id'=>(string)$id,'accion'=>'RESULTADO','resultado'=>'CONTACTO_CORRECTO','decision'=>'sin_identificar','observacion'=>'Nota sintética aprobada']);
$save=static fn(array $values,?callable $confirm):array=>$svc->registrar(42,(int)$values['intento_id'],$values,$confirm);
$confirmation=new TelefoniaResultadoConfirmacion($db);
$result=$confirmation->ejecutar(42,'DEVOLUCION',$data,$save);
$assert($result['resultado']==='CONTACTO_CORRECTO'&&$result['id']==$id,'Native response retains callback envelope fields');
$assert($read($id)['observacion']==='Nota sintética aprobada'&&$taskState($task)==='ATENDIDA','Result and task state committed together');
$meta=json_decode($data['captura_confirmacion'],true);$receipt=(new TelefoniaBorradorService($db))->leerAplicacion(42,[$ref],$meta['request_id']);
$assert($receipt['respuesta']===$result&&$receipt['adaptador']==='DEVOLUCION','Callback and exact response receipt share transaction');
$called=false;$again=$confirmation->ejecutar(42,'DEVOLUCION',$data,static function()use(&$called){$called=true;throw new RuntimeException('Duplicate native save');});
$assert(!$called&&$again===$result,'Lost response replay reads receipt without touching definitive result');
$rejected=false;try{$confirmation->ejecutar(42,'DEVOLUCION',array_replace($data,['intento_id'=>(string)($id+1)]),$save);}catch(DomainException $e){$rejected=true;}
$assert($rejected,'Same request cannot target a different attempt');
[$rollback,$rollbackTask,$rollbackRef]=$new();
$failure=$make($rollbackRef,['intento_id'=>(string)$rollback,'resultado'=>'CONTACTO_CORRECTO','decision'=>'sin_identificar']);
$rejected=false;try{$svc->registrar(42,$rollback,$failure,static function(){throw new RuntimeException('Synthetic receipt failure');});}catch(RuntimeException $e){$rejected=true;}
$assert($rejected&&$read($rollback)['resultado']===''&&$taskState($rollbackTask)==='PENDIENTE','Receipt failure rolls back callback and task together');
$rejected=false;try{$svc->registrar(42,$rollback,array_replace($failure,['pbx_call_id'=>$ref]));}catch(DomainException $e){$rejected=true;}
$assert($rejected&&$read($rollback)['resultado']==='','Capture with another PBX cannot consume the callback');
$rejected=false;try{$svc->registrar(41,$rollback,$failure);}catch(DomainException $e){$rejected=true;}
$assert($rejected&&$read($rollback)['resultado']==='','Another user cannot register the callback');
$negative=$make($rollbackRef,['intento_id'=>(string)$rollback,'resultado'=>'BUZON_VOZ','decision'=>'sin_identificar']);
$negativeResult=$confirmation->ejecutar(42,'DEVOLUCION',$negative,$save);
$assert($negativeResult['resultado']==='BUZON_VOZ'&&$taskState($rollbackTask)==='PENDIENTE','Negative result preserves pending callback commercial rule');
$assert((int)$negativeResult['seguimiento_id']===0&&$negativeResult['atencion']===[],'Simple classification creates no institution or effective verification');
$assert($read($id)['observacion']==='Nota sintética aprobada','Other calls and historical results preserved');
echo "PASS $checks native callback receipt checks: DML-only constructor, exact owner/PBX, lost ACK, immutable attempt, rollback and native task rules. Synthetic SQL only.\n";
