<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../app/services/DenueService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse desde la terminal.\n");
}

$database = new Database();
$connection = $database->connect();
$denue = new DenueService();

$sql = "SELECT id, estado_id, clave_origen, nombre_entidad
        FROM seguimientos_vinculacion
        WHERE origen = 'DENUE'
            AND municipio_id IS NULL
            AND activo = 1
        ORDER BY id ASC";

$resultado = $connection->query($sql);

if (!$resultado) {
    fwrite(STDERR, "No fue posible consultar los seguimientos DENUE.\n");
    exit(1);
}

$pendientes = [];
while ($fila = $resultado->fetch_assoc()) {
    $pendientes[] = $fila;
}

if (empty($pendientes)) {
    echo "No hay seguimientos DENUE pendientes de municipio.\n";
    exit(0);
}

$stmtPorClave = $connection->prepare(
    "SELECT id, nombre
     FROM municipios
     WHERE estado_id = ?
       AND estado = 1
       AND (
           clave_inegi = ?
           OR RIGHT(LPAD(clave_inegi, 5, '0'), 3) = ?
       )
     LIMIT 1"
);

$stmtPorNombre = $connection->prepare(
    "SELECT id, nombre
     FROM municipios
     WHERE estado_id = ?
       AND estado = 1
       AND LOWER(TRIM(nombre)) = LOWER(TRIM(?))
     LIMIT 1"
);

$stmtActualizar = $connection->prepare(
    "UPDATE seguimientos_vinculacion
     SET municipio_id = ?,
         updated_at = NOW()
     WHERE id = ?
       AND municipio_id IS NULL"
);

$actualizados = 0;
$sinResolver = 0;
$erroresDenue = 0;

foreach ($pendientes as $seguimiento) {
    $seguimientoId = (int)$seguimiento['id'];
    $estadoId = (int)$seguimiento['estado_id'];
    $claveOrigen = trim((string)$seguimiento['clave_origen']);
    $nombreEntidad = trim((string)$seguimiento['nombre_entidad']);
    $partes = explode(':', $claveOrigen, 2);

    if (count($partes) !== 2 || $partes[0] !== 'DENUE' || !ctype_digit($partes[1])) {
        echo "[OMITIDO] #{$seguimientoId} {$nombreEntidad}: clave DENUE inválida.\n";
        $sinResolver++;
        continue;
    }

    $respuesta = $denue->obtenerEstablecimientoPorId($partes[1]);

    if (($respuesta['ok'] ?? false) !== true || !is_array($respuesta['candidato'] ?? null)) {
        echo "[ERROR DENUE] #{$seguimientoId} {$nombreEntidad}\n";
        $erroresDenue++;
        continue;
    }

    $candidato = $respuesta['candidato'];
    $municipio = null;
    $claveArea = trim((string)($candidato['clave_area'] ?? ''));

    if (strlen($claveArea) >= 5 && ctype_digit(substr($claveArea, 0, 5))) {
        $claveMunicipal = substr($claveArea, 2, 3);
        $stmtPorClave->bind_param('iss', $estadoId, $claveMunicipal, $claveMunicipal);
        $stmtPorClave->execute();
        $municipio = $stmtPorClave->get_result()->fetch_assoc() ?: null;
    }

    if (!$municipio) {
        $municipioNombre = trim((string)($candidato['municipio_nombre'] ?? ''));

        if ($municipioNombre !== '' && strcasecmp($municipioNombre, 'Estatal') !== 0) {
            $stmtPorNombre->bind_param('is', $estadoId, $municipioNombre);
            $stmtPorNombre->execute();
            $municipio = $stmtPorNombre->get_result()->fetch_assoc() ?: null;
        }
    }

    if (!$municipio) {
        echo "[SIN RESOLVER] #{$seguimientoId} {$nombreEntidad}: municipio no localizado.\n";
        $sinResolver++;
        continue;
    }

    $municipioId = (int)$municipio['id'];
    $stmtActualizar->bind_param('ii', $municipioId, $seguimientoId);
    $stmtActualizar->execute();

    if ($stmtActualizar->affected_rows > 0) {
        $actualizados++;
        echo "[OK] #{$seguimientoId} {$nombreEntidad} -> {$municipio['nombre']}\n";
    }
}

echo "\nProceso terminado.\n";
echo "Actualizados: {$actualizados}\n";
echo "Sin resolver: {$sinResolver}\n";
echo "Errores DENUE: {$erroresDenue}\n";
