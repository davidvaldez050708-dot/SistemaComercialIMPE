<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$configPath = dirname(__DIR__, 2) . '/config/voip_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Falta config/voip_config.php.']);
    exit;
}

$config = require $configPath;
$tokenUrl = trim((string)($config['token_url'] ?? ''));

if ($tokenUrl === '' || !filter_var($tokenUrl, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'La URL de token de Twilio no está configurada.']);
    exit;
}

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);

$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo obtener el token de Twilio.',
        'detalle' => $error !== '' ? $error : 'HTTP ' . $status,
    ]);
    exit;
}

$data = json_decode($body, true);
if (!is_array($data) || empty($data['token'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'mensaje' => 'Twilio devolvió una respuesta de token no válida.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'token' => $data['token'],
    'identity' => $data['identity'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
