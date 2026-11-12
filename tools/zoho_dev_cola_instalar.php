<?php
/**
 * Instalador de una SOLA tabla auxiliar para Zoho Developer.
 * CLI. --check es solo lectura. --install requiere confirmación explícita.
 * No envía registros a Zoho, no activa cron, no cambia tablas existentes.
 */
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--check', '--install'], true)) {
    fwrite(STDERR, "Uso: php tools/zoho_dev_cola_instalar.php --check|--install --confirm-new-table\n");
    exit(2);
}
$modo = $argv[1];
if (($modo === '--check' && count($argv) !== 2) ||
    ($modo === '--install' && (count($argv) !== 3 || $argv[2] !== '--confirm-new-table'))) {
    fwrite(STDERR, "ERROR: argumentos no autorizados.\n");
    exit(2);
}

require_once __DIR__ . '/../config/db_connection.php';

function zohoTablaMetadata(mysqli $db, string $name): ?array
{
    $stmt = $db->prepare(
        "SELECT ENGINE
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1"
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ?: null;
}

function zohoColumnasMetadata(mysqli $db, string $name): array
{
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $cols = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $cols[$row['COLUMN_NAME']] = $row;
    }
    $stmt->close();
    return $cols;
}

function zohoVerificarCola(mysqli $db): bool
{
    $tabla = zohoTablaMetadata($db, 'zoho_dev_registros_pendientes');
    if ($tabla === null) {
        echo "COLA: AUSENTE\n";
        return false;
    }
    if ($tabla['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('COLA_EXISTENTE_NO_INNODB');
    }
    $cols = zohoColumnasMetadata($db, 'zoho_dev_registros_pendientes');
    foreach (['id', 'registro_id', 'estado', 'zoho_id', 'intentos',
              'proximo_intento', 'bloqueado_hasta', 'ultimo_error',
              'created_at', 'updated_at'] as $col) {
        if (!isset($cols[$col])) {
            throw new RuntimeException('COLA_EXISTENTE_INCOMPATIBLE_' . $col);
        }
    }
    if ($cols['registro_id']['COLUMN_TYPE'] !== 'bigint(20) unsigned' &&
        $cols['registro_id']['COLUMN_TYPE'] !== 'bigint unsigned') {
        throw new RuntimeException('COLA_EXISTENTE_REGISTRO_ID_INCOMPATIBLE');
    }
    $res = $db->query(
        "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='zoho_dev_registros_pendientes'"
    );
    $unico = false;
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['INDEX_NAME'] === 'uq_zoho_dev_registro' &&
            (int)$r['NON_UNIQUE'] === 0 && $r['COLUMN_NAME'] === 'registro_id') {
            $unico = true;
        }
    }
    if (!$unico) {
        throw new RuntimeException('COLA_SIN_INDICE_UNICO');
    }
    echo "COLA: INNODB_COMPATIBLE\n";
    return true;
}

try {
    $db = (new Database())->connect(false);
    foreach (['formulario_registros', 'formulario_inscripciones'] as $name) {
        $tabla = zohoTablaMetadata($db, $name);
        if ($tabla === null || $tabla['ENGINE'] !== 'InnoDB') {
            throw new RuntimeException('TABLA_FORMULARIO_NO_COMPATIBLE');
        }
    }
    $cols = zohoColumnasMetadata($db, 'formulario_registros');
    if (!isset($cols['id']) ||
        !preg_match('/^int(?:\([0-9]+\))? unsigned$/D', $cols['id']['COLUMN_TYPE'])) {
        throw new RuntimeException('ID_FORMULARIO_NO_ES_INT_UNSIGNED');
    }
    foreach (['nombre', 'apellido', 'correo', 'movil'] as $column) {
        if (!isset($cols[$column])) {
            throw new RuntimeException('CAMPO_FORMULARIO_FALTANTE');
        }
    }
    echo "FORMULARIOS: INNODB_COMPATIBLE\n";

    $yaExiste = zohoVerificarCola($db);
    if ($modo === '--check') {
        echo "SOLO_LECTURA_OK: sin SQL de escritura; envíos desactivados.\n";
        exit(0);
    }
    if ($yaExiste) {
        echo "YA_INSTALADA: no se ejecutó DDL.\n";
        exit(0);
    }

    // Solo una tabla auxiliar. No ejecuta ALTER/DROP/INSERT/UPDATE ni cron.
    $rutaSql = __DIR__ . '/../database/zoho_dev_registros_pendientes.sql';
    $ddl = file_get_contents($rutaSql);
    if (!is_string($ddl)) {
        throw new RuntimeException('SQL_NO_DISPONIBLE');
    }
    $ddl = preg_replace('/^\s*--[^\n]*\n/m', '', $ddl);
    $ddl = trim((string)$ddl);
    if (substr_count($ddl, ';') !== 1 ||
        !preg_match('/^CREATE TABLE IF NOT EXISTS zoho_dev_registros_pendientes\s*\(/', $ddl) ||
        substr($ddl, -1) !== ';' ||
        preg_match('/\b(DROP|ALTER|TRUNCATE|DELETE|INSERT|UPDATE|REPLACE|GRANT|REVOKE)\b/i', $ddl)) {
        throw new RuntimeException('SQL_NO_ES_UNA_SOLA_CREACION_AUTORIZADA');
    }
    $db->query($ddl);
    if (!zohoVerificarCola($db)) {
        throw new RuntimeException('COLA_NO_CREADA');
    }
    echo "INSTALACION_OK: solo tabla auxiliar; sin registros, cron ni envíos.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "BLOQUEADO: " . preg_replace('/[^A-Z0-9_]/', '',
        strtoupper((string)$e->getMessage())) . "\n");
    exit(1);
}
