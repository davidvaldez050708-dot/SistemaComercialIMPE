<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_WA_ASSIGN_TEST')!=='YES')exit(2);
require __DIR__.'/../tools/asignar_chat_prueba_whatsapp.php';
require __DIR__.'/../app/services/WhatsAppContextoService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$schema='impe_wa_assign_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$db->select_db($schema);$db->set_charset('utf8mb4');
$tmp=$argv[1];$site=$tmp.'/public_html';$backup=$tmp.'/backups';
if(!is_dir($site))mkdir($site,0700,true);if(!is_dir($backup))mkdir($backup,0700,true);
function verifyChat(bool $value,string $why):void {if(!$value)throw new RuntimeException($why);}
function deniedChat(callable $fn):void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Se permitió una asignación inválida.');}
try {
 foreach([
  'roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado INT)',
  'usuarios(id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100),rol_id INT,estado INT)',
  'permisos(id INT PRIMARY KEY,codigo VARCHAR(100),estado INT)',
  'rol_permisos(rol_id INT,permiso_id INT)',
  'whatsapp_cuentas(id INT PRIMARY KEY,phone_number_id VARCHAR(80),usuario_id INT,tipo VARCHAR(20),activo INT)',
  'whatsapp_conversaciones(id INT PRIMARY KEY,cuenta_id INT,telefono_normalizado VARCHAR(40),aliado_seguimiento_id INT NULL,responsable_usuario_id INT NULL)',
  'whatsapp_mensajes(id INT PRIMARY KEY,conversacion_id INT,direccion VARCHAR(20),tipo VARCHAR(20),contenido TEXT)',
  'whatsapp_conversacion_prospectos(conversacion_id INT PRIMARY KEY,prospecto_id INT)',
  'prospectos_comerciales(id INT PRIMARY KEY,telefono_normalizado VARCHAR(40))',
  'prospecto_asignaciones(id INT PRIMARY KEY,prospecto_id INT,asesor_id INT,vigente INT)',
  'seguimientos_vinculacion(id INT PRIMARY KEY,activo INT)'
 ] as $table)$db->query('CREATE TABLE '.$table.' ENGINE=InnoDB');
 $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1)");
 $db->query("INSERT INTO usuarios VALUES(1,'Administrador','',1,1),(22,'prueba','llamadas',3,1),(23,'Otro','Asesor',3,1)");
 $db->query("INSERT INTO permisos VALUES(1,'whatsapp.ver',1),(2,'whatsapp.enviar',1)");
 $db->query('INSERT INTO rol_permisos VALUES(3,1),(3,2)');
 $db->query("INSERT INTO whatsapp_cuentas VALUES(1,'1398857879973278',NULL,'PRUEBA',1),(2,'1508758925647776',22,'PRUEBA',1)");
 $db->query("INSERT INTO whatsapp_conversaciones VALUES(1,1,'527770000001',NULL,23),(2,2,'527770000001',NULL,NULL)");
 $db->query("INSERT INTO whatsapp_mensajes VALUES(1,1,'ENTRANTE','TEXT','anterior'),(2,2,'ENTRANTE','TEXT','Prueba CRM 2')");
 $db->query("INSERT INTO prospectos_comerciales VALUES(42,'527770000001'),(13,'527770000001'),(9,'527770000001')");
 $db->query('INSERT INTO prospecto_asignaciones VALUES(99,42,23,0)');
 $digest=static function()use($db):string{
  $data=[];foreach(['whatsapp_mensajes','prospectos_comerciales','prospecto_asignaciones','whatsapp_conversacion_prospectos','rol_permisos','whatsapp_cuentas'] as $table)$data[$table]=$db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC);
  return hash('sha256',json_encode($data));
 };
 $before=$digest();
 $check=waAsignarChatPrueba($db,false,$site);
 verifyChat($check['conversacion_id']===2 && $check['asesor_id']===22 && !$check['ya_asignada'],'CHECK identifica solamente el chat correcto');
 verifyChat(count(glob($backup.'/*.json'))===0 && $digest()===$before,'CHECK no escribe');
 $applied=waAsignarChatPrueba($db,true,$site,$backup);
 verifyChat(is_file($applied['respaldo']),'respaldo creado');
 $saved=json_decode(file_get_contents($applied['respaldo']),true);
 verifyChat($saved['responsable_anterior']===null && $saved['responsable_nuevo']===22,'respaldo del responsable original');
 verifyChat($digest()===$before,'mensajes, prospectos, asignaciones, enlaces, permisos y canales intactos');
 verifyChat((int)$db->query('SELECT responsable_usuario_id FROM whatsapp_conversaciones WHERE id=1')->fetch_row()[0]===23,'chat anterior intacto');
 $again=waAsignarChatPrueba($db,true,$site,$backup);
 verifyChat($again['ya_asignada'] && count(glob($backup.'/*.json'))===1,'APPLY idempotente');
 foreach([22=>true,23=>false] as $uid=>$allowed){
  $_SESSION=['usuario_id'=>$uid,'rol_id'=>3,'permisos'=>['whatsapp.ver','whatsapp.enviar']];
  $scope=WhatsAppContextoService::alcance($db,$uid,false);
  $count=(int)$db->query('SELECT COUNT(*) FROM whatsapp_conversaciones conv WHERE conv.id=2 AND ('.$scope.')')->fetch_row()[0];
  verifyChat(($count===1)===$allowed,'autorización real limita el chat al asesor elegido');
 }
 $db->query('UPDATE whatsapp_conversaciones SET responsable_usuario_id=23 WHERE id=2');
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$backup));
 $db->query('UPDATE whatsapp_conversaciones SET responsable_usuario_id=NULL WHERE id=2');
 $db->query('INSERT INTO whatsapp_conversacion_prospectos VALUES(2,13)');
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$backup));
 $db->query('DELETE FROM whatsapp_conversacion_prospectos');
 $db->query("INSERT INTO whatsapp_conversaciones VALUES(3,2,'527770000002',NULL,NULL)");
 $db->query("INSERT INTO whatsapp_mensajes VALUES(3,3,'ENTRANTE','TEXT','Prueba CRM 2')");
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$backup));
 $db->query('DELETE FROM whatsapp_mensajes WHERE id=3');$db->query('DELETE FROM whatsapp_conversaciones WHERE id=3');
 $db->query('UPDATE usuarios SET estado=0 WHERE id=22');
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$backup));
 $db->query('UPDATE usuarios SET estado=1 WHERE id=22');
 $db->query('DELETE FROM rol_permisos WHERE permiso_id=2');
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$backup));
 $db->query('INSERT INTO rol_permisos VALUES(3,2)');
 deniedChat(fn()=>waAsignarChatPrueba($db,true,$site,$site));
 verifyChat(count(glob($backup.'/*.json'))===1,'los bloqueos no generan cambios ni nuevos respaldos');
 echo "CHECK_OK: MariaDB real, respaldo, idempotencia, conservación de datos y autorización de dos asesores; bloqueos de cartera, ambigüedad, usuario inactivo y permisos.\n";
}finally{
 $db->query('DROP DATABASE '.$schema);$db->close();
}
