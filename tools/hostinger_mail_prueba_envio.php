<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/app/services/HostingerMailApiService.php';

const CORREO_DIEGO = 'd.institucional2@rededucativamexico.org';
const HOSTINGER_MAIL_BASE_URL_PRUEBA = 'https://api.mail.hostinger.com';

$destino = '';
$enviar = false;

foreach ($argv as $argumento) {
    if (strpos($argumento, '--destino=') === 0) {
        $destino = trim(substr($argumento, strlen('--destino=')));
    }

    if ($argumento === '--enviar') {
        $enviar = true;
    }
}

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$linea('Prueba controlada de envío - Hostinger Mail API');
$linea(str_repeat('=', 47));
$linea('Remitente: ' . CORREO_DIEGO);
$linea();

if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
    $linea('[PENDIENTE] Indica un correo de prueba válido que tú controles.');
    $linea('Ejemplo:');
    $linea('C:\\xampp\\php\\php.exe tools\\hostinger_mail_prueba_envio.php --destino=tu_correo@ejemplo.com');
    exit(2);
}

$servicio = new HostingerMailApiService();

if (!$servicio->estaConfigurado()) {
    $linea('[ERROR] HOSTINGER_MAIL_API_TOKEN no está configurado.');
    exit(1);
}

$acceso = $servicio->diagnosticarAcceso();

if (!($acceso['ok'] ?? false)) {
    $linea('[ERROR] No fue posible validar el token de Mail API.');
    $linea('Detalle: ' . ($acceso['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$mailboxes = is_array($acceso['mailboxes'] ?? null) ? $acceso['mailboxes'] : [];
$mailboxId = '';

foreach ($mailboxes as $mailbox) {
    if (!is_array($mailbox)) {
        continue;
    }

    $correo = strtolower(trim((string)($mailbox['address'] ?? '')));

    if ($correo === CORREO_DIEGO) {
        $mailboxId = trim((string)($mailbox['resource_id'] ?? ''));
        break;
    }
}

if ($mailboxId === '') {
    $linea('[ERROR] El token actual no tiene acceso al buzón confirmado de Diego.');
    exit(1);
}

$asunto = 'Prueba de integración - Sistema Comercial IMPE';
$cuerpo = "Este es un correo de prueba del Sistema Comercial IMPE.\n\n" .
    "Remitente configurado: " . CORREO_DIEGO . "\n" .
    "La prueba valida el envío mediante Hostinger Mail API.\n\n" .
    "No corresponde a un seguimiento real y no modifica expedientes.";

$linea('[OK] Token validado y buzón de Diego autorizado.');
$linea('Destinatario de prueba: ' . $destino);
$linea('Asunto: ' . $asunto);

if (!$enviar) {
    $linea();
    $linea('[LISTO PARA ENVIAR] Todavía no se envió ningún correo.');
    $linea('Si el destinatario es correcto, ejecuta:');
    $linea('C:\\xampp\\php\\php.exe tools\\hostinger_mail_prueba_envio.php --destino=' . $destino . ' --enviar');
    exit(0);
}

$configPath = $rootPath . '/config/hostinger_mail_config.php';

if (!is_file($configPath)) {
    $linea('[ERROR] No existe config/hostinger_mail_config.php.');
    exit(1);
}

require_once $configPath;
$token = defined('HOSTINGER_MAIL_API_TOKEN')
    ? trim((string)HOSTINGER_MAIL_API_TOKEN)
    : trim((string)(getenv('HOSTINGER_MAIL_API_TOKEN') ?: ''));
$baseUrl = defined('HOSTINGER_MAIL_API_BASE_URL')
    ? rtrim(trim((string)HOSTINGER_MAIL_API_BASE_URL), '/')
    : HOSTINGER_MAIL_BASE_URL_PRUEBA;

if ($token === '') {
    $linea('[ERROR] El token Mail API está vacío.');
    exit(1);
}

$payload = [
    'to' => [$destino],
    'subject' => $asunto,
    'text' => $cuerpo,
    'displayName' => 'Diego Bahena'
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    $linea('[ERROR] No fue posible preparar el mensaje.');
    exit(1);
}

$url = $baseUrl . '/api/v1/mailboxes/' . rawurlencode($mailboxId) . '/send';
$curl = curl_init($url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_TIMEOUT, 30);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($curl, CURLOPT_POSTFIELDS, $json);

$respuesta = curl_exec($curl);
$codigoHttp = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($curl);
curl_close($curl);

if ($respuesta === false) {
    $linea('[ERROR] No fue posible comunicarse con Hostinger Mail API.');
    $linea('Detalle: ' . ($errorCurl !== '' ? $errorCurl : 'curl_exec devolvió false.'));
    exit(1);
}

if ($codigoHttp < 200 || $codigoHttp >= 300) {
    $detalle = trim((string)$respuesta);
    $linea('[ERROR] Hostinger rechazó el envío. HTTP ' . $codigoHttp);
    if ($detalle !== '') {
        $linea('Detalle: ' . $detalle);
    }
    exit(1);
}

$linea();
$linea('[OK] Hostinger aceptó el correo de prueba. HTTP ' . $codigoHttp);
$linea('[OK] Remitente: ' . CORREO_DIEGO);
$linea('[OK] Destinatario: ' . $destino);
$linea('La prueba no modificó seguimientos, oficios ni interacciones del sistema.');
