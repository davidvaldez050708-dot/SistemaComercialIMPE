<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/app/services/HostingerMailApiService.php';

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$linea('Diagnóstico Hostinger Mail API');
$linea(str_repeat('=', 36));
$linea('Esta prueba no envía correos.');
$linea();

$servicio = new HostingerMailApiService();

if (!$servicio->estaConfigurado()) {
    $linea('[PENDIENTE] HOSTINGER_MAIL_API_TOKEN no está configurado.');
    exit(2);
}

$resultado = $servicio->diagnosticarAcceso();

if (!($resultado['ok'] ?? false)) {
    $linea('[ERROR] ' . ($resultado['mensaje'] ?? 'No fue posible consultar Hostinger Mail API.'));
    $linea('Detalle: ' . ($resultado['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$mailboxes = is_array($resultado['mailboxes'] ?? null)
    ? $resultado['mailboxes']
    : [];

$linea('[OK] Token Mail API aceptado por Hostinger.');
$linea('Buzones autorizados: ' . count($mailboxes));

foreach ($mailboxes as $mailbox) {
    if (!is_array($mailbox)) {
        continue;
    }

    $correo = trim((string)($mailbox['address'] ?? 'Sin correo'));
    $id = trim((string)($mailbox['resource_id'] ?? 'Sin ID'));
    $linea('  - ' . $correo . ' | ' . $id);
}

$linea();

if (count($mailboxes) === 1 &&
    strtolower(trim((string)($mailboxes[0]['address'] ?? ''))) ===
        'd.institucional2@rededucativamexico.org') {
    $linea('[OK] El token está limitado correctamente al buzón de Diego Bahena.');
} else {
    $linea('[REVISAR] El alcance no coincide exactamente con el buzón de Diego.');
}

$linea('No se envió ningún correo.');
