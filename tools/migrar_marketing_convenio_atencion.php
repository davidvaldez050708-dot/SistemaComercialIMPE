<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require_once $root . '/config/db_connection.php';

$sql = file_get_contents(
    $root .
    '/database/migrations/2026_10_16_marketing_convenio_atencion.sql'
);

if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "[ERROR] No se pudo leer la migración.\n");
    exit(1);
}

$db = (new Database())->connect();

if (!$db) {
    fwrite(STDERR, "[ERROR] No se pudo conectar a la base de datos.\n");
    exit(1);
}

if (!$db->query($sql)) {
    fwrite(STDERR, "[ERROR] " . $db->error . "\n");
    exit(1);
}

echo "[OK] Estado de atención de convenios preparado.\n";
