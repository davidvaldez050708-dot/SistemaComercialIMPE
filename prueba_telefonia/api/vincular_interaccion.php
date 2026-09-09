<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0 || $rolId !== 4) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Solo el Analista puede vincular una llamada del seguimiento.'
    ]);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido.']);
    exit;
}

$seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
$interaccionId = (int)($_POST['interaccion_id'] ?? 0);
$callSid = trim((string)($_POST['call_sid'] ?? ''));
$parentSid = trim((string)($_POST['parent_sid'] ?? ''));

if (
    $seguimientoId <= 0 ||
    $interaccionId <= 0 ||
    !preg_match('/^CA[a-fA-F0-9]{32}$/', $callSid)
) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Los datos de la llamada o de la interacción no son válidos.'
    ]);
    exit;
}

if ($parentSid !== '' && !preg_match('/^CA[a-fA-F0-9]{32}$/', $parentSid)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'El identificador padre de la llamada no es válido.']);
    exit;
}

$configPath = dirname(__DIR__, 2) . '/config/voip_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Falta config/voip_config.php.']);
    exit;
}

$config = require $configPath;
$accountSid = trim((string)($config['account_sid'] ?? ''));
$authToken = trim((string)($config['auth_token'] ?? ''));

if (!preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) || $authToken === '' || $authToken === 'TU_AUTH_TOKEN_PRIVADO') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Configura las credenciales privadas de Twilio.']);
    exit;
}

function twilioGetCall(string $url, string $accountSid, string $authToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Twilio respondió HTTP ' . $status);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respuesta JSON no válida de Twilio.');
    }

    return $data;
}

function fechaMysql($valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    try {
        return (new DateTime($valor))->format('Y-m-d H:i:s');
    } catch (Throwable $error) {
        return null;
    }
}

try {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/'
        . rawurlencode($accountSid)
        . '/Calls/' . rawurlencode($callSid) . '.json';
    $call = twilioGetCall($url, $accountSid, $authToken);

    if (($call['direction'] ?? '') !== 'outbound-dial') {
        throw new RuntimeException('La llamada consultada no corresponde al tramo telefónico saliente.');
    }

    $parentReal = trim((string)($call['parent_call_sid'] ?? ''));
    if ($parentSid !== '' && $parentReal !== '' && !hash_equals($parentSid, $parentReal)) {
        throw new RuntimeException('La llamada no corresponde a la sesión indicada.');
    }

    require_once dirname(__DIR__, 2) . '/config/db_connection.php';
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
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensaje' => 'No tienes acceso a este seguimiento.']);
        exit;
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
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'La interacción telefónica indicada no pertenece a este seguimiento.'
        ]);
        exit;
    }

    $idExternoActual = trim((string)($interaccion['id_externo'] ?? ''));
    $proveedorActual = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));

    if ($idExternoActual !== '' && !hash_equals($idExternoActual, $callSid)) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otra llamada.'
        ]);
        exit;
    }

    if ($idExternoActual !== '' && $proveedorActual !== '' && $proveedorActual !== 'TWILIO') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otro proveedor de telefonía.'
        ]);
        exit;
    }

    $fechaInicio = fechaMysql($call['start_time'] ?? $call['date_created'] ?? '') ?? date('Y-m-d H:i:s');
    $fechaFin = fechaMysql($call['end_time'] ?? '');
    $duracion = max(0, (int)($call['duration'] ?? 0));
    $proveedor = 'TWILIO';

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
        $callSid,
        $interaccionId,
        $seguimientoId,
        $usuarioId
    );
    $stmtActualizar->execute();

    echo json_encode([
        'ok' => true,
        'mensaje' => 'La llamada real quedó vinculada con la interacción exacta.',
        'interaccion_id' => $interaccionId,
        'call_sid' => $callSid,
        'duracion_segundos' => $duracion,
        'estado_twilio' => (string)($call['status'] ?? ''),
        'grabacion_disponible' => $duracion > 0,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo vincular la llamada real con la interacción.',
        'detalle' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
