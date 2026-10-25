<?php
/** Regression checks for Marketing reception layout and optional WebRTC tab.
 * Purely static, no Zadarma/MySQL access or browser assumptions.
 */
$root = dirname(__DIR__);
$read = static fn(string $path) => (string)file_get_contents($root . '/' . $path);
$marketing = $read('app/views/dashboard/marketing.php');
$view = $read('app/views/telefonia/recepcion_marketing.php');
$hostView = $read('app/views/telefonia/host.php');
$css = $read('public/css/telefonia_recepcion_marketing.css');
$hostCss = $read('public/css/telefonia_host.css');
$marketingJs = $read('public/javascript/telefonia_recepcion_marketing.js');
$recordingService = $read('app/services/ZadarmaRecordingService.php');
$recordingApi = $read('prueba_telefonia/api/grabacion_recepcion_marketing.php');
$shellJs = $read('public/javascript/telefonia_shell.js');
$persistent = $read('public/javascript/telefonia_persistente.js');
$hostJs = $read('public/javascript/telefonia_host.js');

$include = "require __DIR__ . '/../telefonia/recepcion_marketing.php';";
$grid = strpos($marketing, '<div class="metric-grid mb-4">');
$nav = $read('app/views/telefonia/mis_llamadas_navegacion.php');
$controller = $read('app/controllers/TelefoniaController.php');
$recTab = strpos($nav, "'vista'=>'recepcion'");
$histTab = strpos($nav, "'vista'=>'historial'");

$tests = [
    'Botón Llamar general con permisos y columna independiente' =>
        str_contains($view, '<th>Llamar</th>') &&
        str_contains($view, 'colspan="9"') &&
        str_contains($marketingJs, 'puedeLlamarAlRegistro(call)') &&
        str_contains($marketingJs, 'marketing-reception-action-cell') &&
        !str_contains($marketingJs, "String(call.estado) === 'Perdida'") &&
        str_contains($marketingJs, 'telefono.openDialer?.({') &&
        str_contains($css, '.marketing-reception-call'),
    'Audio de entrada autorizado, verificado bajo demanda' =>
        !str_contains($recordingService, "^out_[a-fA-F0-9]{32,64}$") &&
        str_contains($recordingApi, "'verificar'") &&
        str_contains($marketingJs, "url.searchParams.set('verificar', '1')") &&
        str_contains($marketingJs, 'Consultar grabación') &&
        str_contains($marketingJs, 'mostrarComprobacion(slot)') &&
        str_contains($marketingJs, 'La grabación todavía está en preparación.') &&
        str_contains($marketingJs, "recordingVerified = '1'"),
    'Silenciar y temporizador comparten experiencia Seguimiento' =>
        str_contains($shellJs, 'syncIncomingClock(value)') &&
        str_contains($shellJs, 'updateIncomingTimer(phoneState)') &&
        str_contains($shellJs, "}, 250);") &&
        str_contains($shellJs, "muteButton.classList.toggle('is-active'") &&
        str_contains($shellJs, "const muteIcon = phoneState.muted ? 'bi-mic' : 'bi-mic-mute'"),
    'Reception not duplicated in Marketing Inicio' =>
        !str_contains($marketing, $include),
    'Reception is Marketing first Mis llamadas tab' =>
        $grid !== false && $recTab !== false && $histTab !== false &&
        $recTab < $histTab &&
        str_contains($controller, "\$puedeRecepcionMarketing ? 'recepcion' : 'historial'"),
    'View renders reception only for assigned users' =>
        str_contains($view, 'marketingTieneRecepcionAsignada()') &&
        str_contains($view, 'if ($recepcionMarketingPermitida)'),
    'Answered telephone icon exists in current Bootstrap Icons' =>
        str_contains($view, 'bi bi-telephone-fill') &&
        str_contains($view, 'bi bi-check-lg') &&
        !str_contains($view, 'bi bi-telephone-check'),
    'Answered icon has explicit dimensions and contrast' =>
        str_contains($css, '.marketing-reception-metric-icon.is-good .marketing-reception-answered-check') &&
        str_contains($css, 'background: #168060;'),
    'Optional tab mode is user controlled' =>
        str_contains($view, 'data-marketing-reception-tab-mode') &&
        str_contains($persistent, 'getHostMode: getHostMode') &&
        str_contains($persistent, 'setHostMode: setHostMode'),
    'Existing popup mode remains the default and preserved' =>
        str_contains($persistent, "return 'popup';") &&
        str_contains($persistent, "'popup=yes'") &&
        str_contains($persistent, "window.open('', hostWindowName, features)"),
    'Tab mode does not call popup move or resize' =>
        str_contains($persistent, "window.open('', hostWindowName + '_tab')") &&
        str_contains($persistent, 'if (!modoPestana) {') &&
        str_contains($hostJs, 'modoPestana || modoEmbebido || nativeUiVisible'),
    'Incoming calls retain Zadarma native controls' =>
        str_contains($hostJs, 'mostrarHostEntrante();') &&
        str_contains($hostJs, 'marcarPestanaEntrante(String(call.caller_id'),
    'Host page respects mode and preserves SDK script' =>
        str_contains($hostView, "'display'") &&
        str_contains($hostView, 'telephony-host-tab-info') &&
        str_contains($hostView, 'public/javascript/telefonia_host.js'),
    'Host page keeps tab info visible' =>
        !str_contains($hostCss, 'body:not(.telephony-host-incoming) > *') &&
        str_contains($hostCss, '.telephony-host-tab-info {'),
    'Recepción elimina avisos duplicados pero conserva el estado operativo' =>
        !str_contains($view, 'data-marketing-reception-browser-note') &&
        !str_contains($view, 'data-marketing-reception-hint') &&
        !str_contains($view, 'marketing-reception-history-note') &&
        str_contains($view, 'data-marketing-reception-state-text'),
];
foreach ($tests as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $name\n");
        exit(1);
    }
}
echo 'Marketing recepción: OK (' . count($tests) . " comprobaciones)\n";
