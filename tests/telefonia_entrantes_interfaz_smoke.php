<?php
/**
 * Regresión de la llamada entrante del 11/10/2026.
 * Sin red, Zadarma, MySQL, navegador ni credenciales.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static fn(string $p): string => (string)file_get_contents($root . '/' . $p);
$hostCss = $read('public/css/telefonia_host.css');
$hostJs = $read('public/javascript/telefonia_host.js');
$shellCss = $read('public/css/telefonia_shell.css');
$shellJs = $read('public/javascript/telefonia_shell.js');
$phoneJs = $read('public/javascript/telefonia_persistente.js');
$hostView = $read('app/views/telefonia/host.php');

$checks = [
    'El widget Zadarma no queda fijado a 1x1 durante una entrante' =>
        str_contains($hostCss, 'body:not(.telephony-host-incoming) > *:not(') &&
        str_contains($hostCss, 'body.telephony-host-incoming > *:not(') &&
        str_contains($hostCss, 'max-height: none !important;') &&
        str_contains($hostCss, 'pointer-events: auto !important;'),
    'Activación y restauración del SDK condicionada al estado real' =>
        str_contains($hostJs, "document.body.classList.add('telephony-host-incoming')") &&
        str_contains($hostJs, "document.body.classList.remove('telephony-host-incoming')") &&
        str_contains($hostJs, 'if (nativeUiVisible) {') &&
        str_contains($hostJs, 'mostrarInterfazNativa();') &&
        str_contains($hostJs, 'ocultarInterfazNativa();'),
    'Iframe mantiene tamaño real antes del primer timbrado' =>
        str_contains($shellCss, 'right: -10000px; bottom: 12px') &&
        !str_contains($shellCss, 'width: 3px; height: 3px;') &&
        str_contains($shellCss, 'height: min(580px, calc(100dvh - 24px));'),
    'Un solo panel al timbrar en el modo embebido' =>
        str_contains($phoneJs, "const incomingInShell = Boolean(state?.active)") &&
        str_contains($phoneJs, "getHostMode() === 'embedded'") &&
        str_contains($phoneJs, 'panel.hidden = !show || managedByShell || incomingInShell;'),
    'No se sustituye el botón nativo Contestar por una falsa marcación CRM' =>
        str_contains($hostJs, 'window.zadarmaWidgetFn(') &&
        str_contains($hostJs, 'mostrarHostEntrante();') &&
        str_contains($phoneJs, 'data-phone-start') &&
        str_contains($phoneJs, 'incomingInShell'),
    'Llamada entrante finalizada puede cerrarse sin colgar' =>
        str_contains($phoneJs, "state?.phase === 'incoming-finished'") &&
        str_contains($phoneJs, 'clearFinished();') &&
        str_contains($phoneJs, 'isIndependentDialer || incoming') &&
        str_contains($phoneJs, 'Cerrar resumen de llamada finalizada'),
    'Aviso de WebRTC no se superpone al botón Mostrar llamada' =>
        str_contains($shellJs, "status.classList.toggle('is-raised', !restore.hidden)") &&
        str_contains($shellCss, '.impe-phone-status.is-raised { bottom: 75px; }'),
    'Se mantiene WebRTC autorizado y sin modificar credenciales' =>
        str_contains($hostView, 'prueba_telefonia/api/zadarma_webrtc.php') &&
        str_contains($hostJs, 'permiteEntrantes') &&
        str_contains($shellJs, 'claimLease()') &&
        str_contains($phoneJs, 'transferCall: transferCall') &&
        str_contains($phoneJs, 'startCall: startCall')
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $label\n");
        exit(1);
    }
}
echo 'Telefonía entrante UI: OK (' . count($checks) . " comprobaciones)\n";
