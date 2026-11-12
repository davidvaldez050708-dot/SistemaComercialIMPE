<?php
/**
 * Trabajador CLI para el piloto Zoho Developer.
 * Uso: php tools/zoho_developer_registros_worker.php --status|--check|--run
 * --run procesa solo registros ficticios en cola, no inscripciones ni datos reales.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroSync.php';

$modo = $argv[1] ?? '';
if (!in_array($modo, ['--status', '--check', '--run'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Uso: php tools/zoho_developer_registros_worker.php --status|--check|--run\n");
    exit(2);
}

$cfg = ZohoDeveloperRegistroSync::configuracion();
if ($cfg === []) {
    fwrite(STDERR, "DESACTIVADO: falta configuración privada estricta de Developer.\n");
    exit(2);
}

if ($modo === '--check') {
    try {
        ZohoDeveloperRegistroSync::tokenVerificado($cfg);
        echo "OK: OAuth y organización Developer esperada verificados. Sin escrituras.\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, "ERROR: verificación OAuth/organización fallida. No se enviaron registros.\n");
        exit(1);
    }
}

try {
    $db = (new Database())->connect(false);
    if ($modo === '--status') {
        $res = $db->query(
            'SELECT estado, COUNT(*) AS total
             FROM zoho_dev_registros_pendientes GROUP BY estado ORDER BY estado'
        );
        while ($fila = $res->fetch_assoc()) {
            echo $fila['estado'] . ': ' . (int)$fila['total'] . "\n";
        }
        echo "Solo se muestran contadores; no se revelan datos personales.\n";
        exit(0);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR: falta base de datos/tabla de cola. No se modificó Zoho.\n");
    exit(1);
}

require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroWorker.php';
try {
    list($enviados, $fallidos) = ZohoDeveloperRegistroWorker::procesar($db, $cfg);
    exit($fallidos > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR: no fue posible procesar la cola de pruebas.\n");
    exit(1);
}
