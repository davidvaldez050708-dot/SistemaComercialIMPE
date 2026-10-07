<?php
session_start();

require_once dirname(__DIR__, 2) . '/app/services/ZadarmaCallLookupService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/TelefoniaExtensionService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/ZadarmaWebhookEventStoreService.php';

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

    $inicio = strtotime((string)($respuesta['received_at'] ?? ''));
    $finTimestamp = strtotime((string)($fin['received_at'] ?? ''));

    if (
        $inicio === false ||
        $finTimestamp === false ||
        $finTimestamp <= $inicio
    ) {
        return 0;
    }

    return max(1, $finTimestamp - $inicio);
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
$destino = trim((string)($_POST['destination'] ?? ''));
$desdeUnix = max(0, (int)($_POST['since'] ?? 0));
$duracionCliente = max(0, (int)($_POST['duration_client'] ?? 0));

if (
    $seguimientoId <= 0 ||
    $interaccionId <= 0 ||
    ($pbxCallId !== '' && !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId))
) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Los datos de la llamada o de la interacción no son válidos.'
    ], 422);
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

$inicio = null;
$respuesta = null;
$fin = null;
$grabacion = null;
$eventosLlamada = [];

if ($pbxCallId !== '') {
    try {
        $eventosLlamada =
            (new ZadarmaWebhookEventStoreService())
                ->obtenerPorPbxCallId($pbxCallId);
    } catch (Throwable $errorStore) {
        error_log('[zadarma_vincular_store] ' . $errorStore->getMessage());
    }

    /*
     * Respaldo para llamadas antiguas o contingencia de almacenamiento.
     */
    if (empty($eventosLlamada) && is_file($logPath)) {
        $lineas = file(
            $logPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];

        foreach ($lineas as $linea) {
            $fila = json_decode($linea, true);
            if (
                is_array($fila) &&
                hash_equals(
                    $pbxCallId,
                    trim((string)($fila['pbx_call_id'] ?? ''))
                )
            ) {
                $eventosLlamada[] = $fila;
            }
        }
    }

    foreach ($eventosLlamada as $fila) {
        $evento = (string)($fila['event'] ?? '');
        if ($evento === 'NOTIFY_OUT_START') {
            $inicio = $fila;
        } elseif ($evento === 'NOTIFY_ANSWER') {
            $respuesta = $fila;
        } elseif ($evento === 'NOTIFY_OUT_END') {
            $fin = $fila;
        } elseif ($evento === 'NOTIFY_RECORD') {
            $grabacion = $fila;
        }
    }
}

$estadistica = null;
if (!$inicio || !$fin || $pbxCallId === '') {
    try {
        $lookup = new ZadarmaCallLookupService();

        if ($pbxCallId !== '') {
            $estadistica = $lookup->buscarPorPbxCallId($pbxCallId);
        } elseif ($destino !== '') {
            $estadistica = $lookup->buscarSalienteReciente(
                $extension,
                $destino,
                $desdeUnix > 0 ? $desdeUnix : (time() - 300)
            );
        }

        if ($estadistica) {
            $pbxCallId = trim((string)($estadistica['pbx_call_id'] ?? $pbxCallId));
            $callStart = trim((string)($estadistica['callstart'] ?? ''));
            $segundos = max(0, (int)($estadistica['seconds'] ?? 0));
            $inicioTimestamp = $callStart !== '' ? strtotime($callStart) : false;

            if (!$inicio) {
                $inicio = [
                    'event' => 'STATISTICS_OUT_START',
                    'internal' => $extension,
                    'destination' => (string)($estadistica['destination'] ?? $destino),
                    'call_start' => $callStart,
                    'pbx_call_id' => $pbxCallId,
                ];
            }

            if (!$fin) {
                $fin = [
                    'event' => 'STATISTICS_OUT_END',
                    'internal' => $extension,
                    'destination' => (string)($estadistica['destination'] ?? $destino),
                    'call_start' => $callStart,
                    'pbx_call_id' => $pbxCallId,
                    'duration' => (string)$segundos,
                    'disposition' => (string)($estadistica['disposition'] ?? ''),
                    'is_recorded' => !empty($estadistica['is_recorded']) ? '1' : '0',
                    'call_id_with_rec' => (string)($estadistica['call_id'] ?? ''),
                    'received_at' => date('c'),
                ];
            }
        }
    } catch (Throwable $error) {
        error_log('[zadarma_vincular_estadisticas] ' . $error->getMessage());
    }
}

if ($pbxCallId === '' || !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'El proveedor de telefonía todavía está publicando el identificador de la llamada.'
    ], 409);
}

if (!$inicio) {
    responderJson([
        'ok' => false,
        'mensaje' => 'El proveedor de telefonía todavía está publicando el inicio de la llamada.'
    ], 409);
}

if (trim((string)($inicio['internal'] ?? '')) !== $extension) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La llamada no corresponde a la extensión Zadarma asignada a tu usuario.'
    ], 403);
}

