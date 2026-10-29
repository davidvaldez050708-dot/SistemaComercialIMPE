<?php
// Sólo SQLite en memoria o la MariaDB efímera del runner de pruebas.
$site=getenv('AGENDA_DESTINATARIOS_SITE');
require_once ($site?:dirname(__DIR__)).'/app/services/AgendaComentarioService.php';
if ($site) $db=(new Database())->connect(false);
else {require_once __DIR__.'/fixtures/agenda_comentarios_db.php';$db=new ComentarioDbPrueba();}
function dcheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function dsql($sql){global $db;return isset($db->pdo)?$db->pdo->exec($sql):$db->query($sql);}
function dsess($id,$agenda=true){$_SESSION=['usuario_id'=>$id,'rol_id'=>$id===7?6:4,'permisos'=>$agenda?['reuniones.ver']:[]];}
if (!$site) {
    dsql('ALTER TABLE agenda_comentarios ADD COLUMN privado INTEGER NOT NULL DEFAULT 0');
    dsql('ALTER TABLE agenda_comentarios ADD COLUMN destinatario_id INTEGER DEFAULT NULL');
    dsql('CREATE TABLE agenda_comentarios_vistos_avisos(comentario_id INTEGER,lector_id INTEGER,leido_at TEXT,PRIMARY KEY(comentario_id,lector_id))');
}
dsql('ALTER TABLE usuarios ADD COLUMN rol_id INT NOT NULL DEFAULT 4');
dsql('ALTER TABLE usuarios ADD COLUMN estado INT NOT NULL DEFAULT 1');
dsql('CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado INT)');
dsql('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(100),estado INT)');
dsql('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');
dsql("INSERT INTO roles VALUES(4,'Analista',1),(6,'Cuenta Clave',1),(8,'Otro con Agenda',1),(9,'Sin Agenda',1),(10,'Rol inactivo',0)");
dsql("INSERT INTO permisos VALUES(26,'reuniones.ver',1),(27,'otro',1)");
dsql('INSERT INTO rol_permisos VALUES(4,26),(6,26),(8,26),(9,27),(10,26)');
dsql('UPDATE usuarios SET rol_id=6 WHERE id=7');
// Los datos iniciales del runner MariaDB y de SQLite tienen dos y cuatro usuarios.
dsql('DELETE FROM usuarios WHERE id NOT IN (5,7)');
dsql("INSERT INTO usuarios(id,nombre,apellidos,rol_id,estado) VALUES(8,'Consulta','Agenda',8,1),(9,'Sin','Agenda',9,1),(10,'Desactivado','Usuario',4,0),(11,'Desactivado','Rol',10,1)");
foreach (['agenda_comentarios_vistos_avisos','agenda_comentarios_lecturas','agenda_comentarios'] as $t) dsql('DELETE FROM '.$t);
if ($site) {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($site.'/tools/configurar_destinatarios_agenda.php');
    exec($cmd.' --check 2>&1',$output,$code);dcheck($code!==0,'Check detecta migración ausente');
    for($i=0;$i<2;$i++){exec($cmd.' --apply 2>&1',$output,$code);dcheck($code===0,'Migración CLI idempotente: '.implode(' ',$output));}
    exec($cmd.' --check 2>&1',$output,$code);dcheck($code===0,'Check tras instalar');
}
$repo=new AgendaComentarioRepository($db);$now=new DateTimeImmutable('2026-10-29 10:00:00');$s=new AgendaComentarioService($repo,$now);
dsess(5);
$list=$s->listar(5,'2026-10-01','2026-11-01');
dcheck($list['destinatarios_disponibles']===true,'Anunciar instalación');
$ids=array_column($list['destinatarios'],'id');sort($ids);dcheck($ids===[5,7,8],'Sólo usuarios activos con rol y permiso de Agenda activos');
$general=$s->crear(5,['fecha'=>'2026-10-29','comentario'=>'General','destinatario_id'=>''])['comentario']['id'];
$direct=$s->crear(5,['fecha'=>'2026-10-29','comentario'=>'Para Cuenta Clave','hora'=>'11:00','destinatario_id'=>'7'])['comentario'];
$id=$direct['id'];dcheck($direct['destinatario_id']===7&&$direct['destinatario_nombre']==='Cuenta Clave','Persistir ID y nombre real');
dcheck(!$direct['recordatorio_pendiente'],'Autor no recibe recordatorio ajeno');
$withoutTime=$s->crear(5,['fecha'=>'2026-10-29','comentario'=>'Directo sin hora','destinatario_id'=>7])['comentario']['id'];
$private=$s->crear(5,['fecha'=>'2026-10-29','comentario'=>'Privado','privado'=>'1'])['comentario']['id'];
$normalIds=static function($service,$user){return array_column(array_filter($service->notificaciones($user)['recordatorios'],static fn($n)=>empty($n['agenda_comentario_visto_por'])),'agenda_comentario_id');};
dcheck($normalIds($s,5)===[$private,$general],'Autor sólo general/privado, no dirigido a otro');
dsess(7);$notifications=$s->notificaciones(7);$got=$normalIds($s,7);sort($got);$expected=[$general,$id,$withoutTime];sort($expected);dcheck($got===$expected,'Destinatario recibe aviso nuevo de los dirigidos y generales');
dcheck(count($notifications['avisos'])===3,'Toast usa exactamente la misma selección de la campana');
$s->registrarEntregados(7,$notifications['recordatorios']);dcheck($s->notificaciones(7)['avisos']===[],'Conserva deduplicación de toast existente');
dcheck($s->leer(7,$id)['ok'],'Lectura inicial retira novedad');
dcheck(!in_array($id,$normalIds($s,7),true),'Novedad dirigida atendida por destinatario');
$later=new AgendaComentarioService($repo,new DateTimeImmutable('2026-10-29 11:01:00'));
dcheck(in_array($id,$normalIds($later,7),true),'Recordatorio dirigido se activa a su hora aunque ya se leyó');
dcheck($later->leer(7,$id)['ok']&&!in_array($id,$normalIds($later,7),true),'Atender a la hora retira sólo recordatorio propio');
dsess(8);dcheck($normalIds($later,8)===[$general],'Tercer usuario recibe sólo general');
$comments=$later->listar(8,'2026-10-01','2026-11-01')['comentarios'];dcheck(count($comments)===3,'Se conserva visibilidad compartida; privado no aparece');
dcheck(!array_values(array_filter($comments,fn($c)=>$c['id']===$id))[0]['recordatorio_pendiente'],'Tercer usuario no recibe estado vencido ajeno');
dsess(9,false);dcheck($s->notificaciones(9)===['recordatorios'=>[],'avisos'=>[]],'Sin Agenda no recibe');
dsess(5);$seen=$s->notificaciones(5)['recordatorios'];dcheck(count(array_filter($seen,fn($n)=>($n['agenda_comentario_visto_por']??0)===7))===1,'Autor conserva aviso de quién leyó');
foreach (['0','-1','7abc','1.5',[],true,9,10,11,99999] as $invalid) {
    $r=$s->crear(5,['fecha'=>'2026-10-29','comentario'=>'Inválido','destinatario_id'=>$invalid]);
    dcheck(($r['codigo_http']??0)===422,'Rechazar ID inválido/inactivo/sin Agenda, sin difundir a todos');
}
dcheck($s->crear(5,['fecha'=>'2026-10-29','comentario'=>'No divulgar','privado'=>'1','destinatario_id'=>7])['codigo_http']===422,'Privado no admite avisos ajenos');
dsql('UPDATE usuarios SET estado=0 WHERE id=7');
dcheck($s->crear(5,['fecha'=>'2026-10-29','comentario'=>'Desactivado después de listar','destinatario_id'=>7])['codigo_http']===422,'Revalidar destinatario al guardar');
dcheck(!in_array(7,array_column($s->listar(5,'2026-10-01','2026-11-01')['destinatarios'],'id'),true),'Lista se actualiza al desactivar cuenta');
dcheck($s->eliminar(5,$id)['ok'],'Se conserva borrado exclusivo del autor');
// Los dirigidos ajenos no consumen los 20 puestos de la campana.
for($i=0;$i<25;$i++)$repo->crear(5,'2026-10-29',null,'Para otra persona '.$i,'2026-10-29 10:00:00',false,8);
dsess(7);dcheck(in_array($general,$normalIds($s,7),true),'Filtro de destinatario antes de LIMIT de campana');
if (!$site) {
    dsess(5);$legacy=new ComentarioDbPrueba();$old=new AgendaComentarioService(new AgendaComentarioRepository($legacy),$now);
    dcheck($old->crear(5,['fecha'=>'2026-10-29','comentario'=>'General anterior'])['ok'],'Generales funcionan antes de migrar');
    dcheck($old->crear(5,['fecha'=>'2026-10-29','comentario'=>'No difundir','destinatario_id'=>7])['codigo_http']===503,'Sin migración no convertir dirigido a Todos');
    dcheck((int)$legacy->pdo->query('SELECT COUNT(*) FROM agenda_comentarios')->fetchColumn()===1,'No persistir una elección dirigida sin columna');
}
echo "OK: destinatarios activos, todos, privado, permisos, campana/toast, hora y lectura, autor, validación y visibilidad compartida; SQL real.\n";
