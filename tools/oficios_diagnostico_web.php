<?php
/** Herramienta temporal. No invoca generación, conversión ni base de datos. */
ini_set('display_errors', '0');

function odError($message = 'Diagnóstico no disponible.', $status = 404)
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function odNoLinks($path)
{
    do {
        if (is_link($path)) {
            throw new RuntimeException('No se admiten enlaces simbólicos.');
        }
        $parent = dirname($path);
        if ($parent === $path) {
            return;
        }
        $path = $parent;
    } while ($path !== '.');
}

function odInside($child, $parent)
{
    $child = str_replace('\\', '/', $child);
    $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
    if (PHP_OS_FAMILY === 'Windows') {
        $child = strtolower($child);
        $parent = strtolower($parent);
    }
    return str_starts_with($child . '/', $parent);
}

function odSaveState($handle, array $state)
{
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
        throw new RuntimeException('No se pudo deshabilitar el diagnóstico.');
    }
}

function odCli(array $args)
{
    if (!in_array($args[1] ?? '', ['--activar', '--retirar'], true)
        || count($args) !== (($args[1] ?? '') === '--activar' ? 4 : 5)) {
        odError('Uso: php HERRAMIENTA --activar RAIZ_CRM CARPETA_PRIVADA | --retirar RAIZ_CRM CARPETA_PRIVADA ID');
    }
    $root = realpath($args[2]);
    $private = realpath($args[3]);
    if (!$root || !$private || !is_dir($root . '/public') || !is_dir($private)
        || !is_file($root . '/app/services/OficioDocxPdfService.php')
        || odInside($private, $root) || odInside($root, $private)) {
        odError('Raíz inválida o carpeta privada dentro del sitio/sus antecesores. Crea una carpeta exclusiva fuera del sitio.');
    }
    odNoLinks($root);
    odNoLinks($root . '/public');
    odNoLinks($private);
    $id = $args[1] === '--activar' ? bin2hex(random_bytes(12)) : $args[4];
    if (!preg_match('/^[a-f0-9]{24}$/D', $id)) {
        odError('Identificador inválido.');
    }
    $statePath = $private . '/oficios-' . $id . '.json';
    $endpoint = $root . '/public/oficios_diag_' . $id . '.php';
    odNoLinks($statePath);
    odNoLinks($endpoint);
    if ($args[1] === '--retirar') {
        $handle = @fopen($statePath, 'r+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            odError('No se pudo abrir el control privado. No se retira ningún archivo.');
        }
        $state = json_decode(stream_get_contents($handle), true);
        if (!is_array($state) || ($state['id'] ?? '') !== $id || ($state['root'] ?? '') !== $root) {
            odError('Control privado inválido.');
        }
        $state['enabled'] = false;
        odSaveState($handle, $state);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (is_file($endpoint)) {
            if (!hash_equals((string)($state['endpoint_sha256'] ?? ''), hash_file('sha256', $endpoint))) {
                odError('Diagnóstico deshabilitado. El archivo cambió: no se elimina automáticamente.');
            }
            if (!unlink($endpoint)) {
                odError('Diagnóstico deshabilitado; no se pudo eliminar su archivo temporal.');
            }
        }
        if (!unlink($statePath)) {
            odError('Endpoint retirado; no se pudo eliminar su control privado deshabilitado.');
        }
        echo 'OK: diagnóstico retirado. Sin cambios en el CRM.' . PHP_EOL;
        return;
    }
    $token = bin2hex(random_bytes(32));
    $source = file_get_contents(__FILE__);
    $source = "<?php\ndefine('IMPE_OD_ROOT', " . var_export($root, true) . ");\n"
        . "define('IMPE_OD_STATE', " . var_export($statePath, true) . ");\n"
        . substr($source, 5);
    $oldMask = umask(0077);
    $state = ['id' => $id, 'root' => $root, 'enabled' => true, 'expires' => time() + 900,
        'token_sha256' => hash('sha256', $token), 'endpoint_sha256' => hash('sha256', $source)];
    $handle = @fopen($statePath, 'x');
    if (!$handle) {
        odError('No se pudo crear el control privado exclusivo.');
    }
    try {
        odSaveState($handle, $state);
    } finally {
        fclose($handle);
        umask($oldMask);
    }
    $handle = @fopen($endpoint, 'x');
    if (!$handle) {
        unlink($statePath);
        odError('No se pudo crear el archivo temporal exclusivo.');
    }
    $written = fwrite($handle, $source);
    fclose($handle);
    if ($written !== strlen($source) || !chmod($endpoint, 0644)) {
        @unlink($endpoint);
        @unlink($statePath);
        odError('No se pudo preparar el archivo temporal.');
    }
    echo 'Activado por 15 minutos; una sola consulta POST.' . PHP_EOL;
    echo 'Abrir en el mismo dominio HTTPS del CRM, como Administrador: /public/oficios_diag_' . $id . '.php' . PHP_EOL;
    echo 'Token (pegar sólo en el formulario; no compartir): ' . $token . PHP_EOL;
    echo 'Retirar: php ' . escapeshellarg(__FILE__) . ' --retirar ' . escapeshellarg($root)
        . ' ' . escapeshellarg($private) . ' ' . $id . PHP_EOL;
}

