<?php
session_start();

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

function soloDigitos(string $valor): string
{
    return preg_replace('/\D+/', '', $valor) ?: '';
}

function telefonosCoinciden(string $a, string $b): bool
{
    $a = soloDigitos($a);
    $b = soloDigitos($b);

    if ($a === '' || $b === '') {
        return false;
    }

    if (hash_equals($a, $b)) {
        return true;
    }

    if (strlen($a) >= 10 && strlen($b) >= 10) {
        return hash_equals(substr($a, -10), substr($b, -10));
    }

    return false;
}

function timestampRegistro(array $registro): int
{
    $receivedAt = trim((string)($registro['received_at'] ?? ''));
    if ($receivedAt !== '') {
        $timestamp = strtotime($receivedAt);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    $callStart = trim((string)($registro['call_start'] ?? ''));
    if ($callStart !== '') {
        $timestamp = strtotime($callStart);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    return 0;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0) {
    responderJson(['ok' => false, 'mensaje' => 'Sesión no activa.'], 401);
}

if ($rolId !== 4) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La consulta de estado telefónico está disponible únicamente para Analistas.'
    ], 403);
}

$destino = trim((string)($_GET['destination'] ?? ''));
$desdeSolicitado = (int)($_GET['since'] ?? 0);

if ($destino === '' || strlen(soloDigitos($destino)) < 8) {
    responderJson(['ok' => false, 'mensaje' => 'Destino telefónico no válido.'], 422);
}

$rootPath = dirname(__DIR__, 2);
$configPath = $rootPath . '/config/zadarma_config.php';
$logPath = $rootPath . '/storage/zadarma_webhooks.log';

if (!is_file($configPath)) {
    responderJson(['ok' => false, 'mensaje' => 'Falta config/zadarma_config.php.'], 500);
}

$config = require $configPath;
$extension = trim((string)($config['pbx_extension'] ?? ''));

if (!preg_match('/^\d{3,6}$/', $extension)) {
    responderJson(['ok' => false, 'mensaje' => 'La extensión Zadarma no está configurada correctamente.'], 500);
}

if (!is_file($logPath)) {
    responderJson(['ok' => true, 'call' => null]);
}

$ahora = time();
$desde = $desdeSolicitado > 0
    ? max($ahora - 900, min($desdeSolicitado, $ahora + 5))
    : $ahora - 120;
$desde -= 10;

$lineas = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$registros = [];

foreach ($lineas as $linea) {
    $fila = json_decode($linea, true);
    if (is_array($fila)) {
        $registros[] = $fila;
    }
}

$pbxCallId = '';
$inicioSeleccionado = null;

foreach ($registros as $registro) {
    if (($registro['event'] ?? '') !== 'NOTIFY_OUT_START') {
        continue;
    }

    if (trim((string)($registro['internal'] ?? '')) !== $extension) {
        continue;
    }

    if (!telefonosCoinciden((string)($registro['destination'] ?? ''), $destino)) {
        continue;
    }

    if (timestampRegistro($registro) < $desde) {
        continue;
    }

    $id = trim((string)($registro['pbx_call_id'] ?? ''));
    if ($id === '') {
        continue;
    }

    $pbxCallId = $id;
    $inicioSeleccionado = $registro;
}

if ($pbxCallId === '') {
    responderJson(['ok' => true, 'call' => null]);
}

$respuesta = null;
$fin = null;
$grabacion = null;

foreach ($registros as $registro) {
    if (!hash_equals($pbxCallId, trim((string)($registro['pbx_call_id'] ?? '')))) {
        continue;
    }

    $evento = (string)($registro['event'] ?? '');

    if ($evento === 'NOTIFY_ANSWER') {
        $respuesta = $registro;
    } elseif ($evento === 'NOTIFY_OUT_END') {
        $fin = $registro;
    } elseif ($evento === 'NOTIFY_RECORD') {
        $grabacion = $registro;
    }
}

$status = 'ringing';
$disposition = '';
$duration = 0;
$isRecorded = false;
$callIdWithRec = '';
$endTime = null;

if ($fin) {
    $disposition = strtolower(trim((string)($fin['disposition'] ?? '')));
    $duration = max(0, (int)($fin['duration'] ?? 0));
    $isRecorded = (string)($fin['is_recorded'] ?? '') === '1';
    $callIdWithRec = trim((string)($fin['call_id_with_rec'] ?? ''));
    $endTime = $fin['received_at'] ?? null;

    if ($disposition === 'answered' || $duration > 0) {
        $status = 'completed';
    } elseif ($disposition === 'busy') {
        $status = 'busy';
    } elseif (in_array($disposition, ['no answer', 'no-answer', 'no_answer'], true)) {
        $status = 'no-answer';
    } elseif (in_array($disposition, ['cancelled', 'canceled'], true)) {
        $status = 'canceled';
    } else {
        $status = 'failed';
    }
} elseif ($respuesta) {
    $status = 'in-progress';
}

if ($grabacion) {
    $isRecorded = true;
    $callIdWithRec = trim((string)($grabacion['call_id_with_rec'] ?? $callIdWithRec));
}

responderJson([
    'ok' => true,
    'call' => [
        'provider' => 'ZADARMA',
        'pbx_call_id' => $pbxCallId,
        'status' => $status,
        'destination' => (string)($inicioSeleccionado['destination'] ?? $destino),
        'internal' => $extension,
        'start_time' => $inicioSeleccionado['call_start'] ?? null,
        'answer_time' => $respuesta['received_at'] ?? null,
        'end_time' => $endTime,
        'duration' => $duration,
        'disposition' => $disposition,
        'status_code' => $fin['status_code'] ?? null,
        'is_recorded' => $isRecorded,
        'call_id_with_rec' => $callIdWithRec,
    ]
]);
