<?php

// Copia este archivo como config/hostinger_mail_config.php.
// Ese archivo es privado, está ignorado por Git y nunca debe subirse.

// Token GENERAL creado desde hPanel > API.
// Se usa únicamente para consultar los servicios de correo, órdenes y buzones
// y para preparar posteriormente el token específico de Email API.
define('HOSTINGER_API_TOKEN', '');
define('HOSTINGER_API_BASE_URL', 'https://developers.hostinger.com');

// Token ESPECÍFICO de Hostinger Mail API.
// Este será el que utilizará el sistema para enviar, leer y responder correos.
// Si se deja vacío, el envío actual seguirá usando SMTP como respaldo.
define('HOSTINGER_MAIL_API_TOKEN', '');
define('HOSTINGER_MAIL_API_BASE_URL', 'https://api.mail.hostinger.com');
