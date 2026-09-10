<?php

/**
 * Prueba técnica de recuperación de la última grabación recibida por webhook.
 *
 * Ejecutar desde la raíz del proyecto:
 *   php tools/prueba_zadarma_grabacion.php
 *
 * El script busca el último evento NOTIFY_RECORD, solicita a Zadarma un enlace
 * temporal y descarga el audio en storage para comprobar el flujo completo.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Este script debe ejecutarse desde la terminal.\n");
    exit(1);
}

$rootPath = dirname(__DIR__);
$configPath = $rootPath . '/config/zadarma_config.php';
$autoloadPath = $rootPath . '/vendor/autoload.php';
$logPath = $rootPath . '/storage/zadarma_webhooks.log';

if (!is_file($configPath)) {
    fwrite(STDERR, "ERROR: No existe config/zadarma_config.php\n");
    exit(1);
}

if (!is_file($autoloadPath)) {
    fwrite(STDERR, "ERROR: No existe vendor/autoload.php. Ejecuta composer install.\n");
    exit(1);
}

if (!is_file($logPath)) {
    fwrite(STDERR, "ERROR: Aún no existe storage/zadarma_webhooks.log\n");
    exit(1);
}

require_once $autoloadPath;
$config = require $configPath;

$apiKey = trim((string)($config['api_key'] ?? ''));
$apiSecret = trim((string)($config['api_secret'] ?? ''));

if ($apiKey === '' || $apiSecret === '') {
    fwrite(STDERR, "ERROR: La configuración privada de Zadarma está incompleta.\n");
    exit(1);
}

$lineas = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$ultimoRecord = null;

for ($i = count($lineas) - 1; $i >= 0; $i--) {
    $fila = json_decode($lineas[$i], true);
    if (!is_array($fila)) {
        continue;
    }

    if (
        ($fila['event'] ?? '') === 'NOTIFY_RECORD' &&
        !empty($fila['pbx_call_id']) &&
        !empty($fila['call_id_with_rec'])
    ) {
        $ultimoRecord = $fila;
        break;
    }
}

if (!$ultimoRecord) {
    fwrite(STDERR, "ERROR: No se encontró ningún NOTIFY_RECORD válido en el log.\n");
    exit(1);
}

$pbxCallId = trim((string)$ultimoRecord['pbx_call_id']);
$callId = trim((string)$ultimoRecord['call_id_with_rec']);

try {
    $api = new \Zadarma_API\Api($apiKey, $apiSecret, false);

    // Cuando conocemos call_id_with_rec debemos consultar únicamente por call_id.
    // Si se manda pbx_call_id, Zadarma puede devolver "links" (plural) en vez de
    // "link", porque una misma llamada PBX puede contener más de una grabación.
    $record = $api->getPbxRecord($callId, null, 300);
    $link = trim((string)($record->link ?? ''));
    $metodoConsulta = 'call_id';

    // Fallback defensivo: si el proveedor no devuelve link para call_id,
    // consultamos por pbx_call_id y tomamos la primera grabación disponible.
    if ($link === '') {
        $record = $api->getPbxRecord(null, $pbxCallId, 300);
        $links = is_array($record->links ?? null) ? $record->links : [];
        $link = trim((string)($links[0] ?? ''));
        $metodoConsulta = 'pbx_call_id';
    }

    if ($link === '' || filter_var($link, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Zadarma no devolvió un enlace válido para la grabación.');
    }

    $rutaTemporal = $rootPath . '/storage/zadarma_prueba_ultima.tmp';
    $fp = fopen($rutaTemporal, 'wb');

    if ($fp === false) {
        throw new RuntimeException('No fue posible crear el archivo temporal de audio.');
    }

    $ch = curl_init($link);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FAILONERROR => false,
        CURLOPT_USERAGENT => 'SistemaComercialIMPE-ZadarmaTest/1.0',
    ]);

    $ok = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '')));
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $httpStatus < 200 || $httpStatus >= 300) {
        @unlink($rutaTemporal);
        throw new RuntimeException(
            $curlError !== '' ? $curlError : 'La descarga respondió HTTP ' . $httpStatus
        );
    }

    $bytes = is_file($rutaTemporal) ? (int)filesize($rutaTemporal) : 0;
    if ($bytes <= 0) {
        @unlink($rutaTemporal);
        throw new RuntimeException('La grabación descargada está vacía.');
    }

    $extension = 'mp3';
    if (str_contains($contentType, 'wav')) {
        $extension = 'wav';
    } elseif (str_contains($contentType, 'ogg')) {
        $extension = 'ogg';
    } elseif (str_contains($contentType, 'mpeg') || str_contains($contentType, 'mp3')) {
        $extension = 'mp3';
    }

    $rutaFinal = $rootPath . '/storage/zadarma_prueba_ultima.' . $extension;
    @unlink($rutaFinal);

    if (!rename($rutaTemporal, $rutaFinal)) {
        @unlink($rutaTemporal);
        throw new RuntimeException('No fue posible guardar la grabación descargada.');
    }

    echo "=== PRUEBA GRABACIÓN ZADARMA ===\n\n";
    echo "[OK] Evento NOTIFY_RECORD encontrado\n";
    echo "[OK] Enlace temporal solicitado a Zadarma\n";
    echo '[OK] Consulta resuelta por: ' . $metodoConsulta . "\n";
    echo "[OK] Grabación descargada correctamente\n";
    echo 'Archivo: storage/' . basename($rutaFinal) . "\n";
    echo 'Tamaño: ' . $bytes . " bytes\n";
    echo "Vigencia del enlace: 300 segundos\n";
    exit(0);
} catch (\Zadarma_API\ApiException $e) {
    fwrite(STDERR, '[ERROR API] ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}
