<?php
/** Instalador idempotente de atributos de canales manuales.
 * Uso: php tools/migrar_canales_manuales_20261022.php --check|--apply
 * Antes de --apply exige un respaldo SQL completo y actualizado de producción.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db_connection.php';
$aplicar = in_array('--apply', $argv, true);
$columnas = [
    'etiqueta' => 'VARCHAR(80) NOT NULL DEFAULT \'Difusión\'',
    'whatsapp_confirmado' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'preferido_difusion' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'es_canal_difusion' => 'TINYINT(1) NOT NULL DEFAULT 0'
];
try {
    $db = (new Database())->connect(false);
    if (!$db->query("SHOW TABLES LIKE 'aliados_manuales_contactos'")->num_rows) {
        throw new RuntimeException('Falta la migración anterior de contactos manuales.');
    }
    $resultado = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aliados_manuales_contactos'");
    $actuales = array_column($resultado->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $faltantes = array_diff(array_keys($columnas), $actuales);
    if (!$faltantes) {
        echo "OK: atributos de canales de aliados manuales instalados.\n";
        exit(0);
    }
    echo "PENDIENTE: " . implode(', ', $faltantes) . ".\n";
    if (!$aplicar) {
        echo "SITIO INTACTO: no se ejecutó SQL.\n";
        exit(2);
    }
    if (!in_array('--respaldo-confirmado', $argv, true)) {
        throw new RuntimeException('Exige --respaldo-confirmado tras verificar exportación completa SQL de producción.');
    }
    foreach ($faltantes as $nombre) {
        // Identificadores y definiciones provienen exclusivamente de constantes internas.
        if (!$db->query("ALTER TABLE aliados_manuales_contactos ADD COLUMN `$nombre` {$columnas[$nombre]}")) {
            throw new RuntimeException('No se añadió ' . $nombre . ': ' . $db->error);
        }
        echo "APLICADO: " . $nombre . "\n";
    }
    echo "OK: atributos de canales de aliados manuales instalados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
