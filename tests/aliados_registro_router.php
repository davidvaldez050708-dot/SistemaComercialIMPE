<?php
// Router exclusivo de pruebas, habilitado sólo en localhost y esquema sintético.
if (getenv('IMPE_TEST_ALLOW_ROUTER')!=='1' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'') || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path!=='/_fixture') return false;
session_start();
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
$role=$_GET['role']??'cc';
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>['aliados.ver','aliados.crear_manual','aliados.editar_manual','aliados.gestionar_programas'],'csrf_token'=>str_repeat('a',64)];
if ($role==='marketing') $_SESSION=array_replace($_SESSION,['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['convocatorias.ver','formularios_aliados.gestionar_enlaces']]);
if ($role==='admin') $_SESSION=array_replace($_SESSION,['usuario_id'=>1,'rol_id'=>1,'rol'=>'Administrador']);
if ($role==='guest') { $_SESSION=[]; echo 'Sin sesión de prueba'; return true; }
if ($role==='contacts') { $_SESSION['permisos'][]='aliados.gestionar_contactos'; $puedeGestionarContactos=true; $estructuraContactosDisponible=true; }
if ($role==='share') { $_SESSION['permisos']=array_merge($_SESSION['permisos'],['aliados.compartir_correo','aliados.preparar_whatsapp','aliados.ver_historial','convocatorias.ver']); $puedeCompartirCorreo=true; $puedePrepararWhatsapp=true; $puedeConsultarConvocatorias=true; $puedeVerHistorial=true; }
$view=$_GET['view']??'aliados';
$baseline=$view==='convenios'&&($_GET['baseline']??'')==='convenios'&&getenv('IMPE_CONVENIOS_BASELINE_DIR');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aliados prueba aislada</title>
<link rel="stylesheet" href="/fixture-bootstrap.css"><?php foreach(['global','dashboard','aliados','aliados_v2_extension','crear_convocatoria','responsive_sistema'] as $css): ?><link rel="stylesheet" href="/public/css/<?= $css ?>.css<?= $baseline&&$css==='aliados_v2_extension'?'?convenios_baseline=1':'' ?>"><?php endforeach; ?>
<script src="/fixture-bootstrap.js"></script><script>window.IMPE_CSRF_TOKEN=<?= json_encode($_SESSION['csrf_token']??'') ?>;</script>
<?php if (in_array($role,['contacts','share'],true)) {
    // Ejecutar la protección CSRF real del layout, sin copiarla ni omitirla.
    $layout=file_get_contents(__DIR__.'/../app/views/layout/dashboard_head.php');
    preg_match('/(\(function \(\) \{\s*\x27use strict\x27;\s*const token = String\(window\.IMPE_CSRF_TOKEN[\s\S]+?\}\)\(\);)/',$layout,$csrfScript);
    echo '<script>'.($csrfScript[1]??'').'</script>';
} ?>
<script src="/public/javascript/aliados_registro.js"></script><script src="/public/javascript/crear_convocatoria.js<?= $baseline?'?convenios_baseline=1':'' ?>"></script><script src="/public/javascript/crear_convocatoria_v2_extension.js<?= $baseline?'?convenios_baseline=1':'' ?>"></script><script src="/public/javascript/aliados.js"></script>
</head><body><main class="p-3">
<?php
if ($view==='convenios') {
    require_once __DIR__.'/../app/helpers/PermissionHelper.php';
    if ($role==='marketing') $_SESSION['permisos']=array_merge($_SESSION['permisos'],['convenios.ver','convocatorias.crear']);
    $puedeCrearDesdeConvenio=puedeCrearConvocatoriaDesdeConvenio();
    require_once __DIR__.'/../app/models/CrearConvocatoriaModel.php';
    $m=new CrearConvocatoriaModel(); $convenios=$m->listarConveniosAliados(); $estados=$m->listarEstados(); $estructuraDisponible=true;
    require $baseline?getenv('IMPE_CONVENIOS_BASELINE_DIR').'/app/views/convocatorias/crear_desde_convenio.php':__DIR__.'/../app/views/convocatorias/crear_desde_convenio.php';
} elseif ($view==='prospectos') {
    require_once __DIR__.'/../app/models/ProspectoModel.php';
    $listado=(new ProspectoModel())->listar((int)($_GET['pagina']??1),25,$_GET); $mensajeError='';
    require __DIR__.'/../app/views/prospectos/index.php';
} else {
    require_once __DIR__.'/../app/models/AliadoModel.php';
    $estado=['id'=>1,'nombre'=>'Morelos']; $esAdministrador=$role==='admin';
    $aliados=(new AliadoModel())->obtenerListado(2,$esAdministrador,['estado_id'=>1]);
    $aliadosPorMunicipio=[1=>['id'=>1,'nombre'=>'Cuernavaca','aliados'=>$aliados]];
    $municipiosAliados=[['id'=>1,'nombre'=>'Cuernavaca']]; $estructuraAliadosDisponible=true;
    $resumenAliados=['total'=>count($aliados),'formalizados'=>1,'municipios'=>1,'con_correo'=>1,'con_whatsapp'=>0,'convocatorias_vigentes'=>0,'seguimientos_pendientes'=>0,'seguimientos_vencidos'=>0];
    require __DIR__.'/../app/views/aliados/estado.php';
}
?></main></body></html>
