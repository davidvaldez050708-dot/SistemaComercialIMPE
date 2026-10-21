<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = rtrim(getenv('IMPE_RUNTIME_ROOT') ?: dirname(__DIR__), '/\\');
require_once $root . '/config/db_connection.php';
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check', '--apply'], true) || count($argv) > 2) { fwrite(STDERR, "Uso: --check|--apply\n"); exit(2); }
try {
    $db = (new Database())->connect(false);
    $pendientes = [];
    // Revisar ambos esquemas antes del primer DDL. No borrar ni recrear tablas.
    foreach (['formulario_registros', 'formulario_inscripciones'] as $tabla) {
        foreach (['estado_id', 'municipio_id'] as $columna) {
            $fila = $db->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'")->fetch_assoc();
            if (!$fila || !preg_match('/^(?:bigint|int|smallint)(?:\([0-9]+\))?(?: unsigned)?$/D', $fila['Type']) || $fila['Extra'] !== '') {
                throw new RuntimeException("Columna territorial no compatible: $tabla.$columna");
            }
            if ($fila['Null'] !== 'YES') { $pendientes[] = "ALTER TABLE `$tabla` MODIFY `$columna` {$fila['Type']} NULL DEFAULT NULL"; }
        }
        foreach (['pais_nombre', 'estado_provincia', 'ciudad'] as $columna) {
            $fila = $db->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'")->fetch_assoc();
            if (!$fila) { $pendientes[] = "ALTER TABLE `$tabla` ADD `$columna` VARCHAR(120) NULL"; }
            elseif (strtolower($fila['Type']) !== 'varchar(120)' || $fila['Null'] !== 'YES') { throw new RuntimeException("Columna de ubicación no compatible: $tabla.$columna"); }
        }
    }
    if ($modo === '--check' && $pendientes) { throw new RuntimeException('Migración incremental pendiente: ' . count($pendientes) . ' cambios. Ejecuta --apply manualmente tras respaldar la BD.'); }
    foreach ($pendientes as $sql) { $db->query($sql); }
    echo "OK: ubicación internacional preparada; registros e identificadores conservados.\n";
} catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL); exit(1); }
