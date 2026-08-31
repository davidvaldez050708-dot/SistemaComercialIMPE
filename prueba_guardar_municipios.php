<?php

require_once __DIR__ . '/app/services/InegiGeoService.php';
require_once __DIR__ . '/app/models/DataTerritorialModel.php';

header('Content-Type: text/html; charset=utf-8');

$claveEstadoInegi = '15';
$estadoId = 15;

$inegiService = new InegiGeoService();
$modelo = new DataTerritorialModel();

echo '<h2>Prueba de guardado de municipios - Estado de México</h2>';

/*
|--------------------------------------------------------------------------
| 1. Consultar municipios oficiales
|--------------------------------------------------------------------------
*/

$resultadoInegi = $inegiService->obtenerMunicipiosEstado(
    $claveEstadoInegi
);

if (
    !isset($resultadoInegi['ok']) ||
    $resultadoInegi['ok'] !== true
) {
    echo '<p><strong>Error:</strong> '
        . htmlspecialchars(
            $resultadoInegi['mensaje']
                ?? 'No fue posible consultar los municipios.'
        )
        . '</p>';

    exit;
}

$municipios = $resultadoInegi['municipios'] ?? [];

echo '<p><strong>Municipios recibidos desde INEGI:</strong> '
    . count($municipios)
    . '</p>';

/*
|--------------------------------------------------------------------------
| 2. Validación de seguridad
|--------------------------------------------------------------------------
|
| Para esta prueba esperamos exactamente los municipios correspondientes
| al Estado de México.
|
*/

if (count($municipios) !== 125) {
    echo '<p><strong>Operación cancelada.</strong></p>';

    echo '<p>Se esperaban 125 municipios para Estado de México, '
        . 'pero INEGI devolvió '
        . count($municipios)
        . '.</p>';

    exit;
}

/*
|--------------------------------------------------------------------------
| 3. Guardar mediante UPSERT
|--------------------------------------------------------------------------
*/

$resultadoGuardado = $modelo->actualizarMunicipiosOficiales(
    $estadoId,
    $municipios
);

if (
    !isset($resultadoGuardado['ok']) ||
    $resultadoGuardado['ok'] !== true
) {
    echo '<p><strong>Error al guardar:</strong> '
        . htmlspecialchars(
            $resultadoGuardado['mensaje']
                ?? 'No fue posible actualizar los municipios.'
        )
        . '</p>';

    exit;
}

/*
|--------------------------------------------------------------------------
| 4. Resultado
|--------------------------------------------------------------------------
*/

echo '<p><strong>Resultado:</strong> '
    . htmlspecialchars($resultadoGuardado['mensaje'])
    . '</p>';

echo '<p><strong>Municipios procesados:</strong> '
    . (int) ($resultadoGuardado['procesados'] ?? 0)
    . '</p>';

echo '<p style="color: green;"><strong>Prueba finalizada correctamente.</strong></p>';