/** Sólo comandos fijos elegidos internamente. Sin shell, stdout ni stderr públicos. */
function odProbe(array $command, float $timeout = 3.0)
{
    foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'usleep'] as $function) {
        if (!function_exists($function)) {
            return 'No ejecutada: falta una función necesaria para controlar el proceso.';
        }
    }
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = @proc_open($command, [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
        $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
    if (!is_resource($process)) {
        return 'Bloqueada: no se pudo iniciar el proceso fijo.';
    }
    $deadline = microtime(true) + $timeout;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            proc_close($process);
            return $exitCode === 0 ? 'Correcta: proceso fijo terminó con código 0.' : 'Falló el proceso fijo.';
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    proc_terminate($process, 9);
    // No bloquear proc_close esperando un proceso que aún figura activo.
    for ($i = 0; $i < 10; $i++) {
        usleep(20000);
        if (!proc_get_status($process)['running']) {
            proc_close($process);
            break;
        }
    }
    return 'Interrumpida por tiempo de espera (3 segundos).';
}

function odReport($probe)
{
    require_once IMPE_OD_ROOT . '/app/services/OficioDocxPdfService.php';
    $diag = (new OficioDocxPdfService())->diagnosticar();
    $disabled = array_map('strtolower', preg_split('/[\s,]+/', (string)ini_get('disable_functions'), -1, PREG_SPLIT_NO_EMPTY));
    $exists = function_exists('proc_open') && is_callable('proc_open');
    $blocked = in_array('proc_open', $disabled, true);
    $converter = $diag['conversor'] ?? [];
    $kind = $converter['tipo'] ?? '';
    $validKinds = ['libreoffice' => 'LibreOffice', 'word' => 'Word / PowerShell', 'phpword_dompdf' => 'PHPWord / Dompdf'];
    $yes = static function ($condition) { return $condition ? 'Sí' : 'No'; };
    $usable = static function ($path) { return is_file($path) && is_executable($path); };
    if (PHP_OS_FAMILY === 'Windows') {
        $lo = $usable('C:\\Program Files\\LibreOffice\\program\\soffice.exe')
            || $usable('C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe');
        $soffice = $lo;
    } else {
        $lo = $usable('/usr/bin/libreoffice') || $usable('/snap/bin/libreoffice');
        $soffice = $usable('/usr/bin/soffice');
    }
    $configured = trim((string)getenv('LIBREOFFICE_PATH'));
    $template = IMPE_OD_ROOT . '/storage/templates/oficio_general_redmex.docx';
    $temp = IMPE_OD_ROOT . '/storage/tmp/oficios';
    $tempParent = is_dir($temp) ? $temp : dirname($temp);
    $rows = [
        'PHP web' => PHP_VERSION,
        'SAPI observado' => PHP_SAPI,
        'proc_open: existe y es invocable' => $yes($exists),
        'proc_open en disable_functions web' => $yes($blocked),
        'Coincide con hPanel' => 'Comparar este resultado web con el cambio realizado; no se consulta hPanel.',
        'LibreOffice (rutas estándar del servicio)' => $lo ? 'Detectado y ejecutable por permisos' : 'No detectado',
        'soffice (rutas estándar del servicio)' => $soffice ? 'Detectado y ejecutable por permisos' : 'No detectado',
        'Ruta explícita LIBREOFFICE_PATH' => $configured === '' ? 'No configurada' : ($usable($configured) ? 'Configurada y ejecutable por permisos' : 'Configurada; no utilizable'),
        'Motor seleccionado por el servicio actual' => $validKinds[$kind] ?? 'No detectado',
        'Plantilla general DOCX legible' => $yes(is_file($template) && is_readable($template)),
        'ZIP (ZipArchive o PharData)' => $yes($diag['zip_disponible'] ?? false),
        'XML DOM / XMLReader / mbstring' => $yes(class_exists('DOMDocument')) . ' / ' . $yes(class_exists('XMLReader')) . ' / ' . $yes(extension_loaded('mbstring')),
        'PHPWord / Dompdf' => $yes(class_exists('PhpOffice\\PhpWord\\IOFactory')) . ' / ' . $yes(class_exists('Dompdf\\Dompdf')),
        'Carpeta temporal de oficios o su padre escribible' => $yes(is_dir($tempParent) && is_writable($tempParent)),
        'DOCX institucional' => 'Preparación existente identificada; no ejecutada en este diagnóstico.',
        'Prueba de ejecución' => 'No solicitada; no se ejecutaron procesos.',
        'PDF / formato / vista previa / descarga' => 'Pendientes de prueba real; no se generaron documentos.',
    ];
    if (!$exists || $blocked) {
        $rows['Prueba de ejecución'] = 'Bloqueada por PHP web; no se intentó ejecutar procesos.';
        $rows['Conversión PDF en Hostinger'] = 'No viable con la configuración observada y el servicio actual.';
        return $rows;
    }
    $rows['Conversión PDF en Hostinger'] = 'Pendiente de prueba de conversión y fidelidad REDMEX.';
    if ($kind === 'phpword_dompdf') {
        $rows['Compatibilidad del motor seleccionado'] = 'Respaldo PHP detectado; la prueba local previa perdió formato REDMEX. No acredita compatibilidad.';
    }
    if ($probe) {
        $command = null;
        $operation = '';
        if ($kind === 'libreoffice' && $usable((string)($converter['ruta'] ?? ''))) {
            $command = [(string)$converter['ruta'], '--version'];
            $operation = 'versión de LibreOffice';
        } elseif (PHP_OS_FAMILY !== 'Windows') {
            foreach (['/usr/bin/true', '/bin/true'] as $fixed) {
                if ($usable($fixed)) {
                    $command = [$fixed];
                    $operation = 'proceso mínimo true; no prueba un conversor';
                    break;
                }
            }
        } else {
            $fixedPhp = strtolower(basename(PHP_BINARY)) === 'php.exe' ? PHP_BINARY : PHP_BINDIR . '/php.exe';
            if ($usable($fixedPhp)) {
                $command = [$fixedPhp, '-n', '-r', 'exit(0);'];
                $operation = 'proceso mínimo PHP; no prueba Word';
            }
        }
        $rows['Operación fija'] = $operation ?: 'Ninguna disponible; no se ejecuta un sustituto.';
        $rows['Prueba de ejecución'] = $command === null ? 'Pendiente: no existe el ejecutable fijo de prueba.' : odProbe($command);
        if ($command !== null && !str_starts_with($rows['Prueba de ejecución'], 'Correcta:')) {
            $rows['Conversión PDF en Hostinger'] = 'No acreditada: la prueba fija no pudo completarse. Diagnóstico detenido.';
        }
    }
    return $rows;
}

function odWeb()
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    if (!defined('IMPE_OD_ROOT') || !defined('IMPE_OD_STATE') || is_link(IMPE_OD_STATE)
        || !is_file(IMPE_OD_STATE) || !empty($_GET)) {
        odError();
    }
    $handle = @fopen(IMPE_OD_STATE, 'r+');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
        odError();
    }
    $state = json_decode(stream_get_contents($handle), true);
    if (!is_array($state) || ($state['enabled'] ?? false) !== true
        || (int)($state['expires'] ?? 0) <= time() || ($state['root'] ?? '') !== IMPE_OD_ROOT) {
        odError();
    }
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1' && (int)($_SERVER['SERVER_PORT'] ?? 0) !== 443) {
        odError('Este diagnóstico requiere HTTPS.', 403);
    }
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start(['read_and_close' => true])) {
        odError();
    }
    if ((int)($_SESSION['usuario_id'] ?? 0) <= 0 || (int)($_SESSION['rol_id'] ?? 0) !== 1
        || (int)($_SESSION['ultima_actividad'] ?? 0) < time() - 1800) {
        odError('Se requiere una sesión vigente de Administrador del CRM.', 403);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    header('Content-Type: text/html; charset=utf-8');
    if ($method === 'GET') {
        flock($handle, LOCK_UN);
        fclose($handle);
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Diagnóstico temporal de oficios</title>';
        echo '<h1>Diagnóstico temporal de oficios</h1><p>Token de un solo uso, con vigencia máxima de 15 minutos. No se generan documentos.</p>';
        echo '<form method="post" autocomplete="off"><label>Token recibido por SSH <input type="password" name="token" required minlength="64" maxlength="64" autocomplete="off"></label>';
        echo '<p><label><input type="checkbox" name="probe" value="1"> Permitir prueba fija de ejecución (máximo 3 segundos; sin convertir documentos).</label></p>';
        echo '<button type="submit">Comprobar y deshabilitar diagnóstico</button></form></html>';
        return;
    }
    if ($method !== 'POST') {
        odError('Método no permitido.', 405);
    }
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)
        || !hash_equals((string)($state['token_sha256'] ?? ''), hash('sha256', $token))) {
        odError('Token inválido o expirado.', 403);
    }
    if (array_diff(array_keys($_POST), ['token', 'probe']) || !in_array($_POST['probe'] ?? '', ['', '1'], true)) {
        odError('Solicitud inválida.', 422);
    }
    // Consumir antes de comprobar capacidades: los fallos tampoco permiten reutilizarlo.
    $state['enabled'] = false;
    $state['token_sha256'] = '';
    odSaveState($handle, $state);
    flock($handle, LOCK_UN);
    fclose($handle);
    $rows = odReport(($_POST['probe'] ?? '') === '1');
    $removed = @unlink(__FILE__);
    $escape = static function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Resultado de diagnóstico de oficios</title><h1>Resultado PHP web</h1>';
    echo '<p>Diagnóstico deshabilitado. Archivo temporal ' . ($removed ? 'retirado.' : 'pendiente de retirar por SSH.') . '</p>';
    echo '<p>Comparte sólo la tabla siguiente. Conserva en privado el token y la orden de retirada.</p><table><thead><tr><th>Comprobación</th><th>Resultado</th></tr></thead><tbody>';
    foreach ($rows as $label => $value) {
        echo '<tr><td>' . $escape($label) . '</td><td>' . $escape($value) . '</td></tr>';
    }
    echo '</tbody></table><p>Ejecuta la orden de retirada impresa por SSH para eliminar también el control privado.</p></html>';
}

try {
    if (PHP_SAPI === 'cli') {
        odCli($argv);
    } else {
        odWeb();
    }
} catch (Throwable $error) {
    odError('El diagnóstico no pudo completarse. No se modificaron documentos ni registros.', 500);
}
