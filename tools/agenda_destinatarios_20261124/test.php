<?php
// BD aislada, creada sólo por mysql_install_db en output; jamás usa config.php.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function tienePermiso($permiso) { return in_array($permiso, $_SESSION['permisos'] ?? [], true); }
foreach (['AgendaComentarioRepository','AgendaComentarioService'] as $clase) {
    $codigo = file_get_contents(__DIR__.'/files/app/services/'.$clase.'.php');
    eval(preg_replace('/^require_once .*;$/m', '', substr($codigo,5)));
}
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$db->real_connect('127.0.0.1','root','','',(int)(getenv('AGENDA_TEST_PORT') ?: 13317));
$db->set_charset('utf8mb4');
$db->query('CREATE DATABASE IF NOT EXISTS agenda_multiple_test');
$db->select_db('agenda_multiple_test');
foreach (['agenda_comentarios_destinatarios','agenda_comentarios_vistos_avisos','agenda_comentarios_lecturas','agenda_comentarios','usuarios','rol_permisos','permisos','roles'] as $tabla) $db->query('DROP TABLE IF EXISTS '.$tabla);
$db->query('CREATE TABLE roles (id INT PRIMARY KEY, nombre VARCHAR(40), estado INT)');
$db->query("INSERT INTO roles VALUES (1,'Administrador',1),(2,'Agenda',1),(3,'Receptor',1),(4,'Sin permisos',1),(5,'Autor limitado',1)");
$db->query('CREATE TABLE permisos (id INT PRIMARY KEY,codigo VARCHAR(100),estado INT)');
$db->query("INSERT INTO permisos VALUES (1,'agenda.ver',1),(2,'agenda.comentarios.ver',1),(3,'agenda.comentarios.crear',1),(4,'agenda.comentarios.eliminar',1),(5,'agenda.comentarios.recibir_dirigidos',1),(6,'agenda.comentarios.enviar_administrador',1)");
$db->query('CREATE TABLE rol_permisos (rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))');
$db->query('INSERT INTO rol_permisos VALUES (1,1),(1,2),(2,1),(2,2),(2,3),(2,4),(2,5),(2,6),(3,5),(5,1),(5,2),(5,3),(5,4)');
$db->query('CREATE TABLE usuarios (id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),rol_id INT,estado INT)');
$db->query("INSERT INTO usuarios VALUES (1,'Autor','',2,1),(2,'Ana','',2,1),(3,'Bea','',3,1),(4,'Ajeno','',2,1),(5,'Inactivo','',2,0),(6,'Sin permisos','',4,1),(7,'Administrador','',1,1),(8,'Autor limitado','',5,1),(9,'Fallo','',2,1)");
$db->query('CREATE TABLE agenda_comentarios (id INT AUTO_INCREMENT PRIMARY KEY,fecha DATE,recordatorio_at DATETIME NULL,comentario TEXT,creado_por INT,autor_nombre VARCHAR(255),creado_at DATETIME,eliminado_at DATETIME NULL,eliminado_por INT NULL,privado INT DEFAULT 0,destinatario_id INT NULL) ENGINE=InnoDB');
$db->query('CREATE TABLE agenda_comentarios_lecturas (comentario_id INT,usuario_id INT,novedad_leida_at DATETIME NULL,recordatorio_atendido_at DATETIME NULL,PRIMARY KEY(comentario_id,usuario_id)) ENGINE=InnoDB');
$db->query('CREATE TABLE agenda_comentarios_vistos_avisos (comentario_id INT,lector_id INT,leido_at DATETIME,PRIMARY KEY(comentario_id,lector_id)) ENGINE=InnoDB');
$sql = file_get_contents(__DIR__.'/files/database/migrations/2026_11_24_agenda_comentarios_destinatarios.sql');
$db->query($sql); $db->query($sql); // migración repetible
$clock = new DateTimeImmutable('2026-11-24 10:00:00');
$repo = new AgendaComentarioRepository($db);
$svc = new AgendaComentarioService($repo,$clock);
$checks=0;
function check($ok,$msg) { global $checks; $checks++; if (!$ok) throw new RuntimeException($msg); }
function user($id,$agenda=true) {
    global $db;
    $result=$db->query('SELECT p.codigo FROM usuarios u JOIN rol_permisos rp ON rp.rol_id=u.rol_id JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1 WHERE u.id='.(int)$id);
    $permisos=array_column($result->fetch_all(MYSQLI_ASSOC),'codigo');
    if (!$agenda) $permisos=array_values(array_intersect($permisos,['agenda.comentarios.recibir_dirigidos']));
    $_SESSION=['usuario_id'=>$id,'permisos'=>$permisos];
}
function createComment($extra=[]) { global $svc; user(1); return $svc->crear(1,array_merge(['fecha'=>'2026-11-24','hora'=>'11:00','comentario'=>'Comentario prueba'],$extra)); }
$r=createComment(['destinatarios_ids'=>['2','3','2']]);
check($r['ok'],'Crear múltiple');$id=$r['comentario']['id'];
check($r['comentario']['destinatarios_ids']===[2,3],'Deduplicar destinatarios');
check($db->query('SELECT COUNT(*) n FROM agenda_comentarios')->fetch_assoc()['n']==1,'Un solo comentario');
foreach ([1,2] as $u) { user($u);check($svc->listar($u,'2026-11-01','2026-12-01')['comentarios'][0]['id']===$id,'Visible para autor y seleccionados'); }
check($repo->obtener($id,3)!==null,'Segundo destinatario tiene acceso SQL');
user(3);check($svc->listar(3,'2026-11-01','2026-12-01')['codigo_http']===403,'Receptor sin Agenda no obtiene acceso a la pantalla');
user(4);check($svc->listar(4,'2026-11-01','2026-12-01')['comentarios']===[],'Oculto a ajenos');
check($svc->leer(4,$id)['codigo_http']===404,'Lectura directa denegada');
$repo->marcarLeido($id,4,'2026-11-24 10:00:00');
check($db->query('SELECT COUNT(*) n FROM agenda_comentarios_lecturas WHERE usuario_id=4')->fetch_assoc()['n']==0,'Protección SQL al leer');
foreach ([1,4] as $u) { user($u);check($svc->notificaciones($u)['recordatorios']===[],'No avisar a autor ni ajenos'); }
foreach ([2,3] as $u) { user($u);check(count($svc->notificaciones($u)['recordatorios'])===1,'Aviso a ambos destinatarios'); }
user(3,false);check($svc->leer(3,$id)['ok'],'Lectura dirigida sin permiso Agenda conservada');
check($db->query('SELECT recordatorio_atendido_at FROM agenda_comentarios_lecturas WHERE usuario_id=3')->fetch_assoc()['recordatorio_atendido_at']===null,'Leer antes de hora conserva recordatorio');
check(count($repo->pendientes(3,'2026-11-24 11:00:00',true))===1,'Recordatorio tras lectura anticipada');
$repo->marcarLeido($id,3,'2026-11-24 11:00:00');
check($repo->pendientes(3,'2026-11-24 11:00:00',true)===[],'Atendido individual');
check(count($repo->pendientes(2,'2026-11-24 11:00:00',true))===1,'Otro destinatario sigue pendiente');
check(count($repo->vistosPendientes(1))===1,'Aviso de lectura del segundo destinatario');
check($repo->leerAvisoVisto(1,$id,3,'2026-11-24 11:01:00'),'Consumir visto del segundo destinatario');
check($repo->leerAvisoVisto(1,$id,3,'2026-11-24 11:01:00'),'Visto idempotente');
check(!$repo->leerAvisoVisto(1,$id,4,'2026-11-24 11:01:00'),'No inventar vistos');
foreach ([['destinatarios_ids'=>['2','5']],['destinatarios_ids'=>['2','6']],['destinatarios_ids'=>['2','999']],['destinatarios_ids'=>'2,3'],['destinatarios_ids'=>['2','x']],['destinatarios_ids'=>['2'],'privado'=>'1']] as $bad) check(!createComment($bad)['ok'],'Validar destinatarios');
check($repo->destinatarioHabilitado(7,1),'Permiso explícito permite enviar a administrador');
check(!$repo->destinatarioHabilitado(7,8),'Sin permiso no permite enviar a administrador');
user(8);check(!$svc->crear(8,['fecha'=>'2026-11-24','comentario'=>'No autorizado','destinatarios_ids'=>['2','7']])['ok'],'No se mezcla un destino permitido con administrador no autorizado');
user(1);$_SESSION['permisos']=array_diff($_SESSION['permisos'],['agenda.comentarios.crear']);
check($svc->crear(1,['fecha'=>'2026-11-24','comentario'=>'No autorizado','destinatarios_ids'=>['2','3']])['codigo_http']===403,'Conserva permiso de crear');
$withoutCreate=$svc->listar(1,'2026-11-01','2026-12-01');
check(!$withoutCreate['destinatarios_disponibles'] && $withoutCreate['destinatarios']===[],'Sin crear no expone selector de destinatarios');
user(1);$_SESSION['permisos']=array_diff($_SESSION['permisos'],['agenda.comentarios.eliminar']);
check($svc->eliminar(1,$id)['codigo_http']===403,'Conserva permiso de eliminar');
check(!$svc->listar(1,'2026-11-01','2026-12-01')['comentarios'][0]['puede_eliminar'],'Oculta acción eliminar sin permiso');
user(6);check($svc->notificaciones(6)['recordatorios']===[],'Usuario sin permiso no recibe avisos');
$vencidos=(new AgendaComentarioService($repo,new DateTimeImmutable('2026-11-24 11:00:00')));
user(2);$due=$vencidos->notificaciones(2);
check(count($due['horarios']??[])===1,'Conserva horarios de recordatorios vencidos de producción');
check($due['recordatorios'][0]['agenda_autor_id']===1 && is_int($due['recordatorios'][0]['agenda_creado_at']),'Conserva metadatos usados por avisos y sonido');
$public=createComment(['hora'=>'']);check($public['ok'],'Crear público sin hora');
$publicId=$public['comentario']['id'];user(4);check($repo->obtener($publicId,4)!==null,'Público sigue visible');
$private=createComment(['privado'=>'1']);check($private['ok'],'Crear privado');
check($repo->obtener($private['comentario']['id'],2)===null,'Privado oculto a otros');
$single=createComment(['destinatario_id'=>'2']);check($single['ok'],'Cliente anterior compatible');
check($repo->obtener($single['comentario']['id'],3)===null,'Dirigido anterior oculto');
$db->query("CREATE TRIGGER fallo_destinatario BEFORE INSERT ON agenda_comentarios_destinatarios FOR EACH ROW BEGIN IF NEW.usuario_id=9 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo simulado'; END IF; END");
$before=$db->query('SELECT COUNT(*) n FROM agenda_comentarios')->fetch_assoc()['n'];
try { createComment(['destinatarios_ids'=>['2','9']]);check(false,'Debe fallar'); } catch (mysqli_sql_exception $e) {}
check($db->query('SELECT COUNT(*) n FROM agenda_comentarios')->fetch_assoc()['n']===$before,'Rollback sin comentarios parciales');
user(1);check($svc->eliminar(1,$id)['ok'],'Eliminar múltiple');
check($repo->obtener($id,2)===null,'Eliminado invisible');
check(!array_filter($repo->pendientes(2,'2026-11-24 11:00:00'),fn($f)=>(int)$f['id']===$id),'Eliminado no avisa');

