<?php
// Reutiliza la BD SQL en memoria y las pruebas de autorización/fecha de agenda.
ob_start();require __DIR__ . '/agenda_confirmacion_campana_test.php';ob_end_clean();
function sesionToastAgenda($id,$rol,$extra=[]) {
    $_SESSION=array_merge(['usuario_id'=>$id,'rol_id'=>$rol,'permisos'=>$rol===6?['reuniones.ver','reuniones.gestionar']:['reuniones.ver','reuniones.solicitar']],$extra);
}
$db->propuesta(1440);sesionToastAgenda(7,6);
$before=$db->fila();$solicitud=$service->obtenerNotificacionesCampana(7,6);
verificar(count($solicitud['avisos'])===1,'La solicitud futura genera un toast para Cuenta Clave');
verificar($solicitud['avisos'][0]['tipo']==='REUNION_SOLICITADA','Tipo correcto');
verificar(strpos($solicitud['avisos'][0]['mensaje'],'Analista de Datos')!==false,'Mensaje identifica el origen');
verificar($db->fila()===$before,'Generar aviso no modifica reunión ni flags de vencimiento');
verificar($service->obtenerNotificacionesCampana(7,6)['avisos']===[],'No repetir solicitud en cada poll');
verificar(count($service->obtenerNotificacionesCampana(7,6)['recordatorios'])===1,'La campana permanece después de emitir el aviso');
// Versión distinta incluso con la misma fecha: nueva propuesta del mismo registro.
$db->pdo->exec("UPDATE reuniones_vinculacion SET updated_at='2026-10-26 20:00:01' WHERE id=8");
verificar(count($service->obtenerNotificacionesCampana(7,6)['avisos'])===1,'Una nueva versión de solicitud puede notificar otra vez');
$sessionKam=$_SESSION;sesionToastAgenda(99,6);verificar($service->obtenerNotificacionesCampana(99,6)['avisos']===[],'Cuenta Clave ajena no recibe toast');
sesionToastAgenda(5,4);verificar($service->obtenerNotificacionesCampana(5,4)['avisos']===[],'El Analista no recibe aviso de su propia solicitud');
$_SESSION=$sessionKam;verificar($service->confirmar(7,6,['reunion_id'=>8,'zoom_url'=>'https://zoom.us/j/prueba'])['ok'],'Confirmación real en el fixture');
$metadata=$_SESSION['agenda_reunion_avisos_mostrados'];sesionToastAgenda(5,4,['agenda_reunion_avisos_mostrados'=>$metadata]);
$before=$db->fila();$confirmacion=$service->obtenerNotificacionesCampana(5,4);
verificar(count($confirmacion['avisos'])===1&&$confirmacion['avisos'][0]['tipo']==='REUNION_CONFIRMADA','El Analista recibe confirmación de Cuenta Clave');
verificar(strpos($confirmacion['avisos'][0]['url'],'reunion_id=8')!==false,'El toast abre la reunión correcta');
verificar($db->fila()===$before,'Notificar confirmación no modifica el estado ni marca vencimiento');
verificar($service->obtenerNotificacionesCampana(5,4)['avisos']===[],'No repetir confirmación al recargar');
sesionToastAgenda(99,4);verificar($service->obtenerNotificacionesCampana(99,4)['avisos']===[],'Otro Analista no recibe el aviso');
sesionToastAgenda(7,6);verificar($service->obtenerNotificacionesCampana(7,6)['avisos']===[],'Cuenta Clave no recibe su propia confirmación');
// Conservar eventos urgentes: ReminderController quita el duplicado de agenda.
$db->propuesta(90);sesionToastAgenda(7,6);$urgente=$service->obtenerNotificacionesCampana(7,6);$urgentReminder=$confirmation->obtener(7,6);
foreach(['reunion_id','accion','fecha'] as $key)verificar($urgente['avisos'][0][$key]===$urgentReminder['recordatorios'][0][$key],'Clave de cola compatible tras deduplicar: '.$key);
$db->propuesta(-5);sesionToastAgenda(7,6);verificar($service->obtenerNotificacionesCampana(7,6)['avisos']===[],'Una propuesta vencida no se presenta como nueva');
$expired=$confirmation->obtener(7,6);verificar(count($expired['avisos'])===1,'Los avisos de vencimiento permanecen independientes');
$db->propuesta(-5,'CONFIRMADA');sesionToastAgenda(5,4);verificar($service->obtenerNotificacionesCampana(5,4)['avisos']===[],'Una confirmación vencida conserva su recordatorio existente, sin toast de novedad');
$db->propuesta(120,'CAMBIO_SOLICITADO');sesionToastAgenda(5,4);verificar($service->obtenerNotificacionesCampana(5,4)['avisos']===[],'No añadir eventos no solicitados');
// Crear respuesta real de backend para las pruebas de navegador, sin tocar datos reales.
$fixture=['generated_at_ms'=>(int)(microtime(true)*1000),'kam'=>$solicitud,'analista'=>$confirmacion,'urgente'=>['recordatorios'=>$urgentReminder['recordatorios'],'avisos'=>$urgente['avisos']]];
$out=dirname(__DIR__).'/tmp/agenda_toasts';if(!is_dir($out))mkdir($out,0700,true);
file_put_contents($out.'/respuestas.json',json_encode($fixture,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "OK: toast solicitud/confirmación sólo al responsable, una vez por sesión y versión, sin escritura de BD, clave compatible con urgentes y vencimientos independientes.\n";
