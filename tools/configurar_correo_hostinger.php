<?php
// CLI únicamente. No crea cuentas, no envía correos y no cambia la BD.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/private_config.php';
require_once dirname(__DIR__) . '/app/services/CorreoSmtpTransport.php';

final class CorreoConfiguracionHostinger
{
    private $directory, $probe, $userLoader;
    public function __construct(?string $directory = null, ?callable $probe = null, ?callable $userLoader = null)
    {
        $this->directory = $directory ?? (getenv('IMPE_PRIVATE_CONFIG_DIR') ?: dirname(__DIR__, 2) . '/impe-private');
        $this->probe = $probe; $this->userLoader = $userLoader;
    }

    public function configurar(string $mode, array $input): array
    {
        if (!in_array($mode, ['--recovery', '--hostinger', '--account'], true)) { throw new RuntimeException('MAIL_SETUP_MODE'); }
        $secret = trim((string)($input['secret'] ?? ''));
        if ($secret === '' || strlen($secret) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $secret)) { throw new RuntimeException('MAIL_SETUP_SECRET'); }
        $directory = $this->directorio();
        $user = null;
        if ($mode === '--hostinger') {
            $a = ['activo' => true, 'transport' => 'hostinger_api', 'correo' => '', 'api_token' => $secret];
            $file = 'mail_central.local.php';
        } else {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('MAIL_SETUP_IDENTITY'); }
            $host = trim((string)($input['host'] ?? ''));
            if ($host === 'smtp.gmail.com') { $secret = str_replace(' ', '', $secret); }
            $a = ['correo' => $email, 'nombre' => 'Sistema Comercial IMPE', 'transport' => 'smtp',
                'smtp_host' => $host, 'smtp_port' => (int)($input['port'] ?? 587), 'smtp_encryption' => $input['tls'] ?? 'tls',
                'smtp_username' => $email, 'auth_method' => 'password', 'smtp_password' => $secret];
            CorreoSmtpTransport::configurar(new \PHPMailer\PHPMailer\PHPMailer(true), $a);
            $file = $mode === '--recovery' ? 'password_reset_mail.local.php' : 'mail_accounts.local.php';
            if ($mode === '--account') {
                $user = $this->usuarioActivo($email);
                $a['activo'] = true; $a['usuarios'] = [(int)$user['id']];
            }
        }
        // Autenticar ANTES de reemplazar cualquier configuración.
        if ($this->probe) { $ok = ($this->probe)($a); }
        else { $ok = $this->autenticar($a); }
        if (!$ok) { throw new RuntimeException('MAIL_SETUP_AUTH'); }
        if ($mode === '--hostinger' && trim((string)($input['email'] ?? '')) !== '') {
            $user = $this->usuarioActivo(strtolower(trim($input['email'])));
            $addresses = is_array($ok) ? ($ok['addresses'] ?? []) : [];
            $addresses = array_values(array_filter($addresses, static function ($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }));
            if (!$addresses) { throw new RuntimeException('MAIL_SETUP_API_MAILBOXES'); }
            $a['authorized_contacts'] = [(int)$user['id'] => $addresses];
        }
        $lockPath = $directory . '/.correo-config.lock';
        if (is_link($lockPath)) { throw new RuntimeException('MAIL_SETUP_LINK'); }
        $lock = fopen($lockPath, 'c+b');
        if (!$lock) { throw new RuntimeException('MAIL_SETUP_WRITE'); }
        chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('MAIL_SETUP_BUSY'); }
            $path = $directory . '/' . $file;
            if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new RuntimeException('MAIL_SETUP_LINK'); }
            if ($mode === '--hostinger') {
                $prior = is_file($path) ? require $path : impeLeerConfigPrivada($file);
                if (!is_array($prior) || !is_array($prior['authorized_contacts'] ?? [])) { throw new RuntimeException('MAIL_SETUP_CONFIG'); }
                $a['authorized_contacts'] = array_replace($prior['authorized_contacts'] ?? [], $a['authorized_contacts'] ?? []);
            }
            if ($mode === '--account') {
                $prior = impeLeerConfigPrivada($file);
                if (is_file($path)) { $prior = require $path; }
                if (!is_array($prior)) { throw new RuntimeException('MAIL_SETUP_CONFIG'); }
                $prior['accounts'] = $prior['accounts'] ?? [];
                if (!is_array($prior['accounts'])) { throw new RuntimeException('MAIL_SETUP_CONFIG'); }
                foreach ($prior['accounts'] as $key => $old) {
                    if (is_array($old) && strcasecmp((string)($old['correo'] ?? (is_string($key) ? $key : '')), $a['correo']) === 0) {
                        $a['usuarios'] = array_values(array_unique(array_merge(array_map('intval', (array)($old['usuarios'] ?? [])), $a['usuarios'])));
                        unset($prior['accounts'][$key]);
                    }
                }
                $prior['accounts'][$a['correo']] = $a; $a = $prior;
            }
            $backup = null;
            if (is_file($path)) {
                $backup = $path . '.respaldo-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                if (!copy($path, $backup) || !chmod($backup, 0600) || hash_file('sha256', $backup) !== hash_file('sha256', $path)) {
                    throw new RuntimeException('MAIL_SETUP_BACKUP');
                }
            }
            $bytes = "<?php\n// Configuración privada de correo. No publicar.\nreturn " . var_export($a, true) . ";\n";
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
            try {
                $h = fopen($temporary, 'xb');
                if (!$h) { throw new RuntimeException('MAIL_SETUP_WRITE'); }
                try {
                    if (!chmod($temporary, 0600) || fwrite($h, $bytes) !== strlen($bytes) || !fflush($h)) { throw new RuntimeException('MAIL_SETUP_WRITE'); }
                } finally { fclose($h); }
                if (!rename($temporary, $path)) { throw new RuntimeException('MAIL_SETUP_WRITE'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
            return ['archivo' => $file, 'ruta' => $path, 'respaldo' => $backup, 'autenticacion' => true];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function directorio(): string
    {
        $dir = rtrim($this->directory, '/\\');
        $normal = str_replace('\\', '/', $dir);
        if ((!str_starts_with($normal, '/') && !preg_match('/^[A-Za-z]:\//D', $normal)) || preg_match('~(^|/)\.{1,2}(/|$)~', $normal)) {
            throw new RuntimeException('MAIL_SETUP_NOT_PRIVATE');
        }
        for ($p = $dir; dirname($p) !== $p; $p = dirname($p)) { if (is_link($p)) { throw new RuntimeException('MAIL_SETUP_LINK'); } }
        // No crear ni siquiera una carpeta dentro de la raíz pública.
        $ancestor = $dir;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) { $ancestor = dirname($ancestor); }
        $realAncestor = realpath($ancestor);
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $roots = [realpath(dirname(__DIR__)), $documentRoot !== '' ? realpath($documentRoot) : false];
        foreach ($roots as $root) {
            if ($root && $realAncestor && str_starts_with(strtolower($realAncestor . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('MAIL_SETUP_NOT_PRIVATE');
            }
        }
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) { throw new RuntimeException('MAIL_SETUP_DIRECTORY'); }
        $real = realpath($dir);
        if (!$real || !is_writable($real)) { throw new RuntimeException('MAIL_SETUP_DIRECTORY'); }
        return $real;
    }

    private function usuarioActivo(string $email): array
    {
        if ($this->userLoader) { $user = ($this->userLoader)($email); }
        else {
            require_once dirname(__DIR__) . '/app/models/UsuarioModel.php';
            $user = (new UsuarioModel())->buscarPorUsuarioOCorreo($email);
        }
        if (!$user || (int)($user['id'] ?? 0) <= 0 || (int)($user['estado'] ?? 0) !== 1 ||
            (int)($user['rol_estado'] ?? 0) !== 1 || strcasecmp((string)($user['correo'] ?? ''), $email) !== 0) {
            throw new RuntimeException('MAIL_SETUP_USER');
        }
        return $user;
    }

    private function autenticar(array $a): bool|array
    {
        if ($a['transport'] === 'smtp') {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            CorreoSmtpTransport::configurar($mail, $a);
            try { return $mail->smtpConnect(); }
            catch (Throwable $e) {
                $code = (int)($mail->getSMTPInstance()->getError()['smtp_code'] ?? 0);
                throw new RuntimeException($code === 535 ? 'MAIL_SETUP_SMTP_AUTH' : 'MAIL_SETUP_SMTP_CONNECTION');
            } finally { $mail->smtpClose(); }
        }
        $c = curl_init('https://api.mail.hostinger.com/api/v1/me'); $body = '';
        curl_setopt_array($c, [CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $a['api_token']],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_WRITEFUNCTION => static function ($c, $part) use (&$body) { if (strlen($body) + strlen($part) > 1048576) { return 0; } $body .= $part; return strlen($part); }]);
        try {
            $ok = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
            if ($ok === false || $code !== 200) { throw new RuntimeException($code === 401 || $code === 403 ? 'MAIL_SETUP_API_AUTH' : 'MAIL_SETUP_API_CONNECTION'); }
            $json = json_decode($body, true);
            $addresses = [];
            foreach (($json['data']['mailboxes'] ?? []) as $box) {
                if (filter_var($box['address'] ?? '', FILTER_VALIDATE_EMAIL) && !empty($box['resourceId'])) { $addresses[] = strtolower($box['address']); }
            }
            if ($addresses) { return ['addresses' => array_values(array_unique($addresses))]; }
            throw new RuntimeException('MAIL_SETUP_API_MAILBOXES');
        } finally { curl_close($c); }
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0');
    try {
        $result = (new CorreoConfiguracionHostinger())->configurar($argv[1] ?? '', [
            'secret' => getenv('IMPE_CORREO_SETUP_SECRET'), 'email' => getenv('IMPE_CORREO_SETUP_EMAIL'),
            'host' => getenv('IMPE_CORREO_SETUP_HOST'), 'port' => getenv('IMPE_CORREO_SETUP_PORT'), 'tls' => getenv('IMPE_CORREO_SETUP_TLS')]);
        echo 'OK: autenticación comprobada y configuración privada guardada: ' . $result['archivo'] . PHP_EOL;
        if ($result['respaldo']) {
            echo 'Restaurar configuración anterior: cp -p -- ' . escapeshellarg($result['respaldo']) . ' ' . escapeshellarg($result['ruta']) . PHP_EOL;
        }
        echo "No se envió ningún mensaje ni se modificó la base de datos.\n";
    } catch (Throwable $e) {
        $code = preg_match('/^MAIL_SETUP_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'MAIL_SETUP_FAILED';
        fwrite(STDERR, 'Sin reemplazar la configuración. Código seguro: ' . $code . PHP_EOL);
        exit(2);
    }
}
