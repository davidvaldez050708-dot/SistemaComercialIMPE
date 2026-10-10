<?php
/**
 * Comprobación estática contra el espacio en blanco de Agenda en móvil.
 * php tests/agenda_toolbar_layout_smoke.php
 */
$path = dirname(__DIR__) . '/public/css/agenda_reunion.css';
$css = (string)file_get_contents($path);
$checks = [
    'Cuadrícula inicia desde arriba' =>
        str_contains($css, '.agenda-page {') &&
        str_contains($css, 'align-content: start;') &&
        str_contains($css, 'grid-auto-rows: max-content;'),
    'Agenda no estira su cabecera en tablet/móvil' =>
        str_contains($css, '.agenda-page > .agenda-toolbar') &&
        str_contains($css, 'align-self: start;') &&
        str_contains($css, 'height: auto;'),
    'Cabecera vertical no distribuye espacio sobrante' =>
        str_contains($css, 'flex-direction: column;') &&
        str_contains($css, 'justify-content: flex-start;'),
    'Controles se mantienen junto a la descripción' =>
        str_contains($css, '.agenda-page .agenda-toolbar-actions') &&
        str_contains($css, 'flex: 0 0 auto;') &&
        str_contains($css, 'align-self: stretch;'),
    'Calendario continúa con scroll horizontal interno' =>
        str_contains($css, '.agenda-calendar-card {') &&
        str_contains($css, 'overflow-x: auto;') &&
        str_contains($css, 'min-width: 760px;'),
];
foreach ($checks as $name => $passes) {
    if (!$passes) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}
echo 'Agenda cabecera móvil: OK (' . count($checks) . " comprobaciones estáticas)\n";
