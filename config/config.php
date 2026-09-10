<?php

// URL principal del sistema.
// En desarrollo normal se conserva localhost. Para pruebas temporales de WebRTC
// se acepta únicamente un Quick Tunnel HTTPS de Cloudflare (trycloudflare.com),
// evitando depender de un dominio fijo mientras se valida la telefonía.
$baseUrl = 'http://localhost/SistemaComercialIMPE/';
$hostActual = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));

if (
    $hostActual !== '' &&
    preg_match('/^[a-z0-9-]+\.trycloudflare\.com(?::\d+)?$/', $hostActual)
) {
    $baseUrl = 'https://' . $hostActual . '/SistemaComercialIMPE/';
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