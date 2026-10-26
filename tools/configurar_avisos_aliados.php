<?php
// Instalación explícita y aditiva; nunca se ejecuta desde una petición web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check', '--apply'], true) || $argc > 2) {
    fwrite(STDERR, "Uso: php tools/configurar_avisos_aliados.php --check|--apply\n"); exit(2);
}
require_once __DIR__ . '/../config/db_connection.php';
try {
    $db = (new Database())->connect(false);
    if ($modo === '--apply') {
        $db->query("CREATE TABLE IF NOT EXISTS aliados_novedades_lecturas (
            asignacion_id INT NOT NULL,
            usuario_id INT NOT NULL,
            asignado_at DATETIME NOT NULL,
            formalizado_at DATETIME NOT NULL,
            leida_at DATETIME NOT NULL,
            PRIMARY KEY (asignacion_id, usuario_id, asignado_at, formalizado_at),
            KEY idx_aliado_novedad_usuario (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    // Validar que una tabla existente permita todas las operaciones previstas.
    $db->query('SELECT asignacion_id,usuario_id,asignado_at,formalizado_at,leida_at FROM aliados_novedades_lecturas LIMIT 0');
    $indices = $db->query("SHOW INDEX FROM aliados_novedades_lecturas WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
    usort($indices, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    if (array_column($indices, 'Column_name') !== ['asignacion_id','usuario_id','asignado_at','formalizado_at']) {
        throw new RuntimeException('Estructura incompatible');
    }
    echo "OK: avisos de aliados preparados; lectura persistente por destinatario y asignación.\n";
    echo "Sin cambios en aliados, territorios, reuniones o credenciales.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "No está preparada la tabla de lectura de avisos. Ejecuta --apply o revisa la estructura existente. Código=" . (int)$error->getCode() . "\n");
    exit(1);
}
