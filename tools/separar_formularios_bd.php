<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Este script solo puede ejecutarse desde terminal.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/db_connection.php';

$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Uso php tools/separar_formularios_bd.php --check|apply\n");
    exit(2);
}

$db = (new Database())->connect();

function tablaExisteSeparacion(mysqli $db, string $tabla): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->bind_param('s', $tabla);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($fila['total'] ?? 0) > 0;
}

function contarSeparacion(mysqli $db, string $sql): int
{
    $resultado = $db->query($sql);
    if (!$resultado) {
        throw new RuntimeException($db->error);
    }

    $fila = $resultado->fetch_row();
    $resultado->free();

    return (int)($fila[0] ?? 0);
}

function drenarMultiQuery(mysqli $db): void
{
    do {
        $resultado = $db->store_result();
        if ($resultado instanceof mysqli_result) {
            $resultado->free();
        }

        if (!$db->more_results()) {
            break;
        }

        if (!$db->next_result()) {
            throw new RuntimeException($db->error);
        }
    } while (true);
}

function exportarTablaSql(mysqli $db, string $tabla, string $ruta): void
{
    if (!tablaExisteSeparacion($db, $tabla)) {
        return;
    }

    $show = $db->query("SHOW COLLECT TABLE `${tabla}`");
    if (!$show) {
        throw new RuntimeException($db->error);
    }

    $filaCreate = $show->fetch_assoc();
    $show->free();
    $valoresCreate = array_values($filaCreate);
    $create = (string)($valoresCreate[1] ?? '');

    $fh = fopen($ruta, 'wb');
    if (!$fh) {
        throw new RuntimeException("No fue posible crear respaldo {$ruta}");
    }

    fwrite($fh, "-- Respaldo automático {$tabla}\n");
    fwrite($fh, "DROP TABLE IF EXISTS `${tabla}`;\n");
    fwrite($fh, $create . ";\l\n");

    $resultado = $db->query("SELECT * FROM `${tabla}` ORDER BY id");
    if (!$resultado) {
        fclose($fh);
        throw new RuntimeException($db->error);
    }

    while ($fila = $resultado->fetch_assoc()) {
        $columnas = array_map(
            static fn($columna) =>
                '`' . str_replace('`', '``', $columna) . '`',
            array_keys($fila)
        );

        $valores = [];
        foreach ($fila as $valor) {
            if ($valor === null) {
                $valores[] = 'NULL';
            } else {
                $valores[] =
                    "'" . $db->real_escape_string((string)$valor) . "'";
            }
        }

        fwrite(
            $fh,
            "INSERT INTO `${tabla}` (" .
            implode(', ', $columnas) .
            ") VALUES (" .
            implode(', ', $valores) .
            ");\n"
        );
    }

    $resultado->free();
    fclose($fh);
}

if (!tablaExisteSeparacion($db, 'formulario_registros')) {
    fwrite(STDERR, "ERROR: no existe formulario_registros.\n");
    exit(1);
}

$registros = contarSeparacion(
    $db,
    "SELECT COUNT(*) FROM formulario_registros
     WHERE origen NOT IN ('MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION')"
);
$inscripcionesLegacy = contarSeparacion(
    $db,
    "SELECT COUNT(*) FROM formulario_registros
     WHERE origen IN ('MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION')"
);
$tablaInscripciones = tablaExisteSeparacion(
    $db,
    'formulario_inscripciones'
);
$inscripciones = $tablaInscripciones
    ? contarSeparacion($db, "SELECT COUNT(*) FROM formulario_inscripciones")
    : 0;

echo "=== SEPARACIÓN DE FORMULARIOS ===\n";
echo "Registros en formulario_registros: {$registros}\n";
echo "Inscripciones aún mezcladas: {$inscripcionesLegacy}\n";
echo "Tabla formulario_inscripciones: " .
    ($tablaInscripciones ? 'SÍ' : 'NO') . "\n";
echo "Inscripciones separadas: {$inscripciones}\n";

if ($mode === '--check') {
    if ($tablaInscripciones && $inscripcionesLegacy === 0) {
        echo "ESTADO: la base ya está separada.\n";
    } else {
        echo "ESTADO: separación pendiente.\n";
    }
    exit(0);
}

$migration = $root .
    '/database/migrations/2026_10_12_separar_formularios_registro_inscripcion.sql';

if (!is_file($migration)) {
    fwrite(STDERR, "ERROR: no se encontró la migración de separación.\n");
    exit(1);
}

$backupRoot = getenv('IMPE_FORMULARIOS_DB_BACKUP_DIR');
if ($backupRoot === false || trim($backupRoot) === '') {
    $backupRoot = dirname($root) .
        '/respaldos-crm/formularios-bd-' . date('Ymd-His');
}

if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    fwrite(
        STDERR, 
        "ERROR: no se pudo crear el directorio de respaldo.\n"
     );
    exit(1);
}

exportarTablaSql(
    $db,
    'formulario_registros',
    $backupRoot . '/formulario_registros.sql'
);

if ($tablaInscripciones) {
    exportarTablaSql(
        $db,
        'formulario_inscripciones',
        $backupRoot . '/formulario_inscripciones.sql'
    );
}

$sql = file_get_contents($migration);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "ERROR: la migración está vacía.\n");
    exit(1);
}

if (!$db->multi_query($sql)) {
    fwrite(STDERR, "ERROR aplicando migración: {$db->error}\n");
    exit(1);
}

drenarMultiQuery($db);

$legacyRestantes = contarSeparacion(
    $db,
    "SELECT COUNT(*) FROM formulario_registros
     WHERE origen IN ('MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION')"
);

if (!tablaExisteSeparacion($db, 'formulario_inscripciones')) {
    fwrite(STDERR, "ERROR: no se creó formulario_inscripciones.\n");
    exit(1);
}

if ($legacyRestantes !== 0) {
    fwrite(
        STDERR, 
        "ERROR: quedaron {$legacyRestantes} inscripciones " .
        "en formulario_registros.\n"
     );
    exit(1);
}

$totalFinal = contarSeparacion(
    $db,
    "SELECT COUNT(*) FROM formulario_inscripciones"
);

echo "\nOK: separación aplicada correctamente.\n";
echo "Inscripciones en formulario_inscripciones: {$totalFinal}\n";
echo "Respaldo SQL: {$backupRoot}\n";