// "Todos" debe respetar el mismo permiso de envío al administrador.
function contieneComentario(array $items, int $id, string $key='id'): bool {
    return (bool)array_filter($items,static fn($item)=>(int)($item[$key]??0)===$id);
}
$db->query("INSERT INTO usuarios VALUES (10,'Segundo administrador','',1,1)");
user(8);
$limited=$svc->crear(8,['fecha'=>'2026-11-24','hora'=>'11:00','comentario'=>'Todos sin administrador','todos_incluye_administrador'=>true]);
check($limited['ok'],'Crear Todos sin permiso para administrador');
$limitedId=$limited['comentario']['id'];
$limitedNoHour=$svc->crear(8,['fecha'=>'2026-11-24','comentario'=>'Todos sin hora y sin administrador']);
$limitedNoHourId=$limitedNoHour['comentario']['id'];
check(!$svc->listar(8,'2026-11-01','2026-12-01')['todos_incluye_administrador'],'API informa Todos excepto administrador');
user(1);check($svc->listar(1,'2026-11-01','2026-12-01')['todos_incluye_administrador'],'API conserva Todos para autor con permiso');
foreach ([2,4,8] as $reader) {
    user($reader);
    check($repo->obtener($limitedId,$reader)!==null,'Todos visible para los demás y su autor');
    check(contieneComentario($svc->listar($reader,'2026-11-01','2026-12-01')['comentarios'],$limitedId),'Todos aparece en agenda de no administrador');
    check(contieneComentario($svc->notificaciones($reader)['avisos'],$limitedNoHourId,'agenda_comentario_id'),'Otros reciben aviso sin hora');
}
foreach ([7,10] as $admin) {
    user($admin);
    check(!contieneComentario($svc->listar($admin,'2026-11-01','2026-12-01')['comentarios'],$limitedId),'Todos sin permiso no aparece para ningún administrador');
    check($repo->obtener($limitedId,$admin)===null,'Consulta directa oculta Todos al administrador');
    check($svc->leer($admin,$limitedId)['codigo_http']===404,'Lectura directa denegada al administrador');
    $repo->marcarLeido($limitedId,$admin,'2026-11-24 11:01:00');
    check((int)$db->query('SELECT COUNT(*) n FROM agenda_comentarios_lecturas WHERE comentario_id='.$limitedId.' AND usuario_id='.$admin)->fetch_assoc()['n']===0,'No registra una lectura no autorizada');
    $items=$svc->notificaciones($admin);
    check(!contieneComentario($items['avisos'],$limitedId,'agenda_comentario_id'),'No avisa al administrador');
    check(!contieneComentario($items['recordatorios'],$limitedNoHourId,'agenda_comentario_id'),'No muestra aviso sin hora al administrador');
    check(!contieneComentario($items['horarios']??[],$limitedId,'agenda_comentario_id'),'No programa sonido para administrador excluido');
    check(!contieneComentario($vencidos->notificaciones($admin)['recordatorios'],$limitedId,'agenda_comentario_id'),'Tampoco genera recordatorio vencido para administrador');
    check($repo->obtener($publicId,$admin)!==null,'Todos del autor con permiso sigue visible al administrador');
    check(contieneComentario($items['avisos'],$publicId,'agenda_comentario_id'),'Todos autorizado conserva sus avisos al administrador');
}
$directAdmin=createComment(['destinatarios_ids'=>['7']]);
user(7);check($repo->obtener($directAdmin['comentario']['id'],7)!==null,'Dirigido permitido al administrador conserva acceso');
check(contieneComentario($svc->notificaciones(7)['recordatorios'],$directAdmin['comentario']['id'],'agenda_comentario_id'),'Dirigido permitido conserva recordatorio');
check($repo->obtener($directAdmin['comentario']['id'],10)===null,'Dirigido a un administrador no llega al otro');

