<?php
// Guardar como mail_central.local.php en impe-private, fuera de public_html. Permisos 0600.
// Una configuración administrativa para todos los módulos con permiso de envío.
return [
    'activo' => true, 'transport' => 'smtp',
    'correo' => 'comunicacion@ejemplo.org', 'nombre' => 'Fundación Red Educativa México',
    'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    'smtp_username' => 'comunicacion@ejemplo.org', 'smtp_password' => 'CONFIGURAR_PRIVADAMENTE',
    'authorized_from' => [], // Alias permitidos por el proveedor y asociados al usuario.
    'authorized_contacts' => [], // Opcional: ID usuario => [otros contactos autorizados].
    // Alternativa: transport=hostinger_api, correo y api_token (o token Hostinger existente).
    // La API publicada no admite Reply-To. Para respuestas al empleado, usar SMTP.
    // reply_smtp puede contener las claves smtp_* de la misma cuenta central:
    // se selecciona antes de enviar cuando se necesita Reply-To diferente de From.
];
