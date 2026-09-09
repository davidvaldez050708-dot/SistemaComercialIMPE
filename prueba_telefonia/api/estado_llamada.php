<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$parentSid = trim((string)($_GET['parent_sid'] ?? ''));
if (!preg_match('/^CA[a-fA-F0-9]{32}$/', $parentSid)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Call SID no válido.']);
    exit;
}

$configPath = dirname(__DIR__, 2) . '/config/voip_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Falta config/voip_config.php.']);
    exit;
}

$config = require $configPath;
$accountSid = trim((string)($config['account_sid'] ?? ''));
$authToken = trim((string)($config['auth_token'] ?? ''));

if (!preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) || $authToken === '' || $authToken === 'TU_AUTH_TOKEN_PRIVADO') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Configura las credenciales privadas de Twilio.']);
    exit;
}

function twilioGetJsonEstado(string $url, string $accountSid, string $authToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Twilio respondió HTTP ' . $status);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respuesta JSON no válida de Twilio.');
    }

    return $data;
}

try {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/'
        . rawurlencode($accountSid)
        . '/Calls.json?ParentCallSid=' . rawurlencode($parentSid)
        . '&PageSize=5';

    $data = twilioGetJsonEstado($url, $accountSid, $authToken);
    $childCall = null;

    foreach (($data['calls'] ?? []) as $call) {
        if (($call['direction'] ?? '') !== 'outbound-dial') {
            continue;
        }

        $childCall = [
            'sid' => $call['sid'] ?? null,
            'parent_call_sid' => $call['parent_call_sid'] ?? $parentSid,
            'to' => $call['to'] ?? '',
            'from' => $call['from'] ?? '',
            'status' => $call['status'] ?? '',
            'duration' => isset($call['duration']) ? (int)$call['duration'] : 0,
            'start_time' => $call['start_time'] ?? $call['date_created'] ?? null,
            'end_time' => $call['end_time'] ?? null,
        ];
        break;
    }

    echo json_encode([
        'ok' => true,
        'parent_sid' => $parentSid,
        'call' => $childCall,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No se pudo consultar el estado de la llamada.',
        'detalle' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
