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
    $config = ZohoLeadsProspectosBridge::configuracion();
    if (($config['enabled'] ?? false) !== true ||
        !preg_match('/^[0-9]{10,25}$/D', (string)($config['expected_org_id'] ?? ''))) {
        throw new RuntimeException('CONFIG_ZOHO_LEADS_AUSENTE');
    }
    $db = (new Database())->connect();
    $bridge = new ZohoLeadsProspectosBridge($db, $config);
    $resumen = $bridge->ejecutar($argv[1] === '--apply');
    printf("Leads consultados: %d; nuevos: %d; existentes: %d; sin nombre: %d; modo: %s\n",
        $resumen['consultados'], $resumen['nuevos'], $resumen['existentes'],
        $resumen['sin_nombre'], $resumen['aplicado'] ? 'aplicado' : 'comprobación');
} catch (Throwable $e) {
    // Los mensajes de mysqli/cURL pueden contener datos de conexión.
    error_log('[zoho_leads_importacion] '.get_class($e));
    $seguros = [
        'CONFIG_ZOHO_LEADS_AUSENTE',
        'Autorización Zoho no disponible.',
        'Autorización Zoho inválida.',
        'Autorización Zoho incompleta.',
        'No se pudo renovar el acceso de lectura a Zoho.',
        'La organización Zoho no coincide.',
        'No se pudieron leer los posibles clientes de Zoho.',
        'Almacén central de prospectos no disponible.',
    ];
    $detalle = in_array($e->getMessage(), $seguros, true) ? $e->getMessage() : 'Consulta el registro privado.';
    fwrite(STDERR, "Importación detenida: ".$detalle."\n");
    exit(1);
} finally {
    if (is_resource($lock)) {flock($lock, LOCK_UN);fclose($lock);}
}
