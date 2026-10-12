<?php
/**
 * Regresión visual del marcador: la shell NO hereda fuentes/iconos del
 * dashboard (son documentos HTML independientes por iframe).
 * Es un test sin DB, credenciales ni tráfico con Zadarma.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/views/telefonia/shell.php');
$style = file_get_contents($root . '/public/css/telefonia_teclado.css');
$js = file_get_contents($root . '/public/javascript/telefonia_teclado.js');
$shellCss = file_get_contents($root . '/public/css/telefonia_shell.css');
$dashboard = file_get_contents($root . '/app/views/layout/dashboard_head.php');

$checks = [
    'Manrope cargada directamente dentro del shell telefónico' =>
        str_contains($view, 'fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap') &&
        str_contains($dashboard, 'fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap'),
    'Bootstrap Icons coincide exactamente con la versión del dashboard' =>
        str_contains($view, 'bootstrap-icons@1.13.1/font/bootstrap-icons.min.css') &&
        str_contains($dashboard, 'bootstrap-icons@1.13.1/font/bootstrap-icons.min.css'),
    'CSS del teclado usa tokens de la institución y Manrope' =>
        str_contains($style, "'Manrope',sans-serif") &&
        str_contains($style, 'var(--color-primary,#273A8A)') &&
        str_contains($style, 'var(--color-border,#E5E9EF)'),
    'Controles del teléfono no heredan fuente predeterminada de Firefox' =>
        str_contains($style, ".impe-dialer button,.impe-dialer input {font-family:'Manrope',sans-serif;}") &&
        !str_contains($style, 'Manrope,system-ui'),
    'Iconos basados en Bootstrap Icons, sin símbolos Unicode del SO' =>
        str_contains($js, 'bi bi-telephone-fill') &&
        str_contains($js, 'bi bi-x-lg') &&
        str_contains($js, 'bi bi-backspace') &&
        str_contains($js, 'bi bi-telephone-outbound') &&
        !str_contains($js, '>☎') &&
        !str_contains($js, '>×'),
    'Solo un botón de borrar dentro del teclado y no en el campo' =>
        substr_count($js, 'data-dialer-backspace') === 0 &&
        str_contains($js, "['1','2','3','4','5','6','7','8','9','+','0','⌫']") &&
        str_contains($js, 'data-dialer-key'),
    'Estado y controles conservan etiquetas actualizables' =>
        str_contains($js, 'data-dialer-state-label') &&
        str_contains($js, 'data-dialer-mute-label') &&
        str_contains($js, "qs('[data-dialer-state-label]').textContent") &&
        str_contains($js, "qs('[data-dialer-mute-label]').textContent"),
    'Modal conserva márgenes y radios coherentes con Seguimiento' =>
        str_contains($style, 'width:min(420px,calc(100vw - 24px))') &&
        str_contains($style, 'border-radius:14px') &&
        str_contains($style, 'box-shadow:0 16px 38px rgba(28,46,74,.14)'),
    'Botones, campos y panel flotante utilizan tamaños compactos' =>
        str_contains($style, '.impe-dialer-keys button {min-height:34px') &&
        str_contains($style, 'min-height:39px') &&
        str_contains($style, '.impe-dialer.is-active-call .impe-dialer-dialog'),
    'Responsive y accesibilidad se mantienen' =>
        str_contains($style, '@media(max-width:575px)') &&
        str_contains($style, '.impe-dialer button:focus-visible') &&
        str_contains($js, 'aria-live="polite"'),
    'Los indicadores del shell usan la misma Manrope' =>
        !str_contains($shellCss, 'system-ui, sans-serif') &&
        str_contains($shellCss, "'Manrope', sans-serif"),
    'Este cambio no toca las capacidades de llamadas' =>
        str_contains($js, 'await api.startCall({') &&
        str_contains($js, 'await api.probe()') &&
        str_contains($js, 'await api.transferCall(extension,attended)')
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $label\n");
        exit(1);
    }
}
echo 'Marcador visual: OK (' . count($checks) . " comprobaciones)\n";
