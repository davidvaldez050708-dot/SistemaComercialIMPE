<?php

require_once dirname(__DIR__, 2) . '/config/private_config.php';
require_once __DIR__ . '/CorreoSmtpTransport.php';
require_once __DIR__ . '/CorreoLimiteService.php';
require_once __DIR__ . '/CorreoGmailApiTransport.php';

/** Autoriza buzones, no inicia sesión en el CRM. Los permisos del CRM no cambian. */
final class CorreoConexionOAuthService
{
    private $request;
    private $probe;

    public function __construct(?callable $request = null, ?callable $probe = null)
    {
        $this->request = $request; $this->probe = $probe;
    }

    public function disponibles(): array
    {
        $out = [];
        foreach (['google', 'microsoft'] as $provider) {
            try { $this->config($provider); $out[$provider] = true; }
            catch (Throwable $e) { $out[$provider] = false; }
        }
        return $out;
    }

    public function iniciar(int $user, string $email, string $provider, array &$session): string
    {
        $this->identidad($user, $email, $session);
        $this->politica($user, $email);
        $a = $this->config($provider);
        if (!CorreoLimiteService::tomar('oauth-start', $user, 10, 600)) { throw new RuntimeException('MAIL_OAUTH_RATE'); }
        $state = bin2hex(random_bytes(32));
        $verifier = self::b64(random_bytes(48));
        $attempts = array_filter($session['mail_oauth_attempts'] ?? [], static function ($v) { return ($v['expires'] ?? 0) > time(); });
        if (count($attempts) >= 5) { throw new RuntimeException('MAIL_OAUTH_RATE'); }
        $attempts[$state] = ['user' => $user, 'email' => $email, 'provider' => $provider,
            'verifier' => $verifier, 'expires' => time() + 600, 'app' => $this->appId($a)];
        $session['mail_oauth_attempts'] = $attempts;
        $params = ['client_id' => $a['client_id'], 'redirect_uri' => $a['redirect_uri'], 'response_type' => 'code',
            'scope' => $this->scope($provider), 'state' => $state, 'login_hint' => $email,
            'code_challenge' => self::b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256'];
        if ($provider === 'google') { $params += ['access_type' => 'offline', 'prompt' => 'consent']; }
        else { $params += ['response_mode' => 'query', 'prompt' => 'select_account']; }
        return $a['authorize_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function completar(int $user, array $query, array &$session): string
    {
        $state = (string)($query['state'] ?? '');
        $attempt = $session['mail_oauth_attempts'][$state] ?? null;
        // Consumir antes de contactar con el proveedor, también en cancelaciones o errores.
        unset($session['mail_oauth_attempts'][$state]);
        if (!preg_match('/^[a-f0-9]{64}$/D', $state) || !is_array($attempt) ||
            (int)$attempt['user'] !== $user || (int)$attempt['expires'] < time()) { throw new RuntimeException('MAIL_OAUTH_STATE'); }
        $email = (string)$attempt['email']; $provider = (string)$attempt['provider'];
        $this->identidad($user, $email, $session); $this->politica($user, $email);
        $a = $this->config($provider);
        if (!hash_equals($attempt['app'], $this->appId($a))) { throw new RuntimeException('MAIL_OAUTH_CONFIG_CHANGED'); }
        if (isset($query['error'])) { throw new RuntimeException('MAIL_OAUTH_DENIED'); }
        $code = (string)($query['code'] ?? '');
        if ($code === '' || strlen($code) > 8192 || preg_match('/[\x00-\x20\x7f]/', $code)) { throw new RuntimeException('MAIL_OAUTH_CODE'); }
        $fields = ['grant_type' => 'authorization_code', 'client_id' => $a['client_id'], 'redirect_uri' => $a['redirect_uri'],
            'code' => $code, 'code_verifier' => $attempt['verifier']];
        if (!empty($a['client_secret'])) { $fields['client_secret'] = $a['client_secret']; }
        if ($provider === 'microsoft') { $fields['scope'] = $this->scope($provider); }
        $r = $this->request ? ($this->request)($a['token_url'], $fields) : $this->token($a['token_url'], $fields);
        $access = $this->validarToken($r['access_token'] ?? null);
        $refresh = $this->validarToken($r['refresh_token'] ?? null);
        $expires = (int)($r['expires_in'] ?? 0);
        if ($expires < 60) { throw new RuntimeException('MAIL_OAUTH_TOKEN'); }
        if (isset($r['scope'])) {
            $scopes = is_string($r['scope']) ? preg_split('/\s+/', trim($r['scope'])) : [];
            $required = $provider === 'google' ? ['https://www.googleapis.com/auth/gmail.send'] : ['https://outlook.office.com/SMTP.Send', 'SMTP.Send'];
            if (!array_intersect($required, $scopes)) { throw new RuntimeException('MAIL_OAUTH_SCOPE'); }
        }
        $account = $this->cuenta($a, $email, $refresh);
        // La identidad se verifica con Google por HTTPS o con SMTP AUTH de Microsoft.
        // No se confía en login_hint ni en un JWT sin verificar.
        $valid = $this->probe ? ($this->probe)($account, $access) : ($provider === 'google'
            ? CorreoGmailApiTransport::identidad($email, $access) : $this->verificarSmtp($account, $access));
        if (!$valid) { throw new RuntimeException('MAIL_OAUTH_MAILBOX'); }
        CorreoEstadoPrivado::ejecutar('oauth', CorreoOAuthTokenProvider::claveCuenta($account), static function ($old, $save) use ($access, $refresh, $expires) {
            $save(['access_token' => $access, 'refresh_token' => $refresh, 'expires_at' => time() + min($expires, 86400)]);
        });
        CorreoEstadoPrivado::ejecutar('mail-connection', $user . ':' . $email, static function ($old, $save) use ($provider, $refresh, $a) {
            $save(['connected' => true, 'provider' => $provider, 'refresh_token' => $refresh, 'app' => $a['client_id'], 'tenant' => $a['tenant'], 'at' => time()]);
        });
        return $email;
    }

    public function obtener(int $user, string $email): ?array
    {
        $r = CorreoEstadoPrivado::leer('mail-connection', $user . ':' . $email);
        if (empty($r['connected'])) { return null; }
        $this->politica($user, $email);
        $a = $this->config((string)$r['provider']);
        if (!hash_equals((string)$r['app'], (string)$a['client_id']) || $r['tenant'] !== $a['tenant']) { throw new RuntimeException('MAIL_OAUTH_CONFIG_CHANGED'); }
        return $this->cuenta($a, $email, (string)$r['refresh_token']);
    }

    public function desconectar(int $user, string $email, array $session): void
    {
        $this->identidad($user, $email, $session);
        $googleScope = $this->scope('google');
        CorreoEstadoPrivado::ejecutar('mail-connection', $user . ':' . $email, static function ($r, $save) use ($email, $googleScope) {
            if (!empty($r['connected'])) {
                $a = ['oauth_provider' => $r['provider'], 'tenant' => $r['tenant'],
                    'smtp_username' => $email, 'client_id' => $r['app'], 'refresh_token' => $r['refresh_token']];
                if ($r['provider'] === 'google') { $a['oauth_scope'] = $googleScope; }
                $key = CorreoOAuthTokenProvider::claveCuenta($a);
                CorreoEstadoPrivado::ejecutar('oauth', $key, static function ($s, $clear) { $clear([]); });
            }
            $save(['connected' => false]);
        });
    }

    private function identidad(int $user, string $email, array $session): void
    {
        if ($user <= 0 || (int)($session['usuario_id'] ?? 0) !== $user || $email !== strtolower(trim($email)) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_OAUTH_IDENTITY');
        }
    }

    private function politica(int $user, string $email): void
    {
        foreach ((impeLeerConfigPrivada('mail_accounts.local.php')['accounts'] ?? []) as $key => $a) {
            if (!is_array($a) || strtolower((string)($a['correo'] ?? $key)) !== $email) { continue; }
            if (empty($a['activo']) || !in_array($user, array_map('intval', $a['usuarios'] ?? []), true)) { throw new RuntimeException('MAIL_OAUTH_FORBIDDEN'); }
            if (($a['transport'] ?? 'smtp') !== 'smtp' || ($a['auth_method'] ?? '') !== 'oauth2' || !empty($a['refresh_token'])) {
                throw new RuntimeException('MAIL_OAUTH_MANAGED');
            }
        }
    }

    private function config(string $provider): array
    {
        if (!in_array($provider, ['google', 'microsoft'], true)) { throw new RuntimeException('MAIL_OAUTH_PROVIDER'); }
        $a = impeLeerConfigPrivada('mail_oauth.local.php')['providers'][$provider] ?? [];
        $uri = (string)($a['redirect_uri'] ?? ''); $parts = parse_url($uri); $q = [];
        parse_str((string)($parts['query'] ?? ''), $q);
        if (empty($a['enabled']) || empty($a['client_id']) || empty($a['client_secret']) || !$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !str_ends_with($parts['path'] ?? '', '/index.php') ||
            count($q) !== 2 || ($q['controller'] ?? '') !== 'correoConexion' || ($q['action'] ?? '') !== 'callback') { throw new RuntimeException('MAIL_OAUTH_CONFIG'); }
        $tenant = (string)($a['tenant'] ?? 'common');
        if (!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $tenant)) { throw new RuntimeException('MAIL_OAUTH_CONFIG'); }
        return array_merge($a, ['oauth_provider' => $provider, 'tenant' => $tenant,
            'authorize_url' => $provider === 'google' ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize',
            'token_url' => $provider === 'google' ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token']);
    }

    private function cuenta(array $a, string $email, string $refresh): array
    {
        $google = $a['oauth_provider'] === 'google';
        $consumer = preg_match('/@(outlook|hotmail|live|msn)\./i', $email);
        $host = $google ? 'smtp.gmail.com' : ($consumer ? 'smtp-mail.outlook.com' : 'smtp.office365.com');
        $account = ['correo' => $email, 'transport' => $google ? 'gmail_api' : 'smtp', 'smtp_username' => $email, 'smtp_host' => $host, 'smtp_port' => 587,
            'smtp_encryption' => 'tls', 'auth_method' => 'oauth2', 'oauth_provider' => $a['oauth_provider'],
            'client_id' => $a['client_id'], 'client_secret' => $a['client_secret'] ?? '', 'tenant' => $a['tenant'], 'refresh_token' => $refresh];
        if ($google) { $account['oauth_scope'] = $this->scope('google'); }
        return $account;
    }

    private function verificarSmtp(array $account, string $token): bool
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        CorreoSmtpTransport::configurar($mail, $account);
        $mail->setOAuth(new class($account['correo'], $token) implements \PHPMailer\PHPMailer\OAuthTokenProvider {
            private $user, $token;
            public function __construct($user, $token) { $this->user = $user; $this->token = $token; }
            public function getOauth64() { return base64_encode('user=' . $this->user . "\001auth=Bearer " . $this->token . "\001\001"); }
        });
        try { return $mail->smtpConnect(); }
        finally { $mail->smtpClose(); }
    }

    private function token(string $url, array $fields): array
    {
        $c = curl_init($url); $body = '';
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($c, $bytes) use (&$body) { if (strlen($body) + strlen($bytes) > 65536) { return 0; } $body .= $bytes; return strlen($bytes); }]);
        try {
            if (curl_exec($c) === false || (int)curl_getinfo($c, CURLINFO_HTTP_CODE) !== 200) { throw new RuntimeException('MAIL_OAUTH_AUTHORIZATION'); }
            $r = json_decode($body, true);
            if (!is_array($r)) { throw new RuntimeException('MAIL_OAUTH_TOKEN'); }
            return $r;
        } finally { curl_close($c); }
    }

    private function validarToken($token): string
    {
        if (!is_string($token) || $token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new RuntimeException('MAIL_OAUTH_TOKEN'); }
        return $token;
    }
    private function appId(array $a): string { return hash('sha256', json_encode([$a['client_id'], $a['redirect_uri'], $a['tenant']])); }
    private function scope(string $p): string { return $p === 'google' ? 'openid email https://www.googleapis.com/auth/gmail.send' : 'https://outlook.office.com/SMTP.Send offline_access'; }
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
}
