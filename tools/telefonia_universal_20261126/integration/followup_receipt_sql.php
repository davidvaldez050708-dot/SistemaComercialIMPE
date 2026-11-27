<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/controllers/SeguimientoVinculacionController.php';
$complete=false;register_shutdown_function(static function()use(&$complete){if(!$complete){fwrite(STDERR,"Followup fixture did not reach its assertions.\n");exit(1);}});
$_SESSION=['usuario_id'=>42,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes','seguimientos_vinculacion.operar_propios']];
$creation='fixture-followup-create-'.bin2hex(random_bytes(7));$seed($creation,'102');
$institution=(new TelefoniaEntrantesAnalistaService($db))->registrar(42,['pbx_call_id'=>$creation,'decision'=>'nueva','nombre_entidad'=>'Institución de seguimiento '.bin2hex(random_bytes(6)),'tipo_entidad'=>'INSTITUCION','estado_id'=>'1','resultado'=>'CONTACTO_CORRECTO','telefono_capturado'=>'5550000101','correo_capturado'=>'fixture@example.invalid','persona_atendio'=>'Persona sintética']);
$sid=(int)$institution['seguimiento_id'];$reference='out_'.bin2hex(random_bytes(16));$seed($reference,'102');
$values=['seguimiento_id'=>(string)$sid,'llamada_pbx_id'=>$reference,'canal'=>'LLAMADA','origen_llamada'=>'ZADARMA','resultado'=>'BUZON_VOZ','fecha_inicio'=>date('Y-m-d\TH:i',time()-60),'observacion'=>'Seguimiento sintético aprobado'];
$revision=(int)floor(microtime(true)*1000);$request=bin2hex(random_bytes(16));$values['captura_confirmacion']=json_encode(['ids'=>[$reference],'revision'=>$revision,'request_id'=>$request]);
$controller=new SeguimientoVinculacionController();$native=new ReflectionMethod($controller,'guardarInteraccionTrabajo');
$save=static fn(array $d,?callable $confirm):array=>$native->invoke($controller,$d,$confirm);
$confirm=new TelefoniaResultadoConfirmacion($db);
$count=static fn():int=>(int)$db->query('SELECT COUNT(*) n FROM interacciones_vinculacion WHERE seguimiento_id='.$sid)->fetch_assoc()['n'];
$before=$count();$result=$confirm->ejecutar(42,'SEGUIMIENTO',$values,$save);
$assert($result['ok']===true&&$result['interaccion_id']>0,'Existing Seguimiento response contract preserved');
$assert($count()===$before+1,'One native interaction committed');
$receipt=(new TelefoniaBorradorService($db))->leerAplicacion(42,[$reference],$request);
$wireResult=json_decode(json_encode($result,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
$assert($receipt['respuesta']===$wireResult,'Full institutional JSON response stored with native interaction');
$assert($confirm->ejecutar(42,'SEGUIMIENTO',$values,$save)===$wireResult&&$count()===$before+1,'Replay does not repeat validation or interaction');
$failure=$values;unset($failure['captura_confirmacion']);$failed=false;
$failure['llamada_pbx_id']='out_'.bin2hex(random_bytes(16));$seed($failure['llamada_pbx_id'],'102');
try{$save($failure,static function(){throw new RuntimeException('Synthetic followup receipt failure');});}catch(RuntimeException $e){$failed=true;}
$assert($failed&&$count()===$before+1,'Receipt failure rolls back native interaction');
$changedApproval=$values;$changedApproval['captura_confirmacion']=json_encode(['ids'=>[$reference],'revision'=>$revision+1,'request_id'=>bin2hex(random_bytes(16))]);$duplicate=false;
try{$confirm->ejecutar(42,'SEGUIMIENTO',$changedApproval,$save);}catch(DomainException $e){$duplicate=true;}
$assert($duplicate&&$count()===$before+1,'Another approved request cannot duplicate the same PBX call');
$nativeRow=$db->query('SELECT proveedor_externo,id_externo FROM interacciones_vinculacion WHERE id='.(int)$result['interaccion_id'])->fetch_assoc();
$assert($nativeRow['proveedor_externo']==='ZADARMA'&&$nativeRow['id_externo']===$reference,'Server-validated PBX identity stored with interaction');
$model=new SeguimientoVinculacionModel($db);$stale=false;
try{$model->registrarInteraccionManual($sid,42,['canal'=>'LLAMADA_IP','resultado'=>'CONTACTADO','fecha_inicio'=>date('Y-m-d H:i:s'),'contacto_esperado'=>['telefono_fuente'=>'5559999999']],false,static function(){});}catch(DomainException $e){$stale=true;}
$assert($stale&&$count()===$before+1,'Stale contact snapshot cannot overwrite or credit a concurrent contact');
$assert(empty($result['verificacion_telefonica']['contabilizable']),'Connected voicemail does not become an effective verification');
$assert(isset($result['seguimiento'],$result['interacciones'],$result['resumen']),'Existing summary and history presentation retained');
$complete=true;echo "PASS $checks native Seguimiento receipt checks: original pipeline/response, same-transaction rollback, exact replay, stale contact and no invented effective call. Synthetic SQL only.\n";
