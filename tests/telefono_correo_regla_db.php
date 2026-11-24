<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../tmp/telefono-correo-stage/app/services/VerificacionInstitucionalService.php';
require __DIR__.'/../app/services/VerificacionTelefonoCorreoService.php';
function exigir($v,$m){if(!$v)throw new RuntimeException($m);}
$actual=['telefono_fuente'=>'5551234567','correo_fuente'=>'antes@example.org','contacto_nombre'=>'Diana'];
foreach([[],['verificacion_telefono_confirmado'=>'1'],['verificacion_correo_confirmado'=>'1'],['verificacion_contacto_confirmado'=>'1']] as $flags){
 $fallo=false;try{VerificacionInstitucionalService::detalles($flags+['resultado'=>'CONTACTO_CORRECTO'],$actual);}catch(DomainException $e){$fallo=true;}exigir($fallo,'Contacto correcto exige las dos casillas');
}
$flags=['resultado'=>'CONTACTO_CORRECTO','verificacion_telefono_confirmado'=>'1','verificacion_correo_confirmado'=>'1','nuevo_correo_contacto'=>'nuevo@example.org'];
$d=VerificacionInstitucionalService::detalles($flags,$actual);
exigir(array_column($d,'campo')===['telefono','correo']&&$d[1]['valor']==='nuevo@example.org','Confirma el correo nuevo del borrador');
exigir(VerificacionInstitucionalService::detalles(['resultado'=>'CONTACTO_REFERIDO','nuevo_correo_contacto'=>'nuevo@example.org'],$actual)===[],'Proporcionar un contacto no acredita confirmacion');
// Resolver la ruta real con datos completos, sin cargar dependencias privadas.
$flujo=file_get_contents(__DIR__.'/../tmp/telefono-correo-stage/app/services/SeguimientoFlujoService.php');
$flujo=preg_replace('/^require_once[^\n]*\n/m','',$flujo);eval(substr($flujo,5));
$r=new ReflectionClass('SeguimientoFlujoService');$obj=$r->newInstanceWithoutConstructor();$resolver=$r->getMethod('resolverFlujo');$resolver->setAccessible(true);
$ficha=['estado_seguimiento'=>'CONTACTANDO','datos_verificados'=>0,'telefono_verificado'=>'5551234567','correo_verificado'=>'nuevo@example.org','contacto_nombre'=>'Diana','contacto_cargo'=>'RH','total_llamadas'=>1,'total_llamadas_validacion'=>0];
exigir($resolver->invoke($obj,$ficha)['accion_principal']['codigo']==='LLAMAR_IP','Datos completos sin acreditacion no habilitan Verificar contacto');
$ficha['total_llamadas_validacion']=1;
exigir($resolver->invoke($obj,$ficha)['accion_principal']['codigo']==='VERIFICAR_CONTACTO','La llamada acreditada habilita el siguiente paso');
$db=new mysqli('127.0.0.1','root','impe-local-test-only',null,33472);
$db->query('CREATE DATABASE IF NOT EXISTS impe_telefono_correo_test CHARACTER SET utf8mb4');$db->select_db('impe_telefono_correo_test');
try{
 $db->query("CREATE TABLE interacciones_vinculacion (id INT PRIMARY KEY,usuario_id INT,seguimiento_id INT,canal VARCHAR(30),proveedor_externo VARCHAR(30),id_externo VARCHAR(100),duracion_segundos INT,fecha_inicio DATETIME,fecha_fin DATETIME,resultado VARCHAR(50) DEFAULT 'CONTACTADO',notas TEXT) ENGINE=InnoDB");
 $db->query('CREATE TABLE telefonia_verificacion_evidencias (interaccion_id INT,usuario_id INT,seguimiento_id INT,campo VARCHAR(20),estado VARCHAR(20),accion VARCHAR(20),valor_confirmado VARCHAR(180),confirmed_at DATETIME,pbx_call_id VARCHAR(100),UNIQUE KEY uk_dato(interaccion_id,campo)) ENGINE=InnoDB');
 $db->query("INSERT INTO interacciones_vinculacion (id,usuario_id,seguimiento_id,canal,proveedor_externo,id_externo,duracion_segundos,fecha_inicio,fecha_fin) VALUES (1,30,10,'LLAMADA_IP','ZADARMA','out_prueba_1',10,'2026-11-24 13:00:00','2026-11-24 13:00:10'),(2,30,10,'LLAMADA_IP','ZADARMA','out_prueba_2',10,'2026-11-24 13:00:00','2026-11-24 13:00:10')");
 exigir(!VerificacionTelefonoCorreoService::tieneEfectiva($db,10,30),'Sin casillas no avanza');
 $db->query("INSERT INTO telefonia_verificacion_evidencias VALUES (1,30,10,'telefono','CONFIRMADA','CONFIRMADO','5551234567',NOW(),'out_prueba_1'),(2,30,10,'correo','CONFIRMADA','CONFIRMADO','nuevo@example.org',NOW(),'out_prueba_2')");
 exigir(!VerificacionTelefonoCorreoService::tieneEfectiva($db,10,30),'No combina dos llamadas');
 $db->query("INSERT INTO telefonia_verificacion_evidencias VALUES (1,30,10,'correo','PENDIENTE','CONFIRMADO','nuevo@example.org',NOW(),'out_prueba_1')");
 exigir(!VerificacionTelefonoCorreoService::tieneEfectiva($db,10,30),'SDK/telefonia pendiente no acredita');
 $db->query("UPDATE telefonia_verificacion_evidencias SET estado='CONFIRMADA' WHERE interaccion_id=1 AND campo='correo'");
 exigir(VerificacionTelefonoCorreoService::tieneEfectiva($db,10,30),'Dos datos confirmados en misma llamada permiten avanzar');
 // Ejecutar el SQL real del contador candidato, no una copia de su condicion.
 $modelo=file_get_contents(__DIR__.'/../tmp/telefono-correo-stage/app/models/SeguimientoVinculacionModel.php');
 $s=strpos($modelo,'public function obtenerResumenVerificacionTelefonicaDia(');$s=strpos($modelo,'        $sql = "SELECT',$s);$e=strpos($modelo,'        $stmt = $this->connection->prepare($sql);',$s);
 $condicionVerificacion=VerificacionTelefonoCorreoService::condicionSql('interacciones_vinculacion');eval(substr($modelo,$s,$e-$s));
 $usuarioId=30;$fecha='2026-11-24';$stmt=$db->prepare($sql);$stmt->bind_param('is',$usuarioId,$fecha);$stmt->execute();$resumen=$stmt->get_result()->fetch_assoc();$stmt->close();
 exigir((int)$resumen['verificaciones_efectivas']===1,'El contador acredita una institucion por dia con la misma regla');
 exigir(!VerificacionTelefonoCorreoService::tieneEfectiva($db,10,31),'No acredita a otro analista');
 foreach(["UPDATE interacciones_vinculacion SET proveedor_externo='ZADARMA_WEBRTC' WHERE id=1","UPDATE interacciones_vinculacion SET proveedor_externo='ZADARMA',duracion_segundos=0 WHERE id=1","UPDATE interacciones_vinculacion SET duracion_segundos=10,fecha_fin=NULL WHERE id=1"] as $sql){$db->query($sql);exigir(!VerificacionTelefonoCorreoService::tieneEfectiva($db,10,30),'Requiere PBX, respuesta y cierre');}
 echo "OK: ambas casillas, mismo registro, mismo analista, correo nuevo, PBX y cierre; no acredita datos solo capturados.\n";
}finally{$db->query('DROP DATABASE impe_telefono_correo_test');$db->close();}
