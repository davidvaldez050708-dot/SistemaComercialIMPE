<?php
if (PHP_SAPI !== 'cli') { exit(2); }
require_once __DIR__ . '/../app/controllers/AliadoController.php';
$casos = [
    'admin' => [['usuario_id'=>1,'rol_id'=>1,'permisos'=>['aliados.ver']], 200],
    'sin-estructura' => [['usuario_id'=>1,'rol_id'=>1,'permisos'=>['aliados.ver']], 503],
    'anonimo' => [[], 401],
    'sin-permiso' => [['usuario_id'=>1,'rol_id'=>1,'permisos'=>[]], 403],
    'otro-rol' => [['usuario_id'=>2,'rol_id'=>2,'permisos'=>['aliados.ver']], 403],
];
if (!isset($casos[$argv[1] ?? ''])) { exit(2); }
[$_SESSION, $esperado] = $casos[$argv[1]];
http_response_code(200);
ob_start();
register_shutdown_function(function () use ($esperado) {
    $texto = ob_get_clean();
    // No tarjeta, token ni información de prospectos antes de autorizar.
    if ($esperado === 200) {
        exit(http_response_code() === 200 && strpos($texto, 'Handly Lenguaje Center') !== false && strpos($texto, 'Adalid Kuribeña') !== false && strpos($texto, '7775635090') !== false && strpos($texto, 'Warning:') === false ? 0 : 1);
    }
    exit(http_response_code() === $esperado && strpos($texto, 'data-copy-ally-url="') === false && ($esperado === 503 || strpos($texto, 'Respuestas recibidas') === false) ? 0 : 1);
});
(new AliadoController())->internacionales();
