<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaDisponibilidadService.php';
require_once __DIR__.'/../app/services/TelefoniaNotificacionService.php';

$port = (int)getenv('IMPE_TEST_MYSQL_PORT');
if (!$port) throw new RuntimeException('Define el puerto de MariaDB local.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER') ?: 'root',
    getenv('IMPE_TEST_MYSQL_PASSWORD') ?: '',null,$port);
$name = 'impe_sondeo_test_'.bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$name`");
try {
    $db->select_db($name);
    foreach ([
        'CREATE TABLE usuarios (id INT PRIMARY KEY, estado INT, rol_id INT)',
        'CREATE TABLE telefonia_extensiones (usuario_id INT PRIMARY KEY, extension VARCHAR(12), activo INT, proveedor VARCHAR(12), permite_entrantes INT, created_at DATETIME, updated_at DATETIME)',
        'CREATE TABLE telefonia_presencia (usuario_id INT PRIMARY KEY, extension VARCHAR(12), ocupado INT, updated_at DATETIME)',
        'CREATE TABLE telefonia_disponibilidad (id INT PRIMARY KEY, destino_usuario INT, origen_usuario INT, destino_extension VARCHAR(12), origen_extension VARCHAR(12), estado VARCHAR(24), expires_at DATETIME, updated_at DATETIME, ruta_json TEXT)',
        'INSERT INTO usuarios VALUES (1,1,4)',
        "INSERT INTO telefonia_extensiones VALUES (1,'102',1,'ZADARMA',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    ] as $sql) $db->query($sql);

    $counter = static fn(string $name): int => (int)$db->query("SHOW SESSION STATUS LIKE '$name'")->fetch_row()[1];
    $svc = new TelefoniaDisponibilidadService($db);
    $reads = $counter('Com_select');
    $result = $svc->sondear(1,true,0,false);
    $delta = $counter('Com_select') - $reads;
    if ($result['entrante'] !== null || $delta !== 2) throw new RuntimeException("Sondeo o consultas inesperados: $delta");
    $db->query("INSERT INTO telefonia_disponibilidad VALUES
        (1,1,2,'102','100','PENDIENTE',UTC_TIMESTAMP()+INTERVAL 1 MINUTE,UTC_TIMESTAMP(),'{}'),
        (2,1,2,'102','100','ACEPTADA',UTC_TIMESTAMP()+INTERVAL 1 MINUTE,UTC_TIMESTAMP(),'{}')");
    if ($svc->sondear(1,true,0,false)['entrante'] !== null) throw new RuntimeException('La reserva debe ocultar la solicitud.');
    $db->query('DELETE FROM telefonia_disponibilidad WHERE id=2');
    if ((int)$svc->sondear(1,true,0,false)['entrante']['id'] !== 1) throw new RuntimeException('La solicitud pendiente debe aparecer.');

    $ddl = $counter('Com_create_table');
    new TelefoniaNotificacionService($db);
    if ($counter('Com_create_table') !== $ddl) throw new RuntimeException('La campana ejecutó DDL.');
    echo "PASS: 2 SELECT sin solicitud, prioridad de reservas y constructor de campana sin DDL.\n";
} finally {
    $db->query("DROP DATABASE `$name`");
}
