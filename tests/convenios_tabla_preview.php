<?php
// Render CLI sin conexión a BD, únicamente datos sintéticos para comprobar la vista real.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
parse_str($argv[1] ?? '', $_GET);
$root = getenv('IMPE_TABLA_APP_ROOT') ?: dirname(__DIR__);
define('BASE_URL', 'http://127.0.0.1:8134/');
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$texto = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$programas = [['codigo'=>'bachillerato-general','nombre'=>'Bachillerato General','clasificacion'=>'bachillerato|bachillerato-general'],['codigo'=>'ingles','nombre'=>'Inglés','clasificacion'=>'ingles|ingles']];
if (($_GET['preview'] ?? '') === 'editor') {
    $puedeCrear = true;
    $aliadoContextoConvocatoria = ['opciones_convocatoria'=>$programas,'origen'=>['nombre_entidad'=>$_GET['aliado_tipo']==='manual'?'PRUEBA':'Instituto de la Juventud Celayense'],'url'=>BASE_URL.'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/Ab7K9','habilitado'=>true];
    // La réplica modular del componente usa sus recursos locales ya existentes.
    $component=__DIR__.'/fixtures/v3_hostinger/aliado_programas_modular.php';
    require $root.'/app/views/convocatorias/partials/editor_convenio_v4.php';
    exit;
}
$estructuraDisponible = true;
$puedeCrearDesdeConvenio = ($_GET['role'] ?? '') !== 'consulta';
$estados = [['id'=>11,'nombre'=>'Guanajuato']];
$convenios = [];
foreach ([['Instituto de la Juventud Celayense','Celaya',1,'institucional',10],['PRUEBA','León',2,'manual',1],['Institución de nombre extenso para comprobar columnas estables y alineadas en cualquier municipio','León',2,'manual',2]] as $item) {
    [$nombre,$municipio,$mid,$tipo,$id]=$item;
    $convenios[]=['nombre_entidad'=>$nombre,'estado_id'=>11,'estado_nombre'=>'Guanajuato','municipio_id'=>$mid,'municipio_nombre'=>$municipio,'origen_tipo'=>$tipo,'origen_id'=>$id,'seguimiento_id'=>$tipo==='institucional'?$id:0,'contacto_nombre'=>'Lic. José Manuel Rentería Gualito','contacto_cargo'=>'Director General','correo_contacto'=>'contacto.institucional@example.test','registro_programas_items'=>$programas,'registro_programas'=>'Bachillerato General · Inglés','registro_estado'=>'Registro habilitado','convenio_formalizado_at'=>$tipo==='institucional'?'2025-12-05 00:00:00':'','convenio_notas'=>"Primera línea <script>alert(1)</script>\n\nSegunda línea completa.",'tiene_convocatoria'=>1];
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= str_repeat('a',64) ?>">
<?php foreach (['/fixture-bootstrap.css','/fixture-icons.css','/fixture-font.css','/public/css/global.css','/public/css/dashboard.css','/public/css/aliados.css','/public/css/crear_convocatoria.css','/public/css/aliados_v2_extension.css'] as $css): ?><link rel="stylesheet" href="<?= $css ?>"><?php endforeach; ?>
<style>body{margin:0;background:#f4f7fb}main.dashboard-main{margin:0;padding:28px;min-width:0}@media(max-width:767px){main.dashboard-main{padding:12px}}</style>
</head><body><main class="dashboard-main"><?php require $root.'/app/views/convocatorias/crear_desde_convenio.php'; ?></main><script src="/fixture-bootstrap.js"></script><script src="/public/javascript/crear_convocatoria.js"></script><script src="/public/javascript/crear_convocatoria_v2_extension.js"></script></body></html>
