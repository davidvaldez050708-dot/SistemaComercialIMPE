<?php
// Fixture aislada: únicamente esquemas locales nuevos con prefijo de pruebas.
if (PHP_SAPI !== 'cli') { exit(1); }
$nombre = getenv('IMPE_TEST_DB_NAME') ?: 'impe_test_handly';
if (!preg_match('/^impe_test_handly[a-z0-9_]*$/D', $nombre)) { throw new RuntimeException('Esquema de prueba inválido.'); }
$db = new mysqli('127.0.0.1', 'root', '', '', (int)(getenv('IMPE_TEST_MYSQL_PORT') ?: 33308));
$db->query("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4"); $db->select_db($nombre);
$db->query('CREATE TABLE estados (id INT PRIMARY KEY, nombre VARCHAR(100), estado INT) ENGINE=InnoDB');
$db->query("INSERT INTO estados VALUES (1, 'Morelos', 1)");
$db->query('CREATE TABLE municipios (id INT PRIMARY KEY, estado_id INT, nombre VARCHAR(100), estado INT) ENGINE=InnoDB');
$db->query("INSERT INTO municipios VALUES (1, 1, 'Cuernavaca', 1)");
foreach (['formulario_registros','formulario_inscripciones'] as $tabla) {
    $db->query("CREATE TABLE `$tabla` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(80), apellido VARCHAR(120), fecha_nacimiento DATE, movil VARCHAR(20), movil_secundario VARCHAR(20), tel_recomendado VARCHAR(20), correo VARCHAR(180), perfil_interes VARCHAR(180), lugar_laboras VARCHAR(180), cargo_puesto VARCHAR(160), estado_id INT NOT NULL, municipio_id INT NOT NULL, creado_por INT NULL, origen VARCHAR(30), created_at DATETIME, updated_at DATETIME) ENGINE=InnoDB");
}
echo "OK: fixture local $nombre\n";
