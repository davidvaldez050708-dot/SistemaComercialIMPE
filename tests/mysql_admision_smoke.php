<?php

require_once __DIR__ . '/../config/mysql_admision.php';

if (($argv[1] ?? '') === '--retener') {
    $admision = new AdmisionMysql($argv[2]);
    $reserva = $admision->reservar(AdmisionMysql::NORMAL);
    echo basename($reserva['estado']), "\n";
    fflush(STDOUT);
    sleep(60);
    exit;
}

function verificar(bool $resultado): void
{
    if (!$resultado) {
        throw new RuntimeException('Falló la comprobación de admisión.');
    }
}

$directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'impe-admision-' . bin2hex(random_bytes(8));
mkdir($directorio, 0700);
try {
    try {
        new AdmisionMysql($directorio . '/ausente');
        verificar(false);
    } catch (AdmisionMysqlOcupada $error) {
        verificar($error->getCode() === AdmisionMysqlOcupada::ESTADO);
    }

    try {
        new AdmisionMysql(dirname(__DIR__));
        verificar(false);
    } catch (AdmisionMysqlOcupada $error) {
        verificar($error->getCode() === AdmisionMysqlOcupada::ESTADO);
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($directorio, 0755);
        clearstatcache();
        try {
            new AdmisionMysql($directorio);
            verificar(false);
        } catch (AdmisionMysqlOcupada $error) {
            verificar($error->getCode() === AdmisionMysqlOcupada::ESTADO);
        } finally {
            chmod($directorio, 0700);
            clearstatcache();
        }
    }

    $control = new AdmisionMysql($directorio);
    $hijo = proc_open([PHP_BINARY, __FILE__, '--retener', $directorio],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    verificar(is_resource($hijo));
    try {
        stream_set_timeout($pipes[1], 5);
        $ocupado = trim((string)fgets($pipes[1]));
        verificar(preg_match('/^mysql-cupo-v2-\d+\.estado$/D', $ocupado) === 1);
        $rutaOcupada = $directorio . DIRECTORY_SEPARATOR . $ocupado;
        $lease = file_get_contents($rutaOcupada);
        $candado = fopen(substr($rutaOcupada, 0, -7) . '.lock', 'c+b');
        verificar(!flock($candado, LOCK_EX | LOCK_NB));
        proc_terminate($hijo);
    } finally {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($hijo);
    }
    verificar(flock($candado, LOCK_EX | LOCK_NB));
    verificar(file_get_contents($rutaOcupada) === $lease);
    fclose($candado);

    // Se aceleran sólo los tiempos de la prueba tras comprobar que la reserva
    // del proceso terminado se conservó. Un archivo incompleto aísla un cupo.
    file_put_contents($rutaOcupada, '- 0.000000');
    file_put_contents($directorio . '/mysql-cupo-v2-1.estado', 'incompleto');
    for ($i = 0; $i < 11; $i++) {
        $normales[] = $control->reservar(AdmisionMysql::NORMAL);
    }
    $arranque = $control->reservar(AdmisionMysql::ARRANQUE_TELEFONO);
    verificar(str_ends_with($arranque['estado'], '-13.estado'));
    $urgente = $control->reservar(AdmisionMysql::CRITICA);
    $otro = $control->reservar(AdmisionMysql::CRITICA);
    $inicio = hrtime(true);
    try {
        $control->reservar(AdmisionMysql::CRITICA);
        verificar(false);
    } catch (AdmisionMysqlOcupada $error) {
        verificar($error->getCode() === AdmisionMysqlOcupada::PLAZO);
        verificar((hrtime(true) - $inicio) / 1000000000 < 1);
    }
    $control->completar($urgente, true);
    $nueva = $control->reservar(AdmisionMysql::CRITICA);
    verificar($nueva['estado'] === $urgente['estado']);
    $control->completar($nueva, false);
    usleep(1100000);
    $recuperada = $control->reservar(AdmisionMysql::CRITICA);
    verificar($recuperada['estado'] === $nueva['estado']);
    foreach ([...$normales, $arranque, $otro, $recuperada] as $reserva) {
        $control->completar($reserva, true);
    }
    echo "Terminación, plazo, permisos, aislamiento de archivo incompleto y recuperación verificados.\n";
} finally {
    foreach (glob($directorio . '/*') as $archivo) {
        unlink($archivo);
    }
    rmdir($directorio);
}
