<?php
/*
 * Verificación estática de overflow: controles y estructura comunes.
 * Ejecutar sin conexión a BD: php tests/responsive_overflow_smoke.php
 */
$root = dirname(__DIR__);
$css = file_get_contents($root . '/public/css/responsive_sistema.css');
$view = file_get_contents($root . '/app/views/seguimiento_vinculacion/index.php');
$head = file_get_contents($root . '/app/views/layout/dashboard_head.php');

$checks = [
 'CSS responsive integrado en la plantilla de todos los roles' =>
     strpos($head, 'responsive_sistema.css') !== false,
 'Selector de territorios tiene un control de reporte identificable' =>
     strpos($view, 'linkage-report-button') !== false,
 'La cuadrícula del selector no impone ancho mínimo' =>
     strpos($css, '.admin-content .linkage-selector .data-selector-heading') !== false &&
     strpos($css, 'grid-template-columns: minmax(0, 1fr);') !== false,
 'El botón de reportes ocupa el ancho disponible en móvil' =>
     strpos($css, '.linkage-report-button') !== false &&
     strpos($css, 'justify-self: stretch;') !== false,
 'Los filtros de territorios usan columna flexible' =>
     strpos($css, '.linkage-territory-toolbar') !== false,
 'Hay controles para tablet y teléfono' =>
     strpos($css, 'max-width: 1199.98px') !== false &&
     strpos($css, 'max-width: 767.98px') !== false,
 'Tablas extensas conservan desplazamiento dentro de su contenedor' =>
     strpos($css, 'overflow-x: auto;') !== false &&
     strpos($css, '.telephony-table-wrap') !== false &&
     strpos($css, '.convocatoria-overview-table-wrap') !== false,
 'La corrección no oculta overflow del documento raíz' =>
     !preg_match('/(?:html|body)\s*\{[^}]*overflow-x\s*:\s*(?:hidden|clip)/s', $css),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}

echo 'Responsive overflow: OK (' . count($checks) . " comprobaciones estáticas)\n";
