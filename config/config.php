<?php

require_once __DIR__ . '/private_config.php';

define('ROOT_PATH', dirname(__DIR__));

$ajustes = impeLeerConfigPrivada('app.local.php');

$normalizarHost = static function ($valor): string {
    $valor = strtolower(trim((string)$valor));
    if (strpos($valor, ',') !== false) {
        $valor = trim(explode(',', $valor, 2)[0]);
    }
    return $valor;
};

$esHostTemporalSeguro = static function (string $host): bool {
    return preg_match('/^[a-z0-9-]+\.trycloudflare\.com(?::\d+)?$/', $host) === 1 ||
        preg_match('/^[a-z0-9-]+(?:-[0-9]+)?\.[a-z0-9-]+\.devtunnels\.ms(?::\d+)?$/', $host) === 1;
};

$hostDirecto = $normalizarHost($_SERVER['HTTP_HOST'] ?? '');
$hostReenviado = $normalizarHost($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
$hostTemporal = $esHostTemporalSeguro($hostDirecto)
    ? $hostDirecto
    : ($esHostTemporalSeguro($hostReenviado) ? $hostReenviado : '');

$esHostLocal = $hostDirecto === '' ||
    preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $hostDirecto) === 1;
$entorno = strtolower(trim((string)($ajustes['app_env'] ?? getenv('APP_ENV') ?: '')));
$esProduccion = $entorno === 'production' ||
    (PHP_SAPI !== 'cli' && !$esHostLocal && $hostTemporal === '');

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $esProduccion ? '0' : '1');

$valorEnv = static function (string $nombre): string {
    $valor = getenv($nombre);
    return $valor === false ? '' : trim((string)$valor);
};

// No confiar en HTTP_HOST para generar enlaces de producción (evita Host header injection).
$baseConfigurada = trim((string)(
    $valorEnv('IMPE_BASE_URL') !== ''
        ? $valorEnv('IMPE_BASE_URL')
        : ($ajustes['base_url'] ?? '')
));

if ($baseConfigurada !== '') {
    $partes = parse_url($baseConfigurada);
    if (!is_array($partes) ||
        !in_array(strtolower((string)($partes['scheme'] ?? '')), ['http', 'https'], true) ||
        empty($partes['host']) ||
        (isset($partes['user']) || isset($partes['pass'])) ||
        isset($partes['query']) ||
        isset($partes['fragment']) ||
        ($esProduccion && strtolower((string)$partes['scheme']) !== 'https')) {
        throw new RuntimeException('IMPE_BASE_URL no es una URL válida para el entorno.');
    }
    $baseUrl = rtrim($baseConfigurada, '/') . '/';
} elseif ($hostTemporal !== '' && !$esProduccion) {
    $baseUrl = 'https://' . $hostTemporal . '/SistemaComercialIMPE/';
} elseif ($esProduccion) {
    throw new RuntimeException('Falta configurar IMPE_BASE_URL para producción.');
} else {
    $baseUrl = 'http://localhost/SistemaComercialIMPE/';
}

define('BASE_URL', $baseUrl);

$bd = is_array($ajustes['db'] ?? null) ? $ajustes['db'] : [];
$hostBd = $valorEnv('IMPE_DB_HOST') ?: trim((string)($bd['host'] ?? 'localhost'));
$usuarioBd = $valorEnv('IMPE_DB_USER') ?: trim((string)($bd['user'] ?? 'root'));
$contrasenaBd = $valorEnv('IMPE_DB_PASSWORD');
if ($contrasenaBd === '' && array_key_exists('password', $bd)) {
    $contrasenaBd = (string)$bd['password'];
}
$nombreBd = $valorEnv('IMPE_DB_NAME') ?: trim((string)($bd['name'] ?? 'sistema_comercial_impe'));

if ($esProduccion && (
    $hostBd === '' || $usuarioBd === '' || $usuarioBd === 'root' ||
    $contrasenaBd === '' || $nombreBd === ''
)) {
    throw new RuntimeException('Falta configurar la base de datos de producción.');
}

define('DB_HOST', $hostBd);
define('DB_USER', $usuarioBd);
define('DB_PASSWORD', $contrasenaBd);
define('DB_NAME', $nombreBd);

date_default_timezone_set('America/Mexico_City');
