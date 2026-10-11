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
$persistent = $read('public/javascript/telefonia_persistente.js');
$hostJs = $read('public/javascript/telefonia_host.js');

$include = "require __DIR__ . '/../telefonia/recepcion_marketing.php';";
$grid = strpos($marketing, '<div class="metric-grid mb-4">');
$block = strpos($marketing, $include);
$convocations = strpos($marketing, '<section class="dashboard-panel marketing-expiring-panel">');

$tests = [
    'Only one reception include' => substr_count($marketing, $include) === 1,
    'Reception follows metrics and precedes convocations' =>
        $grid !== false && $block !== false && $convocations !== false &&
        $grid < $block && $block < $convocations,
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
        str_contains($hostJs, 'if (modoPestana || nativeUiVisible)'),
    'Incoming calls retain Zadarma native controls' =>
        str_contains($hostJs, 'mostrarHostEntrante();') &&
        str_contains($hostJs, 'marcarPestanaEntrante(String(call.caller_id'),
    'Host page respects mode and preserves SDK script' =>
        str_contains($hostView, "'display'") &&
        str_contains($hostView, 'telephony-host-tab-info') &&
        str_contains($hostView, 'public/javascript/telefonia_host.js'),
    'Host page keeps tab info visible' =>
        str_contains($hostCss, ':not(.telephony-host-tab-info)') &&
        str_contains($hostCss, '.telephony-host-tab-info {'),
    'Reception warns about closing telephony context' =>
        str_contains($view, 'data-marketing-reception-browser-note') &&
        str_contains($persistent, 'marketingBrowserNote.hidden = !active;'),
];
foreach ($tests as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "ERROR: $name\n");
        exit(1);
    }
}
echo 'Marketing recepción: OK (' . count($tests) . " comprobaciones)\n";
