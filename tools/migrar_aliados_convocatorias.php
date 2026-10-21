<?php
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
$root=rtrim(getenv('IMPE_RUNTIME_ROOT') ?: dirname(__DIR__),'/\\');require_once $root.'/config/db_connection.php';
$modo=$argv[1]??'--check';if (!in_array($modo,['--check','--apply'],true)||count($argv)>2) { fwrite(STDERR,"Uso: --check|--apply\n");exit(2); }
try {
 $db=(new Database())->connect(false);
 foreach (['formulario_aliados','formulario_aliado_programas','formulario_aliado_procedencias','seguimientos_vinculacion','convocatorias','usuarios','estados','permisos','rol_permisos'] as $tabla) {
  $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$tabla);$s->execute();$fila=$s->get_result()->fetch_assoc();
  if (!$fila || strtoupper($fila['ENGINE'])!=='INNODB') { throw new RuntimeException('Falta tabla InnoDB requerida: '.$tabla); }
 }
 $columnas=['pais_codigo'=>'VARCHAR(4) NULL','pais_nombre'=>'VARCHAR(100) NULL','region'=>'VARCHAR(120) NULL','ciudad'=>'VARCHAR(120) NULL','contacto_nombre'=>'VARCHAR(180) NULL','contacto_telefono'=>'VARCHAR(80) NULL','creado_por'=>'INT NULL'];
 foreach ($columnas as $col=>$tipo) {
  $q=$db->query("SHOW COLUMNS FROM formulario_aliados LIKE '$col'");
  if (!$q->num_rows) { if ($modo==='--check') { throw new RuntimeException('Falta columna formulario_aliados.'.$col); } $db->query("ALTER TABLE formulario_aliados ADD COLUMN $col $tipo"); }
 }
 foreach (['estado_id','analista_id'] as $col) {
  $fila=$db->query("SHOW COLUMNS FROM seguimientos_vinculacion LIKE '$col'")->fetch_assoc();
  if (!$fila) { throw new RuntimeException('Falta institución canónica.'); }
  if ($fila['Null']!=='YES') {
   if ($modo==='--check') { throw new RuntimeException('Falta habilitar institución sin '.$col.' mexicano ficticio.'); }
   $tipo=$fila['Type'];if (!preg_match('/^int(?:\([0-9]+\))?(?: unsigned)?$/D',$tipo)) { throw new RuntimeException('Tipo de columna no revisado.'); }
   $db->query("ALTER TABLE seguimientos_vinculacion MODIFY $col $tipo NULL DEFAULT NULL");
  }
 }
 $q=$db->query("SHOW COLUMNS FROM formulario_aliado_procedencias LIKE 'convocatoria_id'");
 if (!$q->num_rows) { if ($modo==='--check') { throw new RuntimeException('Falta procedencia de convocatoria.'); } $db->query('ALTER TABLE formulario_aliado_procedencias ADD COLUMN convocatoria_id INT NULL'); }
 if ($modo==='--apply') {
  $db->multi_query(file_get_contents(__DIR__.'/../database/migrations/2026_10_20_aliados_convocatorias.sql'));
  do { if ($r=$db->store_result()) { $r->free(); } } while ($db->more_results()&&$db->next_result());
  // Usa las tablas compartidas de Convenios y la asociación ya existente.
  foreach (['2026_09_04_flujo_post_envio_vinculacion.sql','2026_10_16_estado_convenio_convocatoria.sql'] as $archivo) {
   $db->multi_query(file_get_contents(__DIR__.'/../database/migrations/'.$archivo));
   do { if ($r=$db->store_result()) { $r->free(); } } while ($db->more_results()&&$db->next_result());
  }
  $db->query('CREATE TABLE IF NOT EXISTS marketing_convenio_atencion (seguimiento_id INT PRIMARY KEY, atendido_por INT NOT NULL, atendido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
  // Completa sólo la localización conocida, sin reemplazar ediciones posteriores.
  $db->query("UPDATE formulario_aliados SET pais_codigo='US',pais_nombre='Estados Unidos',region='Texas',ciudad='Houston',contacto_nombre='Adalid Kuribeña',contacto_telefono='7775635090' WHERE slug='handly-lenguaje-center' AND pais_codigo IS NULL AND ubicacion='Houston, Texas, EE. UU.'");
  $db->query("INSERT INTO permisos (modulo,codigo,nombre,descripcion) SELECT 'Aliados','aliados.internacionales.configurar','Configurar aliados internacionales','Alta y localización de instituciones internacionales, sin permisos operativos de convenio.' WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE codigo='aliados.internacionales.configurar')");
  $db->query("INSERT INTO rol_permisos (rol_id,permiso_id) SELECT 1,p.id FROM permisos p WHERE p.codigo='aliados.internacionales.configurar' AND NOT EXISTS (SELECT 1 FROM rol_permisos rp WHERE rp.rol_id=1 AND rp.permiso_id=p.id)");
 }
 $db->query('SELECT aliado_id,convocatoria_id,referencia FROM formulario_aliado_convocatorias LIMIT 0');
 if (!(int)$db->query("SELECT COUNT(*) n FROM permisos p INNER JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE p.codigo='aliados.internacionales.configurar' AND rp.rol_id=1")->fetch_assoc()['n']) { throw new RuntimeException('Falta permiso administrativo de configuración internacional.'); }
 echo "OK: estructura internacional preparada. Sin nuevos programas ni reasignación de identificadores.\n";
} catch (Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1); }
