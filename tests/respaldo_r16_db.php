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
putenv('IMPE_BACKUP_LAB');
rechazar(fn()=>$s->restaurar($nombre));
putenv('IMPE_BACKUP_LAB=YES');
$s->validarEnLaboratorio($nombre,'impe_r16_lab_prueba');
$db->select_db('impe_r16_lab_prueba');
comprobar($db->query('SELECT nombre FROM consulta WHERE id=1')->fetch_assoc()['nombre']==='sintetico','restauración y vista');
comprobar($db->query('SHOW TRIGGERS')->num_rows===1 &&
    $db->query('SHOW EVENTS')->num_rows===1 &&
    (int)$db->query('SELECT r16_funcion() n')->fetch_assoc()['n']===7,'trigger, evento y función restaurados');
rechazar(fn()=>$s->validarEnLaboratorio($nombre,'impe_r16_lab_prueba'));
comprobar(true,'se rechaza esquema ocupado');

/* Simula una importación que MySQL confirma parcialmente por ejecutar DDL. */
$db->select_db(DB_NAME);
$db->query("INSERT INTO datos VALUES(2,'estado previo')");
$previo = $s->generar();
$db->query('CREATE DATABASE impe_r16_lab_fallido');
$db->query('CREATE DATABASE impe_r16_lab_recuperado');

$malSql = "CREATE TABLE parcial (id INT);\nINSERT INTO tabla_inexistente VALUES (1);\n";
$metaPath = $ruta . '.json';
$metaOriginal = file_get_contents($metaPath);
$meta = json_decode(json_decode($metaOriginal, true)['datos'], true);
$meta['bytes'] = strlen($malSql);
$meta['sha256'] = hash('sha256', $malSql);
$datos = json_encode($meta, JSON_THROW_ON_ERROR);
try {
    file_put_contents($ruta, $malSql);
    file_put_contents($metaPath, json_encode([
        'datos' => $datos,
        'firma' => hash_hmac('sha256', $datos, hex2bin(getenv('IMPE_BACKUP_HMAC_KEY'))),
    ], JSON_THROW_ON_ERROR));
    clearstatcache(true, $ruta);
    try {
        $s->validarEnLaboratorio($nombre, 'impe_r16_lab_fallido');
        $fallo = false;
    } catch (RuntimeException $e) {
        $fallo = true;
    }
    comprobar($fallo && $db->query('SHOW FULL TABLES FROM impe_r16_lab_fallido')->num_rows === 1,
        'SQL autenticado pero incompatible produce fallo parcial solo en laboratorio');
    comprobar($db->query('SELECT COUNT(*) n FROM datos')->fetch_assoc()['n'] == 2,
        'la fuente conserva su estado tras fallo');
    $s->validarEnLaboratorio($previo, 'impe_r16_lab_recuperado');
    comprobar($db->query('SELECT nombre FROM impe_r16_lab_recuperado.datos WHERE id=2')
        ->fetch_assoc()['nombre'] === 'ESTADO PREVIO', 'copia previa verificada recupera el estado esperado');
} finally {
    file_put_contents($ruta, $original);
    file_put_contents($metaPath, $metaOriginal);
}

$puntero = dirname($ruta) . '/restauracion-activa';
$s->restaurar($nombre);
$primera = trim(file_get_contents($puntero));
comprobar($primera !== DB_NAME && $db->query("SELECT COUNT(*) n FROM `$primera`.datos")
    ->fetch_assoc()['n'] == 1, 'restauración real desde la operación del módulo');
$s->restaurar($previo);
$segunda = trim(file_get_contents($puntero));
comprobar($primera !== $segunda && $db->query("SELECT COUNT(*) n FROM `$segunda`.datos")
    ->fetch_assoc()['n'] == 2, 'publicación de la nueva versión con la anterior conservada');
$copias = glob(dirname($ruta) . '/copia-previa-*.sql');
comprobar(count($copias) === 2 && hash_file('sha256', $copias[0]) ===
    json_decode(file_get_contents($copias[0] . '.json'), true)['sha256'], 'copia previa y huella conservadas');

try {
    file_put_contents($ruta, $malSql);
    file_put_contents($metaPath, json_encode([
        'datos' => $datos,
        'firma' => hash_hmac('sha256', $datos, hex2bin(getenv('IMPE_BACKUP_HMAC_KEY'))),
    ], JSON_THROW_ON_ERROR));
    clearstatcache(true, $ruta);
    rechazar(fn() => $s->restaurar($nombre));
    comprobar(trim(file_get_contents($puntero)) === $segunda &&
        $db->query("SELECT COUNT(*) n FROM `$segunda`.datos")->fetch_assoc()['n'] == 2,
        'fallo parcial conserva la versión activa y sus datos');
    comprobar($db->query("SHOW DATABASES LIKE 'impe_r16_lab_version_%'")->num_rows === 2,
        'no se conserva ni activa el destino incompleto');
} finally {
    file_put_contents($ruta, $original);
    file_put_contents($metaPath, $metaOriginal);
}

require $argv[1] . '/app/controllers/RespaldoController.php';
$procesar = new ReflectionMethod(RespaldoController::class, 'procesar');
$controlador = new RespaldoController();
$_SESSION = ['usuario_id' => 22, 'rol_id' => 2, 'rol' => 'Director de Operaciones', 'csrf_token' => 'prueba'];
$_POST = ['operacion' => 'restaurar', 'nombre' => $nombre, 'csrf_token' => 'prueba', 'confirmacion' => 'RESTAURAR'];
$procesar->invoke($controlador, $s);
comprobar($_SESSION['aviso_respaldo']['error'] && trim(file_get_contents($puntero)) === $segunda,
    'el Director no puede ejecutar la restauración');
$_SESSION['rol_id'] = 1;
$_POST['csrf_token'] = 'incorrecto';
$procesar->invoke($controlador, $s);
comprobar($_SESSION['aviso_respaldo']['error'] && trim(file_get_contents($puntero)) === $segunda,
    'el Administrador con CSRF incorrecto no puede restaurar');
$_POST['csrf_token'] = 'prueba';
$_POST['confirmacion'] = '';
$procesar->invoke($controlador, $s);
comprobar($_SESSION['aviso_respaldo']['error'] && trim(file_get_contents($puntero)) === $segunda,
    'el Administrador debe confirmar explícitamente');
$_POST['confirmacion'] = 'RESTAURAR';
$procesar->invoke($controlador, $s);
$tercera = trim(file_get_contents($puntero));
comprobar(empty($_SESSION['aviso_respaldo']['error']) && $tercera !== $segunda &&
    $db->query("SELECT COUNT(*) n FROM `$tercera`.datos")->fetch_assoc()['n'] == 1,
    'el Administrador autorizado recibe éxito únicamente después de restaurar');
