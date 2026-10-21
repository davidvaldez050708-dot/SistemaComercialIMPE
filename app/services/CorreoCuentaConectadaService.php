<?php

require_once dirname(__DIR__, 2) . '/config/private_config.php';
require_once __DIR__ . '/CorreoSmtpTransport.php';
require_once __DIR__ . '/CorreoConexionOAuthService.php';

/** Transporte institucional administrado una vez; no consulta conexiones personales. */
final class CorreoCuentaConectadaService
{
    private $identityLoader;
    private $apiRequest;
    public function __construct(?callable $identityLoader = null) { $this->identityLoader = $identityLoader; }
    public function configurarConsultaApi(callable $request): void { $this->apiRequest = $request; }

    public function central(): array
    {
        $path = impeRutaConfigPrivada('mail_central.local.php');
        if ($path !== null) {
            $a = impeLeerConfigPrivada('mail_central.local.php');
            if (!$a || ($a['activo'] ?? true) !== true) { throw new RuntimeException('MAIL_CENTRAL_CONFIG'); }
        } else {
            $legacy = impeLeerConfigPrivada('marketing_mail.local.php');
            $path = impeRutaConfigPrivada('hostinger_mail_config.php');
            if ($path) { require_once $path; }
            $from = $legacy['hostinger_mail_from_address'] ?? (defined('HOSTINGER_MAIL_FROM_ADDRESS') ? HOSTINGER_MAIL_FROM_ADDRESS : getenv('HOSTINGER_MAIL_FROM_ADDRESS'));
            $token = $legacy['hostinger_mail_api_token'] ?? (defined('HOSTINGER_MAIL_API_TOKEN') ? HOSTINGER_MAIL_API_TOKEN : getenv('HOSTINGER_MAIL_API_TOKEN'));
            if (!empty($token)) {
                $a = ['transport' => 'hostinger_api', 'correo' => $from, 'api_token' => $token];
            } else {
                $a = $legacy + ['transport' => 'smtp', 'correo' => $legacy['smtp_from_address'] ?? $legacy['smtp_username'] ?? ''];
            }
        }
        $a['transport'] = $a['transport'] ?? 'smtp';
        $a['correo'] = strtolower(trim((string)($a['correo'] ?? $a['smtp_from_address'] ?? $a['smtp_username'] ?? '')));
        if (!in_array($a['transport'], ['smtp', 'hostinger_api'], true) ||
            ($a['correo'] !== '' && !filter_var($a['correo'], FILTER_VALIDATE_EMAIL)) ||
            ($a['transport'] === 'smtp' && $a['correo'] === '')) { throw new RuntimeException('MAIL_CENTRAL_CONFIG'); }
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
        $registered = strtolower(trim((string)($identity['correo'] ?? '')));
        $contact = strtolower(trim($contact));
        if ($contact === $registered && preg_match('/@(gmail|googlemail)\.com$/D', $registered)) {
            $account = (new CorreoConexionOAuthService(null, null, $this->identityLoader))->obtener($user, $registered);
            if (!$account) {
                return $this->error('Autoriza tu Gmail con Google para enviar desde esa dirección.', 422) + [
                    'conexion_requerida' => true,
                    'conexion_url' => 'index.php?controller=correoConexion&action=index&correo=' . rawurlencode($registered)
                ];
            }
            $name = trim((string)($identity['nombre'] ?? '')) ?: $registered;
            return ['ok' => true, 'account' => $account, 'identidad' => [
                'remitente_visible' => $registered, 'nombre_visible' => $name, 'reply_to' => $registered,
                'contacto' => $registered, 'reply_to_aplicado' => true, 'contacto_firma' => $registered,
                'usuario_id' => $user, 'transporte' => 'gmail_api', 'aviso' => ''
            ]];
        }
        $a = $this->central();
        $extra = $a['authorized_contacts'][$user] ?? [];
        $trusted = $contact === $registered || in_array($contact, array_map('strtolower', (array)$extra), true);
        $reply = $trusted ? $contact : $registered;
        if (!filter_var($reply, FILTER_VALIDATE_EMAIL)) { $reply = $a['correo']; }
        $aliases = array_map('strtolower', (array)($a['authorized_from'] ?? []));
        $from = $contact === $a['correo'] || ($trusted && in_array($contact, $aliases, true)) ? $contact : $a['correo'];
        if ($a['transport'] === 'hostinger_api') {
            $boxes = $this->buzones($a);
            if ($trusted && in_array($contact, $boxes, true)) { $from = $contact; }
            elseif (in_array($registered, $boxes, true)) { $from = $registered; }
            elseif ($a['correo'] !== '' && in_array($a['correo'], $boxes, true)) { $from = $a['correo']; }
            elseif (count($boxes) === 1) { $from = $boxes[0]; }
            else { throw new RuntimeException('MAIL_CENTRAL_FROM'); }
            if (!filter_var($reply, FILTER_VALIDATE_EMAIL)) { $reply = $from; }
        }
        // La API publicada no admite Reply-To. No inventar un campo ni simularlo.
        // Si se configuró SMTP central, seleccionarlo antes de cualquier envío por API.
        if ($a['transport'] === 'hostinger_api' && $reply !== $from && !empty($a['reply_smtp']) &&
            (strcasecmp((string)($a['reply_smtp']['smtp_username'] ?? ''), $from) === 0 || in_array($from, (array)($a['reply_smtp']['authorized_from'] ?? []), true))) {
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

    private function buzones(array $a): array
    {
        return CorreoEstadoPrivado::ejecutar('central-mailboxes', hash('sha256', $a['api_token']), function ($state, $save) use ($a) {
            if (($state['expires'] ?? 0) > time() && isset($state['addresses'])) { return $state['addresses']; }
            $url = 'https://api.mail.hostinger.com/api/v1/me';
            if ($this->apiRequest) { $r = ($this->apiRequest)('GET', $url, $a['api_token'], null); }
            else {
                $c = curl_init($url); $body = '';
                curl_setopt_array($c, [CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$a['api_token'], 'Accept: application/json'],
                    CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_WRITEFUNCTION=>static function($c,$part)use(&$body){if(strlen($body)+strlen($part)>1048576)return 0;$body.=$part;return strlen($part);}]);
                try { $ok=curl_exec($c); $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); $r=['ok'=>$ok!==false&&$code===200,'json'=>json_decode($body,true)]; }
                finally { curl_close($c); }
            }
            if (empty($r['ok'])) { throw new RuntimeException('MAIL_CENTRAL_API'); }
            $out=[];
            foreach (($r['json']['data']['mailboxes'] ?? $r['json']['mailboxes'] ?? []) as $box) {
                $email=strtolower(trim((string)($box['address']??'')));
                if (filter_var($email,FILTER_VALIDATE_EMAIL) && !empty($box['resourceId']??$box['resource_id']??'')) { $out[]=$email; }
            }
            if (!$out) { throw new RuntimeException('MAIL_CENTRAL_API'); }
            $out=array_values(array_unique($out)); $save(['addresses'=>$out,'expires'=>time()+60]); return $out;
        });
    }

    private function usuario(int $id)
    {
        require_once __DIR__ . '/../models/UsuarioModel.php';
        return (new UsuarioModel())->buscarPorId($id);
    }
    private function error(string $message, int $code): array { return ['ok' => false, 'mensaje' => $message, 'codigo_http' => $code]; }
}
