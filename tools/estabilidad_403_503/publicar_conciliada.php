<?php
declare(strict_types=1);

// Herramienta CLI. No SQL, HTTP, reinicios, limpieza de pools ni llamadas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function fail(string $message): never { throw new RuntimeException($message); }
function hashOf(string $path): string { return hash_file('sha256', $path) ?: fail('No se pudo calcular SHA-256.'); }
function within(string $path, string $parent): bool {
    return str_starts_with(strtolower(str_replace('\\', '/', $path) . '/'), strtolower(str_replace('\\', '/', $parent) . '/'));
}
function directory(string $path): string {
    $real = realpath($path);
    if (!$real || !is_dir($real) || is_link($path)) fail('Directorio inexistente o enlace: ' . $path);
    return $real;
}
function fileAt(string $root, string $relative): string {
    if (!preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', $relative) || str_contains($relative, '..')) fail('Ruta no permitida.');
    $current = $root;
    foreach (explode('/', $relative) as $segment) {
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current)) fail('No se permiten enlaces: ' . $relative);
    }
    $real = realpath($current);
    if (!$real || !within($real, $root) || !is_file($real)) fail('Archivo no disponible: ' . $relative);
    return $real;
}
function lint(string $path): void {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) fail('No se pudo ejecutar lint.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) fail('Sintaxis inválida: ' . $out . $err);
}
function atomicCopy(string $source, string $dest, string $expected): void {
    if (is_link($dest) || hashOf($dest) !== $expected) fail('El archivo cambió antes del reemplazo: ' . $dest);
    $temp = $dest . '.impe-' . bin2hex(random_bytes(8));
    $handle = fopen($temp, 'xb');
    if (!$handle) fail('No se pudo crear temporal.');
    try {
        $bytes = file_get_contents($source);
        if ($bytes === false || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) fail('Temporal incompleto.');
        fclose($handle); $handle = null;
        if (!chmod($temp, fileperms($dest) & 0777) || hashOf($temp) !== hashOf($source)) fail('Temporal distinto.');
        if (is_link($dest) || hashOf($dest) !== $expected) fail('El archivo cambió durante la preparación.');
        if (!rename($temp, $dest)) fail('No se pudo reemplazar archivo.');
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($temp)) unlink($temp);
    }
}

