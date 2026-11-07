<?php
/**
 * Prueba de render sin base de datos ni autenticación externa.
 * Verifica que Campañas y su configuración sean vistas exclusivas,
 * conservando los POST, CSRF y acceso por permiso de configuración.
 */
define('BASE_URL','/crm/');
$vista=__DIR__.'/../app/views/prospectos/central.php';
function pruebaVista(string $modo,bool $permiso,bool $importar=false): string
{
    global $vista;
    $_GET=['seccion'=>'meta'];
    if ($modo==='config') $_GET['configurar_formularios']='1';
    $seccionProspectos='meta';
    $metaPuedeConfigurar=$permiso;
    $metaPuedeImportar=$importar;
    $metaResultado=null;
    $metaAviso='';
    $metaResumen=null;
    $metaCsrf='test-no-real';
    $mensajeError='';
    $centralDisponible=true;
    $indicadores=['personas'=>0,'aliados'=>0,'generales'=>0,'meta'=>0,'por_incorporar'=>0];
    $listado=['registros'=>[],'total'=>0,'pagina'=>1,'paginas'=>1,'limite'=>25,
              'programas'=>[],'campanias'=>[]];
    $formulariosDisponible=true;
    $formulariosConfiguracion=[];
    $formulariosHistorial=[];
    $formularioAviso='';
    $metaProgramas=[];
    ob_start();
    try {require $vista;return ob_get_clean();}
    catch (Throwable $e) {ob_end_clean();throw $e;}
}
$count=0;
$assert=static function(bool $cond,string $test)use(&$count):void {
    if (!$cond) throw new RuntimeException('FALLO: '.$test);
    $count++;
};
$normal=pruebaVista('normal',true,true);
$assert(substr_count($normal,'Configurar formularios</a>')===0,
    'el acceso conserva el icono dentro del enlace, no texto suelto');
$assert(substr_count($normal,'Configurar formularios')===1,
    'un único botón de configuración en vista habitual');
$assert(strpos($normal,'Prospectos de campañas')!==false,'título del listado');
$assert(strpos($normal,'data-central-filtros')!==false,'filtros preservados');
$assert(strpos($normal,'data-meta-abrir')!==false,'botón importar preservado');
$assert(strpos($normal,'id="campanias-formularios"')===false,
    'no existe tarjeta o acordeón adicional cuando está cerrado');
$assert(strpos($normal,'configurar_formularios=1#campanias-formularios')!==false,
    'único enlace funcional a la configuración');
$config=pruebaVista('config',true,true);
$assert(substr_count($config,'Formularios y programas educativos')===1,
    'encabezado único de configuración');
$assert(substr_count($config,'Volver a campañas')===1,'regreso visible');
$assert(substr_count($config,'id="campanias-formularios"')===1,
    'configuración inline y ancla única');
$assert(strpos($config,'data-central-filtros')===false,
    'sin filtros del listado al editar programas');
$assert(strpos($config,'data-meta-abrir')===false,
    'sin botón importar detrás del editor');
$assert(strpos($config,'name="archivo_formularios"')!==false,
    'detección de formularios preservada');
$assert(strpos($config,'action="/crm/index.php?controller=prospecto&amp;action=metaFormularioDetectar"')!==false,
    'endpoint POST de detección intacto');
$assert(strpos($config,'name="csrf_meta" value="test-no-real"')!==false,
    'CSRF original intacto');
$assert(strpos($config,'id="archivoFormularios"')!==false &&
    strpos($config,'for="archivoFormularios"')!==false,
    'selector nativo con etiqueta accesible');
$assert(strpos($config,'meta-config-empty')!==false,
    'estado vacío integrado en el editor');
$assert(strpos($config,'data-meta-overlay')!==false,
    'modal de importación se conserva, aunque no se abra automáticamente');
$sinPermiso=pruebaVista('config',false,false);
$assert(strpos($sinPermiso,'id="campanias-formularios"')===false &&
    strpos($sinPermiso,'Configurar formularios')===false,
    'GET directo no expone configuración sin permiso');
$assert(strpos($sinPermiso,'data-central-filtros')!==false,
    'sin permiso se conserva listado ordinario');
echo "OK: $count pruebas PHP de diseño, rutas, permisos y formularios de Meta.\n";
