<?php
/** Ejecutar por CLI: --check no escribe; --apply incorpora únicamente Leads nuevos. */
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !in_array($argv[1], ['--check','--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/importar_zoho_leads.php --check|--apply\n");
    exit(2);
}
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/ZohoLeadsProspectosBridge.php';
$lock = null;
try {
    $home = (string)(getenv('HOME') ?: '');
    if ($home === '' || !is_dir($home) || str_contains($home, 'public_html')) {
        throw new RuntimeException('Directorio privado no disponible.');
    }
    if ($argv[1] === '--apply') {
        $lock = fopen(rtrim($home, '/\\').'/.impe_zoho_leads.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Ya hay una importación Zoho en curso.');
        }
    }
    $db = (new Database())->connect();
    $bridge = new ZohoLeadsProspectosBridge($db, ZohoLeadsProspectosBridge::configuracion());
    $resumen = $bridge->ejecutar($argv[1] === '--apply');
    printf("Leads consultados: %d; nuevos: %d; existentes: %d; sin nombre: %d; modo: %s\n",
        $resumen['consultados'], $resumen['nuevos'], $resumen['existentes'],
        $resumen['sin_nombre'], $resumen['aplicado'] ? 'aplicado' : 'comprobación');
} catch (Throwable $e) {
    // Los mensajes de mysqli/cURL pueden contener datos de conexión.
    error_log('[zoho_leads_importacion] '.get_class($e));
    fwrite(STDERR, "Importación detenida. Revisa la configuración y el registro privado.\n");
    exit(1);
} finally {
    if (is_resource($lock)) {flock($lock, LOCK_UN);fclose($lock);}
}
