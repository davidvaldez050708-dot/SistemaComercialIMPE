<?php

require_once __DIR__ . '/../config/db_connection.php';

if (!preg_match('/^impe_test_[a-z0-9_]+$/D', DB_NAME)
    || !preg_match('/^127\.0\.0\.1:\d+$/D', DB_HOST)) {
    throw new RuntimeException('Este ensayo requiere una base de prueba local explícita.');
}

function comprobar(bool $ok, string $mensaje): void
{
    if (!$ok) {
        throw new RuntimeException($mensaje);
    }
}

if (isset($argv[1])) {
    $primera = $argv[1] === 'compartida' ? Database::compartida() : (new Database())->connect(false);
    $segunda = $argv[1] === 'compartida' ? Database::compartida() : (new Database())->connect(false);
    echo "listo\n";
    fflush(STDOUT);
    fgets(STDIN);
    exit(0);
}

$observador = (new Database())->connect(false);
$contar = static function () use ($observador): int {
    return (int)$observador->query('SELECT COUNT(*) FROM information_schema.PROCESSLIST
        WHERE DB=DATABASE() AND ID<>CONNECTION_ID()')->fetch_row()[0];
};
foreach (['independiente' => 6, 'compartida' => 3] as $modo => $esperadas) {
    $procesos = [];
    try {
        for ($i = 0; $i < 3; $i++) {
            $proceso = proc_open([PHP_BINARY, __FILE__, $modo], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $tuberias);
            comprobar(is_resource($proceso), 'No se pudo iniciar un trabajador de prueba');
            $procesos[] = [$proceso, $tuberias];
            comprobar(trim((string)fgets($tuberias[1])) === 'listo', 'Trabajador no listo');
        }
        comprobar($contar() === $esperadas, 'Sesiones concurrentes inesperadas en modo ' . $modo);
        echo "$modo: $esperadas sesiones simultáneas para tres solicitudes\n";
    } finally {
        foreach ($procesos as [$proceso, $tuberias]) {
            fclose($tuberias[0]);
            fclose($tuberias[1]);
            proc_close($proceso);
        }
    }
    comprobar($contar() === 0, 'No deben quedar conexiones de trabajadores');
}

$compartida = Database::compartida();
comprobar($compartida === Database::compartida(), 'La sesión compartida debe conservar identidad');
$aislada = (new Database())->connect(false);
$aislada->begin_transaction();
try {
    comprobar((int)$aislada->query('SELECT @@in_transaction')->fetch_row()[0] === 1, 'Transacción activa');
    comprobar((int)$compartida->query('SELECT @@in_transaction')->fetch_row()[0] === 0, 'Transacción aislada');
} finally {
    $aislada->rollback();
}
comprobar(Database::esFalloTemporal(new RuntimeException('envoltorio', 0, new mysqli_sql_exception('límite', 1203))), '1203 temporal');
comprobar(Database::esFalloTemporal(new mysqli_sql_exception('Operation not permitted', 2002)), '2002 temporal');
comprobar(!Database::esFalloTemporal(new mysqli_sql_exception('Acceso denegado', 1045)), '1045 no equivale a saturación');
comprobar(!Database::esFalloTemporal(new DomainException('Sin permiso', 403)), '403 de autorización');
echo "Reutilización, aislamiento y clasificación verificados.\n";
