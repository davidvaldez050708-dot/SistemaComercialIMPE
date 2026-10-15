<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = $root . '/config/mail_config.php';

echo "=== Diagnóstico de correo institucional ===\n\n";

if (!is_file($config)) {
    echo "ERROR: no existe config/mail_config.php\n";
    exit(1);
}

require_once $config;

$claves = [
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_ENCRYPTION',
    'MAIL_FROM_ADDRESS',
    'MAIL_FROM_NAME',
];

$errores = 0;

foreach ($claves as $clave) {
    $valor = defined($clave) ? constant($clave) : '';
    $texto = trim((string)$valor);

    if ($clave === 'MAIL_PASSWORD') {
        $estado = $texto !== '' ? 'OK (configurada)' : 'FALTA';
    } elseif ($clave === 'MAIL_PORT') {
        $estado = (int)$valor > 0 ? 'OK (' . (int)$valor . ')' : 'FALTA';
    } else {
        $estado = $texto !== '' ? 'OK (' . $texto . ')' : 'FALTA';
    }

    if (strpos($estado, 'FALTA') === 0) {
        $errores++;
    }

    echo str_pad($clave, 22) . ": " . $estado . PHP_EOL;
}

echo "\n";

$host = defined('MAIL_HOST') ? trim((string)MAIL_HOST) : '';
$port = defined('MAIL_PORT') ? (int)MAIL_PORT : 0;

if ($host !== '' && $port > 0) {
    echo "Probando conexión TCP a {$host}:{$port}...\n";
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 8);

    if (is_resource($socket)) {
        echo "CONEXIÓN SMTP: OK\n";
        fclose($socket);
    } else {
        echo "CONEXIÓN SMTP: ERROR ({$errno}) {$errstr}\n";
        $errores++;
    }
}

echo "\n";

if ($errores > 0) {
    echo "RESULTADO: configuración incompleta o conexión no disponible.\n";
    exit(2);
}

echo "RESULTADO: configuración base completa.\n";
echo "Si el envío aún falla, revisa autenticación/credenciales o el log PHP.\n";
