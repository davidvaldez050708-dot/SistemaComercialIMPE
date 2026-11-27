<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/services/InteraccionRutaService.php';
require_once $lab.'/site/app/controllers/SeguimientoVinculacionController.php';
$_SESSION=['usuario_id'=>42,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes','seguimientos_vinculacion.operar_propios']];
$creation='fixture-informative-create-'.bin2hex(random_bytes(7));$seed($creation,'102');
$institution=(new TelefoniaEntrantesAnalistaService($db))->registrar(42,['pbx_call_id'=>$creation,'decision'=>'nueva','nombre_entidad'=>'Institución informativa '.bin2hex(random_bytes(6)),'tipo_entidad'=>'INSTITUCION','estado_id'=>'1','resultado'=>'CONTACTO_CORRECTO','telefono_capturado'=>'5550000101','correo_capturado'=>'fixture@example.invalid','persona_atendio'=>'Persona sintética']);
$sid=(int)$institution['seguimiento_id'];$future=date('Y-m-d H:i:s',time()+172800);
$db->query("UPDATE seguimientos_vinculacion SET estado_seguimiento='ESPERANDO_RESPUESTA',proxima_accion_at='$future' WHERE id=$sid");
$reference='out_'.bin2hex(random_bytes(16));$seed($reference,'102');
$values=['seguimiento_id'=>(string)$sid,'llamada_pbx_id'=>$reference,'canal'=>'LLAMADA','origen_llamada'=>'ZADARMA','resultado'=>'BUZON_VOZ','fecha_inicio'=>date('Y-m-d\TH:i',time()-60),'observacion'=>'Interacción informativa sintética'];
$revision=(int)floor(microtime(true)*1000);$request=bin2hex(random_bytes(16));$values['captura_confirmacion']=json_encode(['ids'=>[$reference],'revision'=>$revision,'request_id'=>$request]);
$service=new InteraccionRutaService($db);$confirm=new TelefoniaResultadoConfirmacion($db);
$save=static fn(array $d,?callable $callback):array=>$service->registrarInformativa($sid,42,$d,$callback);
$count=static fn():int=>(int)$db->query('SELECT COUNT(*) n FROM interacciones_vinculacion WHERE seguimiento_id='.$sid)->fetch_assoc()['n'];
$before=$count();$result=$confirm->ejecutar(42,'INFORMATIVA',$values,$save);
$assert($result['ok']===true&&$count()===$before+1,'Native informative interaction and receipt commit together');
$assert((new TelefoniaBorradorService($db))->leerAplicacion(42,[$reference],$request)['respuesta']===$result,'Original informative response stored in receipt');
$assert($confirm->ejecutar(42,'INFORMATIVA',$values,$save)===$result&&$count()===$before+1,'Lost ACK does not duplicate advanced-route interaction');
$route=$db->query('SELECT estado_seguimiento,proxima_accion_at FROM seguimientos_vinculacion WHERE id='.$sid)->fetch_assoc();
$assert($route['estado_seguimiento']==='ESPERANDO_RESPUESTA'&&$route['proxima_accion_at']===$future,'Advanced route and future commercial action preserved');
$other=$values;unset($other['captura_confirmacion']);$other['llamada_pbx_id']='out_'.bin2hex(random_bytes(16));$seed($other['llamada_pbx_id'],'102');$failed=false;
try{$save($other,static function(){throw new RuntimeException('Synthetic informative receipt failure');});}catch(RuntimeException $e){$failed=true;}
$assert($failed&&$count()===$before+1,'Receipt failure rolls back informative interaction');
$duplicate=$values;$duplicate['captura_confirmacion']=json_encode(['ids'=>[$reference],'revision'=>$revision+1,'request_id'=>bin2hex(random_bytes(16))]);$rejected=false;
try{$confirm->ejecutar(42,'INFORMATIVA',$duplicate,$save);}catch(DomainException $e){$rejected=true;}
$assert($rejected&&$count()===$before+1,'Different informative approval cannot duplicate PBX identity');
$controller=new SeguimientoVinculacionController();$method=new ReflectionMethod($controller,'guardarInteraccionTrabajo');
$rejected=false;try{$method->invoke($controller,array_replace($other,['llamada_pbx_id'=>$reference]),static function(){});}catch(DomainException $e){$rejected=true;}
$assert($rejected,'Informative and ordinary Seguimiento share the same PBX duplicate guard');
$assert(empty($result['verificacion_telefonica']['contabilizable']),'Answered voicemail is not an effective verification');
echo "PASS $checks native informative-route receipt checks: original stage/action rules, rollback, replay, shared PBX exclusion and no invented verification. Synthetic SQL only.\n";
