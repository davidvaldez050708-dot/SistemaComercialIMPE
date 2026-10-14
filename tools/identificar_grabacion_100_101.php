<?php
/**
 * Identifica candidatas de grabación para una llamada interna por API Zadarma.
 * Sólo lectura: NO modifica ninguna interacción ni descarga/audio.
 * Nunca imprime pbx_call_id, call_id, tokens o números externos.
 *
 * php tools/identificar_grabacion_100_101.php \
 *  "/home/usuario/domains/dominio/public_html" 100 101 2026-10-14T10:49 11
 */
declare(strict_types=1);

function normalizarSippbx($v): string {
    return preg_replace('/\D+/', '', trim((string)$v)) ?: '';
}

function evaluarCandidata(array $fila, string $origen, string $destino, DateTimeImmutable $hora, int $duracion): array
{
    $fechaCadena = trim((string)($fila['callstart'] ?? ''));
    $segundos = max(0, (int)($fila['seconds'] ?? -1));
    $sip = normalizarSippbx($fila['sip'] ?? '');
    $dest = normalizarSippbx($fila['destination'] ?? '');
    $direccion = $sip === $origen && $dest === $destino ? '100→101'
        : ($sip === $destino && $dest === $origen ? '101→100'
        : ($sip === $origen || $sip === $destino || $dest === $destino ? 'EXT_RELACIONADA' : 'OTRA'));
    $grabada = filter_var($fila['is_recorded'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $pbx = trim((string)($fila['pbx_call_id'] ?? ''));
    $idAudio = trim((string)($fila['call_id'] ?? ''));

    $intervaloSeg = null;
    // La API regresa callstart sin offset; probar sus dos interpretaciones
    // posibles y reportar explícitamente cuál encaja en la ventana.
    foreach (['America/Mexico_City', 'UTC'] as $zona) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fechaCadena, new DateTimeZone($zona));
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $fechaCadena) continue;
        $delta = abs($dt->getTimestamp() - $hora->getTimestamp());
        $intervaloSeg = $intervaloSeg === null ? $delta : min($intervaloSeg, $delta);
    }
    $ajusteDuracion = abs($segundos - $duracion);
    $enHorario = $intervaloSeg !== null && $intervaloSeg <= 5 * 60;
    $coincidencia = $grabada && $direccion === '100→101' &&
        $enHorario && $ajusteDuracion <= 2 && $pbx !== '';

    return [
        'fecha_api' => $fechaCadena,
        'duracion' => $segundos,
        'direccion' => $direccion,
        'grabada' => $grabada,
        'pbx_disponible' => $pbx !== '',
        'id_audio_disponible' => $idAudio !== '',
        'delta_seg' => $intervaloSeg,
        'delta_duracion' => $ajusteDuracion,
        'coincidencia' => $coincidencia,
        'relacionada' => $direccion !== 'OTRA',
    ];
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo CLI');
}
if (($argv[1] ?? '') === '--self-test') {
    $hora = new DateTimeImmutable('2026-10-14T10:49:00-06:00');
    $test = evaluarCandidata([
        'callstart' => '2026-10-14 10:49:03', 'seconds' => 11,
        'sip' => '100', 'destination' => '101', 'is_recorded' => 'true',
        'pbx_call_id' => 'pbx_prueba_123456', 'call_id' => 'audio_prueba_123'
    ], '100', '101', $hora, 11);
    if (!$test['coincidencia'] || $test['delta_seg'] !== 3) exit(1);
    $otra = evaluarCandidata([
        'callstart' => '2026-10-14 10:49:03', 'seconds' => 11,
        'sip' => '101', 'destination' => '100', 'is_recorded' => 'true',
        'pbx_call_id' => 'otro'
    ], '100', '101', $hora, 11);
    if ($otra['coincidencia']) exit(2);
    $sinPbx = evaluarCandidata([
        'callstart' => '2026-10-14 10:49:03', 'seconds' => 11,
        'sip' => '100', 'destination' => '101', 'is_recorded' => 'true',
    ], '100', '101', $hora, 11);
    if ($sinPbx['coincidencia']) exit(3);
    echo "OK: criterios de identificación (hora, dirección, duración, PBX)\n";
    exit(0);
}

