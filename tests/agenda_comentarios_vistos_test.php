<?php
// SQL real sobre SQLite aislado. No usa datos ni sesiones del CRM publicado.
require_once __DIR__.'/../app/services/AgendaComentarioService.php';
require_once __DIR__.'/fixtures/agenda_comentarios_db.php';
function vistoCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function vistoSession($id,$agenda=true){$_SESSION=['usuario_id'=>$id,'rol_id'=>4,'permisos'=>$agenda?['reuniones.ver']:[]];}
function vistoItems($s,$id){return array_values(array_filter($s->notificaciones($id)['recordatorios'],fn($r)=>isset($r['agenda_comentario_visto_por'])));}
$db=new ComentarioDbPrueba();
$db->pdo->exec('CREATE TABLE agenda_comentarios_vistos_avisos(comentario_id INTEGER,lector_id INTEGER,leido_at TEXT,PRIMARY KEY(comentario_id,lector_id))');
$repo=new AgendaComentarioRepository($db);$s=new AgendaComentarioService($repo,new DateTimeImmutable('2026-10-28 10:00:00'));
vistoSession(5);$id=$s->crear(5,['fecha'=>'2026-10-28','hora'=>'','comentario'=>'Revisar reporte'])['comentario']['id'];
$s->leer(5,$id);vistoCheck(vistoItems($s,5)===[],'No avisar al autor por su propia lectura');
vistoSession(7);$s->leer(7,$id);$s->leer(7,$id);
vistoSession(5);$items=vistoItems($s,5);vistoCheck(count($items)===1,'Una notificación por lector aunque relea');
vistoCheck(strpos($items[0]['mensaje'],'Cuenta Clave marcó como leído tu comentario: Revisar reporte')!==false,'Nombre obtenido de usuarios y comentario correcto');
vistoCheck($items[0]['agenda_comentario_visto_por']===7 && strpos($items[0]['url'],'comentario_visto_por=7')!==false,'Identidad y enlace independientes');
$s->registrarEntregados(5,$items);vistoCheck($s->notificaciones(5)['avisos']===[],'Entrega de visto se deduplica por evento');
vistoSession(8);$s->leer(8,$id);
vistoSession(5);vistoCheck(count(vistoItems($s,5))===2,'Avisos separados para dos lectores');
$reads=$db->pdo->query('SELECT * FROM agenda_comentarios_lecturas ORDER BY usuario_id')->fetchAll(PDO::FETCH_ASSOC);
vistoCheck($s->leerVisto(5,$id,7)['ok']&&$s->leerVisto(5,$id,7)['ok'],'Abrir aviso de lectura es idempotente');
vistoCheck($db->pdo->query('SELECT * FROM agenda_comentarios_lecturas ORDER BY usuario_id')->fetchAll(PDO::FETCH_ASSOC)===$reads,'Ack del autor no marca el comentario ni modifica lecturas');
$items=vistoItems($s,5);vistoCheck(count($items)===1&&$items[0]['agenda_comentario_visto_por']===8,'Abrir uno no retira el aviso del otro lector');
vistoSession(7);$s->leer(7,$id);vistoCheck(vistoItems($s,7)===[],'Otro lector no recibe avisos del autor');
vistoCheck($s->leerVisto(7,$id,8)['codigo_http']===403,'Tercero no puede abrir avisos ajenos');
vistoSession(9,false);vistoCheck($s->leerVisto(9,$id,7)['codigo_http']===403&&vistoItems($s,9)===[],'Sin Agenda no hay avisos ni acceso');
vistoSession(5);vistoCheck(count(vistoItems($s,5))===1,'Releer no regenera un aviso atendido');
vistoCheck($s->leerVisto(5,$id,9)['codigo_http']===404,'No inventar lecturas de otro usuario');
vistoCheck($s->leerVisto(5,$id,5)['codigo_http']===404,'No existe aviso de lectura propia');
$s->eliminar(5,$id);vistoCheck(vistoItems($s,5)===[],'Borrar el comentario retira sus avisos pendientes');
$old=new ComentarioDbPrueba();$oldService=new AgendaComentarioService(new AgendaComentarioRepository($old));
vistoSession(5);$oldId=$oldService->crear(5,['fecha'=>'2026-10-28','comentario'=>'Anterior'])['comentario']['id'];
vistoSession(7);vistoCheck($oldService->leer(7,$oldId)['ok'],'Sin tabla nueva sigue funcionando la lectura anterior');
vistoSession(5);vistoCheck(vistoItems($oldService,5)===[]&&$oldService->leerVisto(5,$oldId,7)['codigo_http']===503,'Falta de instalación limitada a avisos nuevos');
echo "OK: autor/lector reales, lectura propia excluida, deduplicación, múltiples lectores, ack individual e idempotente, permisos, borrado y compatibilidad sin tabla nueva.\n";
