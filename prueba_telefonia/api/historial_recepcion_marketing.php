<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';

function responderHistorialMarketing(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    responderHistorialMarketing(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    responderHistorialMarketing(['ok' => false, 'mensaje' => 'Sesión no activa.'], 401);
}
$modeloRol = new RolModel();
$modeloRol->inicializarPermisosSistema();
$_SESSION['permisos'] = $modeloRol->obtenerCodigosPermisosPorRol(
    (int)($_SESSION['rol_id'] ?? 0)
);
if (!TelefoniaRecepcionMarketingService::tienePermisos()) {
    responderHistorialMarketing(['ok' => false, 'mensaje' => 'No tienes acceso al historial de recepción.'], 403);
}
try {
    responderHistorialMarketing([
        'ok' => true,
        'recepcion' => (new TelefoniaRecepcionMarketingService())->resumen($usuarioId)
    ]);
} catch (Throwable $error) {
    error_log('[telefonia_marketing_historial] ' . $error->getMessage());
    responderHistorialMarketing([
        'ok' => false,
        'mensaje' => 'No fue posible consultar el historial de recepción.'
    ], 500);
}
