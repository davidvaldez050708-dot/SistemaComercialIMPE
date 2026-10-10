<?php
/** php tests/cuenta_clave_mapa_cobertura_smoke.php */
require_once dirname(__DIR__) . '/app/helpers/MapaEstadoHelper.php';

$raiz = dirname(__DIR__);
$vista = (string)file_get_contents($raiz . '/app/views/dashboard/cuenta_clave.php');
$servicio = (string)file_get_contents($raiz . '/app/services/CuentaClaveDashboardService.php');
$css = (string)file_get_contents($raiz . '/public/css/dashboard_cuenta_clave.css');
$ejemplos = [
    'Guanajuato' => 'guanajuato.png',
    'Querétaro' => 'queretaro.png',
    'Baja California' => 'baja-california.png',
    'Sinaloa' => 'sinaloa.png',
    'Quintana Roo' => 'quintana-roo.png',
    'Q. Roo' => 'quintana-roo.png',
    'Yucatán' => 'yucatan.png',
    'Michoacán' => 'michoacán.png',
    'Ciudad de México' => 'ciudad-de-mexico.png',
    'Estado de México' => 'estado-de-mexico.png',
    'Nuevo León' => 'nuevo-leon.png',
    'San Luis Potosí' => 'san-luis-potosi.png',
];
foreach ($ejemplos as $estado => $archivo) {
    $resultado = impeRutaMapaEstado($estado);
    $esperado = 'public/img/estados/' . $archivo;
    if ($resultado !== $esperado || !is_file($raiz . '/' . $resultado)) {
        fwrite(STDERR, "Mapa incorrecto para {$estado}: {$resultado}\n");
        exit(1);
    }
}
if (impeRutaMapaEstado('Municipio de Prueba') !== '') {
    fwrite(STDERR, "El nombre de un municipio no debe crear un mapa estatal ficticio.\n");
    exit(1);
}
$checks = [
    'El servicio conserva nombre oficial' => str_contains($servicio, "'nombre_estado' => (string)("),
    'La vista usa mapa solo en modo estados' => str_contains($vista, 'impeRutaMapaEstado(') &&
        str_contains($vista, "=== 'estados'"),
    'La imagen incluye descripción' => str_contains($vista, 'alt="Mapa de'),
    'Fallback de ícono en municipio o si falta mapa' => str_contains($vista, 'if ($rutaMapaCobertura !== \'\')') &&
        str_contains($vista, 'bi-geo-alt'),
    'La imagen conserva proporción original' => str_contains($css, '.kam-coverage-icon img') &&
        str_contains($css, 'object-fit: contain'),
];
foreach ($checks as $nombre => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$nombre}\n");
        exit(1);
    }
}
echo 'Mapas de Cuenta Clave: OK (' .
    count($ejemplos) . " mapas y " . count($checks) . " verificaciones)\n";
