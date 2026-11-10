<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/services/TelefoniaSesionService.php';
require_once $root . '/app/services/TelefoniaExtensionService.php';
require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';

function responderEntrada(array $data, int $status = 200): void
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
    responderEntrada([
        'ok' => false,
        'mensaje' => 'Sesión no activa.'
    ], 401);
}

try {
    $conexion = Database::compartida();
    $sesionVigente = TelefoniaSesionService::actualizarPermisos($usuarioId, $conexion);
} catch (Throwable $error) {
    if (Database::responderFalloTemporal($error)) exit;
    responderEntrada(['ok' => false, 'mensaje' => 'No fue posible verificar los permisos de telefonía.'], 500);
}
if (!$sesionVigente) {
    responderEntrada(['ok' => false, 'mensaje' => 'Tu cuenta o perfil de acceso no está activo.'], 403);
}
if (
    !tienePermiso('telefonia.usar') ||
    !tienePermiso('telefonia.recibir')
) {
    responderEntrada([
        'ok' => false,
        'mensaje' => 'Tu perfil no tiene permiso para recibir llamadas.'
    ], 403);
}

try {
    $asignacion =
        (new TelefoniaExtensionService($conexion))
            ->resolverParaUsuario($usuarioId);

    if (
        !$asignacion ||
        empty($asignacion['permite_entrantes'])
    ) {
        responderEntrada([
            'ok' => false,
            'mensaje' => 'Tu extensión no tiene habilitadas llamadas entrantes.'
        ], 403);
    }

    $extension = trim(
        (string)($asignacion['extension'] ?? '')
    );

    $desde = (int)($_GET['since'] ?? 0);
    $pbxCallId = trim(
        (string)($_GET['pbx_call_id'] ?? '')
    );
    $eventStore =
        new ZadarmaWebhookEventStoreService($conexion);

    $llamada = $pbxCallId !== ''
        ? $eventStore->obtenerEstadoEntrantePorPbxCallId(
            $extension,
            $pbxCallId
        )
        : $eventStore->buscarEntranteRecientePorExtension(
            $extension,
            $desde
        );

    responderEntrada([
        'ok' => true,
        'extension' => $extension,
        'permite_transferir' =>
            !empty($asignacion['permite_transferir']) &&
            tienePermiso('telefonia.transferir'),
        'call' => $llamada,
    ]);
} catch (Throwable $error) {
    if (Database::responderFalloTemporal($error)) exit;
    error_log(
        '[zadarma_entrada_activa] ' .
        $error->getMessage()
    );

    responderEntrada([
        'ok' => false,
        'mensaje' => 'No fue posible consultar las llamadas entrantes.'
    ], 500);
}
