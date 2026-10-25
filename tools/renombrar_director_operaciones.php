<?php
/** CLI privado: --site /public_html --backup-root /ruta/privada --check|--apply|--restore archivo */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = getopt('', ['site:', 'backup-root:', 'check', 'apply', 'restore:']);
try {
    $root = realpath($args['site'] ?? '');
    $backups = $args['backup-root'] ?? '';
    if (!$root || !is_file($root . '/index.php') || $backups === '') throw new RuntimeException('Indica --site y --backup-root.');
    if (count(array_intersect(['check','apply','restore'], array_keys($args))) !== 1) throw new RuntimeException('Selecciona una sola operación.');
    // Comprobar el destino antes de crear cualquier carpeta.
    $backupReal = realpath($backups) ?: realpath(dirname($backups));
    if (!$backupReal || $backupReal === $root || str_starts_with($backupReal, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('El respaldo debe quedar fuera del sitio.');
    if (!isset($args['check'])) {
        if (!is_dir($backups) && !mkdir($backups, 0700, true)) throw new RuntimeException('No se pudo crear el respaldo privado.');
        $backupReal = realpath($backups);
    }
    if (!$backupReal || $backupReal === $root || str_starts_with($backupReal, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('El respaldo debe quedar fuera del sitio.');
    require $root . '/config/db_connection.php';
    require $root . '/app/services/DirectorOperacionesRolService.php';
    $svc = new DirectorOperacionesRolService((new Database())->connect(false));
    $rol = $svc->comprobar();
    if (isset($args['check'])) {
        echo ($rol['nombre'] === $svc::NOMBRE ? 'ACTUALIZADO' : 'COMPATIBLE') . ': rol 2 · ' . $rol['nombre'] . ". Sin escrituras.\n";
    } elseif (isset($args['restore'])) {
        $file = realpath($args['restore']);
        if (!$file || !str_starts_with($file, $backupReal . DIRECTORY_SEPARATOR)) throw new RuntimeException('Respaldo fuera de la carpeta privada.');
        $old = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($old['site'] ?? '') !== $root || ($old['id'] ?? 0) !== 2) throw new RuntimeException('Respaldo de otro sitio o rol.');
        $svc->renombrar($svc::NOMBRE, (string)$old['nombre']);
        echo "RESTAURADO: nombre anterior. Usuarios y permisos conservados.\n";
    } elseif ($rol['nombre'] === $svc::NOMBRE) {
        echo "SIN CAMBIOS: el rol ya tiene el nombre solicitado.\n";
    } else {
        chmod($backupReal, 0700);
        $file = $backupReal . '/rol-2-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $fp = fopen($file, 'x');
        if (!$fp) throw new RuntimeException('No se pudo guardar el respaldo.');
        chmod($file, 0600);
        $data = json_encode(['site'=>$root,'id'=>2,'nombre'=>$rol['nombre']], JSON_THROW_ON_ERROR);
        if (fwrite($fp, $data) !== strlen($data) || !fflush($fp)) throw new RuntimeException('Respaldo incompleto.');
        fclose($fp);
        $svc->renombrar($rol['nombre'], $svc::NOMBRE);
        echo "APLICADO: Director de Operaciones, rol 2. Usuarios y permisos conservados.\nRESPALDO: $file\n";
    }
} catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL); exit(1); }
