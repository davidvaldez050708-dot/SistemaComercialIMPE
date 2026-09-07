<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/db_connection.php';

const NOMBRE_PLANTILLA_CORREO_INICIAL = 'Correo Programa de Profesionalización REDMEX 2026';
const ASUNTO_CORREO_INICIAL = 'Oferta Educativa y Becas Red Educativa México';

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$db = new Database();
$conexion = $db->connect();

$linea('Actualización del asunto del primer correo REDMEX');
$linea(str_repeat('=', 48));
$linea('Asunto objetivo: ' . ASUNTO_CORREO_INICIAL);
$linea();

$conexion->begin_transaction();

try {
    $nombre = NOMBRE_PLANTILLA_CORREO_INICIAL;
    $asunto = ASUNTO_CORREO_INICIAL;

    $stmtPlantilla = $conexion->prepare(
        "UPDATE plantillas_vinculacion
         SET asunto = ?
         WHERE tipo = 'CORREO'
           AND nombre = ?
           AND activo = 1"
    );
    $stmtPlantilla->bind_param('ss', $asunto, $nombre);
    $stmtPlantilla->execute();
    $plantillasActualizadas = $stmtPlantilla->affected_rows;

    $stmtBorradores = $conexion->prepare(
        "UPDATE oficios_vinculacion
         SET asunto_correo = ?
         WHERE estado_oficio <> 'ENVIADO'
           AND fecha_envio IS NULL
           AND asunto_correo IS NOT NULL
           AND asunto_correo LIKE 'Programa de Profesionalización%'"
    );
    $stmtBorradores->bind_param('s', $asunto);
    $stmtBorradores->execute();
    $borradoresActualizados = $stmtBorradores->affected_rows;

    $conexion->commit();

    $linea('[OK] Plantillas actualizadas: ' . $plantillasActualizadas);
    $linea('[OK] Borradores pendientes actualizados: ' . $borradoresActualizados);
    $linea('[OK] El primer correo utilizará: ' . ASUNTO_CORREO_INICIAL);
} catch (Throwable $error) {
    $conexion->rollback();
    $linea('[ERROR] No fue posible actualizar el asunto.');
    $linea('Detalle: ' . $error->getMessage());
    exit(1);
}
