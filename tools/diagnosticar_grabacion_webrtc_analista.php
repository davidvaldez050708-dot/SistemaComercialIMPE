<?php
/**
 * Informe de sólo lectura para verificar la correlación de una llamada
 * WebRTC con eventos PBX firmados. No muestra números, nombres, IDs o audio.
 *
 * Uso:
 * php tools/diagnosticar_grabacion_webrtc_analista.php /ruta/public_html 101 2026-10-14T10:49
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo CLI.');
}
$root = realpath((string)($argv[1] ?? ''));
$extension = (string)($argv[2] ?? '');
$horaLocal = (string)($argv[3] ?? '');
if ($root === false ||
    !is_file($root . '/config/db_connection.php') ||
    !preg_match('/^\d{3,6}$/D', $extension) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $horaLocal) ||
    count($argv) !== 4) {
    fwrite(STDERR, "Uso: php tools/diagnosticar_grabacion_webrtc_analista.php /ruta/public_html EXTENSION YYYY-MM-DDTHH:MM\n");
    exit(2);
}
require_once $root . '/config/db_connection.php';
date_default_timezone_set('America/Mexico_City');
$local = DateTimeImmutable::createFromFormat(
    '!Y-m-d\TH:i',
    $horaLocal,
    new DateTimeZone('America/Mexico_City')
);
if (!$local || $local->format('Y-m-d\TH:i') !== $horaLocal) {
    fwrite(STDERR, "Fecha no válida.\n");
    exit(2);
}
$utc = $local->setTimezone(new DateTimeZone('UTC'));
echo "Hora solicitada México: " . $local->format('Y-m-d H:i') . "\n";
echo "Hora equivalente UTC: " . $utc->format('Y-m-d H:i') . "\n";
try {
    $db = (new Database())->connect();
    $tz = $db->query("SELECT NOW() AS reloj_mysql, UTC_TIMESTAMP() AS reloj_utc")->fetch_assoc();
    $relojMysql = strtotime((string)$tz['reloj_mysql']);
    $relojUtc = strtotime((string)$tz['reloj_utc']);
    $diferencia = (int)round(($relojMysql - $relojUtc) / 3600);
    echo "Diferencia reloj MySQL-UTC (aprox. horas): $diferencia\n";

    $localFrom = $local->modify('-15 minutes')->format('Y-m-d H:i:s');
    $localTo = $local->modify('+15 minutes')->format('Y-m-d H:i:s');
    $utcFrom = $utc->modify('-15 minutes')->format('Y-m-d H:i:s');
    $utcTo = $utc->modify('+15 minutes')->format('Y-m-d H:i:s');

    $tabla = $db->query("SHOW TABLES LIKE 'telefonia_analista_webrtc_sesiones'");
    if ($tabla && $tabla->num_rows > 0) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cantidad
               FROM telefonia_analista_webrtc_sesiones
              WHERE extension = ?
                AND ((fecha_inicio BETWEEN ? AND ?)
                  OR (fecha_inicio BETWEEN ? AND ?))"
        );
        $stmt->bind_param('sssss', $extension, $localFrom, $localTo, $utcFrom, $utcTo);
        $stmt->execute();
        $cantidad = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo "Sesiones SDK cercanas (sin identificadores): " . (int)($cantidad['cantidad'] ?? 0) . "\n";
    } else {
        echo "Tabla SDK: no disponible\n";
    }
    $tabla = $db->query("SHOW TABLES LIKE 'telefonia_zadarma_eventos'");
    if (!$tabla || $tabla->num_rows === 0) {
        echo "Tabla de eventos PBX: no disponible\n";
        exit(0);
    }
    // Primero localizar PBX IDs dirigidos a la extensión, sin mostrarlos.
    $stmt = $db->prepare(
        "SELECT DISTINCT pbx_call_id
           FROM telefonia_zadarma_eventos
          WHERE internal = ?
            AND evento = 'NOTIFY_INTERNAL'
            AND ((received_at BETWEEN ? AND ?)
              OR (received_at BETWEEN ? AND ?))
          LIMIT 50"
    );
    $stmt->bind_param('sssss', $extension, $localFrom, $localTo, $utcFrom, $utcTo);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'pbx_call_id');
    $stmt->close();
    echo "Llamadas PBX notificadas hacia extensión $extension en ventana: " . count($ids) . "\n";
    if (!$ids) {
        echo "Sin eventos PBX para esa extensión y franja: revisar webhook y datos de Zadarma.\n";
        exit(0);
    }
    $pbxConRecord = 0;
    $pbxConIdAudio = 0;
    foreach ($ids as $id) {
        $stmt = $db->prepare(
            "SELECT
                SUM(CASE WHEN evento = 'NOTIFY_RECORD' THEN 1 ELSE 0 END) AS record_notificado,
                SUM(CASE WHEN TRIM(COALESCE(call_id_with_rec, '')) <> '' THEN 1 ELSE 0 END) AS id_audio_presente
               FROM telefonia_zadarma_eventos WHERE pbx_call_id = ?"
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($fila['record_notificado'] ?? 0) > 0) $pbxConRecord++;
        if ((int)($fila['id_audio_presente'] ?? 0) > 0) $pbxConIdAudio++;
    }
    echo "Candidatas con NOTIFY_RECORD: $pbxConRecord\n";
    echo "Candidatas con identificador de grabación: $pbxConIdAudio\n";
    if (count($ids) !== 1) {
        echo "No es seguro asociar automáticamente una grabación: cero o varias candidatas.\n";
    } else {
        echo "Sólo una candidata PBX en la ventana; antes de vincular confirmar origen y duración.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo completar el diagnóstico: " . get_class($e) . " (código " . (int)$e->getCode() . ").\n");
    exit(1);
}
