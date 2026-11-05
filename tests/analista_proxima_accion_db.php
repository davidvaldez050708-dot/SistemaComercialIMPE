<?php
// Base temporal explícita; jamás utiliza las credenciales/configuración del sitio.
require __DIR__.'/../app/services/InteraccionRutaService.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if(!$port||$port===3306)throw new RuntimeException('Se requiere un puerto aislado explícito distinto de 3306.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_test_proxima_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function one($sql){global $db;return $db->query($sql)->fetch_assoc();}
try{
 $db->query('CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,analista_id INT,activo INT,estado_seguimiento VARCHAR(40),datos_verificados INT,telefono_fuente VARCHAR(50),correo_fuente VARCHAR(80),telefono_verificado VARCHAR(50),correo_verificado VARCHAR(80),contacto_nombre VARCHAR(80),contacto_cargo VARCHAR(80),ultima_interaccion_at DATETIME,proxima_accion_at DATETIME) ENGINE=InnoDB');
 $db->query('CREATE TABLE interacciones_vinculacion(id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT,usuario_id INT,canal VARCHAR(30),fecha_inicio DATETIME,resultado VARCHAR(40),notas TEXT) ENGINE=InnoDB');
 $db->query('CREATE TABLE oficios_vinculacion(id INT PRIMARY KEY,seguimiento_id INT,fecha_envio DATETIME,estado_oficio VARCHAR(30))');
 $db->query('CREATE TABLE reuniones_vinculacion(id INT PRIMARY KEY,fecha_propuesta DATETIME)');
 $db->query("INSERT INTO seguimientos_vinculacion VALUES(1,5,1,'ESPERANDO_RESPUESTA',1,'5551112222','correo@example.test','5551112222','correo@example.test','Persona','RH',NOW(),NULL)");
 $db->query("INSERT INTO oficios_vinculacion VALUES(1,1,NOW(),'ENVIADO')");
 $meeting=date('Y-m-d H:i:s',time()+172800);$db->query("INSERT INTO reuniones_vinculacion VALUES(1,'$meeting')");
 $r=new ReflectionClass(InteraccionRutaService::class);$service=$r->newInstanceWithoutConstructor();$r->getProperty('connection')->setValue($service,$db);
 $input=['canal'=>'LLAMADA','resultado'=>'SOLICITO_LLAMAR_DESPUES','proxima_accion'=>'Volver a llamar','origen_llamada'=>'ZADARMA','observacion'=>'Pidió llamar mañana','verificacion_telefono_confirmado'=>1];
 $result=$service->registrarInformativa(1,5,$input);check(!$result['ok']&&$result['codigo_http']===422,'Servidor exige fecha en etapa avanzada');
 check(one('SELECT COUNT(*) n FROM interacciones_vinculacion')['n']==0,'Validación no crea interacción parcial');
 $date=date('Y-m-d\TH:i',time()+86400);$result=$service->registrarInformativa(1,5,$input+['proxima_accion_at'=>$date]);
 check($result['ok'],'Guardar callback avanzado');$row=one('SELECT * FROM seguimientos_vinculacion');
 check($row['proxima_accion_at']===str_replace('T',' ',$date).':00','Fecha guardada');
 check($row['estado_seguimiento']==='ESPERANDO_RESPUESTA'&&$row['datos_verificados']==1,'Estado y verificación conservados');
 check(!$result['verificacion_telefonica']['contabilizable'],'Casillas antiguas no acreditan otra verificación');
 $notes=one('SELECT notas FROM interacciones_vinculacion')['notas'];check(str_contains($notes,'Próxima acción: Volver a llamar')&&str_contains($notes,'Fecha próxima acción:'),'Trazabilidad completa');
 $condition=InteraccionProximaAccion::condicionSql('s');check(one("SELECT ($condition) valid FROM seguimientos_vinculacion s")['valid']==1,'Consulta reconoce programación explícita');
 $result=$service->registrarInformativa(1,5,['canal'=>'LLAMADA','resultado'=>'CONTACTO_CORRECTO','observacion'=>'Otra consulta']);check($result['ok'],'Registrar sin nuevo acuerdo');
 check(one('SELECT proxima_accion_at FROM seguimientos_vinculacion')['proxima_accion_at']===$row['proxima_accion_at'],'No borra programación al omitirla');
 check(one('SELECT fecha_propuesta FROM reuniones_vinculacion')['fecha_propuesta']===$meeting,'Reunión original intacta');
 $result=$service->registrarInformativa(1,6,$input+['proxima_accion_at'=>$date]);check(!$result['ok']&&$result['codigo_http']===403,'Otro usuario sin acceso no programa');
 echo "PASS DB aislada: validación, escritura atómica, trazabilidad, agenda persistida, ruta y reunión intactas, sin crédito y control de acceso\n";
}finally{$db->query("DROP DATABASE `$name`");}
