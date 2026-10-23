<?php
// Exclusivamente bases nuevas locales de pruebas. Nunca se publica en Hostinger.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^127\.0\.0\.1:(33410|33411)$/D',getenv('IMPE_DB_HOST')?:'')||!preg_match('/^impe_test_prospectos_fase1_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
$schema=getenv('IMPE_DB_NAME');$port=(int)substr(getenv('IMPE_DB_HOST'),10);$db=new mysqli('127.0.0.1','root','','',$port);$db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");$db->select_db($schema);
$db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(100),estado INT) ENGINE=InnoDB');
$nombres=['Aguascalientes','Baja California','Baja California Sur','Campeche','Coahuila','Colima','Chiapas','Chihuahua','Ciudad de México','Durango','Guanajuato','Guerrero','Hidalgo','Jalisco','Estado de México','Michoacán','Morelos','Nayarit','Nuevo León','Oaxaca','Puebla','Querétaro','Quintana Roo','San Luis Potosí','Sinaloa','Sonora','Tabasco','Tamaulipas','Tlaxcala','Veracruz','Yucatán','Zacatecas'];
$q=$db->prepare('INSERT INTO estados VALUES(?,?,1)');foreach($nombres as $index=>$nombre){$id=$index+1;$q->bind_param('is',$id,$nombre);$q->execute();}$db->query("INSERT INTO estados VALUES(99,'Inactivo',0)");
$db->query('CREATE TABLE municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(100),estado INT) ENGINE=InnoDB');$db->query("INSERT INTO municipios VALUES(1,17,'Cuernavaca',1),(2,23,'Chetumal',1)");
$db->query("CREATE TABLE formulario_registros(id BIGINT PRIMARY KEY,nombre VARCHAR(180),apellido VARCHAR(180),fecha_nacimiento DATE,movil VARCHAR(20),movil_secundario VARCHAR(20),tel_recomendado VARCHAR(20),correo VARCHAR(180),perfil_interes VARCHAR(180),lugar_laboras VARCHAR(180),cargo_puesto VARCHAR(180),estado_id INT NULL,municipio_id INT NULL,creado_por INT NULL,origen VARCHAR(30),created_at DATETIME,updated_at DATETIME,KEY(estado_id)) ENGINE=InnoDB");
for($i=1;$i<=62;$i++){
 $estado=$i<=29?17:($i<=55?23:($i<=58?11:($i===59?14:($i===60?99:($i===61?0:null)))));$municipio=$estado===17?1:2;$nombre=$i===1?'<img src=x onerror=alert(1)>':'Prospecto '.$i;
 $q=$db->prepare("INSERT INTO formulario_registros VALUES(?,?,'Prueba','1990-01-01','7771234567','','',CONCAT('prueba',?,'@example.test'),'Bachillerato General','Trabajo','Cargo',?,?,NULL,'PUBLICO','2026-10-23 12:00:00','2026-10-23 12:00:00')");$q->bind_param('isiii',$i,$nombre,$i,$estado,$municipio);$q->execute();
}
$db->query('CREATE TABLE formulario_aliado_procedencias(id BIGINT AUTO_INCREMENT PRIMARY KEY,registro_id BIGINT,tipo VARCHAR(30),aliado_id INT,aliado_nombre VARCHAR(180),programa_codigo VARCHAR(100)) ENGINE=InnoDB');
for($i=1;$i<=20;$i++)$db->query("INSERT INTO formulario_aliado_procedencias(registro_id,tipo,aliado_id,aliado_nombre,programa_codigo) VALUES($i,'registro',7,'Aliado de otra entidad','bachillerato-general')");
$db->query("INSERT INTO formulario_aliado_procedencias(registro_id,tipo,aliado_id,aliado_nombre,programa_codigo) VALUES(1,'registro',7,'Aliado de otra entidad','bachillerato-general'),(25,'inscripcion',8,'Sólo Inscripción','ingles')");
foreach(['formulario_inscripciones','formulario_programas','convocatorias','convocatoria_borradores','registro_enlaces_amigables','formulario_aliados'] as $tabla){$db->query("CREATE TABLE `$tabla`(id INT PRIMARY KEY,marca VARCHAR(100)) ENGINE=InnoDB");$db->query("INSERT INTO `$tabla` VALUES(1,'Intocable')");}
echo "Fixture sintética creada: $schema · ".$db->server_info."\n";
