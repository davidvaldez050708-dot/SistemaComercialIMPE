<?php
// Sólo sobre un esquema sintético vacío. Carga la variante exacta que se entrega a Hostinger.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_union_internacional_[0-9]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);
if($db->query('SHOW TABLES')->num_rows)throw new RuntimeException('Se exige un esquema de prueba vacío');
$manifest=json_decode(file_get_contents(__DIR__.'/../tools/bachillerato_unificacion_release.json'),true,512,JSON_THROW_ON_ERROR);
$root=sys_get_temp_dir().'/impe-union-internacional-'.bin2hex(random_bytes(5));mkdir($root.'/app/helpers',0700,true);mkdir($root.'/app/services',0700,true);
copy(__DIR__.'/../app/helpers/ProgramaEducativo.php',$root.'/app/helpers/ProgramaEducativo.php');
foreach($manifest['files'] as $row)if($row['path']==='app/services/FormularioAliadoService.php')file_put_contents($root.'/'.$row['path'],gzdecode(base64_decode($row['variants'][0]['target'])));
$location=getenv('IMPE_LOCATION_SERVICE');if(!$location||!is_file($location))throw new RuntimeException('Falta la dependencia internacional revisada');
copy($location,$root.'/app/services/FormularioUbicacionService.php');
require $root.'/app/services/FormularioAliadoService.php';
$sql=[
 'CREATE TABLE formulario_aliados (id BIGINT UNSIGNED PRIMARY KEY,nombre VARCHAR(100),ubicacion VARCHAR(100),token CHAR(64),activo TINYINT,seguimiento_id INT NULL,pais_codigo CHAR(2))',
 'CREATE TABLE formulario_programas (codigo VARCHAR(80) PRIMARY KEY,nombre VARCHAR(100),activo TINYINT)',
 'CREATE TABLE formulario_aliado_programas (aliado_id BIGINT UNSIGNED,programa_codigo VARCHAR(80),PRIMARY KEY(aliado_id,programa_codigo),FOREIGN KEY(programa_codigo) REFERENCES formulario_programas(codigo))',
 'CREATE TABLE formulario_aliado_procedencias (id INT AUTO_INCREMENT PRIMARY KEY,aliado_id BIGINT UNSIGNED,tipo VARCHAR(20),registro_id INT,programa_codigo VARCHAR(80),aliado_nombre VARCHAR(100),aliado_ubicacion VARCHAR(100),clave CHAR(64),convocatoria_id INT NULL,UNIQUE(aliado_id,tipo,clave),FOREIGN KEY(programa_codigo) REFERENCES formulario_programas(codigo))',
 'CREATE TABLE convocatorias (id INT PRIMARY KEY,estado TINYINT,fecha_inicio DATE,fecha_termino DATE)',
 'CREATE TABLE formulario_aliado_convocatorias (aliado_id BIGINT UNSIGNED,convocatoria_id INT,referencia CHAR(32),formulario VARCHAR(20),programa_codigo VARCHAR(80))',
];foreach($sql as $query)$db->query($query.' ENGINE=InnoDB');
$token=str_repeat('a',64);$ref=str_repeat('b',32);$ref2=str_repeat('c',32);
$db->query("INSERT INTO formulario_aliados VALUES (1,'Aliado internacional','Estados Unidos','$token',1,NULL,'US')");
$db->query("INSERT INTO formulario_programas VALUES ('bachillerato-286','Bachillerato 286',1),('bachillerato-general','Bachillerato General',1)");
$db->query("INSERT INTO formulario_aliado_programas VALUES (1,'bachillerato-286'),(1,'bachillerato-general')");
$db->query("INSERT INTO convocatorias VALUES (91,1,DATE_SUB(CURDATE(),INTERVAL 3 DAY),DATE_ADD(CURDATE(),INTERVAL 3 DAY)),(92,1,DATE_SUB(CURDATE(),INTERVAL 3 DAY),DATE_ADD(CURDATE(),INTERVAL 3 DAY))");
$db->query("INSERT INTO formulario_aliado_convocatorias VALUES (1,91,'$ref','registro','bachillerato-286'),(1,92,'$ref2','registro','bachillerato-general')");
$data=['correo'=>'union@example.test','movil'=>'7778889900','fecha_nacimiento'=>'1990-01-01','perfil_interes'=>'Bachillerato General','convocatoria_ref'=>$ref,'pais_nombre'=>'Estados Unidos','estado_provincia'=>'Texas','ciudad'=>'Houston','estado_id'=>null,'municipio_id'=>null];
$identity=[$data['correo'],$data['movil'],$data['fecha_nacimiento'],'bachillerato-286'];
$oldKey=hash('sha256',hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE)).':91');
$db->query("INSERT INTO formulario_aliado_procedencias (aliado_id,tipo,registro_id,programa_codigo,clave,convocatoria_id) VALUES (1,'registro',700,'bachillerato-286','$oldKey',91)");
$checks=0;$check=static function($v,$label)use(&$checks){if(!$v)throw new RuntimeException($label);$checks++;echo "OK $label\n";};
$service=new FormularioAliadoService($db);$calls=0;$insert=static function($saved)use(&$calls,$check){$check($saved['estado_provincia']==='Texas'&&$saved['estado_id']===null,'callback conserva ubicación libre internacional');return 700+(++$calls);};
$ally=$service->resolverConvocatoria($token,$ref,'registro');
$check($ally['internacional']&&array_column($ally['programas'],'codigo')===['bachillerato-general'],'enlace antiguo resuelve una sola oferta autorizada antes de migrar');
$check($service->guardar($token,$data,'registro',$insert)===700&&$calls===0,'Registro anterior por convocatoria no se duplica con nombre General');
$check($service->guardar($token,array_replace($data,['perfil_interes'=>'Bachillerato 286']),'registro',$insert)===700&&$calls===0,'nombre antiguo recupera el mismo registro');
$next=array_replace($data,['convocatoria_ref'=>$ref2]);
$check($service->guardar($token,$next,'registro',$insert)===701&&$calls===1,'otra convocatoria conserva procedencia independiente');
$check($service->guardar($token,$next,'registro',$insert)===701&&$calls===1,'reenvío de convocatoria nueva es idempotente');
$check((int)$db->query('SELECT convocatoria_id FROM formulario_aliado_procedencias WHERE registro_id=701')->fetch_row()[0]===92,'se conserva la asociación SQL a la convocatoria correcta');
$general=$data;unset($general['convocatoria_ref']);
$check($service->guardar($token,$general,'registro',$insert)===702&&$calls===2,'enlace sin convocatoria mantiene su ámbito independiente');
$check($service->guardar($token,$general,'registro',$insert)===702&&$calls===2,'reenvío del enlace general es idempotente');
$db->begin_transaction();
$db->query("DELETE FROM formulario_aliado_programas WHERE programa_codigo='bachillerato-286'");
$db->query("UPDATE formulario_aliado_procedencias SET programa_codigo='bachillerato-general' WHERE programa_codigo='bachillerato-286'");
$db->query("UPDATE formulario_aliado_convocatorias SET programa_codigo='bachillerato-general' WHERE programa_codigo='bachillerato-286'");
$db->query("DELETE FROM formulario_programas WHERE codigo='bachillerato-286'");$db->commit();
$check($service->guardar($token,$data,'registro',$insert)===700&&$calls===2,'enlace y clave anteriores continúan idempotentes tras retirar el duplicado');
try{$service->guardar($token,array_replace($data,['perfil_interes'=>'Inglés']),'registro',$insert);throw new RuntimeException('Programa ajeno aceptado');}catch(DomainException $e){$check(true,'programas ajenos siguen rechazados');}
$db->query('UPDATE convocatorias SET estado=0 WHERE id=91');
try{$service->guardar($token,$data,'registro',$insert);throw new RuntimeException('Convocatoria inactiva aceptada');}catch(DomainException $e){$check(true,'convocatoria inactiva no admite reenvíos');}
$check((int)$db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetch_row()[0]===1,'claves foráneas permanecen activas');
echo "PASS $checks comprobaciones de la variante internacional entregada\n";
