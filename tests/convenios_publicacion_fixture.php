<?php
if (PHP_SAPI!=='cli' || !preg_match('/^impe_publicacion_test_\d+$/D',getenv('IMPE_TEST_DB')?:'')) exit(2);
require getenv('IMPE_CONVENIOS_FIXTURE_ROOT').'/config/db_connection.php';
$db=(new Database())->connect();
if (($argv[1]??'')==='--drop') {$db->query('DROP DATABASE `'.getenv('IMPE_TEST_DB').'`');exit;}
function pubcheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
if (($argv[1]??'')==='--verify') {
 $rows=$db->query('SELECT c.*,o.origen_id,o.origen_tipo,o.programa_codigo,e.estado_id FROM convocatorias c JOIN marketing_aliado_convocatorias_origen o ON o.convocatoria_id=c.id JOIN convocatoria_estados e ON e.convocatoria_id=c.id ORDER BY c.id')->fetch_all(MYSQLI_ASSOC);
 pubcheck(count($rows)===4,'Dos programas por cada origen');
 foreach($rows as $row){pubcheck((int)$row['creado_por']===10 && (int)$row['estado_id']===2,'Autor Director y estado real');pubcheck(is_file(ROOT_PATH.'/'.$row['imagen']),'Imagen persistida');pubcheck(str_contains($row['enlace_registro'],'aliado='),'Enlace existente conservado');}
 pubcheck((int)$db->query("SELECT COUNT(*) n FROM convocatoria_borradores WHERE estado='publicado'")->fetch_assoc()['n']===2,'Borradores confirmados');
 pubcheck((int)$db->query('SELECT COUNT(*) n FROM formulario_aliado_registro WHERE publicado=1 AND habilitado=1')->fetch_assoc()['n']===2,'Perfiles y enlaces sin cambios');
 echo "Publicación HTTP real: cuatro convocatorias, dos orígenes, imágenes, autor, estados y enlaces persistidos OK\n";exit;
}
$schema=[
 "CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)",
 "INSERT INTO estados VALUES(1,'Morelos',1),(2,'Baja California',1),(3,'Inactivo',0)",
 "CREATE TABLE municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(80),estado INT)",
 "INSERT INTO municipios VALUES(1,1,'Cuernavaca',1),(2,2,'Mexicali',1)",
 "CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),activo INT,fecha_inicio DATE NULL,fecha_fin DATE NULL)",
 "INSERT INTO asignaciones_territorio VALUES(1,6,1,'CUENTA_CLAVE',1,NULL,NULL),(2,4,1,'ANALISTA_DATOS',1,NULL,NULL),(3,3,1,'ASESOR',1,NULL,NULL),(4,16,2,'CUENTA_CLAVE',1,NULL,'2000-01-01')",
 "CREATE TABLE aliados_manuales(id INT PRIMARY KEY,estado_id INT,municipio_id INT,nombre_entidad VARCHAR(180),activo INT)",
 "INSERT INTO aliados_manuales VALUES(20,2,2,'Aliado manual',1),(10,1,1,'Aliado asignado',1)",
 "CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,municipio_id INT,nombre_entidad VARCHAR(180),correo_verificado VARCHAR(190),correo_fuente VARCHAR(190),activo INT)",
 "INSERT INTO seguimientos_vinculacion VALUES(30,2,2,'Aliado institucional','','',1)",
 "CREATE TABLE aliados_asignaciones(id INT PRIMARY KEY,seguimiento_id INT,activo INT)",
 "INSERT INTO aliados_asignaciones VALUES(1,30,1)",
 "CREATE TABLE formulario_aliados(id INT PRIMARY KEY,token CHAR(64))",
 "INSERT INTO formulario_aliados VALUES(20,REPEAT('a',64)),(30,REPEAT('b',64))",
 "CREATE TABLE formulario_aliado_registro(aliado_id INT PRIMARY KEY,manual_id INT NULL,seguimiento_id INT NULL,publicado INT,habilitado INT,actualizado_por INT)",
 "INSERT INTO formulario_aliado_registro VALUES(20,20,NULL,1,1,7),(30,NULL,30,1,1,7)",
 "CREATE TABLE formulario_programas(codigo VARCHAR(64) PRIMARY KEY,nombre VARCHAR(120),activo INT)",
 "INSERT INTO formulario_programas VALUES('bachillerato-general','Bachillerato General',1),('ingles','Inglés',1)",
 "CREATE TABLE formulario_aliado_programas(aliado_id INT,programa_codigo VARCHAR(64),PRIMARY KEY(aliado_id,programa_codigo))",
 "INSERT INTO formulario_aliado_programas VALUES(20,'bachillerato-general'),(20,'ingles'),(30,'bachillerato-general'),(30,'ingles')",
 "CREATE TABLE formulario_aliado_qr_diseno(aliado_id INT PRIMARY KEY,diseno_json TEXT,logo_binario BLOB,logo_mime VARCHAR(40),version INT,updated_at DATETIME)",
 "CREATE TABLE convocatorias(id INT AUTO_INCREMENT PRIMARY KEY,titulo VARCHAR(180),categoria VARCHAR(30) NULL,tipo_convocatoria VARCHAR(40),subtipo_convocatoria VARCHAR(50),imagen VARCHAR(255),enlace_registro VARCHAR(255),fecha_inicio DATE,fecha_termino DATE,estado INT,creado_por INT,actualizado_por INT)",
 "CREATE TABLE convocatoria_estados(convocatoria_id INT,estado_id INT,PRIMARY KEY(convocatoria_id,estado_id))",
 "CREATE TABLE marketing_aliado_convocatorias_origen(convocatoria_id INT PRIMARY KEY,origen_tipo VARCHAR(20),origen_id INT,programa_codigo VARCHAR(64),creado_por INT)",
 "CREATE TABLE marketing_aliado_manual_convocatorias(manual_id INT,convocatoria_id INT,programa_codigo VARCHAR(64),creado_por INT)"
];
foreach($schema as $sql)$db->query($sql);
$migration=file_get_contents(ROOT_PATH.'/database/migrations/2026_10_23_convocatoria_borradores_v4.sql');
$db->multi_query($migration);do {if($result=$db->store_result())$result->free();}while($db->more_results()&&$db->next_result());
require ROOT_PATH.'/app/services/AliadoRegistroService.php';
$svc=new AliadoRegistroService($db);$perms=['convenios.ver','convocatorias.ver','convocatorias.crear'];
foreach([2,5,7,19] as $rol){
 $_SESSION=['usuario_id'=>10,'rol_id'=>$rol,'rol'=>'Rol autorizado','permisos'=>$perms];
 foreach(['manual'=>20,'institucional'=>30] as $tipo=>$id){$context=$svc->opcionesConvocatoria($tipo,$id);pubcheck(count($context['opciones_convocatoria'])===2 && $context['url']!=='','Editor nacional con enlace existente');}
}
foreach([3=>'ASESOR',4=>'ANALISTA_DATOS',6=>'CUENTA_CLAVE',16=>'CUENTA_CLAVE'] as $rol=>$kind){
 $_SESSION=['usuario_id'=>$rol,'rol_id'=>$rol===16?6:$rol,'rol'=>'Marketing','permisos'=>$perms];
 try{$svc->opcionesConvocatoria('manual',20);throw new LogicException('Acceso ajeno permitido');}catch(DomainException $e){pubcheck($e->getCode()===403,'Rechazar asignación ajena o vencida');}
 if($rol!==16)pubcheck((int)$svc->origen('manual',10,true)['estado_id']===1,'Respetar cada tipo de asignación');
}
$_SESSION=['usuario_id'=>10,'rol_id'=>2,'rol'=>'Director de Operaciones','permisos'=>$perms];
try{$svc->enlace('manual',20,'deshabilitar');throw new LogicException('Administró enlace sin permiso');}catch(DomainException $e){pubcheck($e->getCode()===403,'Permiso de enlace separado');}
$_SESSION['permisos'][]='formularios_aliados.gestionar_enlaces';pubcheck($svc->enlace('manual',20,'generar')['habilitado'],'Gestionar enlace con permiso, sin rol oculto');
$_SESSION['permisos']=['convenios.ver','convocatorias.ver'];
try{$svc->opcionesConvocatoria('manual',20);throw new LogicException('Publicación revocada permitida');}catch(DomainException $e){pubcheck($e->getCode()===403,'Revocar crear bloquea editor');}
echo "Servicios: roles nacionales, tres asignaciones, vencimiento, enlaces y revocación OK\n";
