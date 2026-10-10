<?php
/**
 * Verificación estática para que Inicio del Analista continúe legible en móvil.
 * php tests/analista_inicio_responsive_smoke.php
 */
$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/app/views/layout/dashboard_head.php');
$view = (string)file_get_contents($root . '/app/views/dashboard/analista.php');
$css = (string)file_get_contents($root . '/public/css/responsive_sistema.css');

$checks = [
    'Inicio tiene alcance por módulo' => str_contains($view, 'analyst-dashboard-v2'),
    'La hoja responsive se carga para todos los roles' => str_contains($layout, 'responsive_sistema.css'),
    'Los nombres de agenda se permiten en dos líneas' =>
        str_contains($css, '.analyst-dashboard-v2 .analyst-upcoming-item > div > strong') &&
        str_contains($css, '-webkit-line-clamp: 2'),
    'Las actividades no fuerzan títulos de una sola línea' =>
        str_contains($css, '.analyst-dashboard-v2 .analyst-activity-copy strong') &&
        str_contains($css, 'white-space: normal'),
    'Las tarjetas de indicadores tienen columnas flexibles' =>
        str_contains($css, '.analyst-dashboard-v2 .analyst-kpi-panel') &&
        str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))'),
    'Hay adaptación para pantallas estrechas' =>
        str_contains($css, '@media (max-width: 380px)'),
];

foreach ($checks as $name => $pass) {
    if (!$pass) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}

echo 'Inicio Analista responsive: OK (' . count($checks) . " verificaciones estáticas)\n";