// Lecturas históricas del administrador no deben reabrir el comentario excluido.
$db->query("INSERT INTO agenda_comentarios_lecturas VALUES ($limitedId,7,'2026-11-24 09:00:00',NULL)");
$db->query("INSERT INTO agenda_comentarios_vistos_avisos VALUES ($limitedId,7,'2026-11-24 09:01:00')");
$repo->marcarLeido($limitedId,2,'2026-11-24 10:00:00');
check(!contieneComentario(array_filter($repo->vistosPendientes(8),fn($f)=>(int)$f['lector_id']===7),$limitedId),'No entrega visto de administrador excluido');
check(!$repo->leerAvisoVisto(8,$limitedId,7,'2026-11-24 10:01:00'),'No reabre una lectura histórica excluida');
check($repo->leerAvisoVisto(8,$limitedId,2,'2026-11-24 10:01:00'),'Lectura de otros usuarios sigue funcionando');

// Las reglas siguen el permiso vigente, incluso para comentarios ya existentes.
$db->query('INSERT INTO rol_permisos VALUES (5,6)');
check($repo->obtener($limitedId,7)!==null,'Con permiso concedido Todos incluye administrador');
$db->query('DELETE FROM rol_permisos WHERE rol_id=5 AND permiso_id=6');
check($repo->obtener($limitedId,7)===null,'Sin permiso vigente vuelve a excluir administrador');
$ownAdmin=$repo->crear(7,'2026-11-24',null,'Comentario propio del administrador','2026-11-24 10:00:00');
check($repo->obtener($ownAdmin,7)!==null,'Administrador conserva sus propios comentarios');
check($repo->obtener($ownAdmin,10)===null,'Ser autor administrador no permite enviar a otros administradores sin permiso');

