<?php
/**
 * Diagnóstico PRIVADO de sólo lectura para asociar una llamada interna.
 *
 * php tools/diagnosticar_pbx_grabacion_extensiones.php \
 *   "$HOME/domains/fundacionrededumex.org/public_html" 100 101 2026-10-14T10:49
 *
 * No descarga grabaciones, no vincula registros, no revela IDs/telefonos,
 * no cambia configuración del proveedor ni realiza INSERT/UPDATE/DDL.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo disponible por SSH.');
}

$root = realpath((string)($argv[1] ?? ''));
$origen = (string)($argv[2] ?? '');
$destino = (string)($argv[3] ?? '');
$fechaLocal = (string)($argv[4] ?? '');
if (
    count($argv) !== 5 ||
    $root === false ||
    !is_file($root . '/config/db_connection.php') ||
    !preg_match('/^[0-9]{3,6}$/D', $origen) ||
    !preg_match('/^[0-9]{3,6}$/D', $destino) ||
    !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $fechaLocal)
) {
    fwrite(STDERR, "Uso: php tools/diagnosticar_pbx_grabacion_extensiones.php RUTA_PUBLICA ORIGEN DESTINO YYYY-MM-DDTHH:MM\n");
    exit(2);
}

$mx = new DateTimeZone('America/Mexico_City');
$utc = new DateTimeZone('UTC');
$local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $fechaLocal, $mx);
if (!$local || $local->format('Y-m-d\TH:i') !== $fechaLocal) {
    fwrite(STDERR, "Fecha local inválida.\n");
    exit(2);
}
$fechaUtc = $local->setTimezone($utc);
$localMin = $local->modify('-20 minutes')->format('Y-m-d H:i:s');
$localMax = $local->modify('+20 minutes')->format('Y-m-d H:i:s');
$utcMin = $fechaUtc->modify('-20 minutes')->format('Y-m-d H:i:s');
$utcMax = $fechaUtc->modify('+20 minutes')->format('Y-m-d H:i:s');

echo "DIAGNÓSTICO PBX $origen → $destino (sólo lectura)\n";
echo "Hora México: " . $local->format('Y-m-d H:i') .
    " | Hora UTC: " . $fechaUtc->format('Y-m-d H:i') . "\n";

try {
    require_once $root . '/config/db_connection.php';
    $db = (new Database())->connect();
    $existe = $db->query("SHOW TABLES LIKE 'telefonia_zadarma_eventos'");
    if (!$existe || $existe->num_rows === 0) {
        echo "Eventos guardados: tabla no encontrada.\n";
    } else {
        $stmt = $db->prepare(
            "SELECT evento, COUNT(*) cantidad,
                    SUM(CASE WHEN internal = ? THEN 1 ELSE 0 END) AS origen,
                    SUM(CASE WHEN internal = ? THEN 1 ELSE 0 END) AS destino,
                    SUM(CASE WHEN destination_digits = ? THEN 1 ELSE 0 END) AS llamado
               FROM telefonia_zadarma_eventos
              WHERE (received_at BETWEEN ? AND ?)
                 OR (received_at BETWEEN ? AND ?)
                 OR (call_start BETWEEN ? AND ?)
                 OR (call_start BETWEEN ? AND ?)
              GROUP BY evento
              ORDER BY evento"
        );
        $stmt->bind_param(
            'sssssssssss',
            $origen,
            $destino,
            $destino,
            $localMin,
            $localMax,
            $utcMin,
            $utcMax,
            $localMin,
            $localMax,
            $utcMin,
            $utcMax
        );
        $stmt->execute();
        $eventos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$eventos) {
            echo "Eventos PBX cercanos (cualquier tipo): 0.\n";
        } else {
            echo "Eventos PBX cercanos, agrupados (sin identificadores):\n";
            foreach ($eventos as $e) {
                echo "  " . (string)$e['evento'] .
                    ": total=" . (int)$e['cantidad'] .
                    ", interno_origen=" . (int)$e['origen'] .
                    ", interno_destino=" . (int)$e['destino'] .
                    ", destino_marcado=" . (int)$e['llamado'] . "\n";
            }
        }

        // Revisar también si el archivo NOTIFY_RECORD llegó después de
        // la ventana de tiempo: sus campos no suelen incluir la extensión.
        $stmt = $db->prepare(
            "SELECT DISTINCT pbx_call_id
               FROM telefonia_zadarma_eventos
              WHERE (
                    (received_at BETWEEN ? AND ?)
                 OR (received_at BETWEEN ? AND ?)
                 OR (call_start BETWEEN ? AND ?)
                 OR (call_start BETWEEN ? AND ?)
              )
                AND (internal = ? OR internal = ?
                     OR destination_digits = ?)
                AND pbx_call_id <> ''
              LIMIT 100"
        );
        $stmt->bind_param(
            'sssssssssss',
            $localMin,
            $localMax,
            $utcMin,
            $utcMax,
            $localMin,
            $localMax,
            $utcMin,
            $utcMax,
            $origen,
            $destino,
            $destino
        );
        $stmt->execute();
        $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'pbx_call_id');
        $stmt->close();
        echo "Conversaciones PBX candidatas por extensión/ventana: " . count($ids);
        echo count($ids) >= 100 ? " (búsqueda limitada a 100)\n" : "\n";
        $conGrabacion = 0;
        $conIdAudio = 0;
        $q = $db->prepare(
            "SELECT
                 MAX(CASE WHEN evento = 'NOTIFY_RECORD' THEN 1 ELSE 0 END) AS existe_record,
                 MAX(CASE WHEN TRIM(COALESCE(call_id_with_rec, '')) <> '' THEN 1 ELSE 0 END) AS existe_audio
               FROM telefonia_zadarma_eventos
              WHERE pbx_call_id = ?"
        );
        foreach ($ids as $id) {
            $q->bind_param('s', $id);
            $q->execute();
            $fila = $q->get_result()->fetch_assoc() ?: [];
            $conGrabacion += (int)($fila['existe_record'] ?? 0);
            $conIdAudio += (int)($fila['existe_audio'] ?? 0);
        }
        $q->close();
        echo "  Con NOTIFY_RECORD asociado: $conGrabacion\n";
        echo "  Con call_id_with_rec asociado: $conIdAudio\n";

        $diaLocal = $local->format('Y-m-d');
        $diaUtc = $fechaUtc->format('Y-m-d');
        $stmt = $db->prepare(
            "SELECT COUNT(*) total
               FROM telefonia_zadarma_eventos
              WHERE DATE(received_at) IN (?, ?)"
        );
        $stmt->bind_param('ss', $diaLocal, $diaUtc);
        $stmt->execute();
        $totalDia = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        echo "Notificaciones PBX recibidas durante el día consultado: $totalDia\n";
    }
} catch (Throwable $e) {
    echo "Base de datos: consulta no completada (" . get_class($e) . ").\n";
}

// Comparación de sólo lectura con el API oficial. No utilizar el resultado
// para modificar ninguna interacción; la extensión, tiempo y duración
// deben corroborarse antes de vincular un audio.
try {
    require_once $root . '/config/private_config.php';
    $privada = impeRutaConfigPrivada('zadarma_config.php');
    if (!$privada || !is_file($privada) || !is_file($root . '/vendor/autoload.php')) {
        echo "Zadarma API: configuración o dependencias no disponibles.\n";
        exit(0);
    }
    $config = require $privada;
    $key = trim((string)($config['api_key'] ?? ''));
    $secret = trim((string)($config['api_secret'] ?? ''));
    if ($key === '' || $secret === '') {
        echo "Zadarma API: credenciales no configuradas.\n";
        exit(0);
    }
    require_once $root . '/vendor/autoload.php';
    $api = new \Zadarma_API\Api($key, $secret, false);

    $configRespuesta = json_decode((string)$api->call('/v1/pbx/callinfo/', [], 'get'), true);
    if (is_array($configRespuesta) && ($configRespuesta['status'] ?? '') === 'success') {
        $flags = $configRespuesta['notifications'] ?? [];
        echo "Webhook PBX configurado: " .
            (trim((string)($configRespuesta['url'] ?? '')) !== '' ? 'SÍ' : 'NO') . "\n";
        foreach (['notify_internal', 'notify_answer', 'notify_out_start', 'notify_out_end'] as $flag) {
            $valor = strtolower(trim((string)($flags[$flag] ?? '')));
            echo "  $flag: " . (in_array($valor, ['true', '1', 'on'], true) ? 'activo' : 'inactivo/no reportado') . "\n";
        }
    } else {
        echo "Configuración webhook: el proveedor no devolvió estado consultable.\n";
    }

    // Consultar ambos horarios para no depender del huso horario de la cuenta
    // Zadarma; omitir call_type para incluir entrantes y salientes.
    $inicio = $local->modify('-25 minutes')->format('Y-m-d H:i:s');
    $fin = $fechaUtc->modify('+25 minutes')->format('Y-m-d H:i:s');
    $statsRaw = $api->call(
        '/v1/statistics/pbx/',
        ['start' => $inicio, 'end' => $fin, 'version' => 2, 'limit' => 1000],
        'get'
    );
    $respuesta = json_decode((string)$statsRaw, true);
    if (!is_array($respuesta) || ($respuesta['status'] ?? '') !== 'success') {
        echo "Estadísticas Zadarma: respuesta no disponible/incorrecta.\n";
        exit(0);
    }
    $stats = is_array($respuesta['stats'] ?? null) ? $respuesta['stats'] : [];
    $enVentana = 0;
    $relacionadas = 0;
    $grabadas = 0;
    $duracionCercana = 0;
    foreach ($stats as $registro) {
        if (!is_array($registro)) continue;
        $hora = trim((string)($registro['callstart'] ?? ''));
        // Zadarma usa un timestamp sin offset de zona. Evaluarlo en las
        // dos franjas posibles (MX/UTC), sin inferir el timezone de su cuenta.
        $enHora = ($hora >= $localMin && $hora <= $localMax) ||
                  ($hora >= $utcMin && $hora <= $utcMax);
        if (!$enHora) continue;
        $enVentana++;
        $sip = trim((string)($registro['sip'] ?? ''));
        $llamado = preg_replace('/\D+/', '', (string)($registro['destination'] ?? '')) ?: '';
        $llama101 = ($sip === $origen || $sip === $destino ||
            $llamado === $destino);
        if (!$llama101) continue;
        $relacionadas++;
        $seconds = (int)($registro['seconds'] ?? 0);
        if ($seconds >= 6 && $seconds <= 16) $duracionCercana++;
        if (filter_var($registro['is_recorded'] ?? false, FILTER_VALIDATE_BOOLEAN)) $grabadas++;
    }
    echo "Zadarma estadísticas: entradas totales=" . count($stats) .
        ", en horario=" . $enVentana .
        ", relacionadas con $origen/$destino=" . $relacionadas .
        ", grabadas=" . $grabadas .
        ", con duración aprox. 11s=" . $duracionCercana . "\n";
    if (count($stats) >= 1000) {
        echo "ATENCIÓN: respuesta al límite de 1000 resultados; conteos incompletos.\n";
    }
    echo "No se ha vinculado ni descargado ninguna grabación.\n";
} catch (Throwable $e) {
    echo "Zadarma API: consulta no completada (" . get_class($e) . ").\n";
}
