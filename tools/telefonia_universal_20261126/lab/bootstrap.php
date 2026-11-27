<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');if(!$lab)throw new RuntimeException('Falta la raíz privada.');
$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Sólo fixture loopback.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($c['host'],$c['root_user'],$c['root_password'],null,$c['port']);$db->set_charset('utf8mb4');
$db->query('CREATE DATABASE impe_telefonia_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');$db->select_db('impe_telefonia_lab');
// Esquema sintético explícito. Sin filas de volcados ni configuración productiva.
$sql=[
"CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado TINYINT DEFAULT 1)",
"CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100),usuario VARCHAR(100),correo VARCHAR(150),foto_perfil VARCHAR(250) DEFAULT '',rol_id INT,estado TINYINT DEFAULT 1)",
"CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100) DEFAULT '',codigo VARCHAR(150) UNIQUE,nombre VARCHAR(150) DEFAULT '',descripcion TEXT,estado TINYINT DEFAULT 1)",
"CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))",
"INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Analista de Datos',1),(5,'Marketing',1),(6,'Cuenta Clave',1)",
"INSERT INTO usuarios(id,nombre,apellidos,usuario,correo,rol_id,estado) VALUES(1,'Admin','Laboratorio','lab.admin','admin@example.invalid',1,1),(41,'Ventas','Laboratorio','lab.ventas','ventas@example.invalid',3,1),(42,'Analista','Laboratorio','lab.analista','analista@example.invalid',4,1),(43,'Recepción','Laboratorio','lab.recepcion','recepcion@example.invalid',5,1)",
"CREATE TABLE telefonia_analista_webrtc_sesiones(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,session_id VARCHAR(100) NOT NULL,numero_origen VARCHAR(40) NOT NULL DEFAULT '',caller_name VARCHAR(100) NOT NULL DEFAULT '',estado VARCHAR(24) NOT NULL DEFAULT 'ringing',fecha_inicio DATETIME NOT NULL,fecha_atendida DATETIME NULL,fecha_fin DATETIME NULL,duracion_observada_segundos INT NULL,clasificacion VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',seguimiento_id INT NULL,interaccion_id INT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_sesion(usuario_id,session_id))",
"CREATE TABLE prospectos_comerciales(id INT AUTO_INCREMENT PRIMARY KEY,telefono_normalizado VARCHAR(30),pais VARCHAR(80) DEFAULT 'México')",
"CREATE TABLE prospecto_asignaciones(id INT AUTO_INCREMENT PRIMARY KEY,prospecto_id INT,responsable_id INT,estado VARCHAR(30) DEFAULT 'ACTIVA')",
"CREATE TABLE prospecto_embudo_subetapas(asignacion_id INT,subetapa VARCHAR(30))",
"CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(100),estado TINYINT DEFAULT 1)",
"INSERT INTO estados VALUES(1,'Estado sintético',1)",
"CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),activo TINYINT DEFAULT 1,fecha_inicio DATE NULL,fecha_fin DATE NULL,cuenta_clave_asignacion_id INT NULL)",
"INSERT INTO asignaciones_territorio(id,usuario_id,estado_id,tipo_asignacion) VALUES(1,42,1,'ANALISTA_DATOS'),(2,41,1,'ASESOR')",
];
foreach($sql as $s)$db->query($s);
require $lab.'/site/config/db_connection.php';
require $lab.'/site/app/services/TelefoniaExtensionService.php';
require $lab.'/site/app/services/ZadarmaWebhookEventStoreService.php';
(new TelefoniaExtensionService($db))->asegurarEstructura();(new ZadarmaWebhookEventStoreService($db))->asegurarEstructura();
// Las semillas directas no son webhooks: permiten NULL en su hash. registrar() siempre escribe su hash real.
$db->query('ALTER TABLE telefonia_zadarma_eventos MODIFY event_hash CHAR(64) NULL');
$codes=['telefonia.usar','telefonia.salientes','telefonia.recibir','telefonia.transferir','seguimientos_vinculacion.ver','seguimientos_vinculacion.operar_propios','seguimientos_vinculacion.crear'];
foreach($codes as $code){$s=$db->prepare('INSERT INTO permisos(codigo) VALUES(?)');$s->bind_param('s',$code);$s->execute();$id=$s->insert_id;$s->close();foreach([1,3,4,5] as $role)$db->query("INSERT INTO rol_permisos VALUES($role,$id)");}
foreach([[41,'101'],[42,'102'],[43,'100']] as [$id,$ext])$db->query("INSERT INTO telefonia_extensiones(usuario_id,proveedor,extension,permite_salientes,permite_entrantes,activo,created_at,updated_at) VALUES($id,'ZADARMA','$ext',1,1,1,'2020-01-01 00:00:00','2020-01-01 00:00:00')");
$source=dirname(__DIR__,3);
foreach(['database/migrations/2026_10_17_telefonia_atencion_analista.sql','database/migrations/2026_10_18_transferencia_operaciones_notificaciones.sql','database/migrations/2026_10_18_disponibilidad_devoluciones.sql'] as $f){
 $text=file_get_contents($source.'/'.$f);preg_match_all('/CREATE TABLE IF NOT EXISTS telefonia_[a-z_]+\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s',$text,$matches);foreach($matches[0] as $s)$db->query($s);
}
foreach(['schema_sesiones.sql','schema_transferencia.sql','schema_sucesores.sql'] as $f){$text=preg_replace('/--[^\n]*/','',file_get_contents(dirname(__DIR__).'/'.$f));foreach(explode(';',$text) as $s)if(trim($s)!=='')$db->query($s);}
require $lab.'/site/app/services/TelefoniaVentasSchema.php';TelefoniaVentasSchema::prepararEnCli($db);
$user=$db->real_escape_string($c['user']);$pass=$db->real_escape_string($c['password']);
$db->query("CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$pass'");$db->query("GRANT SELECT,INSERT,UPDATE,DELETE,EXECUTE ON impe_telefonia_lab.* TO '$user'@'127.0.0.1'");
echo "LAB_SCHEMA_OK: datos ficticios; cuenta de aplicación DML sin privilegios DDL; proveedor real bloqueado.\n";
