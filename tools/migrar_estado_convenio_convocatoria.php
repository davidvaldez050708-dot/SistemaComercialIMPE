<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/db_connection.php';

$sql = file_get_contents(
    $root . '/database/migrations/2026_10_16_estado_convenio_convocatoria.sql'
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

if (!$db->multi_query($sql)) {
    fwrite(STDERR, "[ERROR] " . $db->error . "\n");
    exit(1);
}

do {
    if ($result = $db->store_result()) {
        $result->free();
    }
} while ($db->more_results() && $db->next_result());

echo "[OK] Estado de convenios y convocatorias preparado.\n";
