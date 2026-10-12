<?php
/** Pruebas estáticas de regresión sin Zadarma, datos ni credenciales. */
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static fn(string $p): string => (string)file_get_contents($root . '/' . $p);
$hostCss = $read('public/css/telefonia_host.css');
$hostJs = $read('public/javascript/telefonia_host.js');
$shellCss = $read('public/css/telefonia_shell.css');
$shellJs = $read('public/javascript/telefonia_shell.js');
$phoneJs = $read('public/javascript/telefonia_persistente.js');
$shellView = $read('app/views/telefonia/shell.php');
$hostView = $read('app/views/telefonia/host.php');
$checks = [
    'SDK nativo no se altera desde JavaScript' =>
        !str_contains($hostJs, 'raiz.style.setProperty(') &&
        !str_contains($hostJs, 'observerNativo') &&
        str_contains($hostJs, "document.body.classList.add('telephony-host-incoming')"),
    'SDK nativo no se colapsa con CSS' =>
        !str_contains($hostCss, 'body:not(.telephony-host-incoming) > *') &&
        !str_contains($hostCss, 'width: 1px !important;'),
    'Permanece el SDK oficial para atender entrantes' =>
        str_contains($hostJs, 'window.zadarmaWidgetFn(') &&
        str_contains($hostJs, 'mostrarHostEntrante();') &&
        str_contains($hostView, 'prueba_telefonia/api/zadarma_webrtc.php'),
    'PBX no declara WebRTC listo sin SDK' =>
        str_contains($hostJs, 'hostReady: widgetReady,'),
    'Conexión reconocida luego de llamada entrante finalizada' =>
        str_contains($shellJs, "'incoming-finished'].includes(String(state.phase))") &&
        str_contains($phoneJs, "'incoming-finished'"),
    'El shell contiene un solo panel para llamadas entrantes' =>
        str_contains($phoneJs, 'const incomingInShell =') &&
        !str_contains($phoneJs, 'const incomingInShell = Boolean(state?.active)') &&
        str_contains($phoneJs, "getHostMode() === 'embedded'"),
    'La tarjeta nativa se muestra si WebRTC está listo' =>
        str_contains($shellJs, 'const showCall = Boolean(state?.hostReady)') &&
        str_contains($shellCss, 'right: -10000px; bottom: 12px'),
    'Texto explica la diferencia entre webhook y timbrado SIP' =>
        str_contains($shellView, 'data-shell-call-help') &&
        str_contains($shellJs, 'WebRTC no confirma el timbrado.'),
    'No se modifica capacidad de marcar o transferir' =>
        str_contains($hostJs, 'regToCall(') &&
        str_contains($phoneJs, 'transferCall: transferCall') &&
        str_contains($phoneJs, 'startCall: startCall'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) { fwrite(STDERR, "FALLO: $name\n"); exit(1); }
}
echo 'Telefonía entrante: OK (' . count($checks) . " pruebas estáticas)\n";
