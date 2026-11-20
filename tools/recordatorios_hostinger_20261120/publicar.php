<?php
// Plantilla del publicador CLI. No carga el CRM ni ejecuta consultas SQL.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function fail(string $message): never { throw new RuntimeException($message); }
function checkedPath(string $root, string $relative): string {
    $path = $root . '/' . $relative;
    if (!is_file($path) || is_link($path) || str_replace('\\', '/', (string)realpath($path)) !== $path) fail('Archivo ausente o enlace no permitido: ' . $relative);
    return $path;
}
function atomicWrite(string $path, string $data): void {
    $tmp = tempnam(dirname($path), '.impe-anticipos-');
    if ($tmp === false) fail('No se pudo crear temporal');
    try {
        if (file_put_contents($tmp, $data) !== strlen($data)) fail('No se pudo escribir el archivo completo');
        chmod($tmp, fileperms($path) & 0777);
        if (!rename($tmp, $path)) fail('No se pudo instalar el archivo');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function lint(string $relative, string $data): void {
    if (!str_ends_with($relative, '.php')) return;
    $tmp = tempnam(sys_get_temp_dir(), 'impe-anticipos-lint-');
    try {
        file_put_contents($tmp, $data);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) fail('No se pudo ejecutar php -l');
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) fail('Sintaxis inválida en ' . $relative . ': ' . $out);
    } finally { if (is_file($tmp)) unlink($tmp); }
}
$lock = ''; $locked = false; $written = []; $originals = []; $candidates = []; $backup = '';
try {
    $mode = $argv[1] ?? ''; $root = realpath($argv[2] ?? '');
    if (!in_array($mode, ['--check', '--apply', '--restore'], true) || !$root || !is_file($root . '/index.php') ||
        count($argv) !== ($mode === '--restore' ? 4 : 3)) fail('Uso: php publicar.php --check|--apply RAIZ_CRM | --restore RAIZ_CRM RESPALDO');
    $root = str_replace('\\', '/', $root);
    $json = file_get_contents(__DIR__ . '/cambios.json');
    if (hash('sha256', $json) !== '47a93914b979febcce3776de81adf9646b5cefd5e2d2da7c907c2fe2e8b9c3d2') fail('El paquete está incompleto o alterado');
    $patches = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $lock = dirname($root) . '/.impe-anticipos-deploy.lock';
    if ($mode !== '--check') {
        if (is_link($lock) || !@mkdir($lock, 0700)) fail('Otra publicación está en curso: ' . $lock);
        $locked = true;
    }
    $restore = null;
    if ($mode === '--restore') {
        $backup = realpath($argv[3]) ?: fail('Respaldo no encontrado');
        $restore = json_decode(file_get_contents($backup . '/respaldo.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($restore['version'] ?? '') !== 'proximas-acciones-20261120' || $restore['root'] !== $root) fail('El respaldo corresponde a otra instalación');
    }
    foreach ($patches as $relative => $profiles) {
        $path = checkedPath($root, $relative);
        $originals[$relative] = file_get_contents($path);
        $current = str_replace("\r\n", "\n", $originals[$relative]);
        if ($restore !== null) {
            $record = $restore['files'][$relative] ?? fail('Respaldo incompleto');
            if (!in_array(hash('sha256', $originals[$relative]), [$record['before'], $record['after']], true)) fail('Archivo editado después de publicar: ' . $relative);
            $next = file_get_contents($backup . '/files/' . $relative);
            if (hash('sha256', $next) !== $record['before']) fail('Respaldo alterado: ' . $relative);
        } else {
            $matches = [];
            foreach ($profiles as $hunks) {
                $trial = $current;
                foreach ($hunks as $hunk) {
                    if (substr_count($trial, $hunk['before']) === 1) {
                        $trial = str_replace($hunk['before'], $hunk['after'], $trial);
                    } elseif (substr_count($trial, $hunk['after']) !== 1) {
                        continue 2;
                    }
                }
                $matches[hash('sha256', $trial)] = $trial;
            }
            if (count($matches) !== 1) fail('Variante no reconocida o ambigua en ' . $relative . '. No se modifica ningún archivo; requiere conciliar este archivo.');
            $next = array_values($matches)[0];
            if ($next === $current) $next = $originals[$relative];
        }
        lint($relative, $next);
        $candidates[$relative] = $next;
        echo ($next === $originals[$relative] ? 'SIN CAMBIO ' : 'LISTO ') . $relative . PHP_EOL;
    }
    if ($mode === '--check') { echo "CHECK OK. No se escribió en el sitio.\n"; return; }
    $changed = array_filter(array_keys($patches), fn($f) => $originals[$f] !== $candidates[$f]);
    if (!$changed) { echo "Sin cambios pendientes.\n"; return; }
    if ($mode === '--apply') {
        $base = getenv('IMPE_RECORDATORIOS_BACKUPS') ?: dirname($root) . '/respaldos-crm';
        if (!is_dir($base) && !mkdir($base, 0700, true)) fail('No se pudo crear carpeta de respaldos');
        $base = str_replace('\\', '/', realpath($base));
        if ($base === $root || str_starts_with($base . '/', $root . '/')) fail('El respaldo debe quedar fuera de public_html');
        $backup = $base . '/proximas-acciones-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        mkdir($backup, 0700);
        $metadata = ['version' => 'proximas-acciones-20261120', 'root' => $root, 'files' => []];
        foreach ($patches as $relative => $_) {
            $dest = $backup . '/files/' . $relative;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
            if (file_put_contents($dest, $originals[$relative]) !== strlen($originals[$relative])) fail('No se pudo respaldar ' . $relative);
            chmod($dest, 0600);
            $metadata['files'][$relative] = ['before' => hash('sha256', $originals[$relative]), 'after' => hash('sha256', $candidates[$relative])];
        }
        file_put_contents($backup . '/respaldo.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo 'RESPALDO=' . $backup . PHP_EOL;
    }
    foreach ($changed as $relative) {
        $path = checkedPath($root, $relative);
        if (hash_file('sha256', $path) !== hash('sha256', $originals[$relative])) fail('Archivo modificado durante la publicación: ' . $relative);
        atomicWrite($path, $candidates[$relative]);
        $written[] = $relative;
    }
    echo ($mode === '--restore' ? 'RESTAURACIÓN OK' : 'PUBLICACIÓN OK') . '. Sin SQL ni cambios a datos operativos.' . PHP_EOL;
} catch (Throwable $e) {
    foreach (array_reverse($written) as $relative) {
        $path = $root . '/' . $relative;
        if (hash_file('sha256', $path) === hash('sha256', $candidates[$relative])) atomicWrite($path, $originals[$relative]);
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL); $exitCode = 1;
} finally { if ($locked && is_dir($lock)) rmdir($lock); }
exit($exitCode ?? 0);
