<?php

declare(strict_types=1);

$publicDir = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--public-dir=')) {
        $publicDir = rtrim(substr($arg, 13), '/\\');
    }
}

if ($publicDir === '' || !is_file($publicDir . '/config/db_connection.php')) {
    fwrite(STDERR, "ERROR: usa --public-dir=/ruta/public_html\n");
    exit(2);
}

require_once $publicDir . '/config/db_connection.php';

$sqlFile = dirname(__DIR__) .
    '/database/migrations/2026_10_16_cuentas_correo_usuario.sql';

if (!is_file($sqlFile)) {
    fwrite(STDERR, "ERROR: no se encontró la migración.\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "ERROR: migración vacía.\n");
    exit(1);
}

$db = (new Database())->connect(false);

if (!$db->multi_query($sql)) {
    fwrite(STDERR, "ERROR SQL: " . $db->error . "\n");
    exit(1);
}

do {
    if ($result = $db->store_result()) {
        $result->free();
    }
} while ($db->more_results() && $db->next_result());

echo "OK: tabla usuario_correo_config preparada.\n";
