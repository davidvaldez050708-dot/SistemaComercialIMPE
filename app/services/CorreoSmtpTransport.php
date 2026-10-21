<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/CorreoOAuthTokenProvider.php';

class CorreoSmtpSession extends \PHPMailer\PHPMailer\SMTP
{
    public $dataStarted = false;
    public function data($msg_data) { $this->dataStarted = true; return parent::data($msg_data); }
}

final class CorreoSmtpTransport
{
    public static function configurar(\PHPMailer\PHPMailer\PHPMailer $mail, array $a): void
    {
        $host = trim((string)($a['smtp_host'] ?? ''));
        $port = (int)($a['smtp_port'] ?? 587);
        $tls = strtolower((string)($a['smtp_encryption'] ?? 'tls'));
        $auth = strtolower((string)($a['auth_method'] ?? 'password'));
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535 ||
            !in_array($tls, ['ssl', 'tls'], true) || empty($a['smtp_username']) || !in_array($auth, ['password', 'oauth2'], true) ||
            ($auth === 'password' && empty($a['smtp_password']))) { throw new RuntimeException('MAIL_SMTP_CONFIG'); }
        $mail->isSMTP(); $mail->Host = $host; $mail->Port = $port; $mail->SMTPAuth = true;
        $mail->Username = (string)$a['smtp_username']; $mail->CharSet = 'UTF-8';
        $mail->SMTPSecure = $tls === 'ssl' ? 'ssl' : 'tls'; $mail->SMTPAutoTLS = true;
        $mail->Timeout = 15; $mail->SMTPDebug = 0;
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
        if (!empty($a['smtp_ca_file'])) {
            if (!is_file($a['smtp_ca_file'])) { throw new RuntimeException('MAIL_SMTP_CA'); }
            $ssl['cafile'] = $a['smtp_ca_file'];
        }
        $mail->SMTPOptions = ['ssl' => $ssl];
        if (!($mail->getSMTPInstance() instanceof CorreoSmtpSession)) { $mail->setSMTPInstance(new CorreoSmtpSession()); }
        $mail->getSMTPInstance()->Timelimit = 30;
        if ($auth === 'oauth2') {
            $mail->AuthType = 'XOAUTH2'; $mail->setOAuth(new CorreoOAuthTokenProvider($a));
        } else { $mail->Password = (string)$a['smtp_password']; }
    }

    public static function enviar(\PHPMailer\PHPMailer\PHPMailer $mail, string $purpose): array
    {
        try {
            if (!$mail->send()) { throw new RuntimeException('MAIL_SMTP_SEND'); }
            error_log('MAIL_TRANSPORT ' . json_encode(['purpose' => $purpose, 'transport' => 'SMTP', 'status' => 'ACEPTADO']));
            return ['ok' => true, 'proveedor' => 'SMTP', 'estado_entrega' => 'ACEPTADO',
                'entrega_confirmada' => false, 'message_id' => $mail->getLastMessageID()];
        } catch (Throwable $error) {
            $smtp = $mail->getSMTPInstance(); $detail = $smtp->getError();
            $code = (int)($detail['smtp_code'] ?? 0);
            $unknown = $smtp instanceof CorreoSmtpSession && $smtp->dataStarted && ($code < 400 || $code > 599);
            $reason = preg_match('/^MAIL_[A-Z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'MAIL_SMTP_SEND';
            error_log('MAIL_TRANSPORT ' . json_encode(['purpose' => $purpose, 'transport' => 'SMTP', 'status' => $unknown ? 'DESCONOCIDO' : 'RECHAZADO',
                'smtp_code' => $code, 'reason' => $reason, 'exception' => get_class($error)]));
            return ['ok' => false, 'codigo_http' => 502, 'estado_entrega' => $unknown ? 'DESCONOCIDO' : 'RECHAZADO',
                'entrega_confirmada' => false, 'mensaje' => $unknown
                    ? 'El resultado del envío es desconocido. Comprueba el proveedor antes de volver a enviar para evitar duplicados.'
                    : 'El proveedor no aceptó el correo. Revisa la conexión, la autenticación y los permisos de envío de la cuenta.',
                'mensaje_tecnico' => $reason];
        }
    }
}
