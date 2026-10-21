<?php

require_once dirname(__DIR__, 2) . '/config/private_config.php';

final class CorreoCuentaConectadaService
{
    private $identityLoader;
    public function __construct(?callable $identityLoader = null) { $this->identityLoader = $identityLoader; }

    public function resolver(string $from, int $user, bool $marketing = false): array
    {
        if ($user <= 0 || (isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] !== $user)) {
            return $this->error('No tienes autorización para usar esta cuenta de envío.', 403);
        }
        $config = impeLeerConfigPrivada('mail_accounts.local.php');
        foreach (($config['accounts'] ?? []) as $key => $account) {
            if (!is_array($account)) { continue; }
            $address = strtolower(trim((string)($account['correo'] ?? (is_string($key) ? $key : ''))));
            if ($address !== $from) { continue; }
            if (empty($account['activo']) || !in_array($user, array_map('intval', $account['usuarios'] ?? []), true)) {
                return $this->error('Esta cuenta de envío no está conectada para tu usuario. Solicita su autorización al administrador.', 403);
            }
            $account['correo'] = $address;
            $transport = $account['transport'] ?? 'smtp';
            if (!in_array($transport, ['smtp', 'hostinger_api'], true)) { return $this->error('La cuenta tiene un transporte sin configurar.', 422); }
            if ($transport === 'smtp' && strcasecmp((string)($account['smtp_username'] ?? ''), $address) !== 0 &&
                !in_array($address, array_map('strtolower', $account['authorized_from'] ?? []), true)) {
                return $this->error('Falta la autorización de envío para el alias de esta cuenta.', 422);
            }
            if ($transport === 'hostinger_api') { $account = $this->hostinger($account); }
            return ['ok' => true, 'account' => $account + ['transport' => $transport]];
        }
        // Compatibilidad con la cuenta administrativa de Marketing, sin usar Gmail de recuperación.
        if ($marketing) {
            $legacy = impeLeerConfigPrivada('marketing_mail.local.php');
            if (strcasecmp($from, (string)($legacy['smtp_from_address'] ?? '')) === 0 && !empty($legacy['smtp_username'])) {
                return ['ok' => true, 'account' => $legacy + ['correo' => $from, 'transport' => 'smtp']];
            }
        }
        // La API existente sigue disponible para el buzón que pertenece al usuario real del CRM.
        $identity = $this->identityLoader ? ($this->identityLoader)($user) : $this->usuario($user);
        if ($identity && (int)($identity['estado'] ?? 1) === 1 && strcasecmp((string)($identity['correo'] ?? ''), $from) === 0) {
            $legacy = $this->hostinger(['correo' => $from, 'transport' => 'hostinger_api'], $marketing);
            if (!empty($legacy['api_token'])) { return ['ok' => true, 'account' => $legacy]; }
        }
        return $this->error('Esta cuenta no está conectada. Gmail necesita OAuth o una clave de aplicación autorizada; Microsoft necesita OAuth; Hostinger requiere su buzón autorizado por API. Solicita conectar esta dirección para tu usuario.', 422);
    }

    private function usuario(int $id)
    {
        require_once __DIR__ . '/../models/UsuarioModel.php';
        return (new UsuarioModel())->buscarPorId($id);
    }

    private function hostinger(array $a, bool $useMarketing = false): array
    {
        $path = impeRutaConfigPrivada('hostinger_mail_config.php');
        if ($path) { require_once $path; }
        $marketing = $useMarketing ? impeLeerConfigPrivada('marketing_mail.local.php') : [];
        $a['api_token'] = $a['api_token'] ?? ($marketing['hostinger_mail_api_token'] ?? (defined('HOSTINGER_MAIL_API_TOKEN') ? HOSTINGER_MAIL_API_TOKEN : getenv('HOSTINGER_MAIL_API_TOKEN')));
        $a['api_base_url'] = $a['api_base_url'] ?? 'https://api.mail.hostinger.com';
        return $a;
    }

    private function error(string $message, int $code): array { return ['ok' => false, 'mensaje' => $message, 'codigo_http' => $code]; }
}
