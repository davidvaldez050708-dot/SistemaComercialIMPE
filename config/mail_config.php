<?php

// Este archivo puede estar en Git porque NO contiene contraseñas.
// Define los valores privados en mail_config.local.php o variables de entorno.
require_once __DIR__ . '/private_config.php';
$rutaLocal = impeRutaConfigPrivada('mail_config.local.php');
if ($rutaLocal !== null) {
    require_once $rutaLocal;
}

$valores = [
    'MAIL_HOST' => '',
    'MAIL_PORT' => 587,
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_ENCRYPTION' => 'tls',
    'MAIL_FROM_ADDRESS' => '',
    'MAIL_FROM_NAME' => 'Sistema institucional',
];

foreach ($valores as $nombre => $predeterminado) {
    if (defined($nombre)) {
        continue;
    }
    $valor = getenv($nombre);
    $valor = $valor === false || trim((string)$valor) === ''
        ? $predeterminado : trim((string)$valor);
    define($nombre, $nombre === 'MAIL_PORT' ? (int)$valor : $valor);
}