$root = realpath((string)($argv[1] ?? ''));
$origen = (string)($argv[2] ?? '');
$destino = (string)($argv[3] ?? '');
$horaCadena = (string)($argv[4] ?? '');
$duracion = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 86400]
]);
if (
    count($argv) !== 6 || !$root ||
    !is_file("$root/config/private_config.php") ||
    !preg_match('/^\d{3,6}$/D', $origen) ||
    !preg_match('/^\d{3,6}$/D', $destino) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $horaCadena) ||
    $duracion === false
) {
    fwrite(STDERR, "Uso: php tools/identificar_grabacion_100_101.php RUTA_PUBLICA EXTENSION_ORIGEN EXTENSION_DESTINO YYYY-MM-DDTHH:MM SEGUNDOS\n");
    exit(2);
}
$zonaMx = new DateTimeZone('America/Mexico_City');
$hora = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $horaCadena, $zonaMx);
if (!$hora || $hora->format('Y-m-d\TH:i') !== $horaCadena) {
    fwrite(STDERR, "Fecha inválida.\n");
    exit(2);
}

echo "BÚSQUEDA DE AUDIO ($origen → $destino)\n";
echo "Llamada CRM: " . $hora->format('Y-m-d H:i') .
    " México; duración $duracion s\n";

try {
    require_once "$root/config/private_config.php";
    $configPath = impeRutaConfigPrivada('zadarma_config.php');
    if (!$configPath || !is_file($configPath) || !is_file("$root/vendor/autoload.php")) {
        throw new RuntimeException('No están disponibles las credenciales o el autoload privados.');
    }
    $config = require $configPath;
    $key = trim((string)($config['api_key'] ?? ''));
    $secret = trim((string)($config['api_secret'] ?? ''));
    if ($key === '' || $secret === '') {
        throw new RuntimeException('La API no tiene credenciales configuradas.');
    }
    require_once "$root/vendor/autoload.php";
    $api = new \Zadarma_API\Api($key, $secret, false);

    // Ventana que cubre API en UTC y en México, porque el formato callstart
    // carece de desplazamiento horario.
    $desde = $hora->modify('-20 minutes')->format('Y-m-d H:i:s');
    $hasta = $hora->setTimezone(new DateTimeZone('UTC'))
        ->modify('+20 minutes')->format('Y-m-d H:i:s');
    $raw = (string)$api->call('/v1/statistics/pbx/', [
        'start' => $desde,
        'end' => $hasta,
        'version' => 2,
        'limit' => 1000
    ], 'get');
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        throw new RuntimeException('La API no devolvió estadísticas utilizables.');
    }
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
    $candidatas = [];
    foreach ($stats as $row) {
        if (!is_array($row)) continue;
        $evaluada = evaluarCandidata($row, $origen, $destino, $hora, $duracion);
        if ($evaluada['relacionada'] && $evaluada['delta_seg'] !== null && $evaluada['delta_seg'] <= 1200) {
            $candidatas[] = $evaluada;
        }
    }
    usort($candidatas, static function(array $a,array $b): int {
        return ($a['delta_seg'] ?? 999999) <=> ($b['delta_seg'] ?? 999999);
    });
    echo "Estadísticas consultadas: " . count($stats) . " registros\n";
    echo "Llamadas cercanas relacionadas: " . count($candidatas) . "\n";
    $iguales = 0;
    foreach ($candidatas as $i => $fila) {
        if ($fila['coincidencia']) $iguales++;
        echo "  Candidata " . ($i + 1) .
            ": hora_API=" . $fila['fecha_api'] .
            ", direccion=" . $fila['direccion'] .
            ", duracion=" . $fila['duracion'] . "s" .
            ", diferencia_hora=" . $fila['delta_seg'] . "s" .
            ", grabada=" . ($fila['grabada'] ? 'SI' : 'NO') .
            ", PBX_ID=" . ($fila['pbx_disponible'] ? 'PRESENTE' : 'AUSENTE') .
            ", CALL_ID=" . ($fila['id_audio_disponible'] ? 'PRESENTE' : 'AUSENTE') .
            ", criterios=" . ($fila['coincidencia'] ? 'COINCIDEN' : 'NO_COINCIDEN') . "\n";
    }
    echo "Coincidencias completas: $iguales\n";
    echo $iguales === 1
        ? "Una candidata supera los filtros. AÚN NO VINCULADA: falta corroborar contra la sesión y la grabación concreta.\n"
        : "Vinculación detenida: no existe una candidata inequívoca con estos filtros.\n";
    if (count($stats) >= 1000) {
        echo "AVISO: la API alcanzó su límite de resultados; buscar por otra ventana.\n";
    }
    echo "No se escribió en BD ni se descargaron audios.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Diagnóstico no disponible (" . get_class($error) . "). No se hicieron cambios.\n");
    exit(1);
}
