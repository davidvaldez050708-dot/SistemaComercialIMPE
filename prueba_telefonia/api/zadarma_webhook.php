<?php

date_default_timezone_set('America/Mexico_City');

// Verificación inicial exigida por Zadarma al registrar el webhook.
if (isset($_GET['zd_echo'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo (string)$_GET['zd_echo'];
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function responderWebhook(array $data, int $status = 200): void
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

function obtenerFirmaZadarma(): string
{
    if (!empty($_SERVER['HTTP_SIGNATURE'])) {
        return trim((string)$_SERVER['HTTP_SIGNATURE']);
    }

    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $nombre => $valor) {
            if (strcasecmp((string)$nombre, 'Signature') === 0) {
                return trim((string)$valor);
            }
        }
    }

    return '';
}

function cadenaFirmaEvento(string $evento, array $datos): string
{
    switch ($evento) {
        case 'NOTIFY_START':
        case 'NOTIFY_INTERNAL':
        case 'NOTIFY_END':
        case 'NOTIFY_IVR':
            return (string)($datos['caller_id'] ?? '')
                . (string)($datos['called_did'] ?? '')
                . (string)($datos['call_start'] ?? '');

        case 'NOTIFY_ANSWER':
            return (string)($datos['caller_id'] ?? '')
                . (string)($datos['destination'] ?? '')
                . (string)($datos['call_start'] ?? '');

        case 'NOTIFY_OUT_START':
        case 'NOTIFY_OUT_END':
            return (string)($datos['internal'] ?? '')
                . (string)($datos['destination'] ?? '')
                . (string)($datos['call_start'] ?? '');

        case 'NOTIFY_RECORD':
            return (string)($datos['pbx_call_id'] ?? '')
                . (string)($datos['call_id_with_rec'] ?? '');
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responderWebhook(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$rootPath = dirname(__DIR__, 2);
$configPath = $rootPath . '/config/zadarma_config.php';

if (!is_file($configPath)) {
    responderWebhook(['status' => 'error', 'message' => 'Zadarma config not found'], 500);
}

$config = require $configPath;
$apiSecret = trim((string)($config['api_secret'] ?? ''));

if ($apiSecret === '') {
    responderWebhook(['status' => 'error', 'message' => 'Zadarma secret not configured'], 500);
}

$evento = trim((string)($_POST['event'] ?? ''));
$eventosPermitidos = [
    'NOTIFY_START',
    'NOTIFY_INTERNAL',
    'NOTIFY_ANSWER',
    'NOTIFY_END',
    'NOTIFY_OUT_START',
    'NOTIFY_OUT_END',
    'NOTIFY_RECORD',
    'NOTIFY_IVR',
];

if (!in_array($evento, $eventosPermitidos, true)) {
    responderWebhook(['status' => 'error', 'message' => 'Unsupported event'], 400);
}

$firmaRecibida = obtenerFirmaZadarma();
$cadenaFirma = cadenaFirmaEvento($evento, $_POST);
$firmaEsperada = base64_encode(hash_hmac('sha1', $cadenaFirma, $apiSecret));

if ($firmaRecibida === '' || !hash_equals($firmaEsperada, $firmaRecibida)) {
    responderWebhook(['status' => 'error', 'message' => 'Invalid signature'], 403);
}

$camposPermitidos = [
    'event',
    'call_start',
    'pbx_call_id',
    'caller_id',
    'called_did',
    'destination',
    'internal',
    'duration',
    'disposition',
    'status_code',
    'is_recorded',
    'call_id_with_rec',
    'transfer_from',
    'transfer_type',
];

$registro = [
    'received_at' => date('c'),
];

foreach ($camposPermitidos as $campo) {
    if (array_key_exists($campo, $_POST)) {
        $registro[$campo] = is_scalar($_POST[$campo]) ? (string)$_POST[$campo] : null;
    }
}

$logPath = $rootPath . '/storage/zadarma_webhooks.log';
$linea = json_encode(
    $registro,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
) . PHP_EOL;

$archivo = @fopen($logPath, 'ab');
if ($archivo !== false) {
    if (flock($archivo, LOCK_EX)) {
        fwrite($archivo, $linea);
        fflush($archivo);
        flock($archivo, LOCK_UN);
    }
    fclose($archivo);
}

responderWebhook(['status' => 'success']);