try {
    if (PHP_VERSION_ID < 80200) fail('Requiere PHP CLI 8.2 o posterior; verificar además PHP web.');
    $options = getopt('', ['check','apply','restore:','restore-check:','root:','backup-root:']);
    $modes = array_intersect(['check','apply','restore','restore-check'], array_keys($options));
    if (count($modes) !== 1 || empty($options['root'])) fail('Uso: --check|--apply|--restore-check DIR|--restore DIR --root PUBLIC_HTML [--backup-root DIR_PRIVADO_EXISTENTE]');
    $mode = array_values($modes)[0];
    $root = directory($options['root']);
    $source = directory(__DIR__ . '/reconciled');
    $release = json_decode((string)file_get_contents($source . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
    $changed = ['config/db_connection.php','app/controllers/TelefoniaController.php','public/javascript/convocatoria_notificaciones.js'];
    if (($release['version'] ?? '') !== 'impe-403-503-reconciled-v2' || ($release['changed'] ?? []) !== $changed || count($release['files'] ?? []) !== 13) fail('Manifiesto de versión inválido.');
    foreach ($release['files'] as $name => $hashes) {
        $payload = fileAt($source, $name);
        if (hashOf($payload) !== $hashes['after']) fail('Payload alterado: ' . $name);
        fileAt($root, $name);
        if (str_ends_with($name, '.php')) lint($payload);
    }
    $restore = in_array($mode, ['restore','restore-check'], true);
    $backup = null;
    if ($restore) {
        $backup = directory($options[$mode]);
        if (within($backup, $root)) fail('El respaldo debe estar fuera de public_html.');
        $record = json_decode((string)file_get_contents($backup . '/backup.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($record['root'] ?? '') !== $root || ($record['release_sha256'] ?? '') !== hashOf($source . '/release.json')) fail('El respaldo no corresponde a esta instalación/versión.');
        foreach ($release['files'] as $name => $hashes) {
            if (hashOf(fileAt($backup, $name)) !== $hashes['before']) fail('Respaldo distinto al original: ' . $name);
        }
    }
    foreach ($release['files'] as $name => $hashes) {
        $expected = $restore ? $hashes['after'] : $hashes['before'];
        $actual = hashOf(fileAt($root, $name));
        // Permite recuperar una instalación interrumpida: cada archivo debe
        // seguir siendo exactamente el original o el candidato de esta versión.
        if ($actual !== $expected && !($restore && $actual === $hashes['before'])) fail('CONFLICTO: ' . $name . '. No sobrescribir personalizaciones.');
    }
    if (in_array($mode, ['check','restore-check'], true)) {
        echo "OK: hashes de los 13 archivos y sintaxis compatibles. Solo revisión de archivos; no acredita compatibilidad funcional ni estado OPcache. Sitio intacto.\n";
        exit;
    }
    if (!$restore) {
        if (empty($options['backup-root'])) fail('Se requiere --backup-root privado existente.');
        $backupRoot = directory($options['backup-root']);
        if (within($backupRoot, $root)) fail('El respaldo debe estar fuera de public_html.');
        $backup = $backupRoot . '/impe-403-503-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!mkdir($backup, 0700)) fail('No se pudo crear respaldo privado.');
        foreach ($release['files'] as $name => $hashes) {
            $dest = $backup . '/' . $name;
            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0700, true)) fail('No se pudo crear directorio de respaldo.');
            $live = fileAt($root, $name);
            if (hashOf($live) !== $hashes['before'] || !copy($live, $dest) || !chmod($dest, fileperms($live)&0777) || hashOf($dest) !== $hashes['before']) fail('Respaldo inconsistente.');
        }
        $record = ['root'=>$root,'release_sha256'=>hashOf($source.'/release.json'),'time_utc'=>gmdate('c')];
        if (file_put_contents($backup.'/backup.json', json_encode($record,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)) === false) fail('No se pudo registrar respaldo.');
        chmod($backup.'/backup.json',0600);
    }
    $written = [];
    try {
        $order = $restore ? array_reverse($changed) : $changed;
        foreach ($order as $name) {
            $hashes = $release['files'][$name];
            if ($restore && hashOf(fileAt($root, $name)) === $hashes['before']) continue;
            $expected = $restore ? $hashes['after'] : $hashes['before'];
            $newHash = $restore ? $hashes['before'] : $hashes['after'];
            atomicCopy(fileAt($restore ? $backup : $source, $name), fileAt($root, $name), $expected);
            $written[] = $name;
            if (hashOf(fileAt($root, $name)) !== $newHash) fail('Verificación posterior fallida.');
        }
        foreach ($release['files'] as $name => $hashes) {
            if (hashOf(fileAt($root, $name)) !== $hashes[$restore ? 'before' : 'after']) fail('Un archivo cambió durante la operación: ' . $name);
        }
    } catch (Throwable $error) {
        foreach (array_reverse($written) as $name) {
            $hashes = $release['files'][$name];
            $installed = $restore ? $hashes['before'] : $hashes['after'];
            try {
                atomicCopy(fileAt($restore ? $source : $backup, $name), fileAt($root, $name), $installed);
            } catch (Throwable $rollbackError) {
                fwrite(STDERR, 'NO SE PUDO RESTAURAR SIN PISAR CAMBIOS: ' . $name . PHP_EOL);
            }
        }
        throw $error;
    }
    echo 'OK: ' . $mode . '; ' . count($written) . ' archivos. Respaldo: ' . $backup . PHP_EOL;
    echo "Sin SQL, llamadas, cambios de configuración privada, reinicios ni invalidación de OPcache.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
