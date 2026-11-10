<?php
// SQL y sesiones sintéticas; nunca utiliza la BD del CRM.
require_once __DIR__.'/../app/services/AgendaComentarioService.php';
require_once __DIR__.'/fixtures/agenda_comentarios_db.php';
function privadoCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function privadoSession($id,$rol=4,$agenda=true){$_SESSION=['usuario_id'=>$id,'rol_id'=>$rol,'permisos'=>$agenda?['reuniones.ver']:[]];}
$db=new ComentarioDbPrueba();
$db->pdo->exec('ALTER TABLE agenda_comentarios ADD COLUMN privado INTEGER NOT NULL DEFAULT 0');
$db->pdo->exec('CREATE TABLE agenda_comentarios_vistos_avisos(comentario_id INTEGER,lector_id INTEGER,leido_at TEXT,PRIMARY KEY(comentario_id,lector_id))');
$repo=new AgendaComentarioRepository($db);$now=new DateTimeImmutable('2026-10-29 10:00:00');$s=new AgendaComentarioService($repo,$now);
privadoSession(5);
$shared=$s->crear(5,['fecha'=>'2026-10-29','hora'=>'','comentario'=>'Compartido'])['comentario']['id'];
$secret=$s->crear(5,['fecha'=>'2026-10-29','hora'=>'00:00','comentario'=>'Sólo autor 😀','privado'=>'1','creado_por'=>7]);
privadoCheck($secret['ok']&&$secret['comentario']['privado']===true&&$secret['comentario']['hora']==='00:00','Privado, medianoche y autor de sesión');
$id=$secret['comentario']['id'];
$untimed=$s->crear(5,['fecha'=>'2026-10-29','hora'=>'','comentario'=>'Privado sin hora','privado'=>true])['comentario'];
privadoCheck($untimed['hora']===null&&$untimed['privado'],'Hora sigue siendo opcional');
privadoCheck(count($s->listar(5,'2026-10-01','2026-11-01')['comentarios'])===3,'Autor ve compartidos y privados');
privadoCheck($s->listar(5,'2026-10-01','2026-11-01')['privados_disponibles']===true,'Servidor anuncia privacidad instalada');
$own=$s->notificaciones(5);privadoCheck(count($own['recordatorios'])===3,'Autor recibe sus recordatorios privados');
privadoCheck(strpos(json_encode($own,JSON_UNESCAPED_UNICODE),'Comentario privado')!==false,'Campana indica privado');
foreach([[7,6],[8,2],[15,1]] as [$user,$role]){
    privadoSession($user,$role);
    $list=$s->listar($user,'2026-10-01','2026-11-01');
    privadoCheck(count($list['comentarios'])===1&&$list['comentarios'][0]['id']===$shared&&!$list['comentarios'][0]['privado'],'Ni cuenta clave, consulta o administrador ven privados ajenos');
    privadoCheck(count($s->notificaciones($user)['recordatorios'])===1,'Campana y toast sin contenido privado ajeno');
    privadoCheck($s->leer($user,$id)['codigo_http']===404,'ID directo no permite leer privado');
    privadoCheck($s->eliminar($user,$id)['codigo_http']===404,'ID directo no permite borrar ni confirmar existencia');
    privadoCheck($s->leerVisto($user,$id,5)['codigo_http']===404,'Avisos de lectura tampoco revelan privado');
    $repo->marcarLeido($id,$user,'2026-10-29 10:01:00');
}
privadoCheck((int)$db->pdo->query('SELECT COUNT(*) FROM agenda_comentarios_lecturas')->fetchColumn()===0,'SQL también impide lecturas ajenas');
privadoSession(7,6);$s->leer(7,$shared);
privadoSession(5);privadoCheck(count($repo->vistosPendientes(5))===1,'Vistos de compartidos conservados');
privadoCheck($s->leer(5,$id)['ok'],'Autor atiende privado');
privadoCheck(count($repo->vistosPendientes(5))===1,'Privado no genera recibo de lectura propio');
privadoCheck(count($s->notificaciones(5)['recordatorios'])===3,'Sólo se retira aviso privado atendido; compartido y visto siguen');
privadoCheck($s->eliminar(5,$id)['ok'],'Autor borra privado');
foreach(['yes','false',2,[]] as $value){
    privadoCheck($s->crear(5,['fecha'=>'2026-10-29','comentario'=>'X','privado'=>$value])['codigo_http']===422,'Visibilidad inválida no se acepta');
}
// Muchas filas privadas no consumen el límite mensual o de campana ajeno.
for($i=0;$i<505;$i++)$repo->crear(5,'2026-10-29',null,'Privado '.$i,'2026-10-29 10:00:00',true);
privadoSession(8,2);$list=$s->listar(8,'2026-10-01','2026-11-01');
privadoCheck(count($list['comentarios'])===1&&!$list['hay_mas'],'Filtro SQL antes de LIMIT y contador');
privadoCheck(count($s->notificaciones(8)['recordatorios'])===1,'Privados no desplazan avisos compartidos ajenos');
privadoSession(9,4,false);privadoCheck($s->listar(9,'2026-10-01','2026-11-01')['codigo_http']===403,'Sin agenda no accede');
$old=new ComentarioDbPrueba();$oldService=new AgendaComentarioService(new AgendaComentarioRepository($old),$now);
privadoSession(5);privadoCheck($oldService->crear(5,['fecha'=>'2026-10-29','comentario'=>'Antes de migración'])['ok'],'Compatibilidad compartidos sin columna');
privadoCheck($oldService->listar(5,'2026-10-01','2026-11-01')['privados_disponibles']===false,'No anunciar privacidad antes de migración');
privadoCheck($oldService->crear(5,['fecha'=>'2026-10-29','comentario'=>'No divulgar','privado'=>'1'])['codigo_http']===503,'Sin migración no degrada privado a compartido');
privadoCheck((int)$old->pdo->query('SELECT COUNT(*) FROM agenda_comentarios')->fetchColumn()===1,'No guardar privado como compartido si falta migración');
class PrivadoDbConFallo extends ComentarioDbPrueba {
    public function query($sql){if($sql==='SELECT privado FROM agenda_comentarios LIMIT 0')throw new mysqli_sql_exception('Fallo sintético',1213);return parent::query($sql);}
}
$broken=new AgendaComentarioRepository(new PrivadoDbConFallo());
try{$broken->listar('2026-10-01','2026-11-01',7);throw new RuntimeException('No debe listar sin filtro tras fallo');}
catch(mysqli_sql_exception $e){privadoCheck($e->getCode()===1213,'Fallo de BD no elimina filtro de privacidad');}
echo "OK: privados sólo para autor, permisos/IDs directos, SQL antes de LIMIT, recordatorios propios, vistos compartidos, borrado, migración ausente y fallos sin divulgación.\n";
