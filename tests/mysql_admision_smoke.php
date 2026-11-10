<?php

require_once __DIR__ . '/../config/mysql_admision.php';

function verificar(bool $resultado): void
{
    if (!$resultado) {
        throw new RuntimeException('Falló la comprobación de admisión.');
    }
}

$directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'impe-admision-' . bin2hex(random_bytes(8));
mkdir($directorio, 0700);
try {
    $control = new AdmisionMysql($directorio);
    for ($i = 0; $i < 13; $i++) {
        $control->reservar(false);
    }
    $urgente = $control->reservar(true);
    $control->reservar(true);
    $inicio = hrtime(true);
    try {
        $control->reservar(true);
        verificar(false);
    } catch (AdmisionMysqlOcupada $error) {
        verificar((hrtime(true) - $inicio) / 1000000000 < 1);
    }
    $control->completar($urgente, true);
    $nueva = $control->reservar(true);
    $control->completar($nueva, false);
    usleep(1100000);
    $control->reservar(true);
    file_put_contents($directorio . '/mysql-cupo-invalido.reserva', '{incompleto');
    try {
        $control->reservar(true);
        verificar(false);
    } catch (AdmisionMysqlOcupada $error) {
        verificar(str_contains($error->getMessage(), 'requiere revisión'));
    }
    echo "Cupo reservado, devolución por reutilización, caducidad y estado inválido verificados.\n";
} finally {
    foreach (glob($directorio . '/*') as $archivo) {
        unlink($archivo);
    }
    rmdir($directorio);
}
