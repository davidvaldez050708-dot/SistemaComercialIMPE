<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/CorreoEstadoPrivado.php';

/** XOAUTH2 de PHPMailer, usando una autorización previa administrada privadamente. */
final class CorreoOAuthTokenProvider implements \PHPMailer\PHPMailer\OAuthTokenProvider
{
    private $account;
    private $request;
    public function __construct(array $account, ?callable $request = null) { $this->account = $account; $this->request = $request; }

    public static function claveCuenta(array $a): string
    {
        $parts = [$a['oauth_provider'], (string)($a['tenant'] ?? 'common'), trim((string)$a['smtp_username']), $a['client_id'], $a['refresh_token']];
        if (!empty($a['oauth_scope'])) { $parts[] = $a['oauth_scope']; }
        return hash('sha256', json_encode($parts));
    }

    public function getOauth64()
    {
        return base64_encode('user=' . trim((string)($this->account['smtp_username'] ?? '')) . "\001auth=Bearer " . $this->obtenerAccessToken() . "\001\001");
    }

    public function obtenerAccessToken(): string
    {
        $a = $this->account;
        $provider = $a['oauth_provider'] ?? '';
        $user = trim((string)($a['smtp_username'] ?? ''));
        if (!filter_var($user, FILTER_VALIDATE_EMAIL) || !in_array($provider, ['google', 'microsoft'], true) || empty($a['client_id']) || empty($a['refresh_token'])) {
            throw new RuntimeException('MAIL_OAUTH_CONFIG');
        }
        $tenant = (string)($a['tenant'] ?? 'common');
        if (!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $tenant)) { throw new RuntimeException('MAIL_OAUTH_TENANT'); }
        $url = $provider === 'google' ? 'https://oauth2.googleapis.com/token'
            : 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
        $key = self::claveCuenta($a);
        $token = CorreoEstadoPrivado::ejecutar('oauth', $key, function ($state, $save) use ($a, $provider, $url) {
            if (!empty($state['access_token']) && ($state['expires_at'] ?? 0) > time() + 60) { return $state['access_token']; }
            $fields = ['grant_type' => 'refresh_token', 'client_id' => $a['client_id'],
                'refresh_token' => $state['refresh_token'] ?? $a['refresh_token']];
            if (!empty($a['client_secret'])) { $fields['client_secret'] = $a['client_secret']; }
            if ($provider === 'microsoft') { $fields['scope'] = 'https://outlook.office.com/SMTP.Send offline_access'; }
            $response = $this->request ? ($this->request)($url, $fields) : $this->solicitar($url, $fields);
            if (!is_array($response) || empty($response['access_token']) || (int)($response['expires_in'] ?? 0) < 60) {
                throw new RuntimeException('MAIL_OAUTH_AUTHORIZATION');
            }
            $token = (string)$response['access_token'];
            if (strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new RuntimeException('MAIL_OAUTH_TOKEN'); }
            $save(['access_token' => $token, 'expires_at' => time() + min((int)$response['expires_in'], 86400),
                'refresh_token' => $response['refresh_token'] ?? $fields['refresh_token']]);
            return $token;
        });
        return $token;
    }

    private function solicitar(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('MAIL_OAUTH_CURL'); }
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($c, $bytes) use (&$body) {
                if (strlen($body) + strlen($bytes) > 65536) { return 0; }
                $body .= $bytes; return strlen($bytes);
            }]);
        try {
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($ok === false || $status !== 200) { throw new RuntimeException('MAIL_OAUTH_AUTHORIZATION'); }
            $result = json_decode($body, true);
            return is_array($result) ? $result : [];
        } finally { curl_close($curl); }
    }
}
