<?php
session_start();

require_once dirname(__DIR__, 2) .
    '/app/services/TelefoniaExtensionService.php';
require_once dirname(__DIR__, 2) .
    '/app/models/RolModel.php';
require_once dirname(__DIR__, 2) .
    '/app/helpers/PermissionHelper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function responderJson(array $data, int $status = 200): void
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
    responderJson([
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

if (!tienePermiso('telefonia.usar')) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Tu perfil no tiene permiso para utilizar el motor WebRTC.'
    ], 403);
}

$rootPath = dirname(__DIR__, 2);
$configPath = $rootPath . '/config/zadarma_config.php';
$autoloadPath = $rootPath . '/vendor/autoload.php';

if (!is_file($configPath)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Falta config/zadarma_config.php.'
    ], 500);
}

if (!is_file($autoloadPath)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'No se encontró vendor/autoload.php. Ejecuta composer install.'
    ], 500);
}

require_once $autoloadPath;

$config = require $configPath;
$apiKey = trim((string)($config['api_key'] ?? ''));
$apiSecret = trim((string)($config['api_secret'] ?? ''));

if ($apiKey === '' || $apiSecret === '') {
    responderJson([
        'ok' => false,
        'mensaje' => 'La configuración privada de Zadarma está incompleta.'
    ], 500);
}

try {
    $asignacionTelefonica =
        (new TelefoniaExtensionService())
            ->resolverParaUsuario($usuarioId);
} catch (Throwable $e) {
    responderJson([
        'ok' => false,
        'mensaje' => 'No fue posible consultar la extensión telefónica del usuario.'
    ], 500);
}

if (!$asignacionTelefonica) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Tu usuario no tiene una extensión Zadarma activa asignada.'
    ], 422);
}

if (
    empty($asignacionTelefonica['permite_salientes']) &&
    empty($asignacionTelefonica['permite_entrantes'])
) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Tu extensión no tiene capacidades telefónicas habilitadas.'
    ], 403);
}

$extension = trim(
    (string)($asignacionTelefonica['extension'] ?? '')
);

if (!preg_match('/^\d{3,6}$/', $extension)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La extensión Zadarma asignada no es válida.'
    ], 422);
}

try {
    $api = new \Zadarma_API\Api($apiKey, $apiSecret, false);

    // Obtener el PBX ID nos permite construir el login completo que utiliza
    // el widget WebRTC (por ejemplo: 1234-100) sin guardar credenciales SIP.
    $pbx = $api->getPbxInternal();
    $pbxId = trim((string)($pbx->pbx_id ?? ''));
    $extensiones = is_array($pbx->numbers ?? null) ? $pbx->numbers : [];

    if ($pbxId === '' || !in_array((int)$extension, array_map('intval', $extensiones), true)) {
        responderJson([
            'ok' => false,
            'mensaje' => 'La extensión configurada no existe en la centralita Zadarma.'
        ], 422);
    }

    $sipLogin = $pbxId . '-' . $extension;
    $webrtc = $api->getWebrtcKey($sipLogin);
    $webrtcKey = trim((string)($webrtc->key ?? ''));

    if ($webrtcKey === '') {
        responderJson([
            'ok' => false,
            'mensaje' => 'Zadarma no devolvió una clave WebRTC válida.'
        ], 502);
    }

    responderJson([
        'ok' => true,
        'extension' => $extension,
        'sip_login' => $sipLogin,
        'webrtc_key' => $webrtcKey,
        'expires_in_hours' => 72,
        'permite_salientes' => !empty($asignacionTelefonica['permite_salientes']),
        'permite_entrantes' => !empty($asignacionTelefonica['permite_entrantes']),
        'permite_transferir' =>
            !empty($asignacionTelefonica['permite_transferir']) &&
            tienePermiso('telefonia.transferir'),
        'extension_source' =>
            (string)($asignacionTelefonica['origen'] ?? 'USUARIO'),
    ]);
} catch (\Zadarma_API\ApiException $e) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Zadarma rechazó la solicitud WebRTC.',
        'detalle' => $e->getMessage(),
    ], 502);
} catch (Throwable $e) {
    responderJson([
        'ok' => false,
        'mensaje' => 'No fue posible preparar WebRTC con Zadarma.',
        'detalle' => $e->getMessage(),
    ], 500);
}
