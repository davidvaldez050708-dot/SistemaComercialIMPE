<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if(!$port)exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_test_pbx_transferida_'.getmypid();$db->query("CREATE DATABASE `$name`");$db->select_db($name);$db->query("SET time_zone='-06:00'");date_default_timezone_set('America/Mexico_City');
function pbxCheck($value,$message){if(!$value)throw new RuntimeException($message);echo "OK: $message\n";}
try{
 $db->query('CREATE TABLE interacciones_vinculacion(id INT,seguimiento_id INT,usuario_id INT,canal VARCHAR(30),proveedor_externo VARCHAR(30),id_externo VARCHAR(100),resultado VARCHAR(30),notas TEXT)');
 $db->query("INSERT INTO interacciones_vinculacion VALUES(31,21,8,'LLAMADA_IP','ZADARMA_WEBRTC','sdk-transfer-123456789','CONTACTO_EFECTIVO','')");
 $db->query('CREATE TABLE telefonia_analista_webrtc_sesiones(usuario_id INT,extension VARCHAR(12),session_id VARCHAR(100),numero_origen VARCHAR(40),fecha_inicio DATETIME,fecha_atendida DATETIME,fecha_fin DATETIME,estado VARCHAR(20),clasificacion VARCHAR(30),seguimiento_id INT,interaccion_id INT)');
 $start=gmdate('Y-m-d H:i:s',time()-100);$end=gmdate('Y-m-d H:i:s',time()-20);
 $db->query("INSERT INTO telefonia_analista_webrtc_sesiones VALUES(8,'101','sdk-transfer-123456789','+527775551234','$start','$start','$end','ended','VINCULADA',21,31)");
 $db->query('CREATE TABLE telefonia_extensiones(usuario_id INT,extension VARCHAR(12),proveedor VARCHAR(30),activo INT,permite_entrantes INT,created_at DATETIME,updated_at DATETIME)');
 $db->query("INSERT INTO telefonia_extensiones VALUES(8,'101','ZADARMA',1,1,DATE_SUB(NOW(),INTERVAL 10 DAY),DATE_SUB(NOW(),INTERVAL 10 DAY))");
 $db->query('CREATE TABLE telefonia_disponibilidad(id INT,referencia VARCHAR(100),destino_usuario INT,destino_extension VARCHAR(12),numero VARCHAR(40),ruta_json TEXT,estado VARCHAR(30),created_at DATETIME)');
 $route=json_encode(['tipo'=>'INSTITUCION','seguimiento_id'=>21,'referencia_llamada'=>'out-transfer-verified','responsable_id'=>8,'extension'=>'101']);
 $q=$db->prepare("INSERT INTO telefonia_disponibilidad VALUES(1,'out-transfer-verified',8,'101','+527775551234',?,'ENVIADA',NOW())");$q->bind_param('s',$route);$q->execute();
 $db->query('CREATE TABLE telefonia_zadarma_eventos(id INT AUTO_INCREMENT PRIMARY KEY,pbx_call_id VARCHAR(100),evento VARCHAR(40),internal VARCHAR(12),received_at DATETIME,call_id_with_rec VARCHAR(100),payload_json TEXT,is_recorded INT)');
 $events=[['NOTIFY_OUT_START','100',-120,'',[]],['NOTIFY_ANSWER','101',-100,'',['transfer_from'=>'100']],['NOTIFY_OUT_END','100',-20,'',['last_internal'=>'101']],['NOTIFY_RECORD','101',-19,'audio-101-verified',[]],['NOTIFY_RECORD','100',-19,'audio-100-private',[]]];
 foreach($events as [$type,$ext,$offset,$rec,$payload]){$at=date('Y-m-d H:i:s',time()+$offset);$json=json_encode($payload);$q=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,received_at,call_id_with_rec,payload_json,is_recorded) VALUES('out-transfer-verified',?,?,?,?,?,1)");$q->bind_param('sssss',$type,$ext,$at,$rec,$json);$q->execute();}
 $svc=new TelefoniaGrabacionExpedienteTransferidaService($db);
 pbxCheck($svc->buscar(8,21,31)==='out-transfer-verified','Saliente transferida sin NOTIFY_INTERNAL recupera referencia por contexto consumido');
 $before=$db->query('SELECT * FROM interacciones_vinculacion')->fetch_all(MYSQLI_ASSOC);
 $info=$svc->inspeccionar(8,21,31);pbxCheck($info['estado']==='REPORTADA'&&$info['segmentos']===1,'Analista recibe sólo el segmento de su extensión');
 pbxCheck($db->query('SELECT * FROM interacciones_vinculacion')->fetch_all(MYSQLI_ASSOC)===$before,'La consulta no modifica historia ni clasificación');
 $db->query("UPDATE telefonia_disponibilidad SET estado='ACEPTADA'");pbxCheck($svc->buscar(8,21,31)===null,'Aceptar disponibilidad no acredita transferencia');
 $db->query("DELETE FROM telefonia_disponibilidad");pbxCheck($svc->buscar(8,21,31)===null,'Número, hora y eventos sin asociación institucional no bastan');
 $db->query("UPDATE telefonia_zadarma_eventos SET internal='' WHERE evento='NOTIFY_RECORD'");
 $parts=TelefoniaPbxSegmentosService::deEventos($db->query('SELECT * FROM telefonia_zadarma_eventos')->fetch_all(MYSQLI_ASSOC),'101');
 pbxCheck($parts['segmentos']===[],'Un RECORD global no atribuye audio de otro tramo');
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
