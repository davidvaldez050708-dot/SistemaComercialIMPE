<?php
// Sin base de datos: permisos efectivos, ambos menús y guard real del controlador.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/helpers/PermissionHelper.php';
require_once __DIR__ . '/../app/controllers/CrearConvocatoriaController.php';
if (!defined('BASE_URL')) define('BASE_URL', '/');
$cuenta = ['usuario_id'=>2, 'rol_id'=>6, 'rol'=>'Cuenta Clave'];
$marketing = ['usuario_id'=>4, 'rol_id'=>5, 'rol'=>'Marketing'];
$admin = ['usuario_id'=>1, 'rol_id'=>1, 'rol'=>'Administrador'];
$casos = [
    'cc_ambos'=>[$cuenta+['permisos'=>['convenios.ver','convenios.gestionar']],true],
    'cc_ver'=>[$cuenta+['permisos'=>['convenios.ver']],true],
    'cc_gestionar'=>[$cuenta+['permisos'=>['convenios.gestionar']],true],
    'cc_sin'=>[$cuenta+['permisos'=>[]],false],
    'cc_solo_convocatorias'=>[$cuenta+['permisos'=>['convocatorias.ver']],false],
    'marketing_actual'=>[$marketing+['permisos'=>['convocatorias.ver']],true],
    'marketing_convenios'=>[$marketing+['permisos'=>['convenios.ver']],true],
    'marketing_sin'=>[$marketing+['permisos'=>[]],false],
    'admin_ver'=>[$admin+['permisos'=>['convenios.ver']],true],
    'admin_gestionar_restringido'=>[$admin+['permisos'=>['convenios.gestionar']],false],
    'ventas_sin'=>[['usuario_id'=>8,'rol_id'=>7,'rol'=>'Asesor de Ventas','permisos'=>[]],false],
    'sin_sesion'=>[['rol'=>'Marketing','permisos'=>['convocatorias.ver','convenios.ver']],false],
];
if (($argv[1] ?? '') === '--gate') {
    if (!isset($casos[$argv[2] ?? ''])) exit(2);
    $_SESSION=$casos[$argv[2]][0];
    register_shutdown_function(static function () { echo '\nSTATUS='.(http_response_code() ?: 200); });
    $gate=new ReflectionMethod(CrearConvocatoriaController::class,'validarAccesoConvenios');
    $gate->invoke(new CrearConvocatoriaController());
    echo 'ACCESS_OK';
    exit;
}
$count=0;
function verificar($ok,$mensaje) {
    global $count;
    if (!$ok) throw new RuntimeException($mensaje);
    $count++;
}
foreach ($casos as $nombre=>[$sesion,$permitido]) {
    $_SESSION=$sesion;
    verificar(puedeConsultarDirectorioConvenios()===$permitido, 'Política: '.$nombre);
    $nombreCompleto='Usuario de prueba';
    $opcionActiva='crear_convocatoria';
    ob_start(); require __DIR__.'/../app/views/layout/sidebar.php'; $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath=new DOMXPath($dom);
    $links=$xpath->query('//a[contains(@href,"controller=crearConvocatoria&action=index")]');
    verificar($links->length===($permitido?2:0),'Menú escritorio/móvil: '.$nombre);
    foreach ($links as $link) {
        verificar(trim($link->textContent)==='Convenios','Etiqueta: '.$nombre);
        verificar(str_contains($link->getAttribute('class'),'active'),'Activo: '.$nombre);
    }
    verificar($xpath->query('//a[contains(@href,"controller=convocatoria&action=index")]')->length===(tienePermiso('convocatorias.ver')?2:0),'Convocatorias conserva permiso propio: '.$nombre);
    $otrosMarketing=tienePermiso('convocatorias.ver') && strcasecmp($sesion['rol']??'','Marketing')===0;
    foreach (['formulario','correoMarketing'] as $controller) {
        verificar($xpath->query('//a[contains(@href,"controller='.$controller.'&action=index")]')->length===($otrosMarketing?2:0),'Sin conceder otro módulo: '.$nombre.'/'.$controller);
    }
    verificar($xpath->query('//a[contains(@href,"controller=prospecto&action=index")]')->length===(tienePermiso('prospectos.ver_todos')?2:0),'Prospectos mantiene permiso propio: '.$nombre);
    verificar($_SESSION===$sesion,'No modifica permisos ni sesión: '.$nombre);
    // La falta de sesión se verifica por política y ausencia de enlace; el guard redirige al login.
    $process=proc_open([PHP_BINARY,__FILE__,'--gate',$nombre],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    verificar(proc_close($process)===0 && $err==='', 'Guard sin errores: '.$nombre.' '.$err);
    verificar(str_contains($out,'ACCESS_OK')===$permitido,'Acceso directo al controlador: '.$nombre);
    if (!$permitido && isset($sesion['usuario_id'])) verificar(str_contains($out,'STATUS=403'),'HTTP 403: '.$nombre);
}
$source=file_get_contents(__DIR__.'/../app/controllers/CrearConvocatoriaController.php');
verificar(strpos($source,'$this->validarAccesoConvenios();')<strpos($source,'new CrearConvocatoriaModel()'),'Autorizar antes de consultar DB');
echo "PASS $count comprobaciones de Convenios: 12 perfiles/permisos, menús escritorio/móvil y guard real. Sin SQL.\n";
