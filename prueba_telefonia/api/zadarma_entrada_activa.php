<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
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
    !tienePermiso('telefonia.recibir')
) {
    responderEntrada([
        'ok' => false,
        'mensaje' => 'Tu perfil no tiene permiso para recibir llamadas.'
    ], 403);
}

try {
    $asignacion =
        (new TelefoniaExtensionService())
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
        new ZadarmaWebhookEventStoreService();

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
    error_log(
        '[zadarma_entrada_activa] ' .
        $error->getMessage()
    );

    responderEntrada([
        'ok' => false,
        'mensaje' => 'No fue posible consultar las llamadas entrantes.'
    ], 500);
}
