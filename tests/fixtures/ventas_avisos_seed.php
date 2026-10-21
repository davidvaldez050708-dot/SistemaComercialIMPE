<?php
declare(strict_types=1);
// Base desechable explícita: nunca usar Database::connect() ni credenciales del CRM.
require_once dirname(__DIR__,2).'/app/services/TelefoniaVentasSchema.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaVentasLlamadasService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaDisponibilidadService.php';
require_once dirname(__DIR__,2).'/app/services/ZadarmaWebhookEventStoreService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);date_default_timezone_set('America/Mexico_City');
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port)throw new RuntimeException('Define IMPE_TEST_MYSQL_PORT para la instancia aislada.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_bandejas_e2e_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
$db->query("SET time_zone='-06:00'");$checks=0;
function vcheck($ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException($why);++$checks;}
function vreject(callable $fn,string $why): void {try{$fn();}catch(DomainException|InvalidArgumentException $e){vcheck(true,$why);return;}throw new RuntimeException($why);}
try {
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,estado INT,rol_id INT,nombre VARCHAR(90),apellidos VARCHAR(90))');
 $db->query("INSERT INTO usuarios VALUES (7,1,7,'Tania',''),(8,1,3,'Asesor Uno',''),(9,1,3,'Asesor Dos',''),(10,1,4,'Analista','')");
 $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(60),estado INT)');
 $db->query("INSERT INTO permisos VALUES (1,'telefonia.usar',1),(2,'telefonia.recibir',1),(3,'telefonia.salientes',1)");
 $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');$db->query('INSERT INTO rol_permisos VALUES (3,1),(3,2),(3,3)');
 (new TelefoniaExtensionService($db))->asegurarEstructura();
 $db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at) VALUES (8,'150',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY)),(7,'100',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY))");
 new TelefoniaDisponibilidadService($db);
 $db->query('CREATE TABLE telefonia_contactos_personales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT,nombre VARCHAR(90),telefono VARCHAR(18),UNIQUE KEY own_phone(usuario_id,telefono))');
 $db->query("INSERT INTO telefonia_contactos_personales VALUES (1,8,'Ana','+525551110000'),(2,9,'Ajena','+525559998877')");
 require_once dirname(__DIR__,2).'/app/services/TelefoniaMarcacionesVentasService.php';new TelefoniaMarcacionesVentasService($db);
 $svc=new TelefoniaVentasLlamadasService($db);vcheck(!$svc->disponible(),'No crea tablas al consultar');
 $pending=TelefoniaVentasSchema::pendientes($db);vcheck(count($pending)>0&&!$svc->disponible(),'Check de migración es de lectura');
 foreach($pending as $sql)$db->query($sql);
 vcheck(TelefoniaVentasSchema::pendientes($db)===[],'Migración idempotente');
 // Simula una agenda preexistente con colación distinta a las tablas nuevas de Ventas.
 // El UNION de programación debe servir sin modificar tablas de producción.
 $db->query('ALTER TABLE telefonia_contactos_personales CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $contactCollation=$db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_contactos_personales' AND COLUMN_NAME='nombre'")->fetch_assoc()['COLLATION_NAME'];
 $resultCollation=$db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_ventas_resultados' AND COLUMN_NAME='prospecto_nombre'")->fetch_assoc()['COLLATION_NAME'];
 vcheck($contactCollation==='utf8mb4_unicode_ci'&&$resultCollation==='utf8mb4_general_ci','Fixture con dos collations distintas para detectar UNION incompatible');
 $events=new ZadarmaWebhookEventStoreService($db);$results=new TelefoniaResultadoVentasService($db);$base=time()-300;
 $event=function(string $ref,string $type,string $ext='150',int $seconds=0,array $extra=[])use($events,$base){static $offset=0;$at=date('Y-m-d H:i:s',$base);$events->registrar($extra+['event'=>$type,'pbx_call_id'=>$ref,'internal'=>$ext,'caller_id'=>'+525551112233','destination'=>'+525551112233','received_at'=>$at,'call_start'=>$at,'duration'=>$seconds]);};


 require_once dirname(__DIR__,2).'/app/services/TelefoniaNotificacionService.php';
 require_once dirname(__DIR__,2).'/app/services/TelefoniaProgramacionVentasService.php';
 $notices=new TelefoniaNotificacionService($db);$program=new TelefoniaProgramacionVentasService($db);$agenda=new TelefoniaContactosService($db);

 $event('pbx-pendiente-comercial','NOTIFY_OUT_START');
 $event('pbx-pendiente-comercial','NOTIFY_OUT_END','150',18,['disposition'=>'answered']);
 $event('pbx-sin-cierre','NOTIFY_OUT_START');
 $svc->listar(8);
 // Reproduce fallo auxiliar sin perder la llamada ya confirmada en el historial.
 $db->query('RENAME TABLE telefonia_ventas_marcaciones TO telefonia_ventas_marcaciones_offline');
 echo json_encode(['database'=>$name,'pbx'=>'pbx-pendiente-comercial']);
}finally{$db->close();}
