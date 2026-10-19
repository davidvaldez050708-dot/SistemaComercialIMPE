<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TelefoniaTransferenciaContextoService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');
if(!$port)throw new RuntimeException('Se requiere MySQL aislado en IMPE_TEST_MYSQL_PORT.');
$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);
$name='impe_test_identidad_'.getmypid();$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
$checks=0;function verify($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
try{
 $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80))');
 $db->query("INSERT INTO usuarios VALUES (7,'Tania','Marketing')");
 $db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80))');$db->query("INSERT INTO estados VALUES (7,'Chiapas'),(17,'Morelos')");
 $db->query('CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,nombre_entidad VARCHAR(150),analista_id INT,estado_id INT,activo INT,estado_seguimiento VARCHAR(30))');
 $db->query("INSERT INTO seguimientos_vinculacion VALUES (21,'AFIPSA',8,7,1,'CONTACTANDO'),(22,'Institución ajena',9,17,1,'CONTACTANDO')");
 $db->query('CREATE TABLE telefonia_disponibilidad(id INT PRIMARY KEY,origen_usuario INT,destino_usuario INT,destino_extension VARCHAR(12),referencia VARCHAR(100),numero VARCHAR(40),ruta_json TEXT,estado VARCHAR(20))');
 // La fila vieja sigue vigente en la antigua tabla: reproduce el defecto de los 90 s.
 $db->query('CREATE TABLE telefonia_transferencia_contextos_v2(id INT PRIMARY KEY,destino_usuario_id INT,destino_extension VARCHAR(12),telefono10 CHAR(10),referencia_llamada VARCHAR(100),seguimiento_id INT,created_at DATETIME,expires_at DATETIME)');
 $db->query("INSERT INTO telefonia_transferencia_contextos_v2 VALUES (1,8,'101','7777885173','pbx-first-0001',21,NOW(),DATE_ADD(NOW(),INTERVAL 90 SECOND))");
 $insert=function(int $id,string $ref,int $sid,string $institution,int $state)use($db){
  $route=json_encode(['tipo'=>'INSTITUCION','referencia_llamada'=>$ref,'responsable_id'=>8,'extension'=>'101','seguimiento_id'=>$sid,'institucion_nombre'=>$institution,'estado_id'=>$state]);
  $q=$db->prepare("INSERT INTO telefonia_disponibilidad VALUES (?,7,8,'101',?,'+527777885173',?,'ENVIANDO')");$q->bind_param('iss',$id,$ref,$route);$q->execute();$q->close();
 };
 $insert(1,'pbx-first-0001',21,'AFIPSA',7);$svc=new TelefoniaTransferenciaContextoService($db);
 $first=$svc->obtenerParaDestinatario(8,'101','7777885173','pbx-first-0001');
 verify($first['institucion']==='AFIPSA'&&$first['seguimiento_id']===21,'Primera llamada identifica su expediente');
 $insert(2,'pbx-second-0002',0,'UPEMOR',17);
 $second=$svc->obtenerParaDestinatario(8,'101','+527777885173','pbx-second-0002');
 verify($second['institucion']==='UPEMOR'&&$second['seguimiento_id']===0&&$second['estado']==='Morelos','Segunda llamada manual no recupera AFIPSA ni su seguimiento');
 verify($second['referencia_llamada']==='pbx-second-0002','Devuelve referencia exacta de la transferencia actual');
 verify($svc->obtenerParaDestinatario(8,'101','7777885173')===null,'Consulta antigua por número no recupera contexto');
 verify($svc->obtenerParaDestinatario(8,'101','7777885173','pbx-unknown-0003')===null,'Referencia desconocida no busca la anterior del mismo número');
 verify($svc->obtenerParaDestinatario(9,'101','7777885173','pbx-second-0002')===null,'Respeta destinatario');
 verify($svc->obtenerParaDestinatario(8,'102','7777885173','pbx-second-0002')===null,'Respeta extensión');
 verify($svc->obtenerParaDestinatario(8,'101','5551112233','pbx-second-0002')===null,'Número sólo corrobora la referencia');
 $db->query("UPDATE telefonia_disponibilidad SET estado='ACEPTADA' WHERE id=2");
 verify($svc->obtenerParaDestinatario(8,'101','7777885173','pbx-second-0002')===null,'Aceptar disponibilidad no acredita envío de transferencia');
 $db->query("UPDATE telefonia_disponibilidad SET estado='ENVIADA' WHERE id=2");
 verify($svc->obtenerParaDestinatario(8,'101','7777885173','pbx-second-0002')['seguimiento_id']===0,'Transferencia enviada conserva institución manual sin expediente');
 $insert(3,'pbx-third-0003',22,'Institución ajena',17);
 verify($svc->obtenerParaDestinatario(8,'101','7777885173','pbx-third-0003')===null,'No devuelve expediente ajeno aunque exista un ID');
 verify((int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n']===2,'La consulta no crea instituciones ni expedientes');
 echo "Identidad de transferencia/MySQL: $checks comprobaciones OK\n";
}finally{$db->select_db('mysql');$db->query("DROP DATABASE `$name`");$db->close();}
