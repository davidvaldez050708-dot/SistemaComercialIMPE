<?php
/**
 * php tests/agenda_expediente_responsive_smoke.php
 * Revisión estática; no necesita sesión o conexión MySQL.
 */
$root = dirname(__DIR__);
$agenda = (string)file_get_contents($root . '/app/views/seguimiento_vinculacion/agenda.php');
$detalle = (string)file_get_contents($root . '/app/views/seguimiento_vinculacion/detalle.php');
$css = (string)file_get_contents($root . '/public/css/responsive_sistema.css');
$js = (string)file_get_contents($root . '/public/javascript/seguimiento_expediente.js');
$checks = [
    'Agenda sigue teniendo calendario y reuniones' =>
        str_contains($agenda, 'agenda-calendar-grid') && str_contains($agenda, 'data-agenda-meeting'),
    'Calendario desplazable con teclado' =>
        str_contains($agenda, 'class="agenda-calendar-card" role="region" tabindex="0"'),
    'Calendario con instrucciones en pantalla móvil' =>
        str_contains($agenda, 'agenda-mobile-scroll-hint') &&
        str_contains($css, '.agenda-page .agenda-mobile-scroll-hint'),
    'El calendario conserva sus siete columnas sin comprimir eventos' =>
        str_contains($css, '.agenda-page .agenda-calendar-grid') &&
        str_contains($css, 'min-width: 760px'),
    'La agenda mantiene los modales originales' =>
        str_contains($agenda, 'modalAgendaSolicitud') &&
        str_contains($agenda, 'modalAgendaDetalle') &&
        str_contains($agenda, 'modalAgendaCorreo'),
    'Expediente conserva siete pestañas' =>
        str_contains($detalle, 'linkage-detail-tabs') &&
        substr_count($detalle, '<span>') >= 6,
    'Pestañas siguen siendo operables por teclado' =>
        str_contains($js, "tab.addEventListener('keydown'") &&
        str_contains($js, "setAttribute('aria-selected'"),
    'Navegación del expediente con scroll propio' =>
        str_contains($css, '.linkage-expediente-enhanced .linkage-expediente-section-nav') &&
        str_contains($css, 'overscroll-behavior-x: contain'),
    'Nombres largos y estado de etapa caben en móvil' =>
        str_contains($css, '.linkage-expediente-enhanced .linkage-panel .linkage-state-pill') &&
        str_contains($css, 'overflow-wrap: anywhere'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$name}\n");
        exit(1);
    }
}
echo 'Agenda y expediente responsive: OK (' . count($checks) . " comprobaciones estáticas)\n";
