<?php

/**
 * Configura el webhook de llamadas PBX de Zadarma para una URL HTTPS pública.
 *
 * Uso:
 *   php tools/configurar_zadarma_webhook.php https://dominio/SistemaComercialIMPE/prueba_telefonia/api/zadarma_webhook.php
 *
 * El script no imprime la API Key ni el Secret.
 */

$rootPath = dirname(__DIR__);
$configPath = $rootPath . '/config/zadarma_config.php';
$autoloadPath = $rootPath . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Este script debe ejecutarse desde la terminal.\n");
    exit(1);
}

$url = trim((string)($argv[1] ?? ''));

if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || stripos($url, 'https://') !== 0) {
    fwrite(STDERR, "ERROR: Indica una URL HTTPS válida para el webhook.\n");
    fwrite(STDERR, "Ejemplo:\n");
    fwrite(STDERR, "php tools/configurar_zadarma_webhook.php https://dominio/SistemaComercialIMPE/prueba_telefonia/api/zadarma_webhook.php\n");
    exit(1);
}

if (!is_file($configPath)) {
    fwrite(STDERR, "ERROR: No existe config/zadarma_config.php\n");
    exit(1);
}

if (!is_file($autoloadPath)) {
    fwrite(STDERR, "ERROR: No existe vendor/autoload.php. Ejecuta composer install.\n");
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

function respuestaApiZadarma(string $body, string $paso): array
{
    $data = json_decode($body, true);

    if (!is_array($data)) {
        throw new RuntimeException($paso . ': Zadarma devolvió una respuesta no válida.');
    }

    if (($data['status'] ?? '') !== 'success') {
        throw new RuntimeException(
            $paso . ': ' . (string)($data['message'] ?? 'Zadarma rechazó la solicitud.')
        );
    }

    return $data;
}

try {
    $api = new \Zadarma_API\Api($apiKey, $apiSecret, false);

    echo "=== CONFIGURACIÓN WEBHOOK ZADARMA ===\n\n";
    echo "URL: {$url}\n\n";

    $bodyUrl = $api->call(
        '/v1/pbx/callinfo/url/',
        ['url' => $url],
        'post'
    );
    respuestaApiZadarma((string)$bodyUrl, 'Registrar URL');
    echo "[OK] URL de notificaciones registrada\n";

    $bodyNotificaciones = $api->call(
        '/v1/pbx/callinfo/notifications/',
        [
            'notify_start' => 'true',
            'notify_internal' => 'true',
            'notify_end' => 'true',
            'notify_out_start' => 'true',
            'notify_out_end' => 'true',
            'notify_answer' => 'true',
        ],
        'post'
    );
    respuestaApiZadarma((string)$bodyNotificaciones, 'Activar notificaciones');

    echo "[OK] NOTIFY_START activado\n";
    echo "[OK] NOTIFY_INTERNAL activado\n";
    echo "[OK] NOTIFY_ANSWER activado\n";
    echo "[OK] NOTIFY_END activado\n";
    echo "[OK] NOTIFY_OUT_START activado\n";
    echo "[OK] NOTIFY_OUT_END activado\n\n";
    echo "Webhook listo. Realiza una llamada corta para comprobar los eventos.\n";
    exit(0);
} catch (\Zadarma_API\ApiException $e) {
    fwrite(STDERR, '[ERROR API] ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}
