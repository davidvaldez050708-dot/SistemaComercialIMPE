<?php
// Layout y ficha reales, sólo datos ficticios y sin conexión a SQL.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') exit(2);
parse_str($argv[1] ?? '', $_GET);
require __DIR__.'/../config/config.php';
require __DIR__.'/../app/helpers/ProspectoFechaHoraHelper.php';
$mensajeError='';
if (in_array($_GET['action'] ?? '', ['detalle','detalleRegistro'], true)) {
    $id=(int)($_GET['id']??1);
    $ficha=['id'=>$id,'nombre_completo'=>'Persona Ficticia '.$id,
        'nombre'=>'Persona','apellidos'=>'Ficticia '.$id,'telefono_original'=>'5500000011',
        'telefono_normalizado'=>'+525500000011','correo'=>'persona-ficticia@example.test',
        'estado_nombre'=>'Estado ficticio','municipio_nombre'=>'Municipio ficticio',
        'coincidencias'=>1,'asignaciones'=>[],'integraciones'=>[],
        'intereses'=>[['nombre_original'=>'Programa ficticio','clasificacion_estado'=>'CLASIFICADO']],
        'origenes_pagina'=>1,'origenes_paginas'=>1,'intereses_pagina'=>1,'intereses_paginas'=>1,
        'original'=>['id'=>11,'nombre'=>'Persona','apellido'=>'Ficticia',
            'horario_contacto'=>'Lun, Mar, Vie · 07:00–22:00 (hora local)',
            'created_at'=>'2026-11-02 15:00:00','updated_at'=>'2026-11-02 15:00:00'],
        'origenes'=>[['tipo_origen'=>'FORMULARIO_GENERAL','datos_originales_ref'=>'formulario_registros:11',
            'registro_tabla'=>'formulario_registros','registro_id'=>11,
            'fecha_registro_original'=>'2026-11-02 15:00:00','zona_horaria_origen'=>'UTC',
            'valor_original'=>'Lun, Mar, Vie · 07:00–22:00 (hora local)',
            'zona_horaria'=>'America/Mexico_City','interpretacion_estado'=>'INTERPRETADO']]];
    $vistaPanel=true;
    require __DIR__.'/../app/views/prospectos/detalle.php';
    exit;
}
$seccionProspectos=$_GET['seccion']??'todos';
$centralDisponible=true;
$indicadores=['personas'=>2,'aliados'=>1,'generales'=>1,'meta'=>0,'por_incorporar'=>0];
$listado=['total'=>2,'pagina'=>1,'paginas'=>1,'limite'=>25,'programas'=>[],
    'registros'=>array_map(static fn($id)=>['id'=>$id,'fecha_origen'=>'2026-11-02 15:00:00',
        'nombre_completo'=>'Persona Ficticia '.$id,'telefono_original'=>'5500000011',
        'programas'=>'Programa ficticio','procedencias'=>'Formulario de prueba',
        'horario'=>'Sin indicar'],[1,2])];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prospectos — prueba aislada</title>
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/bootstrap.css">
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/fonts.css">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/global.css">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/dashboard.css">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/seguimiento_estabilizacion_visual.css">
<style>body{background:#f5f7fb}main{padding:24px}#referenciaSeguimiento{visibility:hidden}header.prueba{padding:18px 24px;background:white;border-bottom:1px solid #dfe7f1}header.prueba h1{font:800 23px Manrope,sans-serif;margin:0}header.prueba p{font:13px Manrope,sans-serif;color:#64758e;margin:6px 0}</style>
</head><body><header class="prueba"><h1>Prospectos</h1><p>Almacén central y procedencia de los registros.</p></header><main>
<?php require __DIR__.'/../app/views/prospectos/central.php'; ?>
<div class="offcanvas offcanvas-end linkage-work-offcanvas" id="referenciaSeguimiento" aria-hidden="true"></div>
</main></body></html>
