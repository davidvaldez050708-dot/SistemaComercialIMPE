<?php
// Guardar como mail_oauth.local.php fuera de public_html, en impe-private, con permisos 0600.
// Registrar esta URL exacta como redirect URI de tipo Web en ambas aplicaciones.
return ['providers' => [
    'google' => ['enabled' => false, 'client_id' => 'CLIENTE_GOOGLE_PRIVADO', 'client_secret' => 'SECRETO_GOOGLE_PRIVADO',
        'redirect_uri' => 'https://fundacionrededumex.org/index.php?controller=correoConexion&action=callback'],
    'microsoft' => ['enabled' => false, 'tenant' => 'common', 'client_id' => 'CLIENTE_MICROSOFT_PRIVADO', 'client_secret' => 'SECRETO_MICROSOFT_PRIVADO',
        'redirect_uri' => 'https://fundacionrededumex.org/index.php?controller=correoConexion&action=callback'],
]];
