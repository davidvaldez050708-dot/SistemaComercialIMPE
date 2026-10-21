<?php
// Guardar como mail_central.local.php en impe-private, fuera de public_html. Permisos 0600.
// Una configuración administrativa para todos los módulos con permiso de envío.
return [
    'activo' => true, 'transport' => 'hostinger_api',
    // Vacío: cada empleado Hostinger utiliza su correo registrado, comprobado en GET /me.
    // Opcional para otros contactos: buzón institucional de salida autorizado y visible.
    // Gmail registrado se autoriza separadamente con Google, sin sustituir su remitente.
    'correo' => '', 'nombre' => 'Fundación Red Educativa México',
    // Se reutiliza HOSTINGER_MAIL_API_TOKEN de la configuración privada actual.
    // SMTP alternativo: transport=smtp, correo, smtp_host, smtp_port, smtp_encryption,
    // smtp_username y smtp_password (credenciales centrales, nunca personales).
    'authorized_from' => [], // Alias permitidos por el proveedor y asociados al usuario.
    'authorized_contacts' => [], // Opcional: ID usuario => [otros contactos autorizados].
    // Alternativa: transport=hostinger_api, correo y api_token (o token Hostinger existente).
    // Las respuestas al From propio Hostinger llegan al mismo empleado.
    // La API no admite Reply-To diferente de From; para ese caso, usar SMTP.
    // reply_smtp puede contener las claves smtp_* de la misma cuenta central:
    // se selecciona antes de enviar cuando se necesita Reply-To diferente de From.
];
