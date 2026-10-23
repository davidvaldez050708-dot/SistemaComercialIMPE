<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../app/services/AliadoRegistroService.php';require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);
$db->query("ALTER TABLE convocatorias ADD categoria VARCHAR(30) NULL,ADD tipo_convocatoria VARCHAR(40) NOT NULL DEFAULT '',ADD subtipo_convocatoria VARCHAR(50) NOT NULL DEFAULT '',ADD imagen VARCHAR(255) NOT NULL DEFAULT '',ADD enlace_registro VARCHAR(255) NOT NULL DEFAULT '',ADD creado_por INT NOT NULL DEFAULT 4,ADD actualizado_por INT NOT NULL DEFAULT 4");
$db->query('CREATE TABLE convocatoria_estados (convocatoria_id INT NOT NULL,estado_id INT NOT NULL,PRIMARY KEY(convocatoria_id,estado_id)) ENGINE=InnoDB');
$_SESSION=['usuario_id'=>2,'rol_id'=>6,'rol'=>'Cuenta Clave','permisos'=>['aliados.crear_manual','aliados.gestionar_programas']];
$service=new AliadoRegistroService($db);$service->configurar('institucional',10,['bachillerato-general','titulacion-experiencia-laboral']);
$service->guardarManual(['nombre_entidad'=>'V4 Manual','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>1,'programas'=>['bachillerato-general','titulacion-experiencia-laboral']]);
$service->guardarManual(['nombre_entidad'=>'V4 Otro','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>1,'programas'=>['ingles']]);
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces']];
$service->enlace('institucional',10,'habilitar');
foreach($db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad LIKE 'V4 %'")->fetch_all(MYSQLI_ASSOC) as $row)$service->enlace('manual',(int)$row['id'],'habilitar');
echo "OK: fixture exclusiva V4, General canónico y enlaces existentes.\n";
