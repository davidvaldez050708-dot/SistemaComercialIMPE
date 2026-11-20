<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_WHATSAPP_VENTAS_TEST')!=='YES')exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
putenv('IMPE_BASE_URL=https://wa.test/');
if($helper=getenv('IMPE_WA_PERMISSION_FIXTURE'))require_once $helper;
require_once __DIR__.'/../app/models/WhatsAppModel.php';
function waCheck(bool $v,string $why): void {if(!$v)throw new RuntimeException($why);}
function waDeny(callable $fn): void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Se aceptó un acceso indebido.');}
function waSession(int $uid,int $rol=3,array $extra=[]): void {$_SESSION=['usuario_id'=>$uid,'rol_id'=>$rol,'permisos'=>['whatsapp.ver','whatsapp.enviar','ventas.embudo.ver',...$extra]];}
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$db->set_charset('utf8mb4');$schema='impe_wa_ventas_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$db->select_db($schema);
try{
 foreach([
 'usuarios(id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),rol_id INT,estado INT)',
 'roles(id INT PRIMARY KEY,nombre VARCHAR(90),estado INT)',
 'permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(90),codigo VARCHAR(120) UNIQUE,nombre VARCHAR(180),descripcion TEXT,estado INT)',
 'rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))',
 'estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)',
 'municipios(id INT PRIMARY KEY,nombre VARCHAR(80))',
 'seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,municipio_id INT NULL,analista_id INT,nombre_entidad VARCHAR(180),contacto_nombre VARCHAR(100),whatsapp_verificado VARCHAR(80),activo INT)',
 'asignaciones_territorio(id INT PRIMARY KEY,estado_id INT,usuario_id INT,tipo_asignacion VARCHAR(40),activo INT,fecha_inicio DATE NULL,fecha_fin DATE NULL)',
 'aliados_asignaciones(seguimiento_id INT,cuenta_clave_usuario_id INT,activo INT)',
 'aliados_contactos(id INT PRIMARY KEY,seguimiento_id INT,numero VARCHAR(40),activo INT,confirmado_whatsapp INT,autorizado_whatsapp INT,preferido_difusion INT)',
 'prospectos_comerciales(id BIGINT UNSIGNED PRIMARY KEY,nombre_completo VARCHAR(180),telefono_normalizado VARCHAR(40))',
 'prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED,asesor_id INT,vigente INT NULL)',
 'prospecto_embudo_etapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40))',
 'prospecto_embudo_subetapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,subetapa VARCHAR(40))'
 ] as $sql)$db->query("CREATE TABLE $sql ENGINE=InnoDB");
 $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Analista',1),(6,'Cuenta Clave',1)");
 $db->query("INSERT INTO usuarios VALUES(1,'Admin','',1,1),(22,'Asesor','22',3,1),(23,'Asesor','23',3,1),(44,'Analista','44',4,1),(45,'Analista','45',4,1),(66,'Cuenta','Clave',6,1)");
 $db->query("INSERT INTO roles VALUES(2,'Director de Operaciones',1)");
 $db->query("INSERT INTO usuarios VALUES(2,'Director','',2,1),(6,'Cuenta','Global',6,1)");
 $db->query("INSERT INTO estados VALUES(1,'Morelos',1),(2,'Otro',1)");
 $db->query("INSERT INTO seguimientos_vinculacion VALUES(10,1,NULL,45,'Institución prueba','Contacto','+527770000010',1),(11,2,NULL,44,'Otra institución','Otro','+527770000011',1)");
 $db->query("INSERT INTO asignaciones_territorio VALUES(1,1,44,'ANALISTA_DATOS',1,NULL,NULL),(2,2,45,'ANALISTA_DATOS',1,NULL,NULL)");
 $db->query("INSERT INTO aliados_asignaciones VALUES(10,66,1)");
 $db->query("INSERT INTO aliados_contactos VALUES(1,10,'+527770000010',1,1,1,1)");
 $db->query("INSERT INTO prospectos_comerciales VALUES(1,'Prospecto uno','+527770000001'),(2,'Prospecto dos','+527770000002'),(3,'Compartido A','+527770000003'),(4,'Compartido B','+527770000003')");
 $db->query("INSERT INTO prospecto_asignaciones VALUES(1,1,22,1),(2,2,23,1),(3,3,22,1),(4,4,23,1)");
 $db->query("INSERT INTO prospecto_embudo_etapas VALUES(1,'NUEVO'),(2,'SEGUIMIENTO'),(3,'NUEVO'),(4,'NUEVO')");
 foreach(['2026_10_02_c_whatsapp_conversaciones.sql','2026_11_18_whatsapp_contexto_comercial.sql','2026_11_20_whatsapp_ventas_config.sql'] as $file){
  $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/'.$file));
  foreach(explode(';',$sql) as $piece)if(trim($piece)!=='')$db->query($piece);
 }
 require __DIR__.'/whatsapp_ventas_cases.php';
}finally{$db->query("DROP DATABASE $schema");$db->close();}
