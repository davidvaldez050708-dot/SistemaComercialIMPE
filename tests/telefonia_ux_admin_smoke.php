<?php
/**
 * Auditoría estática para UI de extensiones y recepción.
 * No altera MySQL ni conecta a Zadarma.
 */
$raiz = dirname(__DIR__);
$leer = static fn(string $ruta): string =>
    (string)file_get_contents($raiz . '/' . $ruta);

$vista = $leer('app/views/telefonia/extensiones.php');
$css = $leer('public/css/telefonia.css');
$responsive = $leer('public/css/responsive_sistema.css');
$js = $leer('public/javascript/telefonia_admin.js');
$recepcion = $leer('app/views/telefonia/recepcion_marketing.php');
$jsRecepcion = $leer('public/javascript/telefonia_recepcion_marketing.js');
$rol = $leer('app/models/RolModel.php');
$servicio = $leer('app/services/TelefoniaExtensionService.php');

$checks = [
    'Avatares de usuarios sin foto centrados' =>
        str_contains($css, '.telephony-admin .telephony-user > .system-avatar.system-avatar-initials') &&
        str_contains($css, 'justify-content: center;'),
    'Modal de configuración acotado' =>
        str_contains($responsive, '#modalTelefoniaExtension .modal-dialog.telephony-modal-dialog') &&
        str_contains($responsive, 'min(650px, calc(100vw - 32px))'),
    'Modal de liberación acotado' =>
        str_contains($responsive, '#modalTelefoniaLiberar .modal-dialog.system-confirm-dialog') &&
        str_contains($responsive, 'min(480px, calc(100vw - 32px))'),
    'El modal móvil conserva desplazamiento interno' =>
        str_contains($responsive, '#modalTelefoniaExtension .modal-content > form > .modal-body') &&
        str_contains($responsive, 'overflow-y: auto;'),
    'Mensajes de sesión reemplazados por toast' =>
        str_contains($vista, 'data-telephony-flash') &&
        !str_contains($vista, 'alert alert-success telephony-alert') &&
        !str_contains($vista, 'alert alert-danger telephony-alert'),
    'Toast accesible y basado en Bootstrap' =>
        str_contains($js, 'bootstrap.Toast.getOrCreateInstance') &&
        str_contains($js, "aviso.querySelector('span').textContent") &&
        str_contains($js, 'system-toast-error'),
    'Marketing obtiene salientes sólo mediante permiso' =>
        str_contains($rol, "'telefonia.salientes', // devolver llamadas perdidas") &&
        str_contains($servicio, "empty(\$usuario['puede_salientes'])"),
    'Recepción permite Llamar en todos los estados con autorización' =>
        str_contains($jsRecepcion, "window.IMPE_CAN_CALL_OUT === true") &&
        str_contains($jsRecepcion, 'configuracion?.permite_salientes') &&
        str_contains($jsRecepcion, 'puedeLlamarAlRegistro(call)') &&
        !str_contains($jsRecepcion, "String(call.estado) === 'Perdida'"),
    'Devolución usa WebRTC persistente y soporta transferencia' =>
        str_contains($jsRecepcion, 'telefono.openDialer?.({') &&
        str_contains($jsRecepcion, "'MARKETING_CALLBACK'") &&
        str_contains($jsRecepcion, 'explicarDevolucion('),
    'Historial telefónico no se elimina' =>
        str_contains($recepcion, 'data-marketing-reception-rows') &&
        str_contains($recepcion, 'data-marketing-reception-filter') &&
        str_contains($jsRecepcion, 'loadHistory(true)')
];

foreach ($checks as $nombre => $cumple) {
    if (!$cumple) {
        fwrite(STDERR, "ERROR: $nombre\n");
        exit(1);
    }
}
echo 'Telefonía UX: OK (' . count($checks) . " comprobaciones)\n";
