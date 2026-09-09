<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Sesión no activa.');
}

$configPath = dirname(__DIR__, 2) . '/config/voip_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Falta config/voip_config.php.');
}

$config = require $configPath;
$accountSid = trim((string)($config['account_sid'] ?? ''));
$authToken = trim((string)($config['auth_token'] ?? ''));
$recordingSid = trim((string)($_GET['sid'] ?? ''));

if (!preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) || $authToken === '') {
    http_response_code(500);
    exit('Configuración de Twilio incompleta.');
}

if (!preg_match('/^RE[a-fA-F0-9]{32}$/', $recordingSid)) {
    http_response_code(400);
    exit('Recording SID no válido.');
}

$url = sprintf(
    'https://api.twilio.com/2010-04-01/Accounts/%s/Recordings/%s.mp3',
    rawurlencode($accountSid),
    rawurlencode($recordingSid)
);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERPWD => $accountSid . ':' . $authToken,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
]);

$audio = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

if ($audio === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    exit($error !== '' ? $error : 'No se pudo obtener la grabación.');
}

header('Content-Type: ' . ($contentType !== '' ? $contentType : 'audio/mpeg'));
header('Content-Length: ' . strlen($audio));
header('Content-Disposition: inline; filename="grabacion-' . $recordingSid . '.mp3"');
header('Cache-Control: private, max-age=60');

echo $audio;
