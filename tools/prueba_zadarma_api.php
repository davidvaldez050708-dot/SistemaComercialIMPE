<?php

/**
 * Prueba técnica local de Zadarma.
 *
 * Ejecutar desde la raíz del proyecto:
 *   php tools/prueba_zadarma_api.php
 *
 * Requiere el archivo privado config/zadarma_config.php.
 * Nunca imprime la API Key, el Secret ni la clave WebRTC completa.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/zadarma_config.php';

if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: No existe config/zadarma_config.php\n");
    exit(1);
}

$config = require $configPath;

$apiKey = trim((string)($config['api_key'] ?? ''));
$apiSecret = trim((string)($config['api_secret'] ?? ''));
$extension = trim((string)($config['pbx_extension'] ?? ''));

if ($apiKey === '' || $apiSecret === '' || $extension === '') {
    fwrite(STDERR, "ERROR: La configuración de Zadarma está incompleta.\n");
    exit(1);
}

try {
    $api = new \Zadarma_API\Api($apiKey, $apiSecret, false);

    echo "=== PRUEBA API ZADARMA ===\n\n";

    $balance = $api->getBalance();

    echo "[OK] Autenticación con Zadarma\n";
    echo 'Saldo: ' . ($balance->balance ?? 'N/D') . ' ' . ($balance->currency ?? '') . "\n\n";

    $webrtc = $api->getWebrtcKey($extension);

    if (!empty($webrtc->key)) {
        echo "[OK] WebRTC habilitado\n";
        echo "Extensión: {$extension}\n";
        echo "Clave WebRTC recibida correctamente.\n";
        echo 'Longitud de clave: ' . strlen((string)$webrtc->key) . " caracteres\n";
        exit(0);
    }

    fwrite(STDERR, "[ERROR] Zadarma no devolvió una clave WebRTC válida.\n");
    exit(1);
} catch (\Zadarma_API\ApiException $e) {
    fwrite(STDERR, '[ERROR API] ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}
