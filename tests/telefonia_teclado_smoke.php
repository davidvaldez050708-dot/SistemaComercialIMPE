<?php
/**
 * Smoke test del marcador único (sin red, MySQL, sesiones ni Zadarma).
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static fn(string $path) => (string)file_get_contents($root . '/' . $path);
$shell = $read('app/views/telefonia/shell.php');
$shellJs = $read('public/javascript/telefonia_shell.js');
$phone = $read('public/javascript/telefonia_persistente.js');
$marketing = $read('public/javascript/telefonia_recepcion_marketing.js');
$head = $read('app/views/layout/dashboard_head.php');
$dial = $read('public/javascript/telefonia_teclado.js');
$css = $read('public/css/telefonia_teclado.css');
$checks = [
    'El shell carga CSS y JS integrados desde disco' =>
        str_contains($shell, 'impeDialerStyles') &&
        str_contains($shell, 'impeDialerRuntime') &&
        str_contains($shell, 'public/javascript/telefonia_teclado.js'),
    'El shell controla los permisos salientes desde PHP' =>
        str_contains($shell, "'canCallOut' => tienePermiso('telefonia.salientes')") &&
        str_contains($shell, "!empty(\$asignacionTelefonica['permite_salientes'])"),
    'El marcador está montado en la ventana persistente' =>
        str_contains($shellJs, 'IMPE_TELEPHONY_DIALER?.create') &&
        str_contains($shellJs, 'dialer?.sync(value)') &&
        str_contains($shellJs, 'openDialer: openDialer'),
    'El botón Abrir teléfono ya no repite prepare()' =>
        str_contains($phone, 'const abrirOActivar = function ()') &&
        str_contains($phone, 'if (hostUsable())') &&
        str_contains($phone, 'openDialer({})') &&
        str_contains($phone, "marketingAction?.addEventListener('click', abrirOActivar)"),
    'Modo clásico conserva exactamente el mismo teclado' =>
        str_contains($head, "'telefonia_teclado.css'") &&
        str_contains($head, "'telefonia_teclado.js'") &&
        str_contains($phone, 'marcadorLocal = window.IMPE_TELEPHONY_DIALER.create'),
    'El historial NO llama inmediatamente' =>
        str_contains($marketing, 'telefono.openDialer?.({') &&
        !str_contains($marketing, 'telefono.startCall({') &&
        str_contains($marketing, "'MARKETING_CALLBACK'"),
    'Llamada saliente pasa por motor y validación existentes' =>
        str_contains($dial, 'await api.startCall({') &&
        str_contains($dial, 'await api.probe()') &&
        str_contains($dial, 'info?.permite_salientes') &&
        str_contains($dial, 'hasOutgoingRole()'),
    'Controles de conversación y transferencia existentes' =>
        str_contains($dial, 'api?.transferCall') &&
        str_contains($dial, 'transferCall(extension,attended)') &&
        str_contains($dial, 'phoneApi()?.hangup?.()') &&
        str_contains($dial, 'phoneApi()?.toggleMute?.()'),
    'No se duplica flotante bajo el shell' =>
        str_contains($phone, 'isDialerManagingCall?.()') &&
        str_contains($shellJs, 'isDialerManagingCall: function ()'),
    'Estilos compactos y responsivos de Seguimiento' =>
        str_contains($css, 'width:min(420px,calc(100vw - 24px))') &&
        str_contains($css, '.impe-dialer.is-active-call .impe-dialer-dialog') &&
        str_contains($css, '@media(max-width:575px)'),
    'No instala complementos externos ni modifica permisos' =>
        !str_contains($dial, 'window.open(') &&
        !str_contains($dial, 'location.reload(')
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $label\n");
        exit(1);
    }
}
echo 'Telefonía marcador: OK (' . count($checks) . " comprobaciones)\n";
