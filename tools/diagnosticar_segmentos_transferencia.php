<?php
/**
 * Inspección privada de SEGMENTOS PBX. Sólo lectura.
 * No muestra pbx_call_id, call_id_with_rec, teléfonos, grabaciones ni secretos.
 * Ejecutar desde SSH: php tools/diagnosticar_segmentos_transferencia.php
 *   RUTA_PUBLIC_HTML 100 101 YYYY-MM-DDTHH:MM
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = realpath((string)($argv[1] ?? ''));
$origen = (string)($argv[2] ?? '');
$destino = (string)($argv[3] ?? '');
$fecha = (string)($argv[4] ?? '');
if (count($argv) !== 5 || !$root ||
    !is_file($root . '/config/db_connection.php') ||
    !preg_match('/^[0-9]{3,6}$/D', $origen) ||
    !preg_match('/^[0-9]{3,6}$/D', $destino) ||
    !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D', $fecha)) {
    fwrite(STDERR, "Uso: php tools/diagnosticar_segmentos_transferencia.php PUBLIC_HTML 100 101 YYYY-MM-DDTHH:MM\n");
    exit(2);
}
$mx = new DateTimeZone('America/Mexico_City');
$utc = new DateTimeZone('UTC');
$local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fecha, $mx);
if (!$local || $local->format('Y-m-d\TH:i') !== $fecha) {
    fwrite(STDERR, "Fecha inválida.\n"); exit(2);
}
$fechas = [
    [$local->modify('-4 minutes')->format('Y-m-d H:i:s'),
     $local->modify('+4 minutes')->format('Y-m-d H:i:s')],
    [$local->setTimezone($utc)->modify('-4 minutes')->format('Y-m-d H:i:s'),
     $local->setTimezone($utc)->modify('+4 minutes')->format('Y-m-d H:i:s')]
];
echo "REVISIÓN SEGMENTOS $origen → $destino; ventana ±4 minutos; sólo lectura\n";
try {
    require_once $root . '/config/db_connection.php';
    $db = (new Database())->connect();
    // Identificadores usados exclusivamente en memoria para agrupar.
    $find = $db->prepare(
        "SELECT DISTINCT pbx_call_id FROM telefonia_zadarma_eventos
         WHERE pbx_call_id <> ''
           AND (internal = ? OR internal = ? OR destination_digits = ?)
           AND ((received_at BETWEEN ? AND ?) OR (received_at BETWEEN ? AND ?)
             OR (call_start BETWEEN ? AND ?) OR (call_start BETWEEN ? AND ?))
         ORDER BY pbx_call_id LIMIT 25"
    );
    $find->bind_param('sssssssssss',
        $origen,$destino,$destino,
        $fechas[0][0],$fechas[0][1],$fechas[1][0],$fechas[1][1],
        $fechas[0][0],$fechas[0][1],$fechas[1][0],$fechas[1][1]
    );
    $find->execute();
    $ids = array_column($find->get_result()->fetch_all(MYSQLI_ASSOC), 'pbx_call_id');
    $find->close();
    echo "Grupos candidatos: " . count($ids) . (count($ids)===25 ? " (LIMITADOS)" : "") . "\n";
    $query = $db->prepare(
        "SELECT evento, internal, destination_digits, call_id_with_rec,
                is_recorded, received_at, duration, payload_json
         FROM telefonia_zadarma_eventos WHERE pbx_call_id = ?
         ORDER BY id ASC LIMIT 200"
    );
    foreach ($ids as $i => $id) {
        $query->bind_param('s', $id);
        $query->execute();
        $rows = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        $uniqueAudio = [];
        $notifyRecord = 0;
        $leg = ['100'=>[], '101'=>[], 'otro'=>[]];
        $transferCount = 0;
        $hasOrigin = $hasDestination = false;
        $minTime = $maxTime = null;
        foreach ($rows as $row) {
            $type = strtoupper((string)$row['evento']);
            $extension = (string)($row['internal'] ?? '');
            $track = $extension === $origen ? '100'
                : ($extension === $destino ? '101' : 'otro');
            $leg[$track][$type] = ($leg[$track][$type] ?? 0) + 1;
            if ($track==='100') $hasOrigin=true;
            if ($track==='101') $hasDestination=true;
            if ($type === 'NOTIFY_RECORD') $notifyRecord++;
            $audio = trim((string)($row['call_id_with_rec'] ?? ''));
            if ($audio !== '') $uniqueAudio[$audio]=true;
            $payload = json_decode((string)($row['payload_json'] ?? ''),true);
            if (is_array($payload) && (string)($payload['transfer_from'] ?? '') === $origen &&
                $extension === $destino) $transferCount++;
            $when = (string)($row['received_at'] ?? '');
            if ($when !== '') {
                if ($minTime===null || $when<$minTime) $minTime=$when;
                if ($maxTime===null || $when>$maxTime) $maxTime=$when;
            }
        }
        echo "Grupo " . ($i+1) . ": ambos_ext=" .
            ($hasOrigin && $hasDestination?'sí':'no') .
            ", transferencias_100_a_101=$transferCount" .
            ", NOTIFY_RECORD=$notifyRecord, ids_audio_distintos=" .
            count($uniqueAudio) . ", eventos=" . count($rows) . "\n";
        foreach (['100','101','otro'] as $track) {
            if (!$leg[$track]) continue;
            ksort($leg[$track]);
            $pairs=[];
            foreach ($leg[$track] as $type=>$n) $pairs[]=$type.'='.$n;
            echo "  $track: " . implode(', ',$pairs) . "\n";
        }
        // La presencia de 1 ID para todos los eventos no implica un
        // archivo que contenga toda la llamada; no declarar cobertura.
    }
    $query->close();
    echo "DIAGNÓSTICO TERMINADO: ninguna grabación descargada, vinculada o divulgada.\n";
} catch (Throwable $e) {
    echo "CONSULTA FALLIDA: " . get_class($e) . " (sin cambios en datos).\n";
    exit(1);
}
