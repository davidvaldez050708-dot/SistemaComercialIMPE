<?php
// Exclusivamente datos sintéticos; este archivo no se entrega a Hostinger.
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
require_once __DIR__.'/../app/controllers/ConvocatoriaController.php';
require_once __DIR__.'/../app/services/ReporteConvocatoriaDataService.php';
$db=(new Database())->connect(false);
$db->query("INSERT IGNORE INTO formulario_programas (codigo,nombre,activo) VALUES ('bachillerato-general','Bachillerato General',1)");
$_SESSION=['usuario_id'=>2,'rol_id'=>6,'rol'=>'Cuenta Clave','permisos'=>['aliados.crear_manual','aliados.editar_manual','aliados.gestionar_programas','convenios.ver','convocatorias.ver','convocatorias.crear']];
$service=new AliadoRegistroService($db);
foreach ([['IQJ General V3',['bachillerato-general']],['General y 286 V3',['bachillerato-general','bachillerato-286']],['General sin clase V3',['bachillerato-general','v3-sin-clase']]] as [$name,$programs]) {
    $query=$db->prepare('SELECT id FROM aliados_manuales WHERE nombre_entidad=?');$query->bind_param('s',$name);$query->execute();
    $id=(int)($query->get_result()->fetch_assoc()['id']??0);
    $id=$service->guardarManual(['id'=>$id,'nombre_entidad'=>$name,'tipo_entidad'=>'INSTITUCION','estado_id'=>23,'municipio_id'=>9101,'programas'=>$programs]);
    $ids[$name]=$id;
}
$service->configurar('institucional',9101,['bachillerato-general']);
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['formularios_aliados.gestionar_enlaces','convenios.ver','convocatorias.ver','convocatorias.crear']];
foreach ($ids as $id) $service->enlace('manual',$id,'habilitar');
$checks=0;
$check=static function($value,$label)use(&$checks){if(!$value)throw new RuntimeException($label);echo "OK $label\n";$checks++;};
foreach ([['manual',$ids['IQJ General V3']],['institucional',9101]] as [$kind,$id]) {
    $context=$service->opcionesConvocatoria($kind,$id);
    $check($context['programas']===['bachillerato-general'],"autorización General conservada $kind");
    $check($context['opciones_convocatoria']===[['codigo'=>'bachillerato-general','nombre'=>'Bachillerato General','clasificacion'=>'bachillerato|bachillerato-general']],"clasificación propia General $kind");
}
$mixed=$service->opcionesConvocatoria('manual',$ids['General y 286 V3']);
$check(count($mixed['opciones_convocatoria'])===1 && $mixed['opciones_convocatoria'][0]['codigo']==='bachillerato-general','el alias y General se fusionan en una sola opción');
$unknown=$service->opcionesConvocatoria('manual',$ids['General sin clase V3']);
$check(count($unknown['opciones_convocatoria'])===2 && in_array('',array_column($unknown['opciones_convocatoria'],'clasificacion'),true),'programa sin mapeo no desaparece silenciosamente del lote');
$valid=new ReflectionMethod(ConvocatoriaController::class,'esClasificacionValida');
$controller=new ConvocatoriaController();
foreach (AliadoRegistroService::clasificacionesPorPrograma() as $code=>$classes) foreach ($classes as $class) {
    [$type,$subtype]=explode('|',$class,2);
    $check($valid->invoke($controller,$type,$subtype,false),"mapa de programa aceptado por backend: $code / $class");
}
$check(!$valid->invoke($controller,'titulacion','bachillerato-general',false),'familia incorrecta rechazada');
$check(!$valid->invoke($controller,'bachillerato','v3-sin-clase',false),'clasificación desconocida rechazada');
$report=new ReflectionMethod(ReporteConvocatoriaDataService::class,'opcionesFiltro');
$filters=$report->invoke((new ReflectionClass(ReporteConvocatoriaDataService::class))->newInstanceWithoutConstructor());
$check(($filters['subtipos']['bachillerato']['bachillerato-general']??'')==='Bachillerato General','filtro de reportes reconoce la clasificación guardada');
echo "PASS $checks comprobaciones General, catálogo y validación; sólo BD sintética\n";
