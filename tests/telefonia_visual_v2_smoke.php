<?php
/**
 * Regresión CSS: modal editar, configurar/liberar y avatares.
 * Sólo lectura, sin base de datos.
 */
$raiz = dirname(__DIR__);
$css = (string)file_get_contents($raiz . '/public/css/telefonia.css');
$responsive = (string)file_get_contents($raiz . '/public/css/responsive_sistema.css');
$vista = (string)file_get_contents($raiz . '/app/views/telefonia/extensiones.php');

$checks = [
    'El correo no aplica estilos al avatar' =>
        !preg_match('/(?<![a-zA-Z0-9_-])\\.telephony-user\\s+span\\s*\\{/', $css) &&
        str_contains($css, '.telephony-user > div:last-child > span {'),
    'Avatar sin foto centrado y aislado' =>
        str_contains($css, '.telephony-user > span.system-avatar.system-avatar-initials {') &&
        str_contains($css, 'align-items: center !important;') &&
        str_contains($css, 'justify-content: center !important;') &&
        str_contains($css, 'margin: 0 !important;'),
    'Modal configurar/editar compacto y prioritario' =>
        str_contains($css, '#modalTelefoniaExtension.modal .modal-dialog.telephony-modal-dialog {') &&
        str_contains($css, 'max-width: 620px !important;'),
    'Modal liberar compacto y prioritario' =>
        str_contains($css, '#modalTelefoniaLiberar.modal .modal-dialog.system-confirm-dialog {') &&
        str_contains($css, 'max-width: 440px !important;'),
    'Modal desplazable sólo por contenido' =>
        str_contains($css, '#modalTelefoniaExtension.modal .modal-content > form > .modal-body,') &&
        str_contains($css, 'overflow-y: auto;'),
    'CSS para celulares' =>
        str_contains($css, '@media (max-width: 575.98px)') &&
        str_contains($css, 'max-height: calc(100dvh - 20px);'),
    'Los mismos modales siguen en la vista original' =>
        str_contains($vista, 'id="modalTelefoniaExtension"') &&
        str_contains($vista, 'id="modalTelefoniaLiberar"') &&
        str_contains($vista, 'renderAvatarUsuario('),
    'El responsive general no invalida las prioridades' =>
        str_contains($responsive, '.admin-content .modal-dialog') &&
        str_contains($css, 'width: calc(100% - 32px) !important;'),
    'Identificador único del parche para Hostinger' =>
        substr_count($css, '/* TELEFONIA_VISUAL_COMPACTA_V2') === 1
];
foreach ($checks as $nombre => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $nombre\n");
        exit(1);
    }
}
echo 'Telefonía visual v2: OK (' . count($checks) . " verificaciones)\n";
