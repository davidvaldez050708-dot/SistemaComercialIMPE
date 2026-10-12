<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Este script solo puede ejecutarse desde terminal.\n");
    exit(1);
}

$sourceRoot = dirname(__DIR__);

$runtimeRoot = trim((string)(
    getenv('IMPE_RUNTIME_ROOT') !== false
        ? getenv('IMPE_RUNTIME_ROOT')
        : ''
));

if ($runtimeRoot === '') {
    $runtimeRoot = $sourceRoot;
}

$runtimeRoot = rtrim($runtimeRoot, '/');

if (!is_file($runtimeRoot . '/config/db_connection.php')) {
    fwrite(
        STDERR,
        "ERROR: no se encontró config/db_connection.php en {$runtimeRoot}.\n"
    );
    exit(1);
}

require_once $runtimeRoot . '/config/db_connection.php';

$mode = $argv[1] ?? '--check';

if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(
        STDERR,
        "Uso: php tools/separar_formularios_bd.php --check|--apply\n"
    );
    exit(2);
}

$db = (new Database())->connect();

function tablaExisteFormularios(mysqli $db, string $tabla): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $tabla);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($fila['total'] ?? 0) > 0;
}

function contarFormularios(mysqli $db, string $sql): int
{
    $resultado = $db->query($sql);

    if (!$resultado) {
        throw new RuntimeException($db->error);
    }

    $fila = $resultado->fetch_row();
    $resultado->free();

    return (int)($fila[0] ?? 0);
}

function mostrarEstadoFormularios(mysqli $db): array
{
    if (!tablaExisteFormularios($db, 'formulario_registros')) {
        throw new RuntimeException(
            'No existe la tabla formulario_registros.'
        );
    }

    $mezcladas = contarFormularios(
        $db,
        "SELECT COUNT(*)
         FROM formulario_registros
         WHERE origen IN (
             'MARKETING_INSCRIPCION',
             'PUBLICO_INSCRIPCION'
         )"
    );

    $registros = contarFormularios(
        $db,
        "SELECT COUNT(*)
         FROM formulario_registros
         WHERE origen NOT IN (
             'MARKETING_INSCRIPCION',
             'PUBLICO_INSCRIPCION'
         )"
    );

    $tablaInscripciones = tablaExisteFormularios(
        $db,
        'formulario_inscripciones'
    );

    $inscripciones = $tablaInscripciones
        ? contarFormularios(
            $db,
            "SELECT COUNT(*) FROM formulario_inscripciones"
        )
        : 0;

    echo "=== SEPARACIÓN DE FORMULARIOS ===\n";
    echo "Registros: {$registros}\n";
    echo "Inscripciones aún mezcladas: {$mezcladas}\n";
    echo "Tabla formulario_inscripciones: " .
        ($tablaInscripciones ? 'SI' : 'NO') . "\n";
    echo "Inscripciones separadas: {$inscripciones}\n";

    return [
        'mezcladas' => $mezcladas,
        'tabla_inscripciones' => $tablaInscripciones,
        'inscripciones' => $inscripciones
    ];
}

try {
    $estado = mostrarEstadoFormularios($db);

    if ($mode === '--check') {
        echo (
            $estado['tabla_inscripciones'] &&
            $estado['mezcladas'] === 0
        )
            ? "ESTADO: la base ya está separada.\n"
            : "ESTADO: separación pendiente.\n";

        exit(0);
    }

    $migracion = $sourceRoot .
        '/database/migrations/' .
        '2026_10_12_separar_formularios_registro_inscripcion.sql';

    if (!is_file($migracion)) {
        throw new RuntimeException(
            'No se encontró la migración de separación.'
        );
    }

    $sql = file_get_contents($migracion);

    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException(
            'La migración de separación está vacía.'
        );
    }

    if (!$db->multi_query($sql)) {
        throw new RuntimeException($db->error);
    }

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

    echo "\n";
    $final = mostrarEstadoFormularios($db);

    if (
        !$final['tabla_inscripciones'] ||
        $final['mezcladas'] !== 0
    ) {
        throw new RuntimeException(
            'La separación no quedó completa.'
        );
    }

    echo "OK: separación aplicada correctamente.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
