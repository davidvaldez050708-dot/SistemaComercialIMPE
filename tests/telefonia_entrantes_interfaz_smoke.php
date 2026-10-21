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
    'Se muestra modal CRM, no panel blanco al entrar llamada' =>
        str_contains($shellJs, 'const customVisible = !callbackRegistration && (incoming || finishedIncoming || shownOutgoing)') &&
        str_contains($shellJs, "incomingPanel.hidden = !customVisible") &&
        str_contains($shellView, 'data-incoming-answer') &&
        str_contains($shellView, 'data-incoming-hangup') &&
        str_contains($shellCss, '.impe-incoming-panel'),
    'El motor Zadarma permanece vivo fuera del viewport' =>
        str_contains($shellCss, 'right: -10000px; bottom: 12px') &&
        str_contains($shellJs, "call.classList.toggle('is-visible', nativeVisible)"),
    'Acciones entrantes mediante SDK real de Zadarma' =>
        str_contains($hostJs, 'IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY') &&
        str_contains($hostView, 'telefonia_entrantes_bridge.js') &&
        str_contains($shellJs, 'providerBridge()?.answer()') &&
        str_contains($shellJs, 'providerBridge()?.hangup()') &&
        str_contains($hostJs, 'const setMuted = function (value)') &&
        str_contains($hostJs, 'const tracks = syncMicrophone();') &&
        str_contains($hostJs, 'nuevaLlamadaEntrante ? false : Boolean(state.muted)'),
    'Sin reconocimiento visual por CSS o color de iconos' =>
        !str_contains($read('public/javascript/telefonia_entrantes_bridge.js'), 'querySelectorAll') &&
        str_contains($read('public/javascript/telefonia_entrantes_bridge.js'), "'finishCall'") &&
        str_contains($read('public/javascript/telefonia_entrantes_bridge.js'), "'answer'"),
    'Mismo diseño que Seguimiento' =>
        str_contains($shellView, 'impePersistentPhoneStyles') &&
        str_contains($shellView, 'persistent-phone-panel impe-incoming-panel') &&
        str_contains($shellView, 'persistent-phone-header') &&
        str_contains($shellView, 'persistent-phone-compact-actions'),
    'Manrope idéntica al CRM en el documento persistente' =>
        str_contains($shellView, 'family=Manrope') &&
        str_contains($shellCss, "font-family: 'Manrope', sans-serif;") &&
        str_contains($shellCss, '.impe-incoming-panel, .impe-incoming-panel button'),
    'Se puede mover y minimizar sin desconectar' =>
        str_contains($shellJs, "addEventListener('pointerdown'") &&
        str_contains($shellJs, "addEventListener('pointermove'") &&
        str_contains($shellJs, "toggleIncomingCompact") &&
        str_contains($shellJs, "localStorage.setItem(incomingPositionKey") &&
        str_contains($shellView, 'data-incoming-answer-compact'),
    'Sin mensaje permanente ni enlace Zadarma' =>
        !str_contains($shellView, 'data-incoming-provider') &&
        !str_contains($shellJs, 'El teléfono está conectando sus controles') &&
        str_contains($shellJs, "notice.hidden = !(actionNotice || sdkNotice)"),
    'Cada timbrado comienza en cero' =>
        str_contains($hostJs, "state.phase === 'incoming-ringing') return 0;") &&
        str_contains($hostJs, 'duration: answered') &&
        str_contains($shellJs, ".includes(call.phase) ? 0 :") &&
        str_contains($shellJs, "'incoming-ringing'") &&
        str_contains($shellJs, 'syncIncomingClock(value)') &&
        str_contains($shellJs, 'updateIncomingTimer(phoneState)') &&
        str_contains($shellJs, 'incomingCallId(value) !== incomingCallId(currentState)'),
    'Contestar requiere confirmación y colgar conserva el resumen PBX' =>
        !str_contains($shellJs, 'Contestando… espera la confirmación') &&
        str_contains($shellJs, 'const finishing = shownOutgoing') &&
        str_contains($shellJs, "pendingIncomingAction = {") &&
        str_contains($shellJs, "const finishingIncoming = incoming && pending === 'hangup'") &&
        str_contains($hostJs, 'actualizarEstadoTrasAccion'),
    'Salida y recepción comparten una sola instancia WebRTC' =>
        str_contains($phoneJs, "getHostMode() === 'embedded'") &&
        str_contains($phoneJs, 'shell.ensureHost();') &&
        str_contains($phoneJs, 'await prepare({ openHost: false });') &&
        str_contains($shellJs, 'singleCallPanel: true') &&
        str_contains($shellJs, 'shownOutgoing') &&
        str_contains($shellJs, 'pendingOutgoingHangup'),
    'No se modifica capacidad de marcar o transferir' =>
        str_contains($hostJs, 'regToCall(') &&
        str_contains($phoneJs, 'transferCall: transferCall') &&
        str_contains($phoneJs, 'startCall: startCall'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) { fwrite(STDERR, "FALLO: $name\n"); exit(1); }
}
echo 'Telefonía entrante: OK (' . count($checks) . " pruebas estáticas)\n";
