<?php
// Copiar como mail_accounts.local.php en impe-private. No publicar contraseñas/tokens.
// Sustituir los usuarios ficticios por IDs reales autorizados del CRM.
return ['accounts' => [
    'empleado@gmail.com' => ['activo' => true, 'usuarios' => [999999], 'transport' => 'smtp',
        'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        'smtp_username' => 'empleado@gmail.com', 'auth_method' => 'password', 'smtp_password' => 'CLAVE_DE_APLICACION_PRIVADA'],
    'empleado@institucion.test' => ['activo' => true, 'usuarios' => [999998], 'transport' => 'hostinger_api'],
    'empleado@outlook.com' => ['activo' => false, 'usuarios' => [999997], 'transport' => 'smtp',
        'smtp_host' => 'smtp-mail.outlook.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        'smtp_username' => 'empleado@outlook.com', 'auth_method' => 'oauth2', 'oauth_provider' => 'microsoft',
        'tenant' => 'common', 'client_id' => 'CLIENT_ID_PRIVADO', 'client_secret' => 'SECRET_PRIVADO', 'refresh_token' => 'TOKEN_AUTORIZADO_PRIVADO'],
    // Gmail OAuth: smtp.gmail.com, auth_method oauth2, oauth_provider google y credenciales de la app autorizada.
    // Microsoft 365: smtp.office365.com; requiere OAuth y SMTP AUTH permitido para ese buzón.
    // Otros dominios: host/puerto/TLS y credenciales SMTP propias de esa cuenta.
]];
