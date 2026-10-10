<?php
/**
 * Inspección estática de elementos sensibles a anchos móviles.
 * php tests/analista_modulos_responsive_smoke.php
 */
$base = dirname(__DIR__);
$css = (string)file_get_contents($base . '/public/css/responsive_sistema.css');
$desempeno = (string)file_get_contents($base . '/app/views/desempeno/index.php');
$territorial = (string)file_get_contents($base . '/app/views/data_territorial/index.php');
$reporte = (string)file_get_contents($base . '/app/views/reportes/data_territorial.php');

$checks = [
    'Selector territorial usa estructura reconocible' =>
        str_contains($territorial, 'data-territorial-detail-switch'),
    'Selector territorial permite reducir su ancho' =>
        str_contains($css, '.data-territorial-module .data-territorial-detail-switch') &&
        str_contains($css, '.data-territorial-module .data-territorial-detail-select'),
    'Navegación de secciones con desplazamiento interno' =>
        str_contains($css, '.data-territorial-module .data-section-nav') &&
        str_contains($css, 'overscroll-behavior-x: contain'),
    'Gráfica de Desempeño es accesible mediante teclado' =>
        str_contains($desempeno, 'class="performance-chart-scroll" role="region" tabindex="0"'),
    'Historial de Desempeño no oculta columnas' =>
        str_contains($css, '.performance-module .performance-chart-scroll') &&
        str_contains($css, 'overflow-x: auto') &&
        str_contains($css, 'grid-template-columns: repeat(var(--performance-days), minmax(28px, 1fr))'),
    'El formulario de filtros se adapta' =>
        str_contains($css, '.performance-module .performance-filter-field'),
    'Reporte conserva las acciones de exportación' =>
        str_contains($reporte, 'territorial-report-actions') &&
        str_contains($css, '.report-module .territorial-report-actions .btn'),
    'El catálogo de reportes adapta sus botones' =>
        str_contains($css, '.report-module .report-catalog-card .report-card-action'),
    'No se oculta el desbordamiento de la página raíz' =>
        !preg_match('/(?:html|body)\s*\{[^}]*overflow-x\s*:\s*(?:hidden|clip)/s', $css),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}

echo 'Módulos territoriales, Desempeño y Reportes: OK (' . count($checks) . " comprobaciones estáticas)\n";
