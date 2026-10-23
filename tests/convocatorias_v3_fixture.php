<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))throw new RuntimeException('Sólo fixture aislada');
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
$db=(new Database())->connect(false);
// Completar sólo la réplica sintética de convocatorias. En CI estas columnas
// ya las aporta la fixture transaccional anterior; no hay migración de entrega.
foreach(['creado_por','actualizado_por'] as $column){
    if(!$db->query("SHOW COLUMNS FROM convocatorias LIKE '$column'")->num_rows)$db->query("ALTER TABLE convocatorias ADD COLUMN $column INT NOT NULL DEFAULT 4");
}
if(($argv[1]??'')==='--schema-only')return;
$_SESSION=['usuario_id'=>2,'rol_id'=>6,'rol'=>'Cuenta Clave','permisos'=>['aliados.crear_manual','aliados.gestionar_programas','formularios_aliados.gestionar_enlaces','convenios.ver','convocatorias.ver','convocatorias.crear']];
$db->query("INSERT INTO estados (id,nombre,estado) VALUES (23,'Quintana Roo',1),(22,'Querétaro',1)");
$db->query("INSERT INTO municipios (id,estado_id,nombre,estado) VALUES (9101,23,'Othón P. Blanco',1),(9102,22,'Querétaro',1)");
$db->query("INSERT INTO asignaciones_territorio (id,usuario_id,estado_id,tipo_asignacion,activo) VALUES (9101,2,23,'CUENTA_CLAVE',1),(9102,2,22,'CUENTA_CLAVE',1)");
$db->query("INSERT INTO seguimientos_vinculacion (id,estado_id,municipio_id,nombre_entidad,tipo_entidad,analista_id,activo) VALUES (9101,23,9101,'INSTITUTO QUINTANARROENSE DE LA JUVENTUD - IQJ','INSTITUCION',3,1),(9102,22,9102,'Secretaría de Seguridad Pública','SECRETARIA',3,1)");
$db->query("INSERT INTO aliados_asignaciones (id,seguimiento_id,cuenta_clave_usuario_id,origen_asignacion,asignado_at,activo) VALUES (9101,9101,2,'FORMALIZACION',NOW(),1),(9102,9102,2,'FORMALIZACION',NOW(),1)");
$service=new AliadoRegistroService($db);
$service->configurar('institucional',9101,['bachillerato-286']);
$service->configurar('institucional',9102,['bachillerato-286','titulacion-experiencia-laboral']);
foreach([['Uno V3',['bachillerato-286']],['Tres V3',['bachillerato-286','ingles','titulacion-experiencia-laboral']]] as [$nombre,$programas])$service->guardarManual(['estado_id'=>23,'municipio_id'=>9101,'nombre_entidad'=>$nombre,'tipo_entidad'=>'INSTITUCION','programas'=>$programas]);
$db->query("INSERT INTO formulario_programas (codigo,nombre,activo) VALUES ('v3-sin-clase','Programa sin clasificación de prueba',1)");
$service->guardarManual(['estado_id'=>23,'municipio_id'=>9101,'nombre_entidad'=>'Sin clase V3','tipo_entidad'=>'INSTITUCION','programas'=>['v3-sin-clase']]);
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces']];
foreach([['institucional',9101],['institucional',9102]] as [$tipo,$id])$service->enlace($tipo,$id,'habilitar');
foreach(['Uno V3','Tres V3','Sin clase V3'] as $nombre){$s=$db->prepare('SELECT id FROM aliados_manuales WHERE nombre_entidad=?');$s->bind_param('s',$nombre);$s->execute();$id=(int)$s->get_result()->fetch_assoc()['id'];$service->enlace('manual',$id,'habilitar');}
echo "OK: datos sintéticos V3, instituciones IQJ/SSP y manuales de uno/tres/sin clasificación.\n";
