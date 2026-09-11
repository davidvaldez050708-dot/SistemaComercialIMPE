<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

function fechaMysqlDesdeIso(?string $valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    try {
        $fecha = new DateTime($valor);
        $fecha->setTimezone(new DateTimeZone('America/Mexico_City'));
        return $fecha->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function fechaMysqlLocal(?string $valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    $fecha = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $valor,
        new DateTimeZone('America/Mexico_City')
    );

    if (!$fecha) {
        return null;
    }

    return $fecha->format('Y-m-d H:i:s');
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0) {
    responderJson(['ok' => false, 'mensaje' => 'Sesión no activa.'], 401);
}

if ($rolId !== 4) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Solo el Analista puede vincular una llamada del seguimiento.'
    ], 403);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    responderJson(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
}

$seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
$interaccionId = (int)($_POST['interaccion_id'] ?? 0);
$pbxCallId = trim((string)($_POST['pbx_call_id'] ?? ''));

if (
    $seguimientoId <= 0 ||
    $interaccionId <= 0 ||
    !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)
) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Los datos de la llamada o de la interacción no son válidos.'
    ], 422);
}

$rootPath = dirname(__DIR__, 2);
$configPath = $rootPath . '/config/zadarma_config.php';
$logPath = $rootPath . '/storage/zadarma_webhooks.log';

if (!is_file($configPath)) {
    responderJson(['ok' => false, 'mensaje' => 'Falta config/zadarma_config.php.'], 500);
}

if (!is_file($logPath)) {
    responderJson(['ok' => false, 'mensaje' => 'Todavía no se recibió el registro de la llamada Zadarma.'], 404);
}

$config = require $configPath;
$extension = trim((string)($config['pbx_extension'] ?? ''));

if (!preg_match('/^\d{3,6}$/', $extension)) {
    responderJson(['ok' => false, 'mensaje' => 'La extensión Zadarma no está configurada correctamente.'], 500);
}

$lineas = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$inicio = null;
$fin = null;
$grabacion = null;

foreach ($lineas as $linea) {
    $fila = json_decode($linea, true);
    if (!is_array($fila)) {
        continue;
    }

    if (!hash_equals($pbxCallId, trim((string)($fila['pbx_call_id'] ?? '')))) {
        continue;
    }

    $evento = (string)($fila['event'] ?? '');
    if ($evento === 'NOTIFY_OUT_START') {
        $inicio = $fila;
    } elseif ($evento === 'NOTIFY_OUT_END') {
        $fin = $fila;
    } elseif ($evento === 'NOTIFY_RECORD') {
        $grabacion = $fila;
    }
}

if (!$inicio) {
    responderJson(['ok' => false, 'mensaje' => 'No se encontró el inicio de esta llamada Zadarma.'], 404);
}

if (trim((string)($inicio['internal'] ?? '')) !== $extension) {
    responderJson(['ok' => false, 'mensaje' => 'La llamada no corresponde a la extensión asignada al Analista.'], 403);
}

if (!$fin) {
    responderJson(['ok' => false, 'mensaje' => 'La llamada todavía no termina de procesarse en Zadarma.'], 409);
}

try {
    require_once $rootPath . '/config/db_connection.php';
    $database = new Database();
    $connection = $database->connect();

    $sqlSeguimiento = "SELECT id
        FROM seguimientos_vinculacion
        WHERE id = ?
          AND analista_id = ?
          AND activo = 1
        LIMIT 1";
    $stmtSeguimiento = $connection->prepare($sqlSeguimiento);
    $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
    $stmtSeguimiento->execute();

    if (!$stmtSeguimiento->get_result()->fetch_assoc()) {
        responderJson(['ok' => false, 'mensaje' => 'No tienes acceso a este seguimiento.'], 403);
    }

    $sqlInteraccion = "SELECT
            id,
            proveedor_externo,
            id_externo
        FROM interacciones_vinculacion
        WHERE id = ?
          AND seguimiento_id = ?
          AND usuario_id = ?
          AND canal = 'LLAMADA_IP'
        LIMIT 1";
    $stmtInteraccion = $connection->prepare($sqlInteraccion);
    $stmtInteraccion->bind_param('iii', $interaccionId, $seguimientoId, $usuarioId);
    $stmtInteraccion->execute();
    $interaccion = $stmtInteraccion->get_result()->fetch_assoc() ?: null;

    if (!$interaccion) {
        responderJson([
            'ok' => false,
            'mensaje' => 'La interacción telefónica indicada no pertenece a este seguimiento.'
        ], 404);
    }

    $idExternoActual = trim((string)($interaccion['id_externo'] ?? ''));
    $proveedorActual = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));

    if ($idExternoActual !== '' && !hash_equals($idExternoActual, $pbxCallId)) {
        responderJson([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otra llamada.'
        ], 409);
    }

    if ($idExternoActual !== '' && $proveedorActual !== '' && $proveedorActual !== 'ZADARMA') {
        responderJson([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otro proveedor de telefonía.'
        ], 409);
    }

    $fechaInicio = fechaMysqlLocal($inicio['call_start'] ?? null) ?? date('Y-m-d H:i:s');
    $fechaFin = fechaMysqlDesdeIso($fin['received_at'] ?? null);
    $duracion = max(0, (int)($fin['duration'] ?? 0));

    if ($fechaFin === null && $duracion > 0) {
        try {
            $temporal = new DateTime($fechaInicio, new DateTimeZone('America/Mexico_City'));
            $temporal->modify('+' . $duracion . ' seconds');
            $fechaFin = $temporal->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $fechaFin = null;
        }
    }

    $proveedor = 'ZADARMA';

    $sqlActualizar = "UPDATE interacciones_vinculacion
        SET fecha_inicio = ?,
            fecha_fin = ?,
            duracion_segundos = ?,
            proveedor_externo = ?,
            id_externo = ?
        WHERE id = ?
          AND seguimiento_id = ?
          AND usuario_id = ?
          AND canal = 'LLAMADA_IP'";
    $stmtActualizar = $connection->prepare($sqlActualizar);
    $stmtActualizar->bind_param(
        'ssissiii',
        $fechaInicio,
        $fechaFin,
        $duracion,
        $proveedor,
        $pbxCallId,
        $interaccionId,
        $seguimientoId,
        $usuarioId
    );
    $stmtActualizar->execute();

    responderJson([
        'ok' => true,
        'mensaje' => 'La llamada Zadarma quedó vinculada con la interacción exacta.',
        'interaccion_id' => $interaccionId,
        'pbx_call_id' => $pbxCallId,
        'duracion_segundos' => $duracion,
        'estado_zadarma' => (string)($fin['disposition'] ?? ''),
        'grabacion_disponible' =>
            (string)($fin['is_recorded'] ?? '') === '1' ||
            $grabacion !== null,
        'call_id_with_rec' => trim((string)(
            $grabacion['call_id_with_rec'] ??
            $fin['call_id_with_rec'] ??
            ''
        )),
    ]);
} catch (Throwable $e) {
    responderJson([
        'ok' => false,
        'mensaje' => 'No se pudo vincular la llamada Zadarma con la interacción.',
        'detalle' => $e->getMessage(),
    ], 500);
}
