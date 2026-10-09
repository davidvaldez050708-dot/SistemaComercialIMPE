<?php

// Guardar como app.local.php en:
//  - XAMPP: config/app.local.php (ignorado por Git)
//  - Hostinger: ../impe-private/app.local.php, junto a public_html/
//
// ¡Nunca introducir claves reales en este archivo de ejemplo!
return [
    'app_env' => 'production',
    'base_url' => 'https://crm.ejemplo.org/',
    'db' => [
        'host' => 'localhost',
        'user' => 'u123456789_crm',
        'password' => 'REEMPLAZAR_EN_ARCHIVO_PRIVADO',
        'name' => 'u123456789_crm'
    ]
];
