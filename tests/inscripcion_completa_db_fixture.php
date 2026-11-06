<?php
if(PHP_SAPI!=='cli')exit(1);
$name=getenv('IMPE_DB_NAME')?:'';
if(!preg_match('/^impe_test_inscripcion_[a-z0-9_]+$/D',$name))throw new RuntimeException('Base aislada requerida.');
require dirname(__DIR__).'/config/db_connection.php';
$db=new mysqli(getenv('IMPE_DB_HOST')?:'127.0.0.1:33339',getenv('IMPE_DB_USER')?:'root',getenv('IMPE_DB_PASSWORD')?:'');
$db->set_charset('utf8mb4');
if(($argv[1]??'')==='limpiar'){
 $db->select_db($name);$hay=$db->query("SHOW TABLES LIKE 'formulario_inscripciones_completas'")->num_rows; $r=$hay?$db->query('SELECT comprobante_archivo FROM formulario_inscripciones_completas'):null;
 while($r && ($f=$r->fetch_assoc()))if(preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png)$/D',$f['comprobante_archivo'])){ $p=dirname(ROOT_PATH).'/impe-inscripciones-private/'.$f['comprobante_archivo'];if(is_file($p))unlink($p); }
 $db->query("DROP DATABASE `$name`");echo "CLEAN OK\n";exit;
}
if(($argv[1]??'')==='fallo'){
 $db->select_db($name);$db->query("CREATE TRIGGER prueba_fallo BEFORE INSERT ON formulario_inscripciones_completas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo sintetico'");echo "FAILURE READY\n";exit;
}
if(($argv[1]??'')==='tabla_completa'){
 $db->select_db($name);echo $db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='formulario_inscripciones_completas'")->fetch_assoc()['n'];exit;
}
if(($argv[1]??'')==='conteo'){
 $db->select_db($name);echo json_encode($db->query('SELECT COUNT(*) total FROM formulario_inscripciones')->fetch_assoc());exit;
}
if(($argv[1]??'')==='bandeja'){
 $db->select_db($name);$db->set_charset('utf8mb4');
 require dirname(__DIR__).'/app/models/InscritosModel.php';
 $m=new InscritosModel($db);$r=$m->listar(InscritosModel::filtros([]));
 echo json_encode(['total'=>$r['total'],'programas'=>array_count_values(array_column($r['registros'],'programa_slug')),'origenes'=>array_count_values(array_column($r['registros'],'origen'))]);exit;
}
if(($argv[1]??'')==='campos_bachillerato'){
 $db->select_db($name);
 $r=$db->query("SELECT datos FROM formulario_inscripciones_completas WHERE programa_slug='bachillerato-286' LIMIT 1")->fetch_assoc();
 $datos=$r ? json_decode($r['datos'],true) : [];
 // Devolver únicamente nombres de campos, nunca datos de personas.
 echo json_encode(array_keys($datos),JSON_UNESCAPED_UNICODE);exit;
}
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);
$db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(90),estado INT) ENGINE=InnoDB');
$db->query("INSERT INTO estados VALUES(1,'Morelos',1),(2,'Chiapas',1)");
$db->query('CREATE TABLE municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(90),estado INT) ENGINE=InnoDB');
$db->query("INSERT INTO municipios VALUES(1,1,'Cuernavaca',1),(2,2,'Tuxtla',1)");
$db->query('CREATE TABLE formulario_inscripciones(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(80) NOT NULL,apellido VARCHAR(120) NOT NULL,fecha_nacimiento DATE NOT NULL,movil VARCHAR(10) NOT NULL,correo VARCHAR(190) NOT NULL,perfil_interes VARCHAR(100) NOT NULL,lugar_laboras VARCHAR(180) NOT NULL,cargo_puesto VARCHAR(160),estado_id INT NOT NULL,municipio_id INT NOT NULL,creado_por INT,origen VARCHAR(30),created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
// La preparación aditiva se prueba mediante el mismo CLI que se entrega para Hostinger.
echo "FIXTURE OK\n";
