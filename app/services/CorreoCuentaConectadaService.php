<?php

require_once dirname(__DIR__, 2) . '/config/private_config.php';
require_once __DIR__ . '/CorreoSmtpTransport.php';

/** Transporte institucional administrado una vez; no consulta conexiones personales. */
final class CorreoCuentaConectadaService
{
    private $identityLoader;
    public function __construct(?callable $identityLoader = null) { $this->identityLoader = $identityLoader; }

    public function central(): array
    {
        $path = impeRutaConfigPrivada('mail_central.local.php');
        if ($path !== null) {
            $a = impeLeerConfigPrivada('mail_central.local.php');
            if (!$a || ($a['activo'] ?? true) !== true) { throw new RuntimeException('MAIL_CENTRAL_CONFIG'); }
        } else {
            $legacy = impeLeerConfigPrivada('marketing_mail.local.php');
            if (!empty($legacy['smtp_username']) && !empty($legacy['smtp_host'])) {
                $a = $legacy + ['transport' => 'smtp', 'correo' => $legacy['smtp_from_address'] ?? $legacy['smtp_username']];
            } else {
                $path = impeRutaConfigPrivada('hostinger_mail_config.php');
                if ($path) { require_once $path; }
                $from = $legacy['hostinger_mail_from_address'] ?? (defined('HOSTINGER_MAIL_FROM_ADDRESS') ? HOSTINGER_MAIL_FROM_ADDRESS : getenv('HOSTINGER_MAIL_FROM_ADDRESS'));
                $token = $legacy['hostinger_mail_api_token'] ?? (defined('HOSTINGER_MAIL_API_TOKEN') ? HOSTINGER_MAIL_API_TOKEN : getenv('HOSTINGER_MAIL_API_TOKEN'));
                $a = ['transport' => 'hostinger_api', 'correo' => $from, 'api_token' => $token];
            }
        }
        $a['transport'] = $a['transport'] ?? 'smtp';
        $a['correo'] = strtolower(trim((string)($a['correo'] ?? $a['smtp_from_address'] ?? $a['smtp_username'] ?? '')));
        if (!filter_var($a['correo'], FILTER_VALIDATE_EMAIL) || !in_array($a['transport'], ['smtp', 'hostinger_api'], true)) { throw new RuntimeException('MAIL_CENTRAL_CONFIG'); }
        if ($a['transport'] === 'hostinger_api') {
            $path = impeRutaConfigPrivada('hostinger_mail_config.php');
            if ($path) { require_once $path; }
            $a['api_token'] = $a['api_token'] ?? (defined('HOSTINGER_MAIL_API_TOKEN') ? HOSTINGER_MAIL_API_TOKEN : getenv('HOSTINGER_MAIL_API_TOKEN'));
            $a['api_base_url'] = $a['api_base_url'] ?? 'https://api.mail.hostinger.com';
            if (empty($a['api_token']) || $a['api_base_url'] !== 'https://api.mail.hostinger.com') { throw new RuntimeException('MAIL_CENTRAL_CONFIG'); }
        }
        $a['nombre'] = trim((string)($a['nombre'] ?? $a['smtp_from_name'] ?? 'Fundación Red Educativa México'));
        if ($a['transport'] === 'smtp') { CorreoSmtpTransport::configurar(new \PHPMailer\PHPMailer\PHPMailer(true), $a); }
        return $a;
    }

    public function resolver(string $contact, int $user, bool $marketing = false): array
    {
        if ($user <= 0 || (isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] !== $user)) { return $this->error('La sesión no está autorizada para enviar.', 403); }
        $identity = $this->identityLoader ? ($this->identityLoader)($user) : $this->usuario($user);
        if (!$identity || (int)($identity['estado'] ?? 0) !== 1) { return $this->error('No fue posible identificar una cuenta activa para enviar.', 403); }
        $a = $this->central();
        $registered = strtolower(trim((string)($identity['correo'] ?? '')));
        $contact = strtolower(trim($contact));
        $extra = $a['authorized_contacts'][$user] ?? [];
        $trusted = $contact === $registered || in_array($contact, array_map('strtolower', (array)$extra), true);
        $reply = $trusted ? $contact : $registered;
        if (!filter_var($reply, FILTER_VALIDATE_EMAIL)) { $reply = $a['correo']; }
        $aliases = array_map('strtolower', (array)($a['authorized_from'] ?? []));
        $from = $contact === $a['correo'] || ($trusted && in_array($contact, $aliases, true)) ? $contact : $a['correo'];
        // La API publicada no admite Reply-To. No inventar un campo ni simularlo.
        // Si se configuró SMTP central, seleccionarlo antes de cualquier envío por API.
        if ($a['transport'] === 'hostinger_api' && $reply !== $from && !empty($a['reply_smtp'])) {
            $a = array_replace($a, $a['reply_smtp'], ['transport' => 'smtp']);
            CorreoSmtpTransport::configurar(new \PHPMailer\PHPMailer\PHPMailer(true), $a);
        }
        $applied = $a['transport'] === 'smtp';
        $a['correo'] = $from;
        return ['ok' => true, 'account' => $a, 'identidad' => [
            'remitente_visible' => $from, 'nombre_visible' => $a['nombre'],
            'reply_to' => $applied ? $reply : $from, 'contacto' => $contact, 'reply_to_aplicado' => $applied,
            'contacto_firma' => $trusted ? $contact : $registered,
            'usuario_id' => $user, 'transporte' => $a['transport'],
            'aviso' => !$applied && $reply !== $from ? 'Las respuestas se recibirán únicamente en la cuenta institucional.' : ''
        ]];
    }

    private function usuario(int $id)
    {
        require_once __DIR__ . '/../models/UsuarioModel.php';
        return (new UsuarioModel())->buscarPorId($id);
    }
    private function error(string $message, int $code): array { return ['ok' => false, 'mensaje' => $message, 'codigo_http' => $code]; }
}
