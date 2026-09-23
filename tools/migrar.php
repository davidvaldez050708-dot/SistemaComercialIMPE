<?php

/**
 * Runner de migraciones SQL del Sistema Comercial IMPE.
 *
 * Uso:
 *   php tools/migrar.php --status
 *   php tools/migrar.php --baseline=2026_09_21
 *   php tools/migrar.php --run
 *
 * Base existente del proyecto:
 *   1. Marca como aplicadas las migraciones que ya existían en la BD.
 *   2. Ejecuta únicamente las posteriores.
 *
 * Instalación nueva:
 *   1. Importa database/sistema_comercial_impe.sql.
 *   2. Ejecuta php tools/migrar.php --run
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Este script solo puede ejecutarse desde terminal.\n");
    exit(1);
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/db_connection.php';

$migrationsPath = $rootPath . '/database/migrations';
$argumentos = array_slice($argv, 1);
$comando = $argumentos[0] ?? '--status';

if (!is_dir($migrationsPath)) {
    fwrite(STDERR, "ERROR: No existe database/migrations.\n");
    exit(1);
}

$database = new Database();
$db = $database->connect();

if (!($db instanceof mysqli)) {
    fwrite(STDERR, "ERROR: No fue posible abrir la conexión MySQL.\n");
    exit(1);
}

$db->query(
    "CREATE TABLE IF NOT EXISTS sistema_migraciones (
        nombre VARCHAR(255) NOT NULL,
        checksum CHAR(64) NOT NULL,
        lote INT UNSIGNED NOT NULL DEFAULT 1,
        aplicado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (nombre),
        KEY idx_sistema_migraciones_lote (lote),
        KEY idx_sistema_migraciones_fecha (aplicado_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

if ($db->errno) {
    fwrite(
        STDERR,
        "ERROR creando sistema_migraciones: " . $db->error . "\n"
    );
    exit(1);
}

function listarMigraciones(string $directorio): array
{
    $archivos = glob($directorio . '/*.sql') ?: [];
    sort($archivos, SORT_STRING);
    return $archivos;
}

function migracionesAplicadas(mysqli $db): array
{
    $resultado = $db->query(
        "SELECT nombre, checksum, lote, aplicado_at
         FROM sistema_migraciones
         ORDER BY nombre"
    );

    $aplicadas = [];

    if (!$resultado) {
        return $aplicadas;
    }

    while ($fila = $resultado->fetch_assoc()) {
        $nombreRegistrado = (string)$fila['nombre'];
        $aplicadas[$nombreRegistrado] = $fila;

        $alias = aliasMigracionCompatible($nombreRegistrado);
        if ($alias !== $nombreRegistrado && !isset($aplicadas[$alias])) {
            $aplicadas[$alias] = $fila;
        }
    }

    $resultado->free();
    return $aplicadas;
}

function checksumMigracion(string $ruta): string
{
    return hash_file('sha256', $ruta) ?: '';
}

function nombreMigracion(string $ruta): string
{
    return basename($ruta);
}

function aliasMigracionCompatible(string $nombre): string
{
    $aliases = [
        '20260903_secretarias_denue.sql' =>
            '2026_09_03_secretarias_denue.sql'
    ];

    return $aliases[$nombre] ?? $nombre;
}

function fechaMigracion(string $nombre): string
{
    if (preg_match('/^(\d{4}_\d{2}_\d{2})_/', $nombre, $match)) {
        return $match[1];
    }

    return '';
}

function normalizarFechaCorte(string $valor): string
{
    $valor = trim($valor);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return str_replace('-', '_', $valor);
    }

    if (preg_match('/^\d{4}_\d{2}_\d{2}$/', $valor)) {
        return $valor;
    }

    return '';
}

function siguienteLote(mysqli $db): int
{
    $resultado = $db->query(
        "SELECT COALESCE(MAX(lote), 0) + 1 AS lote
         FROM sistema_migraciones"
    );

    if (!$resultado) {
        return 1;
    }

    $fila = $resultado->fetch_assoc() ?: [];
    $resultado->free();

    return max(1, (int)($fila['lote'] ?? 1));
}

function registrarMigracion(
    mysqli $db,
    string $nombre,
    string $checksum,
    int $lote
): void {
    $stmt = $db->prepare(
        "INSERT INTO sistema_migraciones (
            nombre,
            checksum,
            lote,
            aplicado_at
        ) VALUES (?, ?, ?, NOW())"
    );

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ssi', $nombre, $checksum, $lote);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $stmt->close();
}

function esMigracionSecretariasDenueLegacy(string $nombre): bool
{
    return in_array(
        $nombre,
        [
            '20260903_secretarias_denue.sql',
            '2026_09_03_secretarias_denue.sql'
        ],
        true
    );
}

function columnaExiste(mysqli $db, string $tabla, string $columna): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ss', $tabla, $columna);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($fila['total'] ?? 0) > 0;
}

function prepararSqlMigracionCompatible(mysqli $db, string $sql, string $nombre): string
{
    if (!esMigracionSecretariasDenueLegacy($nombre)) {
        return $sql;
    }

    $columnas = [
        'fuente_datos' => "VARCHAR(30) NOT NULL DEFAULT 'Sistema'",
        'clave_denue' => "VARCHAR(30) DEFAULT NULL",
        'fecha_actualizacion_denue' => "DATETIME DEFAULT NULL"
    ];

    $faltantes = [];

    foreach ($columnas as $columna => $definicion) {
        if (!columnaExiste($db, 'secretarias_estatales', $columna)) {
            $faltantes[$columna] = $definicion;
        }
    }

    $sql = preg_replace(
        '/\\s*ALTER\\s+TABLE\\s+`?secretarias_estatales`?\\s+ADD\\s+(?:COLUMN\\s+)?`?(fuente_datos|clave_denue|fecha_actualizacion_denue)`?\\s+[^;]+;/i',
        '',
        $sql
    );

    if (!empty($faltantes)) {
        $agregados = [];

        foreach ($faltantes as $columna => $definicion) {
            $agregados[] = "ADD COLUMN `{$columna}` {$definicion}";
        }

        $sql =
            "ALTER TABLE `secretarias_estatales`\n    " .
            implode(",\n    ", $agregados) .
            ";\n\n" .
            $sql;
    }

    return $sql;
}

function tablaExiste(mysqli $db, string $tabla): bool
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

function indiceExiste(mysqli $db, string $tabla, string $indice): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?"
    );

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ss', $tabla, $indice);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($fila['total'] ?? 0) > 0;
}

function foreignKeyExiste(mysqli $db, string $tabla, string $constraint): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ss', $tabla, $constraint);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($fila['total'] ?? 0) > 0;
}

function prepararMigracionMarketingConvocatorias(mysqli $db): string
{
    $sql = [];

    if (!tablaExiste($db, 'convocatorias')) {
        $sql[] = "CREATE TABLE convocatorias (
            id INT NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(255) NOT NULL,
            imagen VARCHAR(500) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_termino DATE NOT NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT NULL,
            actualizado_por INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_convocatorias_estado (estado),
            KEY idx_convocatorias_periodo (fecha_inicio, fecha_termino),
            KEY idx_convocatorias_creado_por (creado_por),
            KEY idx_convocatorias_actualizado_por (actualizado_por),
            CONSTRAINT fk_convocatorias_creado_por
                FOREIGN KEY (creado_por) REFERENCES usuarios(id)
                ON UPDATE CASCADE ON DELETE SET NULL,
            CONSTRAINT fk_convocatorias_actualizado_por
                FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    if (!tablaExiste($db, 'convocatoria_estados')) {
        $sql[] = "CREATE TABLE convocatoria_estados (
            convocatoria_id INT NOT NULL,
            estado_id INT NOT NULL,
            PRIMARY KEY (convocatoria_id, estado_id),
            KEY idx_convocatoria_estados_estado (estado_id),
            CONSTRAINT fk_convocatoria_estados_convocatoria
                FOREIGN KEY (convocatoria_id) REFERENCES convocatorias(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_convocatoria_estados_estado
                FOREIGN KEY (estado_id) REFERENCES estados(id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    $sql[] = "INSERT INTO roles (nombre, descripcion, estado)
        SELECT 'Marketing', 'Gestión de convocatorias y publicaciones.', 1
        WHERE NOT EXISTS (
            SELECT 1 FROM roles WHERE nombre = 'Marketing'
        )";

    $sql[] = "INSERT INTO permisos (modulo,codigo,nombre,descripcion,estado)
        VALUES
        ('Convocatorias','convocatorias.ver','Ver convocatorias','Consultar convocatorias registradas.',1),
        ('Convocatorias','convocatorias.crear','Crear convocatorias','Registrar nuevas convocatorias.',1),
        ('Convocatorias','convocatorias.editar','Editar convocatorias','Actualizar información de convocatorias.',1),
        ('Convocatorias','convocatorias.gestionar','Gestionar convocatorias','Administrar el estado y operación general de las convocatorias.',1),
        ('Convocatorias','convocatorias.descargar','Descargar imágenes','Descargar la imagen asociada a una convocatoria.',1),
        ('Convocatorias','convocatorias.cambiar_estado','Activar / desactivar convocatorias','Modificar el estado lógico de una convocatoria.',1)
        ON DUPLICATE KEY UPDATE
            modulo = VALUES(modulo),
            nombre = VALUES(nombre),
            descripcion = VALUES(descripcion),
            estado = 1";

    $sql[] = "INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
        SELECT roles.id, permisos.id
        FROM roles
        INNER JOIN permisos
            ON permisos.codigo IN (
                'convocatorias.ver',
                'convocatorias.crear',
                'convocatorias.editar',
                'convocatorias.gestionar',
                'convocatorias.descargar',
                'convocatorias.cambiar_estado'
            )
        WHERE roles.nombre = 'Marketing'";

    $sql[] = "INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
        SELECT roles.id, permisos.id
        FROM roles
        INNER JOIN permisos
            ON permisos.codigo IN (
                'convocatorias.ver',
                'convocatorias.crear',
                'convocatorias.editar',
                'convocatorias.gestionar',
                'convocatorias.descargar',
                'convocatorias.cambiar_estado'
            )
        WHERE roles.nombre = 'Administrador'";

    return implode(";\n\n", array_filter($sql)) . ";";
}

function ejecutarSql(mysqli $db, string $sql, string $nombre): void
{
    if (trim($sql) === '') {
        return;
    }

    if (!$db->multi_query($sql)) {
        throw new RuntimeException(
            $nombre . ': ' . $db->error
        );
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
            throw new RuntimeException(
                $nombre . ': ' . $db->error
            );
        }
    } while (true);
}

function mostrarStatus(
    array $archivos,
    array $aplicadas
): void {
    echo "=== MIGRACIONES SISTEMA COMERCIAL IMPE ===\n\n";

    foreach ($archivos as $ruta) {
        $nombre = aliasMigracionCompatible(nombreMigracion($ruta));
        $checksum = checksumMigracion($ruta);
        $registro = $aplicadas[$nombre] ?? null;

        if (!$registro) {
            echo "[PENDIENTE] {$nombre}\n";
            continue;
        }

        if (!hash_equals((string)$registro['checksum'], $checksum)) {
            echo "[CAMBIÓ]    {$nombre}\n";
            continue;
        }

        echo "[APLICADA]   {$nombre}\n";
    }

    echo "\n";
}

$archivos = listarMigraciones($migrationsPath);
$aplicadas = migracionesAplicadas($db);

if ($comando === '--status') {
    mostrarStatus($archivos, $aplicadas);
    exit(0);
}

if (str_starts_with($comando, '--baseline=')) {
    $corte = normalizarFechaCorte(
        substr($comando, strlen('--baseline='))
    );

    if ($corte === '') {
        fwrite(
            STDERR,
            "ERROR: Usa --baseline=AAAA-MM-DD o AAAA_MM_DD.\n"
        );
        exit(1);
    }

    $lote = siguienteLote($db);
    $marcadas = 0;

    foreach ($archivos as $ruta) {
        $nombre = aliasMigracionCompatible(nombreMigracion($ruta));
        $fecha = fechaMigracion($nombre);

        if ($fecha === '' || strcmp($fecha, $corte) > 0) {
            continue;
        }

        $checksum = checksumMigracion($ruta);

        if (isset($aplicadas[$nombre])) {
            if (
                !hash_equals(
                    (string)$aplicadas[$nombre]['checksum'],
                    $checksum
                )
            ) {
                fwrite(
                    STDERR,
                    "ERROR: {$nombre} ya está registrada con otro checksum.\n"
                );
                exit(1);
            }

            continue;
        }

        registrarMigracion($db, $nombre, $checksum, $lote);
        $marcadas++;
        echo "[BASELINE] {$nombre}\n";
    }

    echo "\n{$marcadas} migración(es) marcadas como aplicadas.\n";
    echo "Ejecuta ahora: php tools/migrar.php --run\n";
    exit(0);
}

if ($comando !== '--run') {
    fwrite(
        STDERR,
        "ERROR: Comando no reconocido. Usa --status, --baseline=FECHA o --run.\n"
    );
    exit(1);
}

$lote = siguienteLote($db);
$ejecutadas = 0;

foreach ($archivos as $ruta) {
    $nombre = aliasMigracionCompatible(nombreMigracion($ruta));
    $checksum = checksumMigracion($ruta);
    $registro = $aplicadas[$nombre] ?? null;

    if ($registro) {
        if (!hash_equals((string)$registro['checksum'], $checksum)) {
            fwrite(
                STDERR,
                "ERROR: {$nombre} cambió después de haberse aplicado.\n" .
                "No se ejecutaron las migraciones pendientes.\n"
            );
            exit(1);
        }

        continue;
    }

    $sql = file_get_contents($ruta);

    if (!is_string($sql)) {
        fwrite(STDERR, "ERROR leyendo {$nombre}.\n");
        exit(1);
    }

    echo "[EJECUTANDO] {$nombre}\n";

    try {
        $sql = prepararSqlMigracionCompatible($db, $sql, $nombre);

        if ($nombre === '2026_09_23_marketing_convocatorias.sql') {
            $sql = prepararMigracionMarketingConvocatorias($db);
        }

        ejecutarSql($db, $sql, $nombre);
        registrarMigracion($db, $nombre, $checksum, $lote);
        $ejecutadas++;
        echo "[OK]         {$nombre}\n";
    } catch (Throwable $error) {
        fwrite(
            STDERR,
            "[ERROR]      {$nombre}\n" .
            $error->getMessage() . "\n"
        );
        exit(1);
    }
}

if ($ejecutadas === 0) {
    echo "No hay migraciones pendientes.\n";
} else {
    echo "\n{$ejecutadas} migración(es) aplicadas correctamente.\n";
}

exit(0);
