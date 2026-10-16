<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require_once $root . '/config/db_connection.php';

$db = (new Database())->connect();

if (!$db) {
    fwrite(STDERR, "[ERROR] No se pudo conectar a la base de datos.\n");
    exit(1);
}

$institucion = 'Instituto de la Juventud Celayense';

$sql = "SELECT id
        FROM seguimientos_vinculacion
        WHERE TRIM(nombre_entidad) = ?
        ORDER BY id DESC
        LIMIT 1";

$stmt = $db->prepare($sql);

if (!$stmt) {
    fwrite(STDERR, "[ERROR] " . $db->error . "\n");
    exit(1);
}

$stmt->bind_param('s', $institucion);
$stmt->execute();

$result = $stmt->get_result();
$fila = $result ? $result->fetch_assoc() : null;

if (!$fila) {
    fwrite(STDERR, "[ERROR] No se encontró el convenio: {$institucion}\n");
    exit(1);
}

$seguimientoId = (int)$fila['id'];

$stmtEliminar = $db->prepare(
    "DELETE FROM marketing_convenio_atencion
     WHERE seguimiento_id = ?"
);

if (!$stmtEliminar) {
    fwrite(STDERR, "[ERROR] " . $db->error . "\n");
    exit(1);
}

$stmtEliminar->bind_param('i', $seguimientoId);

if (!$stmtEliminar->execute()) {
    fwrite(STDERR, "[ERROR] No fue posible restablecer el convenio.\n");
    exit(1);
}

echo "[OK] Convenio restablecido a PENDIENTE para la prueba.\n";
echo "Institución: {$institucion}\n";
echo "Seguimiento ID: {$seguimientoId}\n";
