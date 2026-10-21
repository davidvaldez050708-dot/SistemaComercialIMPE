<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D', getenv('IMPE_DB_NAME') ?: '')) { exit(2); }
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
require_once __DIR__.'/../app/models/FormularioRegistroModel.php';
require_once __DIR__.'/../app/models/AliadoInternacionalModel.php';
$db=(new Database())->connect(false);
if (($argv[1] ?? '') === '--prepare') {
$db->query("ALTER TABLE formulario_aliados ADD pais_codigo VARCHAR(2) NULL");
$db->query("UPDATE formulario_aliados SET pais_codigo='US' WHERE slug='handly-lenguaje-center'");
$db->query("CREATE TABLE convocatorias (id INT PRIMARY KEY, estado INT, fecha_inicio DATE, fecha_termino DATE)");
$db->query("INSERT INTO convocatorias VALUES (91,1,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 1 DAY))");
$db->query("CREATE TABLE formulario_aliado_convocatorias (convocatoria_id INT, aliado_id BIGINT UNSIGNED, referencia VARCHAR(32), formulario VARCHAR(20), programa_codigo VARCHAR(80))");
$db->query("ALTER TABLE formulario_aliado_procedencias ADD convocatoria_id INT NULL");
echo "OK: esquema sintético internacional preparado\n";
return;
}
$checks=0;
function compatibleCheck($ok,$msg) { global $checks; if(!$ok) throw new RuntimeException($msg); $checks++; echo "OK: $msg\n"; }
function compatibleReject(callable $fn,$msg) { try {$fn();} catch(DomainException $e) {compatibleCheck(true,$msg);return;} throw new RuntimeException('ACEPTÓ: '.$msg); }
$s=new AliadoRegistroService($db);$form=new FormularioRegistroModel();
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>['aliados.crear_manual','aliados.gestionar_programas']];
$id=$s->guardarManual(['nombre_entidad'=>'Aliado Compatible','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>1,'contacto_nombre'=>'Prueba','programas'=>['bachillerato-286']]);
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['formularios_aliados.gestionar_enlaces']];
$link=$s->enlace('manual',$id,'generar');parse_str(parse_url($link['url'],PHP_URL_QUERY),$params);$token=$params['aliado'];
$a=$form->aliadoExclusivo($token,'registro');
compatibleCheck(!$a['internacional'] && $a['registro_gestionado'],'manual nacional no se confunde con internacional sin seguimiento');
compatibleReject(fn()=>$form->aliadoExclusivo($token,'inscripcion'),'perfil Registro no habilita Inscripción');
compatibleCheck(count($a['programas'])===1 && $a['programas'][0]['codigo']==='bachillerato-286','oferta exclusiva conservada');
$datos=['nombre'=>'Ana','apellido'=>'Compatible','fecha_nacimiento'=>'1990-01-01','movil'=>'7775550001','correo'=>'compatible@example.test','perfil_interes'=>'Bachillerato 286','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>1,'municipio_id'=>1,'creado_por'=>null,'origen'=>'PUBLICO','aliado_token'=>$token];
$registro=$form->guardar($datos);
compatibleCheck($form->guardar($datos)===$registro,'reenvío nacional idempotente con modelo internacional');
compatibleReject(fn()=>$form->guardar(array_replace($datos,['perfil_interes'=>'Inglés'])),'programa ajeno rechazado');
compatibleReject(fn()=>$form->guardar(array_replace($datos,['municipio_id'=>2])),'municipio ajeno rechazado');
compatibleCheck($db->query('SELECT pais_nombre FROM formulario_registros WHERE id='.$registro)->fetch_assoc()['pais_nombre']===null,'nacional mantiene ubicación por catálogo');
$internacionales=(new AliadoInternacionalModel($db))->obtenerAliados();
compatibleCheck(count($internacionales)===1 && $internacionales[0]['slug']==='handly-lenguaje-center','directorio internacional excluye nuevo perfil nacional');
$handly=$db->query("SELECT id,token FROM formulario_aliados WHERE slug='handly-lenguaje-center'")->fetch_assoc();
$ref=str_repeat('a',32);$ally=(int)$handly['id'];
$db->query("INSERT INTO formulario_aliado_convocatorias VALUES (91,$ally,'$ref','registro','bachillerato-general')");
$program=$db->query("SELECT programa_codigo FROM formulario_aliado_programas WHERE aliado_id=$ally AND programa_codigo LIKE 'bachillerato%' LIMIT 1")->fetch_assoc()['programa_codigo'];
$db->query("UPDATE formulario_aliado_convocatorias SET programa_codigo='".$db->real_escape_string($program)."'");
$legacy=$form->aliadoExclusivo($handly['token'],'registro');
compatibleCheck($legacy['internacional'] && count($legacy['programas'])===2,'Handly mantiene programas y modalidad internacional');
compatibleCheck(count($form->convocatoriaExclusiva($handly['token'],$ref,'registro')['programas'])===1,'convocatoria mantiene su oferta');
compatibleReject(fn()=>$form->convocatoriaExclusiva($handly['token'],$ref,'inscripcion'),'convocatoria de Registro no abre Inscripción');
compatibleReject(fn()=>$form->convocatoriaExclusiva($handly['token'],str_repeat('b',32),'registro'),'referencia de convocatoria inválida');
$internacional=array_replace($datos,['aliado_token'=>$handly['token'],'convocatoria_ref'=>$ref,'correo'=>'convocatoria@example.test','estado_id'=>null,'municipio_id'=>null,'pais_nombre'=>'Estados Unidos','estado_provincia'=>'Texas','ciudad'=>'Houston','perfil_interes'=>'Bachillerato General']);
$r=$form->guardar($internacional);
compatibleCheck($r===$form->guardar($internacional),'reenvío de convocatoria idempotente');
$saved=$db->query("SELECT r.*,p.convocatoria_id FROM formulario_registros r JOIN formulario_aliado_procedencias p ON p.tipo='registro' AND p.registro_id=r.id WHERE r.id=$r")->fetch_assoc();
compatibleCheck($saved['estado_provincia']==='Texas' && $saved['ciudad']==='Houston' && $saved['estado_id']===null && (int)$saved['convocatoria_id']===91,'conserva ubicación libre y procedencia de convocatoria');
compatibleReject(fn()=>$form->guardar(array_replace($internacional,['estado_id'=>1])),'internacional rechaza catálogo mexicano manipulado');
$db->query('UPDATE convocatorias SET estado=0 WHERE id=91');
compatibleReject(fn()=>$form->guardar($internacional),'convocatoria desactivada no acepta ni reenvíos');
$db->query('UPDATE convocatorias SET estado=1 WHERE id=91');
echo "PASS $checks comprobaciones de compatibilidad PR114\n";
