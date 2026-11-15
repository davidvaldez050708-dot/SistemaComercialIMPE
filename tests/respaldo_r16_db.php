<?php
if (PHP_SAPI !== 'cli' || getenv('IMPE_BACKUP_LAB') !== 'YES' || empty($argv[1])) exit(2);
require $argv[1].'/app/services/RespaldoBaseDatos.php';
if (!preg_match('/\A127\.0\.0\.1:[0-9]+\z/', DB_HOST) || DB_NAME !== 'impe_r16_fuente') exit(2);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',DB_USER,DB_PASSWORD,'',(int)substr(DB_HOST,10));
function comprobar(bool $ok, string $caso): void {
    if (!$ok) throw new RuntimeException($caso);
    echo "OK $caso\n";
}
function rechazar(callable $operacion): void {
    try { $operacion(); } catch (DomainException $e) { return; }
    throw new RuntimeException('Se esperaba rechazo');
}
$db->query('CREATE DATABASE '.DB_NAME);
$db->query('CREATE DATABASE impe_r16_lab_prueba');
$db->select_db(DB_NAME);
$db->query('CREATE TABLE datos (id INT PRIMARY KEY, nombre VARCHAR(20)) ENGINE=InnoDB');
$db->query("INSERT INTO datos VALUES(1,'sintetico')");
$db->query('CREATE VIEW consulta AS SELECT * FROM datos');
$db->query("CREATE TRIGGER datos_bi BEFORE INSERT ON datos FOR EACH ROW SET NEW.nombre=UPPER(NEW.nombre)");
$db->query('CREATE FUNCTION r16_funcion() RETURNS INT DETERMINISTIC RETURN 7');
$db->query('CREATE PROCEDURE r16_procedimiento() SELECT 7');
$db->query('CREATE EVENT r16_evento ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO SELECT 7');
$s=new RespaldoBaseDatos();
$nombre=$s->generar();
$ruta=$s->archivo($nombre);
$historial=$s->historial();
comprobar(count($historial)===1 && $historial[0]['estado']==='Integridad verificada' &&
    $historial[0]['bytes']===filesize($ruta) && $historial[0]['fecha']>0,'historial e integridad');
$lock=fopen(dirname($ruta).'/.lock','c');flock($lock,LOCK_EX);
rechazar(fn()=>$s->generar());fclose($lock);
comprobar(true,'bloqueo concurrente');
$original=file_get_contents($ruta);
foreach (['alterado'=>($original[0]==='x'?'y':'x').substr($original,1),'truncado'=>substr($original,0,120)] as $caso=>$contenido) {
    file_put_contents($ruta,$contenido);clearstatcache(true,$ruta);
    rechazar(fn()=>$s->validarEnLaboratorio($nombre,'impe_r16_lab_prueba'));
    comprobar($db->query('SHOW FULL TABLES FROM impe_r16_lab_prueba')->num_rows===0,$caso.' rechazado antes del SQL');
}
file_put_contents($ruta,$original);clearstatcache(true,$ruta);
rechazar(fn()=>$s->restaurar($nombre));
$s->validarEnLaboratorio($nombre,'impe_r16_lab_prueba');
$db->select_db('impe_r16_lab_prueba');
comprobar($db->query('SELECT nombre FROM consulta WHERE id=1')->fetch_assoc()['nombre']==='sintetico','restauración y vista');
comprobar($db->query('SHOW TRIGGERS')->num_rows===1 &&
    $db->query('SHOW EVENTS')->num_rows===1 &&
    (int)$db->query('SELECT r16_funcion() n')->fetch_assoc()['n']===7,'trigger, evento y función restaurados');
rechazar(fn()=>$s->validarEnLaboratorio($nombre,'impe_r16_lab_prueba'));
comprobar(true,'se rechaza esquema ocupado');
