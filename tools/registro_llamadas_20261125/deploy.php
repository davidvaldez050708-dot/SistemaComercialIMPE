<?php
/** CLI only. Upload this package outside public_html. No HTTP entry point. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function fail(string $message): void { throw new RuntimeException($message); }
function inside(string $path, string $root): bool {
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root.'/');
}
function target(string $root, string $relative): string {
    if (!preg_match('~^(?:app|public|prueba_telefonia)/[A-Za-z0-9_./-]+$~D', $relative) ||
        str_contains($relative, '..')) fail('Unsafe relative path.');
    $current = $root;
    foreach (explode('/', $relative) as $component) {
        $current .= DIRECTORY_SEPARATOR.$component;
        if (is_link($current)) fail('Symlink refused: '.$relative);
    }
    $resolved = realpath($current);
    if (!$resolved || !inside($resolved, $root) || !is_file($resolved)) fail('Missing target: '.$relative);
    return $resolved;
}
function checkedCopy(string $from, string $to, string $hash): void {
    if (!copy($from, $to) || hash_file('sha256', $to) !== $hash) fail('Copy failed: '.$to);
}
function replaceFile(string $source, string $destination, string $hash, int $mode): void {
    $stage = tempnam(dirname($destination), '.impe-stage-');
    if ($stage === false) fail('Cannot stage replacement.');
    try {
        checkedCopy($source, $stage, $hash);
        if (!chmod($stage, $mode)) fail('Cannot preserve file mode.');
        if (!rename($stage, $destination)) fail('Cannot replace '.$destination);
        if (hash_file('sha256', $destination) !== $hash) fail('Replacement hash mismatch.');
    } finally { if (is_file($stage)) unlink($stage); }
}

$lock = null;
try {
    $options = getopt('', ['root:', 'backup-root:', 'check', 'apply', 'rollback:']);
    $actions = (int)isset($options['check']) + (int)isset($options['apply']) + (int)isset($options['rollback']);
    if ($actions !== 1 || !isset($options['root'], $options['backup-root'])) {
        fail('Usage: php deploy.php --root=ABSOLUTE_DOCROOT --backup-root=PRIVATE_EXISTING_DIR --check|--apply|--rollback=BACKUP_ID');
    }
    $root = realpath($options['root']);
    $private = realpath($options['backup-root']);
    $package = realpath(__DIR__);
    if (!$root || !$private || !is_dir($root) || !is_dir($private)) fail('Root and private backup directory must exist.');
    if (inside($private, $root) || inside($package, $root)) fail('Package and backups must be outside the document root.');
    if (is_link($options['backup-root']) || is_link($options['root'])) fail('Symlink root refused.');
    $manifest = json_decode(file_get_contents(__DIR__.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['domain'] ?? '') !== 'fundacionrededumex.org') fail('Unexpected package domain.');
    $lock = fopen($private.'/.registro-llamadas.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('Another package operation is active.');
    chmod($private.'/.registro-llamadas.lock', 0600);
    $files = $manifest['files'];
    $rollback = isset($options['rollback']);
    if ($rollback) $files = array_reverse($files);
    if ($rollback) {
        $id = $options['rollback'];
        if (!preg_match('/^registro-[a-zA-Z0-9_-]+$/D', $id)) fail('Invalid backup ID.');
        $backup = $private.'/'.$id;
        if (is_link($backup)) fail('Symlink backup refused.');
        $receipt = json_decode(file_get_contents($backup.'/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['root'] ?? '') !== $root || ($receipt['manifest_sha256'] ?? '') !== hash_file('sha256', __DIR__.'/manifest.json')) fail('Backup does not match this root/package.');
    }
    // All targets and payloads pass before a single application file changes.
    foreach ($files as $file) {
        $destination = target($root, $file['path']);
        $before = hash_file('sha256', $destination);
        if ($before !== $file[$rollback ? 'after_sha256' : 'before_sha256']) fail('Preflight rejected production difference: '.$file['path']);
        $source = $rollback ? target($backup.'/files', $file['path']) : target(__DIR__.'/production-candidate', $file['path']);
        if (hash_file('sha256', $source) !== $file[$rollback ? 'before_sha256' : 'after_sha256']) fail('Payload/backup differs: '.$file['path']);
        if (!$rollback && str_ends_with($file['path'], '.php')) {
            $process = proc_open([PHP_BINARY, '-l', $source], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
            if (!is_resource($process)) fail('Cannot start PHP lint.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) fail('PHP lint rejected '.$file['path'].': '.$output.$error);
        }
    }
    if (isset($options['check'])) { echo 'Preflight OK: '.count($files)." files; application unchanged.\n"; exit(0); }
    if (!$rollback) {
        $id = 'registro-'.gmdate('Ymd_His').'-'.bin2hex(random_bytes(4));
        $backup = $private.'/'.$id;
        if (!mkdir($backup, 0700)) fail('Cannot create private backup.');
        $modes = [];
        foreach ($files as $file) {
            $source = target($root, $file['path']);
            $destination = $backup.'/files/'.$file['path'];
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) fail('Cannot create backup directories.');
            $modes[$file['path']] = fileperms($source) & 0777;
            checkedCopy($source, $destination, $file['before_sha256']);
            chmod($destination, 0600);
        }
        $receipt = ['root'=>$root, 'manifest_sha256'=>hash_file('sha256', __DIR__.'/manifest.json'), 'modes'=>$modes, 'created_at'=>gmdate('c')];
        if (file_put_contents($backup.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) fail('Cannot record backup.');
        chmod($backup.'/receipt.json', 0600);
        // Re-read after the backup, to reject changes made while it was copied.
        foreach ($files as $file) if (hash_file('sha256', target($root, $file['path'])) !== $file['before_sha256']) fail('Production changed during backup.');
    }
    $completed = [];
    try {
        foreach ($files as $file) {
            $destination = target($root, $file['path']);
            $expected = $file[$rollback ? 'after_sha256' : 'before_sha256'];
            if (hash_file('sha256', $destination) !== $expected) fail('Target changed during replacement: '.$file['path']);
            $source = $rollback ? target($backup.'/files', $file['path']) : target(__DIR__.'/production-candidate', $file['path']);
            replaceFile($source, $destination, $file[$rollback ? 'before_sha256' : 'after_sha256'], $receipt['modes'][$file['path']]);
            $completed[] = $file;
        }
    } catch (Throwable $error) {
        $recoveryErrors = [];
        foreach (array_reverse($completed) as $file) {
            try {
                $destination = target($root, $file['path']);
                $expected = $file[$rollback ? 'before_sha256' : 'after_sha256'];
                if (hash_file('sha256', $destination) !== $expected) fail('Concurrent edit: '.$file['path']);
                $source = $rollback ? target(__DIR__.'/production-candidate', $file['path']) : target($backup.'/files', $file['path']);
                replaceFile($source, $destination, $file[$rollback ? 'after_sha256' : 'before_sha256'], $receipt['modes'][$file['path']]);
            } catch (Throwable $recovery) { $recoveryErrors[] = $recovery->getMessage(); }
        }
        fail($error->getMessage().' Recovery: '.($recoveryErrors ? implode('; ', $recoveryErrors) : 'completed').'; backup='.$id);
    }
    echo ($rollback ? 'Rollback' : 'Apply').' verified: '.count($files).' files; backup='.$id."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n"); exit(1);
} finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
