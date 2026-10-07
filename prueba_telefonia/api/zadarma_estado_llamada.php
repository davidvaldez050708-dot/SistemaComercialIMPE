<?php
session_start();

require_once dirname(__DIR__, 2) . '/app/services/ZadarmaCallLookupService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/TelefoniaExtensionService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/ZadarmaWebhookEventStoreService.php';

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

function duracionConversacion(?array $respuesta, ?array $fin): int
{
    if (!$fin) {
        return 0;
    }

    $duracionProveedor = max(0, (int)($fin['duration'] ?? 0));
    if ($duracionProveedor > 0) {
        return $duracionProveedor;
    }

    if (!$respuesta) {
        return 0;
    }

    $inicioConversacion = strtotime((string)($respuesta['received_at'] ?? ''));
    $finConversacion = strtotime((string)($fin['received_at'] ?? ''));

    if (
        $inicioConversacion === false ||
        $finConversacion === false ||
        $finConversacion <= $inicioConversacion
    ) {
        return 0;
    }

    return max(1, $finConversacion - $inicioConversacion);
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rol = trim((string)($_SESSION['rol'] ?? ''));

if ($usuarioId <= 0) {
    responderJson(['ok' => false, 'mensaje' => 'Sesión no activa.'], 401);
}

if (
    !in_array(
        $rol,
        ['Analista de Datos', 'Asesor de Ventas'],
        true
    )
) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Tu perfil no tiene acceso al estado telefónico.'
    ], 403);
}

$destino = trim((string)($_GET['destination'] ?? ''));
$desdeSolicitado = (int)($_GET['since'] ?? 0);
$forzarFinal = (int)($_GET['final'] ?? 0) === 1;
$usarEstadisticas = (int)($_GET['stats'] ?? 0) === 1;

if ($destino === '' || strlen(soloDigitos($destino)) < 8) {
    responderJson(['ok' => false, 'mensaje' => 'Destino telefónico no válido.'], 422);
}

$rootPath = dirname(__DIR__, 2);
$logPath = $rootPath . '/storage/zadarma_webhooks.log';

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

$extension = trim(
    (string)($asignacionTelefonica['extension'] ?? '')
);

if (!preg_match('/^\d{3,6}$/', $extension)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La extensión Zadarma asignada no es válida.'
    ], 422);
}

$ahora = time();
$desde = $desdeSolicitado > 0
    ? max($ahora - 900, min($desdeSolicitado, $ahora + 5))
    : $ahora - 120;
$desde -= 10;

$registros = [];
$pbxCallId = '';
$inicioSeleccionado = null;
$eventStore = null;

try {
    $eventStore = new ZadarmaWebhookEventStoreService();
    $inicioPersistido = $eventStore->buscarInicioSalienteReciente(
        $extension,
        $destino,
        $desde
    );

    if ($inicioPersistido) {
        $pbxCallId = trim((string)($inicioPersistido['pbx_call_id'] ?? ''));
        $inicioSeleccionado = $inicioPersistido;

        if ($pbxCallId !== '') {
            $registros = $eventStore->obtenerPorPbxCallId($pbxCallId);
        }
    }
} catch (Throwable $errorStore) {
    error_log('[zadarma_estado_store] ' . $errorStore->getMessage());
}

/*
 * Compatibilidad con llamadas registradas antes de habilitar la tabla o
 * contingencia si la persistencia de eventos no estuviera disponible.
 */
if ($pbxCallId === '' && is_file($logPath)) {
    $lineas = file(
        $logPath,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    ) ?: [];

    foreach ($lineas as $linea) {
        $fila = json_decode($linea, true);
        if (is_array($fila)) {
            $registros[] = $fila;
        }
    }

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
}

if (
    $pbxCallId === '' &&
    $forzarFinal &&
    $usarEstadisticas
) {
    try {
        $estadistica = (new ZadarmaCallLookupService())->buscarSalienteReciente(
            $extension,
            $destino,
            $desdeSolicitado > 0 ? $desdeSolicitado : (time() - 300)
        );

        if ($estadistica) {
            $disposition = strtolower((string)($estadistica['disposition'] ?? ''));
            $status = 'failed';

            if ($disposition === 'answered') {
                $status = 'completed';
            } elseif ($disposition === 'busy') {
                $status = 'busy';
            } elseif (in_array($disposition, ['no answer', 'no-answer', 'no_answer'], true)) {
                $status = 'no-answer';
            } elseif (in_array($disposition, ['cancel', 'cancelled', 'canceled'], true)) {
                $status = 'canceled';
            }

            responderJson([
                'ok' => true,
                'call' => [
                    'provider' => 'ZADARMA',
                    'source' => 'statistics',
                    'pbx_call_id' => (string)($estadistica['pbx_call_id'] ?? ''),
                    'status' => $status,
                    'destination' => (string)($estadistica['destination'] ?? $destino),
                    'internal' => $extension,
                    'start_time' => $estadistica['callstart'] ?? null,
                    'answer_time' => null,
                    'end_time' => null,
                    'duration' => max(0, (int)($estadistica['seconds'] ?? 0)),
                    'disposition' => $disposition,
                    'status_code' => null,
                    'is_recorded' => !empty($estadistica['is_recorded']),
                    'record_ready' => false,
                    'call_id_with_rec' => (string)($estadistica['call_id'] ?? ''),
                ],
            ]);
        }
    } catch (Throwable $error) {
        error_log('[zadarma_estado_estadisticas] ' . $error->getMessage());
    }
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
    $duration = duracionConversacion($respuesta, $fin);
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

if ($forzarFinal && !$fin) {
    try {
        $estadistica = (new ZadarmaCallLookupService())->buscarPorPbxCallId($pbxCallId);
        if ($estadistica) {
            $disposition = strtolower((string)($estadistica['disposition'] ?? ''));
            $duration = max($duration, (int)($estadistica['seconds'] ?? 0));
            $isRecorded = $isRecorded || !empty($estadistica['is_recorded']);
            $callIdWithRec = trim((string)($estadistica['call_id'] ?? $callIdWithRec));

            if ($disposition === 'answered') {
                $status = 'completed';
            } elseif ($disposition === 'busy') {
                $status = 'busy';
            } elseif (in_array($disposition, ['no answer', 'no-answer', 'no_answer'], true)) {
                $status = 'no-answer';
            } elseif (in_array($disposition, ['cancel', 'cancelled', 'canceled'], true)) {
                $status = 'canceled';
            } else {
                $status = 'failed';
            }
        }
    } catch (Throwable $error) {
        error_log('[zadarma_estado_estadistica_final] ' . $error->getMessage());
    }
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
        'record_ready' => $grabacion !== null,
        'call_id_with_rec' => $callIdWithRec,
    ]
]);