if (!$fin) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La llamada todavía no termina de procesarse en el proveedor de telefonía.'
    ], 409);
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
            id_externo,
            notas
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
    $disposicion = strtoupper(trim((string)($fin['disposition'] ?? '')));
    $huboRespuesta =
        $respuesta !== null ||
        in_array($disposicion, ['ANSWERED', 'ANSWER', 'CONNECTED', 'SUCCESS'], true);

    /*
     * La duración contabilizable debe representar conversación real.
     * El cronómetro del navegador incluye timbrado, por lo que solo sirve
     * como respaldo cuando Zadarma confirma que la llamada fue contestada
     * pero todavía no publica una duración positiva.
     */
    $duracionProveedor = max(
        duracionConversacion($respuesta, $fin),
        max(0, (int)($fin['duration'] ?? 0))
    );
    $duracion = $duracionProveedor;

    if ($huboRespuesta && $duracion <= 0) {
        $duracion = $duracionCliente;
    }

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

    /*
     * Una verificación declarada por el Analista solo conserva su marca de
     * efectividad cuando Zadarma confirma que la llamada fue contestada.
     * Con esto un resultado manual favorable no puede convertir una llamada
     * sin respuesta en verificación efectiva.
     */
    $notasInteraccion = (string)($interaccion['notas'] ?? '');

    $notasInteraccion = str_replace(
        '[VERIFICACION_PENDIENTE_TELEFONIA]',
        '',
        $notasInteraccion
    );

    if (
        strpos($notasInteraccion, '[VERIFICACION_EFECTIVA]') !== false &&
        !$huboRespuesta
    ) {
        $notasInteraccion = str_replace(
            '[VERIFICACION_EFECTIVA]',
            '',
            $notasInteraccion
        );
        $notasInteraccion = preg_replace(
            '/^Verificación obtenida:\s*.*$/miu',
            'Verificación no contabilizada: el proveedor de telefonía no registró respuesta.',
            $notasInteraccion
        );
        $notasInteraccion = trim((string)$notasInteraccion);
    }

    $sqlActualizar = "UPDATE interacciones_vinculacion
        SET fecha_inicio = ?,
            fecha_fin = ?,
            duracion_segundos = ?,
            proveedor_externo = ?,
            id_externo = ?,
            notas = ?
        WHERE id = ?
          AND seguimiento_id = ?
          AND usuario_id = ?
          AND canal = 'LLAMADA_IP'";
    $stmtActualizar = $connection->prepare($sqlActualizar);
    $stmtActualizar->bind_param(
        'ssisssiii',
        $fechaInicio,
        $fechaFin,
        $duracion,
        $proveedor,
        $pbxCallId,
        $notasInteraccion,
        $interaccionId,
        $seguimientoId,
        $usuarioId
    );
    $stmtActualizar->execute();

    $verificacionEfectiva =
        $huboRespuesta &&
        strpos($notasInteraccion, '[VERIFICACION_EFECTIVA]') !== false;
    $yaContabilizadaHoy = false;

    if ($verificacionEfectiva) {
        $fechaConteo = substr((string)$fechaInicio, 0, 10);
        $sqlDuplicada = "SELECT COUNT(*) AS total
            FROM interacciones_vinculacion
            WHERE seguimiento_id = ?
              AND usuario_id = ?
              AND id <> ?
              AND canal = 'LLAMADA_IP'
              AND DATE(fecha_inicio) = ?
              AND notas LIKE '%[VERIFICACION_EFECTIVA]%'
              AND TRIM(COALESCE(proveedor_externo, '')) <> ''
              AND TRIM(COALESCE(id_externo, '')) <> ''
              AND COALESCE(duracion_segundos, 0) > 0";
        $stmtDuplicada = $connection->prepare($sqlDuplicada);
        $stmtDuplicada->bind_param(
            'iiis',
            $seguimientoId,
            $usuarioId,
            $interaccionId,
            $fechaConteo
        );
        $stmtDuplicada->execute();
        $filaDuplicada = $stmtDuplicada->get_result()->fetch_assoc() ?: [];
        $yaContabilizadaHoy = (int)($filaDuplicada['total'] ?? 0) > 0;
    }

    responderJson([
        'ok' => true,
        'mensaje' => 'La llamada quedó vinculada con la interacción exacta.',
        'interaccion_id' => $interaccionId,
        'pbx_call_id' => $pbxCallId,
        'duracion_segundos' => $duracion,
        'estado_zadarma' => (string)($fin['disposition'] ?? ''),
        'hubo_respuesta' => $huboRespuesta,
        'verificacion_efectiva' => $verificacionEfectiva,
        'institucion_ya_contabilizada_hoy' => $yaContabilizadaHoy,
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
        'mensaje' => 'No se pudo vincular la llamada con la interacción.',
        'detalle' => $e->getMessage(),
    ], 500);
}
