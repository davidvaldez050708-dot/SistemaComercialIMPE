<?php

// Prueba con datos ficticios, sin solicitudes externas.
$dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'impe-config-test-' . bin2hex(random_bytes(6));
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
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $content);
    }
    $directorioAnterior = getenv('IMPE_PRIVATE_CONFIG_DIR');
    putenv('IMPE_PRIVATE_CONFIG_DIR=' . $dir);
    require dirname(__DIR__) . '/config/config.php';
    require dirname(__DIR__) . '/config/api_keys.php';
    require dirname(__DIR__) . '/config/mail_config.php';

    // realpath() normaliza las barras: Windows utiliza \\ y Linux utiliza /.
    // Nunca comparar rutas construidas con literales '/' usando ===.
    $rutaEsperada = realpath($dir . DIRECTORY_SEPARATOR . 'zadarma_config.php');
    $rutaEncontrada = impeRutaConfigPrivada('zadarma_config.php');
    $rutaNormalizada = $rutaEncontrada !== null
        ? realpath($rutaEncontrada)
        : false;

    $checks = [
        'base_url_https' => BASE_URL === 'https://crm.ejemplo.org/',
        'usuario_mysql_ficticio' => DB_USER === 'impe_test',
        'password_mysql_ficticio' => DB_PASSWORD === 'solo-para-pruebas',
        'token_inegi_ficticio' => INEGI_INDICADORES_TOKEN === 'indicadores-test',
        'token_denue_ficticio' => DENUE_TOKEN === 'denue-test',
        'smtp_servidor_ficticio' => MAIL_HOST === 'smtp.ejemplo.org',
        'smtp_password_ficticio' => MAIL_PASSWORD === 'mail-test',
        'errores_desactivados' => ini_get('display_errors') === '0',
        'ruta_privada_zadarma' => $rutaEsperada !== false
            && $rutaNormalizada !== false
            && $rutaNormalizada === $rutaEsperada,
        'rechazar_traversal' => impeRutaConfigPrivada('../zadarma_config.php') === null
    ];
    $fallos = array_keys(array_filter(
        $checks,
        static fn (bool $correcto): bool => !$correcto
    ));
    if ($fallos !== []) {
        // Solo se muestran los nombres de las pruebas, nunca los valores secretos.
        throw new RuntimeException(
            'Fallaron las comprobaciones: ' . implode(', ', $fallos)
        );
    }
    echo "Configuración privada ficticia: OK\n";
} finally {
    foreach (array_keys($files) as $name) {
        @unlink($dir . DIRECTORY_SEPARATOR . $name);
    }
    @rmdir($dir);
    if (isset($directorioAnterior)) {
        putenv($directorioAnterior === false
            ? 'IMPE_PRIVATE_CONFIG_DIR'
            : 'IMPE_PRIVATE_CONFIG_DIR=' . $directorioAnterior);
    }
}
