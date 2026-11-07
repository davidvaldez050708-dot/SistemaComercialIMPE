<?php
/** Renderizados de Campañas: listar, editar y consultar historial son excluyentes. */
define('BASE_URL','/crm/');
$view=__DIR__.'/../app/views/prospectos/central.php';
function renderHistoryTest(string $mode,int $pages=3,int $page=2):string
{
    global $view;
    $_GET=['seccion'=>'meta'];
    if ($mode==='history') {
        $_GET['historial_importaciones']='1';
        $_GET['pagina']=(string)$page;
    }
    if ($mode==='config') $_GET['configurar_formularios']='1';
    $seccionProspectos='meta';
    $metaPuedeConfigurar=true;
    $metaPuedeImportar=true;
    $metaEnHistoriaGuardada=$mode==='history';
    $metaHistorialVisible=$metaEnHistoriaGuardada;
    $metaHistorialDisponible=true;
    $metaHistorialListado=[
        'registros'=>[[
           'lote_ref'=>str_repeat('a',32),'terminado_at'=>'2026-11-07 02:00:00',
           'nombre_archivo'=>'bachillerato_01.csv','responsable'=>'Tania Ejemplo',
           'usuario_id'=>10,'formularios_total'=>1,'campanias_total'=>2,
           'formularios_detalle'=>[[
                'id'=>'f:222','nombre'=>'Bachillerato','total'=>15,
                'programas'=>['Bachillerato General']
           ]],
           'filas_leidas'=>19,'nuevos'=>10,'repetidos_crm'=>5,
           'repetidos_archivo'=>2,'rechazados'=>2
        ]],
        'total'=>36,'pagina'=>$page,'paginas'=>$pages,'limite'=>15,
        'indicadores'=>['filas'=>684,'nuevos'=>360,'repetidos'=>252,'rechazados'=>72]
    ];
    $metaResultado=null;$metaAviso='';$metaResumen=null;$metaCsrf='dummy';
    $mensajeError='';$centralDisponible=true;
    $indicadores=['personas'=>0,'aliados'=>0,'generales'=>0,'meta'=>0,'por_incorporar'=>0];
    $listado=['registros'=>[],'total'=>0,'pagina'=>1,'paginas'=>1,'limite'=>25,
              'programas'=>[],'campanias'=>[]];
    $formulariosDisponible=true;$formulariosConfiguracion=[];$formulariosHistorial=[];
    $formularioAviso='';$metaProgramas=[];
    ob_start();
    try {require $view;return ob_get_clean();}
    catch (Throwable $e) {ob_end_clean();throw $e;}
}
$checks=0;
$ok=static function($yes,string $message)use(&$checks) {
    if (!$yes)throw new RuntimeException('FALLO: '.$message);
    $checks++;
};
$normal=renderHistoryTest('normal');
$ok(substr_count($normal,'> Historial</a>')===1,'un solo acceso discreto al historial');
$ok(strpos($normal,'Configurar formularios')!==false,'configuración sigue visible');
$ok(strpos($normal,'data-meta-abrir')!==false,'importación Meta preservada');
$ok(strpos($normal,'id="campanias-historial"')===false,'historial no ocupa espacio cerrado');
$ok(strpos($normal,'data-central-filtros')!==false,'filtros de prospectos intactos');
$history=renderHistoryTest('history');
$ok(strpos($history,'Historial de importaciones')!==false,'título de historial');
$ok(strpos($history,'Volver a campañas')!==false,'regreso desde historial');
$ok(strpos($history,'id="campanias-historial"')!==false,'panel alterno visible');
$ok(strpos($history,'data-central-filtros')===false,'listado ordinario oculto');
$ok(strpos($history,'data-meta-abrir')===false,'botón de importar no estorba al historial');
$ok(strpos($history,'id="campanias-formularios"')===false,'configuración no duplicada');
$ok(strpos($history,'bachillerato_01.csv')!==false,'archivo conservado');
$ok(strpos($history,'Bachillerato General')!==false,'programa reconocido');
$ok(strpos($history,'Tania Ejemplo')!==false,'responsable identificado');
$ok(strpos($history,'>10</strong>')!==false,'contador de nuevos mostrado');
$ok(strpos($history,'>5 en CRM')!==false||strpos($history,'5 en CRM')!==false,
    'repetidos distinguen CRM y repetidos del archivo');
$ok(strpos($history,'aria-current="page" aria-label="Página 2"')!==false,
    'página 2 resaltada con accesibilidad');
$ok(strpos($history,'aria-label="Página 1"')!==false &&
    strpos($history,'aria-label="Página 3"')!==false,
    'paginación por números');
$ok(strpos($history,'historial_importaciones=1&amp;pagina=3')!==false,
    'enlace preserva historial en página siguiente');
$ok(strpos($history,'name="desde"')!==false &&
    strpos($history,'name="hasta"')!==false,'filtros por fecha');
$ok(strpos($history,'name="buscar"')!==false,
    'filtro de formulario y archivo');
$config=renderHistoryTest('config');
$ok(strpos($config,'Formularios y programas educativos')!==false,
    'editor de asociaciones intacto');
$ok(strpos($config,'name="csrf_meta"')!==false,
    'CSRF de edición intacto');
$ok(strpos($config,'id="campanias-historial"')===false,
    'historial no invade la edición');
echo "OK: $checks verificaciones de render de Campañas con 3 vistas independientes.\n";
