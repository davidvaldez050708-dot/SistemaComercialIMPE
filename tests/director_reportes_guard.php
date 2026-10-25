<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/controllers/ReporteController.php';
require dirname(__DIR__).'/app/controllers/ReporteAdministradorController.php';
$caso=$argv[1]??'';
$_SESSION=['usuario_id'=>10,'rol_id'=>2,'permisos'=>['reportes.ver','reportes.usuarios','reportes.exportar']];
if ($caso==='sin_exportar') $_SESSION['permisos']=['reportes.ver','reportes.usuarios'];
if ($caso==='sin_usuarios') $_SESSION['permisos']=['reportes.ver','reportes.exportar'];
if ($caso==='sin_centro') $_SESSION['permisos']=['reportes.usuarios','reportes.exportar'];
if ($caso==='otro_rol') $_SESSION['rol_id']=3;
if ($caso==='lectura_revocada') $_SESSION['permisos']=[];
register_shutdown_function(static function () {echo '\nHTTP='.((int)http_response_code() ?: 200);});
if ($caso==='lectura_revocada') (new ReporteController())->usuarios();
else {
    $r=new ReflectionClass(ReporteAdministradorController::class);
    $r->getMethod('validarAdministrador')->invoke($r->newInstanceWithoutConstructor());
    echo 'AUTORIZADO';
}
