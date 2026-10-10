<?php
/**
 * Cobertura estática de desbordamientos de los tres modales operativos.
 * php tests/seguimiento_modales_responsive_smoke.php
 */
$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/app/views/seguimiento_vinculacion/estado.php');
$css = (string)file_get_contents($root . '/public/css/responsive_sistema.css');
$checks = [
    'Los tres modales conservan sus IDs' =>
        str_contains($view, 'id="modalBuscarCandidato"') &&
        str_contains($view, 'id="modalConfirmarSeguimiento"') &&
        str_contains($view, 'id="modalAgregarSeguimientoManual"'),
    'Filtros y resultados conservan sus selectores' =>
        str_contains($view, 'data-candidate-search-form') &&
        str_contains($view, 'data-candidate-results') &&
        str_contains($view, 'data-candidate-pagination'),
    'Formularios de alta conservan guardado' =>
        str_contains($view, 'data-candidate-confirm-form') &&
        str_contains($view, 'data-manual-follow-form'),
    'Los modales se adaptan a alto y ancho de pantalla' =>
        str_contains($css, 'max-width: 767.98px'), 
    'Altura visible y contenido con scroll dentro del modal' =>
        str_contains($css, '#modalBuscarCandidato .modal-body') &&
        str_contains($css, 'max-height: calc(100dvh - 24px)') &&
        str_contains($css, 'overscroll-behavior-y: contain'),
    'Resultados de candidatos conservan espacio para paginación' =>
        str_contains($css, '#modalBuscarCandidato .linkage-candidate-results') &&
        str_contains($css, 'overflow: visible'),
    'Confirmar y registro mantienen sus acciones al pie' =>
        str_contains($css, '#modalConfirmarSeguimiento form[data-candidate-confirm-form]') &&
        str_contains($css, '#modalAgregarSeguimientoManual form[data-manual-follow-form]') &&
        str_contains($css, '.modal-footer > .btn'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}
echo 'Modales de Seguimiento responsive: OK (' . count($checks) . " verificaciones estáticas)\n";
