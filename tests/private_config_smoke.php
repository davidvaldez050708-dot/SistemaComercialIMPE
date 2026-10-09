<?php

// Prueba con datos ficticios, sin solicitudes externas.
$dir = sys_get_temp_dir() . '/impe-config-test-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700) && !is_dir($dir)) {
    throw new RuntimeException('No fue posible crear la carpeta de pruebas.');
}
$files = [
    'app.local.php' => <<<'PHP'
<?php
return [
    'app_env' => 'production',
    'base_url' => 'https://crm.ejemplo.org/',
    'db' => [
        'host' => 'localhost',
        'user' => 'impe_test',
        'password' => 'solo-para-pruebas',
        'name' => 'impe_test'
    ],
];
PHP,
    'api_keys.local.php' => <<<'PHP'
<?php
define('INEGI_INDICADORES_TOKEN', 'indicadores-test');
define('DENUE_TOKEN', 'denue-test');
PHP,
    'mail_config.local.php' => <<<'PHP'
<?php
define('MAIL_HOST', 'smtp.ejemplo.org');
define('MAIL_PASSWORD', 'mail-test');
PHP,
    'zadarma_config.php' => <<<'PHP'
<?php
return ['api_key' => 'test', 'api_secret' => 'test'];
PHP
];

try {
    foreach ($files as $name => $content) {
        file_put_contents($dir . '/' . $name, $content);
    }
    putenv('IMPE_PRIVATE_CONFIG_DIR=' . $dir);
    require dirname(__DIR__) . '/config/config.php';
    require dirname(__DIR__) . '/config/api_keys.php';
    require dirname(__DIR__) . '/config/mail_config.php';

    $checks = [
        BASE_URL === 'https://crm.ejemplo.org/',
        DB_USER === 'impe_test',
        DB_PASSWORD === 'solo-para-pruebas',
        INEGI_INDICADORES_TOKEN === 'indicadores-test',
        DENUE_TOKEN === 'denue-test',
        MAIL_HOST === 'smtp.ejemplo.org',
        MAIL_PASSWORD === 'mail-test',
        ini_get('display_errors') === '0',
        impeRutaConfigPrivada('zadarma_config.php') === $dir . '/zadarma_config.php'
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Falló la validación de configuración privada.');
    }
    echo "Configuración privada ficticia: OK\n";
} finally {
    foreach (array_keys($files) as $name) {
        @unlink($dir . '/' . $name);
    }
    @rmdir($dir);
}
