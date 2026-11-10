<?php
declare(strict_types=1);

// Se copia sólo al docroot desechable. No carga la aplicación ni abre MySQL.
if (getenv('IMPE_LAB_SNAPSHOT') !== '1') {
    http_response_code(404);
    exit;
}

$contadores = function_exists('mysqli_get_client_stats') ? mysqli_get_client_stats() : [];
$enlaces = function_exists('mysqli_get_links_stats') ? mysqli_get_links_stats() : [];
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'laboratorio' => getenv('IMPE_LAB_NONCE') ?: null,
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'pid' => getmypid(),
    'estadisticas' => ini_get('mysqlnd.collect_statistics'),
    'connection_reused_disponible' => array_key_exists('connection_reused', $contadores),
    'max_persistentes' => ini_get('mysqli.max_persistent'),
    'contadores' => array_intersect_key($contadores, array_flip([
        'connect_success', 'pconnect_success', 'connect_failure', 'connection_reused',
    ])),
    'enlaces' => array_intersect_key($enlaces, array_flip(['total', 'active_plinks', 'cached_plinks'])),
], JSON_THROW_ON_ERROR);
