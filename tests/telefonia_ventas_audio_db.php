<?php
declare(strict_types=1);
// Integración con eventos persistidos; únicamente una base desechable explícita.
require_once dirname(__DIR__).'/app/services/TelefoniaVentasSchema.php';
require_once dirname(__DIR__).'/app/services/TelefoniaVentasLlamadasService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaResultadoVentasService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaDisponibilidadService.php';
require_once dirname(__DIR__).'/app/services/ZadarmaWebhookEventStoreService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);date_default_timezone_set('America/Mexico_City');
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port)throw new RuntimeException('Define el puerto de MySQL de pruebas.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_audio_test_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');$db->query("SET time_zone='-06:00'");$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
try{
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,estado INT,rol_id INT,nombre VARCHAR(90),apellidos VARCHAR(90))');
 $db->query("INSERT INTO usuarios VALUES(8,1,3,'Asesor',''),(9,1,3,'Otro','')");
 $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(60),estado INT)');$db->query("INSERT INTO permisos VALUES(1,'telefonia.usar',1),(2,'telefonia.recibir',1),(3,'telefonia.salientes',1)");
 $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');$db->query('INSERT INTO rol_permisos VALUES(3,1),(3,2),(3,3)');
 (new TelefoniaExtensionService($db))->asegurarEstructura();
 $db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at) VALUES(8,'150',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY))");
 new TelefoniaDisponibilidadService($db);
 $db->query('CREATE TABLE telefonia_contactos_personales(id INT PRIMARY KEY,usuario_id INT,nombre VARCHAR(90),telefono VARCHAR(18))');
 require_once dirname(__DIR__).'/app/services/TelefoniaMarcacionesVentasService.php';new TelefoniaMarcacionesVentasService($db);
 foreach(TelefoniaVentasSchema::pendientes($db) as $sql)$db->query($sql);
 $events=new ZadarmaWebhookEventStoreService($db);$svc=new TelefoniaVentasLlamadasService($db);$result=new TelefoniaResultadoVentasService($db);
 $event=static function(string $ref,string $type,string $ext,array $extra=[])use($events):void{
  $at=date('Y-m-d H:i:s',time()-120);$events->registrar($extra+['event'=>$type,'pbx_call_id'=>$ref,'internal'=>$ext,'caller_id'=>'+527777885173','destination'=>'+527777885173','received_at'=>$at,'call_start'=>$at]);
 };
 // Dos PBX del mismo número: jamás cruzar la referencia de la primera llamada.
 foreach(['pbx-audio-primera','pbx-audio-segunda'] as $ref){$event($ref,'NOTIFY_OUT_START','150');$event($ref,'NOTIFY_ANSWER','150');}
 $event('pbx-audio-primera','NOTIFY_OUT_END','150',['duration'=>18,'is_recorded'=>1,'call_id_with_rec'=>'150.123456','disposition'=>'answered']);
 $event('pbx-audio-primera','NOTIFY_RECORD','',['call_id_with_rec'=>'150.123456']);
 $event('pbx-audio-primera','NOTIFY_RECORD','100',['call_id_with_rec'=>'record-recepcion']);
 $event('pbx-audio-segunda','NOTIFY_OUT_END','150',['duration'=>5,'disposition'=>'answered']);
 $event('pbx-audio-segunda','NOTIFY_RECORD','',['call_id_with_rec'=>'record-sin-tramo']);
 $result->guardar(8,'pbx-audio-primera','CONVERSACION_PERSONA');$result->guardar(8,'pbx-audio-segunda','CONVERSACION_PERSONA');
 $a=$svc->obtener(8,'pbx-audio-primera');$b=$svc->obtener(8,'pbx-audio-segunda');
 $check($a['segmentos']===['150.123456'],'END propio + RECORD sin extensión recuperan audio exacto');
 $check($a['segundos']===18&&$a['conectada'],'Conservar duración y conexión PBX');
 $check($b['segmentos']===[]&&$b['audio_motivo']==='sin_tramo','Mismo número, otro PBX no hereda audio');
 $list=array_column($svc->listar(8),null,'pbx_call_id');
 $check($list['pbx-audio-primera']['segmentos']===$a['segmentos'],'Listado y detalle coinciden, incluido is_recorded');
 $check($list['pbx-audio-segunda']['audio_motivo']==='sin_tramo','Listado conserva la evidencia pendiente');
 // Transferencia con dos segmentos propios y un tramo independiente de Recepción.
 $event('pbx-transferida-audio','NOTIFY_INTERNAL','150');$event('pbx-transferida-audio','NOTIFY_ANSWER','150');
 $event('pbx-transferida-audio','NOTIFY_END','100',['duration'=>90,'is_recorded'=>1,'call_id_with_rec'=>'record-100-only']);
 $event('pbx-transferida-audio','NOTIFY_END','150',['duration'=>9,'is_recorded'=>1,'call_id_with_rec'=>'record-150-a']);
 $event('pbx-transferida-audio','NOTIFY_END','150',['duration'=>12,'is_recorded'=>1,'call_id_with_rec'=>'record-150-b']);
 $result->guardar(8,'pbx-transferida-audio','INTERESADO');$t=$svc->obtener(8,'pbx-transferida-audio');
 $check($t['segmentos']===['record-150-a','record-150-b'],'Sólo los dos segmentos propios de la transferencia');
 $check($t['segundos']===12,'No tomar duración de Recepción');
 $check(TelefoniaVentasLlamadasService::presentacionAudio($t)['disponible'],'Reproducción autorizable con resultado humano y evidencia');
 try{$svc->obtener(9,'pbx-transferida-audio');throw new RuntimeException('Acceso cruzado permitido');}catch(DomainException $e){$check(true,'Propietario ajeno rechazado');}
 $before=(int)$db->query('SELECT COUNT(*) n FROM telefonia_ventas_llamadas')->fetch_assoc()['n'];
 $db->query('START TRANSACTION READ ONLY');$svc->autorizar(8);$svc->requerir();$svc->obtener(8,'pbx-transferida-audio');$db->rollback();
 $check((int)$db->query('SELECT COUNT(*) n FROM telefonia_ventas_llamadas')->fetch_assoc()['n']===$before,'Ruta del diagnóstico funciona en transacción sólo lectura');
 echo "Ventas audio DB: $checks comprobaciones aprobadas.\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
