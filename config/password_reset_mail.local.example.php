<?php
// Copiar como password_reset_mail.local.php en impe-private, nunca en Git.
// Si no existe se conservan MAIL_* de mail_config.local.php / entorno.
return [
    'correo' => 'recuperacion@gmail.com', 'nombre' => 'Sistema Comercial IMPE',
    'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    'smtp_username' => 'recuperacion@gmail.com', 'auth_method' => 'password',
    'smtp_password' => 'CONFIGURAR_PRIVADAMENTE_CLAVE_DE_APLICACION',
];
