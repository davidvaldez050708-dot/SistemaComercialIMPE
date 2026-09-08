<?php

require_once __DIR__ . '/../app/services/HostingerInboundMailService.php';

header('Content-Type: application/json; charset=utf-8');

$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();

    if (is_array($headers)) {
        foreach ($headers as $nombre => $valor) {
            if (strcasecmp((string)$nombre, 'Authorization') === 0) {
                $authorization = trim((string)$valor);
                break;
            }
        }
    }
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

try {
    $servicio = new HostingerInboundMailService();
    $resultado = $servicio->procesarWebhook($authorization, $rawBody);
} catch (Throwable $error) {
    error_log('Error procesando webhook de Hostinger Mail: ' . $error->getMessage());

    $resultado = [
        'ok' => false,
        'codigo_http' => 500,
        'mensaje' => 'No fue posible procesar el evento de correo.'
    ];
}

$codigoHttp = (int)($resultado['codigo_http'] ?? 200);
if ($codigoHttp < 100 || $codigoHttp > 599) {
    $codigoHttp = 500;
}

http_response_code($codigoHttp);

echo json_encode(
    $resultado,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
);
