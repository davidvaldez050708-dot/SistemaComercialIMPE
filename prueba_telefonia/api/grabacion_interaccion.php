<?php
session_start();

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/models/SeguimientoVinculacionModel.php';
require_once $root . '/app/services/TwilioRecordingService.php';
require_once $root . '/config/db_connection.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Sesión no activa.');
}

if (!isset($_SESSION['permisos'])) {
    $modeloRol = new RolModel();
    $modeloRol->inicializarPermisosSistema();
    $_SESSION['permisos'] = $modeloRol->obtenerCodigosPermisosPorRol(
        (int)($_SESSION['rol_id'] ?? 0)
    );
}

if (!tienePermiso('seguimientos_vinculacion.ver')) {
    http_response_code(403);
    exit('No tienes permiso para consultar esta grabación.');
}

$interaccionId = (int)($_GET['interaccion_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($interaccionId <= 0 || $usuarioId <= 0) {
    http_response_code(422);
    exit('Interacción no válida.');
}

$database = new Database();
$connection = $database->connect();
$sql = "SELECT
            id,
            seguimiento_id,
            canal,
            resultado,
            notas,
            proveedor_externo,
            id_externo,
            duracion_segundos
        FROM interacciones_vinculacion
        WHERE id = ?
        LIMIT 1";
$stmt = $connection->prepare($sql);
$stmt->bind_param('i', $interaccionId);
$stmt->execute();
$interaccion = $stmt->get_result()->fetch_assoc() ?: null;

if (!$interaccion || strtoupper((string)$interaccion['canal']) !== 'LLAMADA_IP') {
    http_response_code(404);
    exit('La llamada solicitada no existe.');
}

$seguimientoId = (int)$interaccion['seguimiento_id'];
$modelo = new SeguimientoVinculacionModel();
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($rolId === 1) {
    $seguimiento = $modelo->obtenerSeguimientoAdministrador($seguimientoId);
} elseif (tienePermiso('seguimientos_vinculacion.supervisar')) {
    $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
} else {
    $seguimiento = $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
}

if (!$seguimiento) {
    http_response_code(403);
    exit('No tienes acceso a esta grabación.');
}

$proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
$callSid = trim((string)($interaccion['id_externo'] ?? ''));
$duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));
$resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
$notas = (string)($interaccion['notas'] ?? '');
$excluirGrabacion =
    strpos($notas, '[BUZON_VOZ]') !== false ||
    strpos($notas, '[FUERA_SERVICIO]') !== false ||
    in_array($resultado, ['NO_CONTESTO', 'SIN_RESPUESTA', 'NUMERO_INCORRECTO'], true);

if ($excluirGrabacion) {
    http_response_code(404);
    exit('Esta llamada se conserva únicamente en el historial telefónico.');
}

if (
    $proveedor !== 'TWILIO' ||
    $duracion <= 0 ||
    !preg_match('/^CA[a-fA-F0-9]{32}$/', $callSid)
) {
    http_response_code(404);
    exit('Esta llamada no tiene una grabación asociada.');
}

try {
    $servicio = new TwilioRecordingService();
    $grabacion = $servicio->obtenerGrabacionParaLlamada($callSid);

    if (!$grabacion || empty($grabacion['sid'])) {
        http_response_code(404);
        exit('La grabación todavía no está disponible.');
    }

    $audio = $servicio->descargarGrabacion($grabacion['sid']);
    $contentType = trim((string)($audio['content_type'] ?? ''));

    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'audio/mpeg'));
    header('Content-Length: ' . strlen($audio['body']));
    header('Content-Disposition: inline; filename="llamada-' . $interaccionId . '.mp3"');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');

    echo $audio['body'];
} catch (Throwable $error) {
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
