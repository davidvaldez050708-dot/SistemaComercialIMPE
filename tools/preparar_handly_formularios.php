<?php
// Operación explícita por CLI. Nunca se ejecuta desde el publicador de archivos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$runtimeRoot = rtrim(getenv('IMPE_RUNTIME_ROOT') ?: dirname(__DIR__), '/\\');
require_once $runtimeRoot . '/config/db_connection.php';
$modo = $argv[1] ?? '--check';
if (!in_array($modo, ['--check', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Uso: php preparar_handly_formularios.php --check|--apply\n"); exit(2);
}
try {
    $db = (new Database())->connect(false);
    foreach (['formulario_registros', 'formulario_inscripciones'] as $tabla) {
        $s = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->bind_param('s', $tabla); $s->execute();
        $fila = $s->get_result()->fetch_assoc();
        if (!$fila || strtoupper($fila['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('Se requieren ambos formularios existentes con motor InnoDB: ' . $tabla);
        }
    }
    if ($modo === '--apply') {
        $sql = file_get_contents(__DIR__ . '/../database/migrations/2026_10_19_formulario_aliado_programas.sql');
        $db->multi_query($sql);
        do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
        if ($db->errno) { throw new RuntimeException($db->error); }
        $db->begin_transaction();
        try {
            // Una nueva configuración se crea completa. Las existentes nunca se sobreescriben.
            $q = $db->query("SELECT id FROM formulario_aliados WHERE slug = 'handly-lenguaje-center' FOR UPDATE");
            if (!$q->num_rows) {
                $programas = ['bachillerato-general' => 'Bachillerato General', 'titulacion-experiencia-laboral' => 'Titulación por experiencia laboral'];
                foreach ($programas as $codigo => $nombre) {
                    $s = $db->prepare('INSERT INTO formulario_programas (codigo, nombre) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM formulario_programas WHERE codigo = ?)');
                    $s->bind_param('sss', $codigo, $nombre, $codigo); $s->execute();
                }
                $token = bin2hex(random_bytes(32));
                $s = $db->prepare("INSERT INTO formulario_aliados (slug, nombre, ubicacion, token) VALUES ('handly-lenguaje-center', 'Handly Lenguaje Center', 'Houston, Texas, EE. UU.', ?)");
                $s->bind_param('s', $token); $s->execute();
                $id = $db->insert_id;
                foreach (array_keys($programas) as $codigo) {
                    $s = $db->prepare('INSERT INTO formulario_aliado_programas (aliado_id, programa_codigo) VALUES (?, ?)');
                    $s->bind_param('is', $id, $codigo); $s->execute();
                }
            }
            validarHandly($db); // Impide confirmar una configuración parcialmente autorizada.
            $db->commit();
        } catch (Throwable $error) { $db->rollback(); throw $error; }
    }
    $aliado = validarHandly($db);
    foreach (['registro', 'inscripcion'] as $tipo) {
        echo ucfirst($tipo) . ': ' . BASE_URL . 'index.php?controller=formularioPublico&action=' . $tipo . '&aliado=' . $aliado['token'] . PHP_EOL;
    }
    echo "OK: Handly activo, exclusivamente Bachillerato General y Titulación por experiencia laboral.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'NO PREPARADO: ' . $error->getMessage() . PHP_EOL); exit(1);
}
function validarHandly(mysqli $db): array
{
    require_once __DIR__ . '/../app/services/FormularioAliadoService.php';
    $fila = $db->query("SELECT token FROM formulario_aliados WHERE slug = 'handly-lenguaje-center'")->fetch_assoc();
    if (!$fila) { throw new RuntimeException('Falta preparar Handly (migración explícita --apply, previa copia de BD).'); }
    $aliado = (new FormularioAliadoService($db))->resolver($fila['token']);
    $total = (int)$db->query('SELECT COUNT(*) n FROM formulario_aliado_programas WHERE aliado_id = ' . (int)$aliado['id'])->fetch_assoc()['n'];
    $nombres = array_column($aliado['programas'], 'nombre'); sort($nombres);
    if ($total !== 2 || $aliado['nombre'] !== 'Handly Lenguaje Center' || $nombres !== ['Bachillerato General', 'Titulación por experiencia laboral']) {
        throw new RuntimeException('Programas de Handly distintos de los autorizados. Revisar manualmente; no se cambió la configuración existente.');
    }
    $aliado['token'] = $fila['token']; return $aliado;
}
