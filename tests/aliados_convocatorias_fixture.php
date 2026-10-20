<?php
if (PHP_SAPI!=='cli') { exit(2); }
require __DIR__.'/formulario_aliados_fixture.php';
function ejecutarSqlAliados(mysqli $db,string $sql): void {
 $db->multi_query($sql);do { if($r=$db->store_result())$r->free(); } while($db->more_results()&&$db->next_result());
}
$root=dirname(__DIR__);$dump=file_get_contents($root.'/database/sistema_comercial_impe.sql');
preg_match('/CREATE TABLE `seguimientos_vinculacion` \(.*?ENGINE=InnoDB[^;]*;/s',$dump,$m);
$sql=str_replace('`id` int(11) NOT NULL,','`id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,',$m[0]);$db->query($sql);
$db->query('CREATE TABLE usuarios (id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100)) ENGINE=InnoDB');$db->query("INSERT INTO usuarios VALUES (1,'Administración',''),(2,'Operación',''),(3,'Marketing','')");
$db->query('CREATE TABLE roles (id INT PRIMARY KEY,nombre VARCHAR(100)) ENGINE=InnoDB');$db->query("INSERT INTO roles VALUES (1,'Administrador'),(7,'Cuenta Clave'),(8,'Marketing')");
$db->query('CREATE TABLE permisos (id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(80),codigo VARCHAR(100) UNIQUE,nombre VARCHAR(120),descripcion VARCHAR(255),estado INT DEFAULT 1) ENGINE=InnoDB');
$db->query('CREATE TABLE rol_permisos (rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
$sql=file_get_contents($root.'/database/migrations/2026_09_23_marketing_convocatorias.sql');$inicio=strpos($sql,'CREATE TABLE IF NOT EXISTS convocatorias');$fin=strpos($sql,'INSERT INTO permisos',$inicio);ejecutarSqlAliados($db,substr($sql,$inicio,$fin-$inicio));
$db->query('ALTER TABLE convocatorias ADD categoria VARCHAR(30) NULL, ADD tipo_convocatoria VARCHAR(30), ADD subtipo_convocatoria VARCHAR(50), ADD enlace_registro VARCHAR(1000), ADD activacion_automatica TINYINT DEFAULT 0');
echo "OK: instituciones y convocatorias con esquema existente\n";

$db->query('ALTER TABLE estados ADD clave_inegi VARCHAR(10), ADD nombre_corto VARCHAR(100)');
$db->query('CREATE TABLE asignaciones_territorio (id INT PRIMARY KEY,usuario_id INT,estado_id INT,cuenta_clave_asignacion_id INT,tipo_asignacion VARCHAR(30),activo INT,es_principal INT,fecha_inicio DATE,fecha_fin DATE) ENGINE=InnoDB');
