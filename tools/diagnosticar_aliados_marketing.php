<?php

require_once __DIR__ . '/../config/db_connection.php';

$database = new Database();
$connection = $database->connect();

function tableExists(mysqli $connection, string $table): bool
{
    $safe = $connection->real_escape_string($table);
    $result = $connection->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

echo "=== Diagnóstico Aliados -> Marketing ===\n";

foreach ([
    'aliados_asignaciones',
    'seguimientos_vinculacion',
    'seguimientos_vinculacion_post_envio'
] as $table) {
    echo str_pad($table, 42) .
        (tableExists($connection, $table) ? "OK\n" : "NO EXISTE\n");
}

if (!tableExists($connection, 'aliados_asignaciones')) {
    exit(1);
}

$result = $connection->query(
    "SELECT COUNT(*) AS total
     FROM aliados_asignaciones
     WHERE activo = 1"
);

$totalAsignaciones = $result
    ? (int)($result->fetch_assoc()['total'] ?? 0)
    : 0;

echo "\nAliados activos en aliados_asignaciones: {$totalAsignaciones}\n";

$sql = "SELECT
            aa.seguimiento_id,
            aa.activo,
            aa.origen_asignacion,
            aa.asignado_at,
            s.nombre_entidad,
            s.estado_id,
            e.nombre AS estado_nombre,
            COALESCE(m.nombre, '') AS municipio_nombre,
            COALESCE(p.convenio_formalizado_at, aa.asignado_at) AS fecha_aliado,
            CONCAT_WS(' ', u.nombre, u.apellidos) AS analista
        FROM aliados_asignaciones aa
        INNER JOIN seguimientos_vinculacion s
            ON s.id = aa.seguimiento_id
        INNER JOIN estados e
            ON e.id = s.estado_id
        LEFT JOIN municipios m
            ON m.id = s.municipio_id
        LEFT JOIN seguimientos_vinculacion_post_envio p
            ON p.seguimiento_id = s.id
        LEFT JOIN usuarios u
            ON u.id = s.analista_id
        WHERE aa.activo = 1
        ORDER BY COALESCE(p.convenio_formalizado_at, aa.asignado_at) DESC
        LIMIT 20";

$result = $connection->query($sql);

if (!$result) {
    fwrite(STDERR, "ERROR SQL: " . $connection->error . "\n");
    exit(1);
}

echo "\nPrimeros aliados encontrados:\n";

$rows = 0;
while ($row = $result->fetch_assoc()) {
    $rows++;
    echo sprintf(
        "%2d. ID %d | %s | %s | %s | %s | %s\n",
        $rows,
        (int)$row['seguimiento_id'],
        (string)$row['nombre_entidad'],
        (string)$row['estado_nombre'],
        (string)$row['municipio_nombre'],
        (string)$row['analista'],
        (string)$row['fecha_aliado']
    );
}

if ($rows === 0) {
    echo "(sin registros)\n";
}

echo "\n=== Fin diagnóstico ===\n";
