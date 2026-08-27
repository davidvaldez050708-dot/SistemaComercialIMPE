<?php

// URL principal del sistema
define('BASE_URL', 'http://localhost/SistemaComercialIMPE/');

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