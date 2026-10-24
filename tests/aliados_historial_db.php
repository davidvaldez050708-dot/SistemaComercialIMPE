<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_historial_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoHistorialService.php';
require_once __DIR__.'/../app/services/AliadoCompartirService.php';
$db=(new Database())->connect(false);$count=0;
function verifyHistory($v,$m){global $count;if(!$v)throw new RuntimeException($m);$count++;echo "OK $m\n";}
function rejectHistory($fn,$code,$m){try{$fn();}catch(DomainException $e){verifyHistory($e->getCode()===$code,$m);return;}throw new RuntimeException('Aceptó '.$m);}
class CorreoHistorialSintetico extends CorreoSalidaInstitucionalService{
 public int $llamadas=0;public bool $exito=true;public function __construct(){}
 public function enviar($datos){$this->llamadas++;return ['ok'=>$this->exito,'proveedor'=>'SIMULADO'];}
}
$permisos=['aliados.ver','aliados.ver_historial','convocatorias.ver','aliados.compartir_correo','aliados.preparar_whatsapp'];
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>$permisos];
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_assoc()['id'];
$mail=new CorreoHistorialSintetico();$share=new AliadoCompartirService($db,new AliadoDifusionService($mail));$history=new AliadoHistorialService($db);
$counts=fn()=>[(int)$db->query('SELECT COUNT(*) FROM aliados_manuales_difusiones')->fetch_row()[0],(int)$db->query('SELECT COUNT(*) FROM aliados_convocatorias_envios')->fetch_row()[0],(int)$db->query('SELECT COUNT(*) FROM seguimientos_vinculacion')->fetch_row()[0]];
$db->query('DELETE FROM aliados_manuales_difusiones');$db->query('DELETE FROM aliados_convocatorias_envios');
verifyHistory(!$history->consultar('manual',$manual)['historial'],'aliado sin envíos permanece vacío');
$model=new AliadoModel();$getManual=fn()=>array_values(array_filter($model->obtenerListado(2,false,['estado_id'=>1]),fn($r)=>($r['origen_tipo']??'')==='manual'&&(int)$r['id']===$manual))[0];
verifyHistory(empty($getManual()['ultimo_envio_id']),'sin correo no inventa última difusión');
$o=['origen_tipo'=>'manual','origen_id'=>$manual];$p=$share->preparar($o);$c=$p['convocatorias'][0];$before=$counts();$share->borrador($o+['convocatoria_id'=>$c['id']]);
verifyHistory($counts()===$before&&$mail->llamadas===0,'preparar WhatsApp o borrador no registra ni envía');
$data=$o+['convocatoria_id'=>$c['id'],'operacion_id'=>$p['operacion_id'],'destinatario'=>$p['canales']['correo']['destinatarios'][0],'asunto'=>'Prueba aislada','mensaje'=>'Mensaje de prueba'];
verifyHistory($share->ejecutar($data,'CORREO')['ok']&&$mail->llamadas===1,'correo aceptado por proveedor simulado queda registrado');
$events=$history->consultar('manual',$manual)['historial'];$row=$getManual();
verifyHistory(count($events)===1&&(int)$events[0]['convocatoria_id']===(int)$c['id'],'historial recupera la convocatoria enviada');
verifyHistory(str_contains($row['ultima_convocatoria_titulo'],$c['titulo'])&&$row['ultimo_envio_canal']==='CORREO','correo ya guardado aparece en última difusión');
verifyHistory($row['seguimiento_convocatoria_estado']==='','correo no inventa una respuesta ni expediente');
verifyHistory($row['ultimo_envio_at']===$events[0]['enviado_at'],'tabla e historial usan la misma fecha');
$before=$counts();$share->ejecutar($data,'CORREO');verifyHistory($counts()===$before&&$mail->llamadas===1,'reintento no duplica correo ni historial');
$p=$share->preparar($o);$next=$p['convocatorias'][1];$contact=$p['canales']['whatsapp_manual']['contactos'][0];
$data=$o+['convocatoria_id'=>$next['id'],'operacion_id'=>$p['operacion_id'],'contacto_id'=>$contact['id'],'asunto'=>'','mensaje'=>'WhatsApp de prueba'];
verifyHistory($share->ejecutar($data,'WHATSAPP_MANUAL')['ok']&&$mail->llamadas===1,'confirmación por convocatoria no envía WhatsApp ni correo');
$events=$history->consultar('manual',$manual)['historial'];$row=$getManual();
verifyHistory(count($events)===2&&$events[0]['estado_envio']==='COMPARTIDO'&&$events[0]['canal']==='WHATSAPP_MANUAL','WhatsApp tiene evento independiente del correo');
verifyHistory((int)$events[0]['convocatoria_id']===(int)$next['id']&&$row['ultimo_envio_id']===$events[0]['id'],'última difusión elige la publicación correcta, incluso con fecha empatada');
verifyHistory($row['seguimiento_convocatoria_estado']==='','no confunde WhatsApp marcado con confirmación de la institución');
$before=$counts();$last=$row['ultimo_envio_id'];$p=$share->preparar($o);$mail->exito=false;
verifyHistory(!$share->ejecutar($o+['convocatoria_id'=>$next['id'],'operacion_id'=>$p['operacion_id'],'destinatario'=>$p['canales']['correo']['destinatarios'][0],'asunto'=>'Fallo simulado','mensaje'=>'No enviar'],'CORREO')['ok'],'fallo del proveedor se conserva como fallo');
verifyHistory($counts()===$before&&$getManual()['ultimo_envio_id']===$last,'fallo no altera el resumen ni crea difusión exitosa');$mail->exito=true;
$inst=['origen_tipo'=>'institucional','origen_id'=>10];$p=$share->preparar($inst);$c=$p['convocatorias'][0];
verifyHistory($share->ejecutar($inst+['convocatoria_id'=>$c['id'],'operacion_id'=>$p['operacion_id'],'destinatario'=>$p['canales']['correo']['destinatarios'][0],'asunto'=>'Prueba institucional','mensaje'=>'Mensaje sintético'],'CORREO')['ok'],'correo institucional permanece operativo');
verifyHistory(count($history->consultar('institucional',10)['historial'])===1,'historial institucional consulta su tabla original');
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>$permisos];
verifyHistory(count($history->consultar('institucional',10)['historial'])===1,'Marketing consulta historial sin asignación personal de Cuenta Clave');
verifyHistory(count($history->consultar('manual',$manual)['historial'])===2,'Marketing consulta historial manual nacional');
verifyHistory(count($share->preparar($inst)['convocatorias'])>0&&count($share->preparar($o)['convocatorias'])>0,'Compartir usa el mismo alcance nacional para ambos orígenes');
$_SESSION=['usuario_id'=>3,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>$permisos];
rejectHistory(fn()=>$history->consultar('manual',$manual),403,'Cuenta Clave ajena no consulta historial manual');
rejectHistory(fn()=>$history->consultar('institucional',10),403,'Cuenta Clave ajena no consulta historial institucional');
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>['aliados.ver']];rejectHistory(fn()=>$history->consultar('manual',$manual),403,'permiso de historial obligatorio');
$_SESSION['permisos']=['aliados.ver_historial'];rejectHistory(fn()=>$history->consultar('manual',$manual),403,'permiso de ver aliado obligatorio');
$_SESSION['permisos']=$permisos;rejectHistory(fn()=>$history->consultar('otro',10),422,'origen inválido rechazado');rejectHistory(fn()=>$history->consultar('manual',0),422,'ID inválido rechazado');
$before=$counts();$sent=$mail->llamadas;for($i=0;$i<3;$i++){$history->consultar('manual',$manual);$history->consultar('institucional',10);$getManual();}
verifyHistory($before===$counts()&&$mail->llamadas===$sent,'consultas y resumen no envían ni insertan registros o expedientes');
$_SESSION=[];rejectHistory(fn()=>$history->consultar('manual',$manual),401,'sesión caducada denegada');
echo "PASS $count comprobaciones de historial con SQL real/proveedor simulado\n";
