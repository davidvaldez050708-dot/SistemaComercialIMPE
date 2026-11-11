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

    $inserciones = $counter('Com_insert');
    $svc->sondear(1,true,0,false,null,false);
    if ($counter('Com_insert') !== $inserciones) {
        throw new RuntimeException('El sondeo sin heartbeat escribió presencia.');
    }

    require_once __DIR__.'/../app/helpers/TelefoniaPresenciaRitmo.php';
    $db->query('DELETE FROM telefonia_disponibilidad');
    for ($usuario=2;$usuario<=30;$usuario++) {
        $rol = [3, 4, 7][$usuario % 3];
        $db->query("INSERT INTO usuarios VALUES ($usuario,1,$rol)");
        $ext = (string)(200+$usuario);
        $db->query("INSERT INTO telefonia_extensiones VALUES ($usuario,'$ext',1,'ZADARMA',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }
    foreach ([10,30] as $usuarios) {
        $resultados = [];
        foreach ([false,true] as $optimizado) {
            $insert = $counter('Com_insert');
            $select = $counter('Com_select');
            for ($ronda=0;$ronda<10;$ronda++) {
                $sesiones = [];
                for ($segundo=0;$segundo<=9;$segundo+=3) {
                    $hora = 1000+$ronda*20+$segundo;
                    for ($posicion=0;$posicion<$usuarios;$posicion++) {
                        $usuario = ($posicion+$ronda)%$usuarios+1;
                        $sesiones[$usuario] ??= [];
                        // Las dos pestañas comparten la misma sesión PHP.
                        for ($tab=0;$tab<2;$tab++) {
                            $disponible = $segundo !== 3;
                            $actualizar = !$optimizado || TelefoniaPresenciaRitmo::debeActualizar(
                                $sesiones[$usuario],$usuario,$disponible,$hora);
                            $svc->sondear($usuario,$disponible,0,false,null,$actualizar);
                            if ($actualizar) TelefoniaPresenciaRitmo::confirmar(
                                $sesiones[$usuario],$usuario,$disponible,$hora);
                        }
                    }
                }
            }
            $resultados[] = [$counter('Com_insert')-$insert,$counter('Com_select')-$select];
        }
        if ($resultados !== [[$usuarios*80,$usuarios*160],[$usuarios*30,$usuarios*160]]) {
            throw new RuntimeException('Contadores de rotación inesperados: '.json_encode($resultados));
        }
        echo "$usuarios usuarios, 10 rotaciones y 2 pestañas sintéticas: UPSERT ".
            $resultados[0][0].' -> '.$resultados[1][0].", SELECT ".$resultados[1][1]."\n";
    }

    $ddl = $counter('Com_create_table');
    new TelefoniaNotificacionService($db);
    if ($counter('Com_create_table') !== $ddl) throw new RuntimeException('La campana ejecutó DDL.');
    echo "PASS: 2 SELECT sin solicitud, prioridad de reservas y constructor de campana sin DDL.\n";
} finally {
    $db->query("DROP DATABASE `$name`");
}
