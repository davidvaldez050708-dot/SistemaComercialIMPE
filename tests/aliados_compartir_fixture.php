<?php
if(PHP_SAPI!=='cli'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);
if(!$db->query("SHOW TABLES LIKE 'aliados_contactos'")->num_rows)require_once __DIR__.'/aliados_canales_fixture.php';
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
require_once __DIR__.'/../app/services/AliadoCompartirService.php';
$db->query("UPDATE usuarios SET correo='cuenta@example.test' WHERE id=2");
$db->query('DROP TABLE aliados_convocatorias_envios');
$sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_02_aliados_cuenta_clave.sql');
preg_match('/CREATE TABLE IF NOT EXISTS aliados_convocatorias_envios[\s\S]+?;/',$sql,$ddl);
$db->query($ddl[0]);$db->query('ALTER TABLE aliados_convocatorias_envios ADD COLUMN convocatoria_enlace_registro VARCHAR(1000)');
if(!$db->query("SHOW COLUMNS FROM convocatorias LIKE 'imagen'")->num_rows){
 $db->query("ALTER TABLE convocatorias ADD COLUMN imagen VARCHAR(255) DEFAULT '',ADD COLUMN enlace_registro VARCHAR(255) DEFAULT '',ADD COLUMN tipo_convocatoria VARCHAR(60) DEFAULT '',ADD COLUMN subtipo_convocatoria VARCHAR(60) DEFAULT ''");
}
$db->query('CREATE TABLE IF NOT EXISTS convocatoria_estados (convocatoria_id INT,estado_id INT)');
if(!$db->query("SHOW COLUMNS FROM convocatorias LIKE 'categoria'")->num_rows)$db->query("ALTER TABLE convocatorias ADD COLUMN categoria VARCHAR(60) DEFAULT ''");
$db->query('CREATE TABLE IF NOT EXISTS marketing_convenio_convocatorias (seguimiento_id INT,convocatoria_id INT,creado_por INT DEFAULT 4)');
$db->query("CREATE TABLE IF NOT EXISTS formulario_aliado_convocatorias (aliado_id BIGINT UNSIGNED,convocatoria_id INT,programa_codigo VARCHAR(64),formulario VARCHAR(20))");
$permisos=['aliados.ver','aliados.compartir_correo','aliados.preparar_whatsapp','aliados.ver_historial','convocatorias.ver'];
foreach($permisos as $p){$s=$db->prepare("INSERT IGNORE INTO permisos (modulo,codigo,nombre,estado) VALUES ('aliados',?,?,1)");$s->bind_param('ss',$p,$p);$s->execute();}
$db->query("INSERT IGNORE INTO rol_permisos SELECT 4,id FROM permisos WHERE codigo IN ('".implode("','",$permisos)."')");
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_assoc()['id'];
$db->query("UPDATE aliados_manuales SET correo_contacto='manual@example.test' WHERE id=$manual");
$db->query("UPDATE seguimientos_vinculacion SET correo_verificado='instituto@example.test' WHERE id=10");
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'permisos'=>array_merge($permisos,['aliados.gestionar_programas']),'csrf_token'=>str_repeat('a',64)];
$r=new AliadoRegistroService($db);
foreach(['manual'=>$manual,'institucional'=>10] as $tipo=>$id)$r->configurar($tipo,$id,['bachillerato-general','titulacion-experiencia-laboral']);
$db->query('UPDATE formulario_aliado_registro SET publicado=1,habilitado=1');
$db->query('DELETE FROM aliados_manuales_contactos');
$db->query("INSERT INTO aliados_manuales_contactos (aliado_id,nombre,telefono,etiqueta,whatsapp_confirmado,whatsapp_autorizado,preferido_difusion,creado_por) VALUES ($manual,'Uno','7771113333','Difusión A',1,1,1,2),($manual,'Dos','7771114444','Difusión B',1,1,0,2),($manual,'No autorizado','7771115555','Sin autorización',1,0,0,2)");
$db->query("INSERT INTO aliados_contactos (seguimiento_id,numero,numero_normalizado,etiqueta,origen,confirmado_whatsapp,autorizado_whatsapp,preferido_difusion,creado_por,actualizado_por) VALUES (10,'7772223333','7772223333','Difusión A','CUENTA_CLAVE',1,1,1,2,2),(10,'7772224444','7772224444','Difusión B','CUENTA_CLAVE',1,1,0,2,2),(10,'7772225555','7772225555','No autorizado','CUENTA_CLAVE',1,0,0,2,2)");
$publicaciones=[];
foreach(['manual'=>$manual,'institucional'=>10] as $tipo=>$id){
 foreach(['bachillerato-general','titulacion-experiencia-laboral'] as $n=>$programa){
  $titulo=ucfirst($tipo).' · Programa '.($n+1).' · Periodo de prueba';$imagen='public/uploads/convocatorias/fixture-compartir-'.($n+1).'.svg';
  $s=$db->prepare("INSERT INTO convocatorias (titulo,imagen,enlace_registro,fecha_inicio,fecha_termino,estado,tipo_convocatoria,subtipo_convocatoria) VALUES (?,?,'https://ajeno.example.test/no-usar',DATE_SUB(CURDATE(),INTERVAL 1 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY),1,'bachillerato','bachillerato-general')");$s->bind_param('ss',$titulo,$imagen);$s->execute();$cid=$db->insert_id;
  $s=$db->prepare('INSERT INTO marketing_aliado_convocatorias_origen (convocatoria_id,origen_tipo,origen_id,programa_codigo,creado_por) VALUES (?,?,?,?,4)');$s->bind_param('isis',$cid,$tipo,$id,$programa);$s->execute();$publicaciones[$tipo][]=$cid;
  $db->query("INSERT INTO convocatoria_estados VALUES ($cid,1)");
 }
}
// Casos negativos y legado: misma fecha/territorio no implica pertenencia.
$db->query("INSERT INTO convocatorias (titulo,fecha_inicio,fecha_termino,estado,tipo_convocatoria,subtipo_convocatoria) VALUES ('Sólo territorial',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 20 DAY),1,'bachillerato','bachillerato-general')");$territorial=$db->insert_id;
$db->query("INSERT INTO convocatoria_estados VALUES ($territorial,1)");
$db->query("INSERT INTO convocatorias (titulo,fecha_inicio,fecha_termino,estado,tipo_convocatoria,subtipo_convocatoria) VALUES ('Legado institucional',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 20 DAY),1,'bachillerato','bachillerato-general')");$legacy=$db->insert_id;$db->query("INSERT INTO marketing_convenio_convocatorias (seguimiento_id,convocatoria_id,creado_por) VALUES (10,$legacy,4)");
$db->query("INSERT INTO convocatorias (titulo,fecha_inicio,fecha_termino,estado) VALUES ('Vencida',DATE_SUB(CURDATE(),INTERVAL 5 DAY),DATE_SUB(CURDATE(),INTERVAL 1 DAY),1),('Borrador',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 10 DAY),0)");
$desde=$db->insert_id;foreach([$desde,$desde+1] as $cid)$db->query("INSERT INTO marketing_aliado_convocatorias_origen (convocatoria_id,origen_tipo,origen_id,programa_codigo,creado_por) VALUES ($cid,'manual',$manual,'bachillerato-general',4)");
$perfil=(int)$db->query('SELECT aliado_id FROM formulario_aliado_registro WHERE seguimiento_id=10')->fetch_assoc()['aliado_id'];
$db->query("INSERT INTO convocatorias (titulo,fecha_inicio,fecha_termino,estado) VALUES ('Internacional Registro ligado',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 20 DAY),1)");$internacional=$db->insert_id;
$db->query("INSERT INTO formulario_aliado_convocatorias VALUES ($perfil,$internacional,'bachillerato-general','registro')");
$db->query("INSERT INTO convocatorias (titulo,fecha_inicio,fecha_termino,estado) VALUES ('Internacional Inscripción excluida',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 20 DAY),1)");$inscripcion=$db->insert_id;
$db->query("INSERT INTO formulario_aliado_convocatorias VALUES ($perfil,$inscripcion,'bachillerato-general','inscripcion')");
echo 'OK: fixture Compartir manual/institucional, programas, canales y relaciones reales'.PHP_EOL;
