<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';

function responderDestinos(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($usuarioId <= 0) {
    responderDestinos([
        'ok' => false,
        'mensaje' => 'Sesión no activa.'
    ], 401);
}

if (!isset($_SESSION['permisos'])) {
    $modeloRol = new RolModel();
    $modeloRol->inicializarPermisosSistema();
    $_SESSION['permisos'] =
        $modeloRol->obtenerCodigosPermisosPorRol(
            (int)($_SESSION['rol_id'] ?? 0)
        );
}

if (
    !tienePermiso('telefonia.usar') ||
    !tienePermiso('telefonia.transferir')
) {
    responderDestinos([
        'ok' => false,
        'mensaje' => 'Tu perfil no tiene permiso para transferir llamadas.'
    ], 403);
}

try {
    $destinos =
        (new ZadarmaWebhookEventStoreService())
            ->listarDestinosTransferencia(
                $usuarioId
            );

    responderDestinos([
        'ok' => true,
        'destinos' => $destinos,
    ]);
} catch (Throwable $error) {
    error_log(
        '[zadarma_destinos_transferencia] ' .
        $error->getMessage()
    );

    responderDestinos([
        'ok' => false,
        'mensaje' => 'No fue posible consultar los destinos de transferencia.'
    ], 500);
}
