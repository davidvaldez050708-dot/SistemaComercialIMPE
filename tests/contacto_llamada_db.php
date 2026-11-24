<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/VerificacionInstitucionalService.php';
require_once __DIR__.'/../app/services/DatosContactoLlamadaService.php';
// Doble del alcance: el SQL productivo sigue usando el alcance territorial vigente.
class SeguimientoTerritorialScope {static function analista(string $a): string{return $a.'.analista_id=?';}}
function comprobar($ok,$mensaje){if(!$ok)throw new RuntimeException($mensaje);}
$db=new mysqli('127.0.0.1','root','impe-local-test-only',null,33472);
$db->query('CREATE DATABASE IF NOT EXISTS impe_contacto_llamada_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->select_db('impe_contacto_llamada_test');
try {
 $db->query('DROP TABLE IF EXISTS interacciones_vinculacion');
 $db->query('DROP TABLE IF EXISTS seguimientos_vinculacion');
 $db->query('CREATE TABLE seguimientos_vinculacion (id INT PRIMARY KEY,analista_id INT,activo INT,nombre_entidad VARCHAR(180),telefono_fuente VARCHAR(40),telefono_verificado VARCHAR(40),correo_verificado VARCHAR(160),contacto_nombre VARCHAR(140),contacto_cargo VARCHAR(150),datos_verificados INT DEFAULT 0) ENGINE=InnoDB');
 $db->query('CREATE TABLE interacciones_vinculacion (id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT,notas TEXT) ENGINE=InnoDB');
 $db->query("INSERT INTO seguimientos_vinculacion VALUES (1,30,1,'Institucion anterior','5550000000','','','Contacto anterior','Area anterior',0),(2,31,1,'Ajeno','5551111111','','','Otro','',0),(3,30,0,'Inactivo','','','','','',0)");
 $input=['actualizar_datos_llamada'=>'1','nuevo_correo_contacto'=>'contacto@example.org','nuevo_contacto_nombre'=>'Contacto nuevo','nuevo_contacto_cargo'=>'Vinculacion','nombre_entidad_actualizado'=>'Institucion corregida','nuevo_telefono_contacto'=>''];
 foreach(['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'] as $r){
  $datos=DatosContactoLlamadaService::extraer($input,'LLAMADA',$r);
  $db->begin_transaction();
  $nota=DatosContactoLlamadaService::nota($datos);$s=$db->prepare('INSERT INTO interacciones_vinculacion (seguimiento_id,notas) VALUES (1,?)');$s->bind_param('s',$nota);$s->execute();$s->close();
  DatosContactoLlamadaService::guardar($db,1,30,$datos);$db->commit();
 }
 $fila=$db->query('SELECT * FROM seguimientos_vinculacion WHERE id=1')->fetch_assoc();
 comprobar($fila['correo_verificado']==='contacto@example.org'&&$fila['nombre_entidad']==='Institucion corregida','Contacto normal y referido actualizan ficha');
 comprobar($fila['telefono_fuente']==='5550000000'&&$fila['telefono_verificado']===''&&$fila['datos_verificados']==='0','Conserva fuente y no acredita verificacion automaticamente');
 foreach(['OCUPADO','SIN_RESPUESTA','BUZON_VOZ','FUERA_SERVICIO','OTRO'] as $r)comprobar(DatosContactoLlamadaService::extraer($input,'LLAMADA',$r)===[],'No modificar contacto sin conversacion');
 comprobar(DatosContactoLlamadaService::extraer($input,'CORREO','CONTACTO_CORRECTO')===[],'Otros canales conservan flujo');
 comprobar(DatosContactoLlamadaService::extraer([],'LLAMADA','CONTACTO_CORRECTO')===[],'Clientes antiguos conservan flujo');
 foreach([['nuevo_correo_contacto'=>'invalido'],['nuevo_telefono_contacto'=>'abc'],['nuevo_contacto_nombre'=>['x']],['nombre_entidad_actualizado'=>str_repeat('a',181)]] as $mal){
  $fallo=false;try {DatosContactoLlamadaService::extraer(array_merge($input,$mal),'LLAMADA','CONTACTO_CORRECTO');}catch(InvalidArgumentException $e){$fallo=true;}comprobar($fallo,'Validacion de entradas');
 }
 foreach([2,3] as $id){
  $db->begin_transaction();$fallo=false;try{DatosContactoLlamadaService::guardar($db,$id,30,['correo_verificado'=>'intruso@example.org']);}catch(DomainException $e){$fallo=true;}$db->rollback();comprobar($fallo,'No modifica ajeno o inactivo');
 }
 // Un fallo de actualización revierte también la interacción de la misma operación.
 $db->query("CREATE TRIGGER rechazar_contacto BEFORE UPDATE ON seguimientos_vinculacion FOR EACH ROW BEGIN IF NEW.correo_verificado='fallo@example.org' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo sintetico'; END IF; END");
 $antes=(int)$db->query('SELECT COUNT(*) FROM interacciones_vinculacion')->fetch_row()[0];
 $db->begin_transaction();$fallo=false;
 try{$db->query("INSERT INTO interacciones_vinculacion (seguimiento_id,notas) VALUES (1,'no debe quedar')");DatosContactoLlamadaService::guardar($db,1,30,['correo_verificado'=>'fallo@example.org']);$db->commit();}
 catch(Throwable $e){$db->rollback();$fallo=true;}
 comprobar($fallo&&(int)$db->query('SELECT COUNT(*) FROM interacciones_vinculacion')->fetch_row()[0]===$antes,'Rollback conjunto');
 comprobar($db->query('SELECT correo_verificado FROM seguimientos_vinculacion WHERE id=1')->fetch_row()[0]==='contacto@example.org','Sin actualizacion parcial');
 echo "OK: contacto normal/referido, correo vacio, propietario, validacion, sin verificaciones artificiales y rollback conjunto.\n";
} finally {$db->query('DROP DATABASE impe_contacto_llamada_test');$db->close();}
