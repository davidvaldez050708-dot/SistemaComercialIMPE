<?php
/**
 * Validación estática de la navegación permanente y controles de seguridad.
 * No necesita base de datos, credenciales de Zadarma ni navegador.
 */
$root = dirname(__DIR__);
$read = static fn(string $file): string =>
    (string)file_get_contents($root . '/' . $file);
$login = $read('app/controllers/LoginController.php');
$controller = $read('app/controllers/TelefoniaController.php');
$shell = $read('app/views/telefonia/shell.php');
$host = $read('app/views/telefonia/host.php');
$hostJs = $read('public/javascript/telefonia_host.js');
$client = $read('public/javascript/telefonia_persistente.js');
$layout = $read('app/views/telefonia/recepcion_marketing.php');
$view = $read('app/views/dashboard/marketing.php');
$shellJs = $read('public/javascript/telefonia_shell.js');
$checks = [
    'Shell protegida por permiso real' =>
        str_contains($controller, 'public function shell()') &&
        str_contains($controller, '$this->validarUsuarioTelefoniaHtml();') &&
        str_contains($controller, 'resolverParaUsuario('),
    'Shell no permite acceso a extensión inactiva' =>
        str_contains($controller, "empty(\$asignacionTelefonica['permite_entrantes'])") &&
        str_contains($controller, "empty(\$asignacionTelefonica['permite_salientes'])"),
    'No evade cambio obligatorio de contraseña' =>
        str_contains($controller, "if (!empty(\$_SESSION['requiere_cambio_password']))"),
    'Login sigue permitiendo modo anterior a otros roles' =>
        str_contains($login, "\$destinoInicio = 'index.php?controller=home&action=index';") &&
        str_contains($login, "tienePermiso('telefonia.usar')") &&
        str_contains($login, 'resolverParaUsuario('),
    'Login sólo redirige con extensión válida' =>
        str_contains($login, "'index.php?controller=telefonia&action=shell'"),
    'Motor y navegación son iframes hermanos y no se recrean al navegar' =>
        str_contains($shell, 'id="impeCrmFrame"') &&
        str_contains($shell, 'data-shell-host-region') &&
        str_contains($shell, 'data-shell-call') &&
        str_contains($shellJs, 'const ensureHost = function () {'),
    'Navegación bajo router y permisos existentes' =>
        str_contains($shell, 'index.php?controller=home&action=index') &&
        str_contains($shell, 'allow="microphone; autoplay"'),
    'CRM utiliza contexto embebido si existe' =>
        str_contains($client, 'window.parent.IMPE_TELEPHONY_SHELL') &&
        str_contains($client, 'return shell.ensureHost();'),
    'SDK embebido no mueve ni redimensiona ventana' =>
        str_contains($hostJs, "const modoEmbebido = visualizacionHost === 'embed';") &&
        str_contains($hostJs, 'modoPestana || modoEmbebido || nativeUiVisible'),
    'Host conserva SDK/WebRTC de Zadarma' =>
        str_contains($host, 'public/javascript/telefonia_host.js') &&
        str_contains($hostJs, 'window.zadarmaWidgetFn(') &&
        str_contains($hostJs, 'sendTransferDtmf('),
    'Transferencia sin API DTMF muestra panel oficial en CRM' =>
        str_contains($hostJs, "'IMPE_TELEPHONY_NATIVE_CONTROLS'") &&
        str_contains($shellJs, "event.data?.type !== 'IMPE_TELEPHONY_NATIVE_CONTROLS'") &&
        str_contains($shellJs, 'event.source !== hostFrame.contentWindow'),
    'Recepción se activa al entrar Marketing pero exige permisos' =>
        str_contains($shell, 'marketingShell') &&
        str_contains($shell, "tienePermiso('telefonia.recibir')") &&
        str_contains($shellJs, 'if (autoEnabled) {'),
    'Auto recepción configurable para el usuario' =>
        str_contains($layout, 'data-marketing-reception-auto') &&
        str_contains($client, "'impe:telephony:auto-reception:' + userId") &&
        str_contains($shellJs, "'impe:telephony:auto-reception:' + userId"),
    'La navegación no elimina WebRTC en cambios de módulo' =>
        str_contains($shellJs, 'hostRegion.appendChild(frame)') &&
        !str_contains($shellJs, 'crm.appendChild(frame)'),
    'Extensión duplicada entre pestañas evitada' =>
        str_contains($shellJs, 'claimLease()') &&
        str_contains($shellJs, 'releaseLease()') &&
        str_contains($shellJs, "localStorage.setItem(leaseKey"),
    'No reinicia llamada activa desde controles de recuperación' =>
        str_contains($shellJs, 'if (freshState(currentState) && currentState.active) return;'),
    'Salir al login cierra contexto WebRTC' =>
        str_contains($shellJs, "controller === 'login' && action === 'mostrarLogin'") &&
        str_contains($shellJs, 'window.location.replace(String(cfg.loginUrl))'),
    'No abre ventanas adicionales dentro de shell' =>
        !str_contains($shellJs, 'window.open(') &&
        str_contains($client, 'return shell.ensureHost();'),
    'Transferencias existentes conservadas' =>
        str_contains($client, 'transferCall: transferCall'),
    'Marketing abre Recepción desde Mis llamadas y no duplica Inicio' =>
        !str_contains($view, "require __DIR__ . '/../telefonia/recepcion_marketing.php';") &&
        str_contains($controller, "\$puedeRecepcionMarketing ? 'recepcion' : 'historial'")
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $name\n");
        exit(1);
    }
}
echo "Telefonía persistente: OK (" . count($checks) . " comprobaciones)\n";
