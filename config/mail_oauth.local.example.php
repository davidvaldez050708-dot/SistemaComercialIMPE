<?php
// Guardar como mail_oauth.local.php fuera de public_html, en impe-private (0600).
// Aplicación Google de tipo Web; habilitar Gmail API y registrar la URL exacta.
return ['providers' => ['google' => [
    'enabled' => false, 'client_id' => 'CLIENTE_GOOGLE_PRIVADO', 'client_secret' => 'SECRETO_GOOGLE_PRIVADO',
    'redirect_uri' => 'https://fundacionrededumex.org/index.php?controller=correoConexion&action=callback',
]]];
