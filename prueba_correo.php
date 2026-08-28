<?php

require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/app/services/MailService.php';

$correoDestino = 'davidvaldez050708@gmail.com';
$nombreDestino = 'Usuario de Prueba';
$passwordTemporal = 'Prueba847K';

$mailService = new MailService();

$enviado = $mailService->enviarPasswordTemporal(
    $correoDestino,
    $nombreDestino,
    $passwordTemporal
);

if ($enviado) {

    echo 'Correo enviado correctamente.';

} else {

    echo 'No se pudo enviar el correo. Revisa la configuración o el log de PHP.';
}