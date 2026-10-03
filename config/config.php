<?php

// URL principal del sistema.
// En desarrollo normal se conserva localhost. Para pruebas que requieren
// callbacks HTTPS (telefonía o WhatsApp) se aceptan únicamente hosts temporales
// conocidos de Cloudflare Quick Tunnels y Visual Studio Dev Tunnels.
$baseUrl = 'http://localhost/SistemaComercialIMPE/';

$normalizarHost = static function ($valor) {
    $valor = strtolower(trim((string)$valor));

    // Algunos proxies agregan una lista separada por comas.
    if (strpos($valor, ',') !== false) {
        $valor = trim(explode(',', $valor, 2)[0]);
    }

    return $valor;
};

$esHostTemporalSeguro = static function ($host) {
    if ($host === '') {
        return false;
    }

    return
        preg_match(
            '/^[a-z0-9-]+\.trycloudflare\.com(?::\d+)?$/',
            $host
        ) === 1 ||
        preg_match(
            '/^[a-z0-9-]+(?:-[0-9]+)?\.[a-z0-9-]+\.devtunnels\.ms(?::\d+)?$/',
            $host
        ) === 1;
};

$hostDirecto = $normalizarHost($_SERVER['HTTP_HOST'] ?? '');
$hostReenviado = $normalizarHost($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');

/*
 * Visual Studio Dev Tunnels puede entregar la petición a Apache con
 * Host=localhost y conservar el host público en X-Forwarded-Host.
 * Solo confiamos en el host reenviado cuando coincide exactamente con
 * los dominios temporales permitidos.
 */
$hostPublico = '';

if ($esHostTemporalSeguro($hostDirecto)) {
    $hostPublico = $hostDirecto;
} elseif ($esHostTemporalSeguro($hostReenviado)) {
    $hostPublico = $hostReenviado;
}

if ($hostPublico !== '') {
    $baseUrl = 'https://' . $hostPublico . '/SistemaComercialIMPE/';
}

define('BASE_URL', $baseUrl);

// Ruta física principal del proyecto
define('ROOT_PATH', dirname(__DIR__));

// Configuración de base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'sistema_comercial_impe');

// Zona horaria
date_default_timezone_set('America/Mexico_City');

// Mostrar errores durante el desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);