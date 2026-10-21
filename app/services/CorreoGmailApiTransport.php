<?php

require_once __DIR__ . '/CorreoOAuthTokenProvider.php';

/** Envío Gmail mediante MIME/HTTPS, sin una conexión SMTP ni sustitución de remitente. */
final class CorreoGmailApiTransport
{
    public static function identidad(string $email, string $token, ?callable $request = null): bool
    {
        $r = self::solicitar('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $token, null, $request);
        return !empty($r['ok']) && ($r['json']['email_verified'] ?? false) === true &&
            strcasecmp((string)($r['json']['email'] ?? ''), $email) === 0;
    }

    public static function enviar(\PHPMailer\PHPMailer\PHPMailer $mail, array $account, ?callable $request = null): array
    {
        $posting = false;
        try {
            if (($account['oauth_provider'] ?? '') !== 'google' || strcasecmp($mail->From, (string)($account['correo'] ?? '')) !== 0) {
                throw new RuntimeException('MAIL_GMAIL_IDENTITY');
            }
            $token = (new CorreoOAuthTokenProvider($account))->obtenerAccessToken();
            if (!self::identidad($mail->From, $token, $request)) { throw new RuntimeException('MAIL_GMAIL_IDENTITY'); }
            // preSend genera MIME con To/Cc; nunca se llama a send()/postSend().
            $mail->isSMTP();
            if (!$mail->preSend()) { throw new RuntimeException('MAIL_GMAIL_MIME'); }
            $raw = rtrim(strtr(base64_encode($mail->getSentMIMEMessage()), '+/', '-_'), '=');
            $posting = true;
            $r = self::solicitar('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $token, ['raw' => $raw], $request);
            $code = (int)($r['codigo_http'] ?? 0); $id = $r['json']['id'] ?? null;
            if ($code === 200 && is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,256}$/D', $id)) {
                error_log('MAIL_TRANSPORT transport=GMAIL_API status=ACEPTADO');
                return ['ok' => true, 'proveedor' => 'GMAIL_API', 'estado_entrega' => 'ACEPTADO', 'entrega_confirmada' => false, 'message_id' => $id];
            }
            $unknown = !empty($r['incierto']) || $code < 400 || $code >= 500 || $code === 408;
            return self::error($unknown, $code);
        } catch (Throwable $e) {
            error_log('MAIL_TRANSPORT transport=GMAIL_API phase=' . ($posting ? 'post' : 'preparation') . ' exception=' . get_class($e));
            return self::error($posting, 0);
        }
    }

    private static function error(bool $unknown, int $code): array
    {
        error_log('MAIL_TRANSPORT transport=GMAIL_API status=' . ($unknown ? 'DESCONOCIDO' : 'RECHAZADO') . ' http=' . $code);
        return ['ok' => false, 'proveedor' => 'GMAIL_API', 'estado_entrega' => $unknown ? 'DESCONOCIDO' : 'RECHAZADO',
            'entrega_confirmada' => false, 'codigo_http' => 502, 'mensaje' => $unknown
                ? 'Google no confirmó el resultado. Revisa el buzón y el historial antes de repetir el envío.'
                : 'Google no aceptó el correo con esta identidad. Revisa la autorización y los permisos de envío de la cuenta.'];
    }

    private static function solicitar(string $method, string $url, string $token, ?array $payload, ?callable $request): array
    {
        if ($request) { return $request($method, $url, $token, $payload); }
        $c = curl_init($url); $body = '';
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($c, $bytes) use (&$body) { if (strlen($body) + strlen($bytes) > 65536) { return 0; } $body .= $bytes; return strlen($bytes); }]);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
        try {
            $ok = curl_exec($c); $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
            return ['ok' => $ok !== false && $status >= 200 && $status < 300, 'codigo_http' => $status,
                'incierto' => $ok === false, 'json' => json_decode($body, true) ?: []];
        } finally { curl_close($c); }
    }
}
