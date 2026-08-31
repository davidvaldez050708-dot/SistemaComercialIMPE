<?php

require_once __DIR__ . '/app/services/InegiGeoService.php';

$service = new InegiGeoService();

/*
 * Estado de México = clave INEGI 15
 */
$resultado = $service->obtenerMunicipiosEstado('15');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Prueba INEGI - Municipios</h2>';

if (!$resultado['ok']) {
    echo '<p><strong>Error:</strong> '
        . htmlspecialchars($resultado['mensaje'])
        . '</p>';
    exit;
}

echo '<p><strong>Clave estatal:</strong> '
    . htmlspecialchars($resultado['clave_estado'])
    . '</p>';

echo '<p><strong>Municipios recibidos:</strong> '
    . (int) $resultado['total_municipios']
    . '</p>';

echo '<h3>Primeros 10 municipios</h3>';

echo '<pre>';
print_r(array_slice($resultado['municipios'], 0, 10));
echo '</pre>';