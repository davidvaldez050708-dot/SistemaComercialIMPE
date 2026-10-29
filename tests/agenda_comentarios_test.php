<?php
// SQL del repositorio sobre SQLite en memoria. Sin BD del CRM ni mensajes reales.
require_once __DIR__.'/../app/services/AgendaComentarioService.php';
set_error_handler(static function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
function comprobarComentario($ok,$mensaje){if(!$ok)throw new RuntimeException($mensaje);}
require_once __DIR__.'/fixtures/agenda_comentarios_db.php';
function sesionComentario($id,$permiso=true,$rol=4){$_SESSION=['usuario_id'=>$id,'rol_id'=>$rol,'permisos'=>$permiso?['reuniones.ver']:[]];}
$db=new ComentarioDbPrueba();$repo=new AgendaComentarioRepository($db);
$ahora=new DateTimeImmutable('2026-10-28 10:00:00');$service=new AgendaComentarioService($repo,$ahora);
sesionComentario(5);
$r=$service->crear(5,['fecha'=>'2026-10-29','comentario'=>"Texto con <script>alert('x')</script> y áéíóú",'hora'=>'','creado_por'=>7,'autor_nombre'=>'FALSO']);
comprobarComentario($r['ok'],'Crear sin hora');$sinHora=$r['comentario']['id'];
comprobarComentario($r['comentario']['autor']==='Analista de Prueba','Autor de la sesión y DB, no de POST');
comprobarComentario($r['comentario']['hora']===null,'No inventar medianoche para comentario sin hora');
comprobarComentario($repo->obtener($sinHora)['creado_at']==='2026-10-28 10:00:00','Hora PHP explícita, sin NOW UTC de MySQL');
foreach([['fecha'=>'2026-02-30'],['fecha'=>'no'],['hora'=>'25:10'],['hora'=>[]],['comentario'=>' '],['comentario'=>str_repeat('a',251)],['comentario'=>[]]]as$invalid){
    $data=array_merge(['fecha'=>'2026-10-28','comentario'=>'válido','hora'=>''],$invalid);
    comprobarComentario($service->crear(5,$data)['codigo_http']===422,'Validar datos sin normalizarlos silenciosamente');
}
comprobarComentario($service->crear(5,['fecha'=>'2026-10-28','comentario'=>str_repeat('😀',250),'hora'=>''])['ok'],'250 caracteres Unicode');
sesionComentario(9,false);$queries=count($db->queries);
foreach([$service->listar(9,'2026-10-01','2026-11-01'),$service->crear(9,['fecha'=>'2026-10-28','comentario'=>'no']),$service->leer(9,$sinHora),$service->eliminar(9,$sinHora)]as$r)comprobarComentario($r['codigo_http']===403,'Sin Agenda no hay acceso');
comprobarComentario($service->notificaciones(9)===['recordatorios'=>[],'avisos'=>[]]&&count($db->queries)===$queries,'Sin Agenda no se consultan ni notifican comentarios');
sesionComentario(7,true,6);
$notas=$service->listar(7,'2026-09-28','2026-11-09');comprobarComentario($notas['ok']&&count($notas['comentarios'])===2,'Comentarios compartidos sin alterar alcance de reuniones');
comprobarComentario(!$notas['comentarios'][0]['puede_eliminar'],'Cuenta Clave no borra comentarios ajenos');
comprobarComentario($service->eliminar(7,$sinHora)['codigo_http']===403,'Servidor rechaza borrado ajeno');
$avisos=$service->notificaciones(7);comprobarComentario(count($avisos['avisos'])===2,'Novedad para Cuenta Clave');
comprobarComentario($avisos['recordatorios'][0]['estado']==='normal','Sin hora no tiene recordatorio recurrente');
$service->registrarEntregados(7,$avisos['recordatorios']);comprobarComentario(!$service->notificaciones(7)['avisos'],'Novedad no se repite cada poll');
comprobarComentario($service->leer(7,$sinHora)['ok'],'Lectura personal');
comprobarComentario(count($service->notificaciones(7)['recordatorios'])===1,'Leer retira sólo ese aviso');
sesionComentario(8,true,2);comprobarComentario(count($service->notificaciones(8)['avisos'])===2,'Perfil de consulta con Agenda también recibe novedades');
sesionComentario(5);
$r=$service->crear(5,['fecha'=>'2026-10-28','comentario'=>'Comentario programado','hora'=>'11:00']);$conHora=$r['comentario']['id'];
sesionComentario(7,true,6);$service->leer(7,$conHora);
$despues=new AgendaComentarioService($repo,new DateTimeImmutable('2026-10-28 11:01:00'));
$listado=$despues->listar(7,'2026-10-01','2026-11-01')['comentarios'];
comprobarComentario(count(array_filter($listado,fn($c)=>$c['id']===$conHora && $c['recordatorio_pendiente']))===1,'El servidor comunica el vencimiento sin depender de la zona del navegador');
$horaPayload=$despues->notificaciones(7);
$due=array_values(array_filter($horaPayload['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$conHora));
comprobarComentario(count($due)===1&&$due[0]['estado']==='vencida','Leer antes de la hora conserva el recordatorio futuro');
$despues->registrarEntregados(7,$due);
comprobarComentario(count(array_filter($despues->notificaciones(7)['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$conHora))===1,'Permanece pendiente para la cadencia existente');
$despues->leer(7,$conHora);
comprobarComentario(!array_filter($despues->notificaciones(7)['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$conHora),'Atender después de la hora detiene el recordatorio de ese usuario');
sesionComentario(8,true,2);comprobarComentario(count(array_filter($despues->notificaciones(8)['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$conHora))===1,'Atender no afecta a otros destinatarios');
sesionComentario(5);comprobarComentario($service->eliminar(5,$conHora)['ok'],'Autor puede borrar');
sesionComentario(7,true,6);comprobarComentario(!array_filter($despues->notificaciones(7)['recordatorios'],fn($r)=>$r['agenda_comentario_id']===$conHora),'Borrar retira notificaciones');
sesionComentario(5);
for($i=0;$i<15;$i++)$service->crear(5,['fecha'=>'2026-10-28','comentario'=>'Pendiente '.$i,'hora'=>'']);
sesionComentario(7,true,6);$_SESSION['agenda_comentarios_avisos']=[];
$batch=$service->notificaciones(7);$service->registrarEntregados(7,array_slice($batch['recordatorios'],0,12));
comprobarComentario(count($service->notificaciones(7)['avisos'])===count($batch['avisos'])-12,'No consumir novedades excluidas por el límite de campana');
comprobarComentario(!preg_grep('/\b(UPDATE|INSERT INTO|DELETE FROM|ALTER TABLE)\s+(reuniones|seguimientos|usuarios)/i',$db->queries),'No escribir tablas de otros módulos');
$missing=new ComentarioDbPrueba();$missing->pdo->exec('DROP TABLE agenda_comentarios_lecturas');
$absent=new AgendaComentarioService(new AgendaComentarioRepository($missing),$ahora);
comprobarComentario($absent->listar(7,'2026-10-01','2026-11-01')['codigo_http']===503,'Ausencia de migración se limita a comentarios');
comprobarComentario($absent->notificaciones(7)===['recordatorios'=>[],'avisos'=>[]],'Campana existente continúa sin tablas nuevas');
$out=dirname(__DIR__).'/tmp/agenda_comentarios';if(!is_dir($out))mkdir($out,0700,true);
file_put_contents($out.'/avisos.json',json_encode(['normal'=>['recordatorios'=>[$avisos['recordatorios'][0]],'avisos'=>[$avisos['avisos'][0]]],
    'hora'=>['recordatorios'=>$due,'avisos'=>array_values(array_filter($horaPayload['avisos'],fn($r)=>$r['agenda_comentario_id']===$conHora))]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo "OK: alta, fecha/hora, autor, 250 Unicode, permisos, borrado, lectura por usuario, cadencia sin hora/con hora, límite y tablas ausentes. Sin BD real.\n";
