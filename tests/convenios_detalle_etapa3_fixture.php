<?php
// Sólo datos sintéticos. No es una migración ni se publica en Hostinger.
if(PHP_SAPI!=='cli'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
require_once __DIR__.'/../app/models/CrearConvocatoriaModel.php';
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
$db=(new Database())->connect(false);
$db->query("INSERT IGNORE INTO municipios (id,estado_id,nombre,estado) VALUES (9002,1,'Jiutepec',1)");
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'permisos'=>['aliados.crear_manual','aliados.editar_manual','aliados.gestionar_programas']];
$s=new AliadoRegistroService($db);
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_assoc()['id'];
$s->configurar('manual',$manual,['bachillerato-286','titulacion-experiencia-laboral']);
$db->query("UPDATE aliados_manuales SET notas=CONCAT('Primera línea completa del convenio.',CHAR(10),CHAR(10),'Segunda línea: <script>texto de prueba</script> y datos completos.'),relacion_fecha=NULL WHERE id=$manual");
$db->query("UPDATE formulario_programas SET nombre='Bachillerato 286 · Campus A. Programa autorizado' WHERE codigo='bachillerato-286'");
$sin=$s->guardarManual(['nombre_entidad'=>'Aliado sin programas','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>9002,'programas'=>['bachillerato-286']]);
$db->query("DELETE ap FROM formulario_aliado_programas ap JOIN formulario_aliado_registro r ON r.aliado_id=ap.aliado_id WHERE r.manual_id=$sin");
$s->guardarManual(['nombre_entidad'=>'Institución manual con nombre muy largo para comprobar que las columnas y los botones conservan su distribución institucional sin desbordamiento','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>9002,'programas'=>['ingles'],'notas'=>'Notas completas de una relación manual.']);
$db->query("UPDATE seguimientos_vinculacion_post_envio SET convenio_formalizado_at='2026-10-22 00:00:00',convenio_fecha='2026-10-21',convenio_notas='' WHERE seguimiento_id=10");
$db->query("INSERT INTO seguimientos_vinculacion (id,estado_id,municipio_id,nombre_entidad,tipo_entidad,analista_id,activo) VALUES (11,1,9002,'Institución con hora real','INSTITUCION',3,1)");
$db->query("INSERT INTO seguimientos_vinculacion_post_envio VALUES (11,'2026-10-20','Notas institucionales completas','DOC-11','2026-10-22 12:34:00',3)");
$db->query("INSERT INTO aliados_asignaciones VALUES (2,11,2,'FORMALIZACION',NOW(),1)");
$s->configurar('institucional',11,['ingles']);
(new CrearConvocatoriaModel())->listarConveniosAliados();
$db->query("INSERT INTO marketing_convenio_atencion (seguimiento_id,atendido_por,atendido_at) VALUES (10,4,NOW()) ON DUPLICATE KEY UPDATE atendido_por=4");
$filas=(new CrearConvocatoriaModel())->listarConveniosAliados();
$encontrado=array_values(array_filter($filas,fn($f)=>($f['origen_tipo']??'')==='manual'&&(int)$f['origen_id']===$manual))[0];
if(count($encontrado['registro_programas_items'])!==2||!in_array('Bachillerato 286 · Campus A. Programa autorizado',array_column($encontrado['registro_programas_items'],'nombre'),true))throw new RuntimeException('No conserva los programas estructurados.');
if($encontrado['registro_programas']!==implode(' · ',array_column($encontrado['registro_programas_items'],'nombre')))throw new RuntimeException('Cambió la representación histórica.');
echo "PASS: lista estructurada y representación histórica de programas, sin dividir nombres\n";