// Edición del mismo comentario: autor, privacidad y recordatorio conservados.
$editable=createComment(['destinatarios_ids'=>['2','3']]);$editId=$editable['comentario']['id'];
check($editable['comentario']['puede_editar'],'Autor puede editar con permiso de crear');
user(2);check(!$svc->listar(2,'2026-11-01','2026-12-01')['comentarios'][0]['puede_editar'],'Destinatario no puede editar comentarios ajenos');
$editData=['fecha'=>'2026-11-24','hora'=>'11:00','comentario'=>'Texto editado','destinatarios_ids'=>['2','3']];
check($svc->editar(2,$editId,$editData)['codigo_http']===403,'Rechaza edición de otro autor');
user(4);check($svc->editar(4,$editId,$editData)['codigo_http']===404,'Ajeno no obtiene comentario para editar');
user(1);$_SESSION['permisos']=array_diff($_SESSION['permisos'],['agenda.comentarios.crear']);
check($svc->editar(1,$editId,$editData)['codigo_http']===403,'Edición requiere permiso de crear');
user(1);check($svc->editar(1,0,$editData)['codigo_http']===404,'Id inválido');
check($svc->editar(1,999999,$editData)['codigo_http']===404,'Id inexistente');
$repo->marcarLeido($editId,2,'2026-11-24 11:00:00');
$beforeEdit=$repo->obtener($editId,1);
$edited=$svc->editar(1,$editId,$editData);
check($edited['ok'] && $edited['comentario']['id']===$editId,'Actualiza mismo id');
check($edited['comentario']['comentario']==='Texto editado','Devuelve texto nuevo');
check($edited['comentario']['creado_at']===$beforeEdit['creado_at'],'Conserva fecha de creación');
$read=$db->query('SELECT * FROM agenda_comentarios_lecturas WHERE comentario_id='.$editId.' AND usuario_id=2')->fetch_assoc();
check(!empty($read['novedad_leida_at']) && !empty($read['recordatorio_atendido_at']),'Editar texto conserva lectura y recordatorio atendido');
check($svc->editar(1,$editId,$editData)['ok'],'Guardar sin cambios es válido');
$badEdits=[['comentario'=>''],['comentario'=>str_repeat('x',251)],['hora'=>'25:00'],['fecha'=>'2026-02-30'],['privado'=>'1'],['destinatarios_ids'=>['5']]];
foreach ($badEdits as $bad) check(!$svc->editar(1,$editId,array_merge($editData,$bad))['ok'],'Valida la edición como la creación');
check($repo->obtener($editId,1)['comentario']==='Texto editado','Ediciones inválidas no alteran comentario');
$editData['hora']='12:00';check($svc->editar(1,$editId,$editData)['ok'],'Permite cambiar hora');
$read=$db->query('SELECT * FROM agenda_comentarios_lecturas WHERE comentario_id='.$editId.' AND usuario_id=2')->fetch_assoc();
check($repo->obtener($editId,2)['recordatorio_at']==='2026-11-24 12:00:00' && $read['recordatorio_atendido_at']===null && !empty($read['novedad_leida_at']),'Reprograma sin convertir lectura en comentario nuevo');
check(count(array_filter($repo->pendientes(2,'2026-11-24 12:00:00'),fn($c)=>(int)$c['id']===$editId))===1,'Nueva hora vuelve a estar pendiente');
$editData['destinatarios_ids']=['3'];check($svc->editar(1,$editId,$editData)['ok'],'Cambia destinatarios');
check($repo->obtener($editId,2)===null && $repo->obtener($editId,3)!==null,'Retira visibilidad al destinatario eliminado');
check($db->query('SELECT COUNT(*) n FROM agenda_comentarios_lecturas WHERE comentario_id='.$editId.' AND usuario_id=2')->fetch_assoc()['n']==0,'Retira lectura del destinatario eliminado');
$editData['destinatarios_ids']=[];$editData['privado']='1';
check($svc->editar(1,$editId,$editData)['ok'],'Convierte a privado');
check($repo->obtener($editId,3)===null && $repo->obtener($editId,1)!==null,'Privado sólo visible para autor');
check($db->query('SELECT COUNT(*) n FROM agenda_comentarios_destinatarios WHERE comentario_id='.$editId)->fetch_assoc()['n']==0,'Privado sin destinatarios almacenados');
$editData['privado']='0';$editData['hora']='';
check($svc->editar(1,$editId,$editData)['ok'],'Convierte a Todos sin hora');
check($repo->obtener($editId,4)!==null && $repo->obtener($editId,1)['recordatorio_at']===null,'Todos visible sin recordatorio');
$beforeRollback=$repo->obtener($editId,1);
try {$svc->editar(1,$editId,array_merge($editData,['destinatarios_ids'=>['2','9'],'comentario'=>'No guardar']));check(false,'Debe fallar actualización de destinatarios');} catch (mysqli_sql_exception $e) {}
check($repo->obtener($editId,1)===$beforeRollback,'Rollback restaura texto, privacidad y destinatarios');
check(!$repo->editar($editId,2,'2026-11-24',null,'Ajeno'),'SQL protege autor de edición');
check($svc->eliminar(1,$editId)['ok'],'Eliminar comentario editado');
check($svc->editar(1,$editId,$editData)['codigo_http']===404,'No restaura comentario eliminado al editar');
user(8);$limitedEditData=['fecha'=>'2026-11-24','comentario'=>'Todos editado','destinatarios_ids'=>[]];
check($svc->editar(8,$limitedId,$limitedEditData)['ok'],'Autor limitado puede editar su comentario');
check($repo->obtener($limitedId,7)===null,'Editar Todos conserva exclusión de administrador');
check(!$svc->editar(8,$limitedId,array_merge($limitedEditData,['destinatarios_ids'=>['7']]))['ok'],'Editar no permite saltar permiso para administrador');

// Compatibilidad antes de la migración: rechazar varios y conservar uno.
$db->query('DROP TABLE agenda_comentarios_destinatarios');
$repo=new AgendaComentarioRepository($db);$svc=new AgendaComentarioService($repo,$clock);
check(!createComment(['destinatarios_ids'=>['2','3']])['ok'],'Sin migración no ampliar audiencia');
check(createComment(['destinatario_id'=>'2'])['ok'],'Sin migración uno funciona');
check($repo->listar('2026-11-01','2026-12-01',4)!==[],'Consulta anterior funciona');
check($repo->obtener($limitedId,7)===null,'Exclusión también funciona sin tabla de destinatarios múltiples');
user(1);check($svc->editar(1,$single['comentario']['id'],['fecha'=>'2026-11-24','comentario'=>'Uno editado','destinatario_id'=>'2'])['ok'],'Editar un destinatario antes de migración conserva compatibilidad');
echo json_encode(['ok'=>true,'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;
