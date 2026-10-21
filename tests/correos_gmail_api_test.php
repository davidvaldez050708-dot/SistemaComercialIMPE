<?php
// HTTP simulado, MIME real de PHPMailer. No hay peticiones a Google ni envíos SMTP.
require dirname(__DIR__) . '/app/services/CorreoSalidaInstitucionalService.php';
function gmailExpect($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
class GmailNoSmtp extends \PHPMailer\PHPMailer\PHPMailer {
    public function send() { throw new LogicException('No debe ejecutar SMTP ni mail()'); }
}
$root = dirname(__DIR__); $work = sys_get_temp_dir() . '/impe-gmail-api-' . bin2hex(random_bytes(6)); mkdir($work, 0700, true);
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $work); putenv('IMPE_MAIL_STATE_DIR=' . $work . '/state');
$a = ['correo' => 'qa@gmail.com', 'smtp_username' => 'qa@gmail.com', 'activo' => true, 'usuarios' => [7], 'transport' => 'gmail_api',
    'oauth_provider' => 'google', 'auth_method' => 'oauth2', 'client_id' => 'CLIENTE_QA', 'refresh_token' => 'REFRESH_QA',
    'oauth_scope' => 'openid email https://www.googleapis.com/auth/gmail.send'];
file_put_contents($work . '/mail_accounts.local.php', '<?php return ' . var_export(['accounts' => ['qa@gmail.com' => $a]], true) . ';');
CorreoEstadoPrivado::ejecutar('oauth', CorreoOAuthTokenProvider::claveCuenta($a), static function ($s, $save) {
    $save(['access_token' => 'ACCESS_QA', 'refresh_token' => 'REFRESH_QA', 'expires_at' => time() + 3600]);
});
$fixture = $root . '/tmp/gmail-api-' . bin2hex(random_bytes(6)) . '.png';
if (!is_dir(dirname($fixture))) { mkdir(dirname($fixture), 0700, true); }
file_put_contents($fixture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGxkAAAAASUVORK5CYII='));
$calls = []; $code = 200; $owner = 'qa@gmail.com'; $verified = true; $hasId = true;
try {
    $sender = new CorreoSalidaInstitucionalService(static function () { return new GmailNoSmtp(true); }, null,
        static function ($method, $url, $token, $payload) use (&$calls, &$code, &$owner, &$verified, &$hasId) {
            $calls[] = $method;
            gmailExpect($token === 'ACCESS_QA', 'token sólo en backend');
            if ($method === 'GET') {
                gmailExpect($url === 'https://openidconnect.googleapis.com/v1/userinfo', 'identidad HTTPS fijada');
                return ['ok' => true, 'codigo_http' => 200, 'json' => ['email' => $owner, 'email_verified' => $verified]];
            }
            gmailExpect($url === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', 'envío HTTPS fijado');
            $mime = base64_decode(strtr($payload['raw'], '-_', '+/'));
            gmailExpect(str_contains($mime, 'To: externo@institucion.test') && str_contains($mime, 'qa@gmail.com'), 'MIME To y From exactos');
            gmailExpect(str_contains($mime, 'Content-ID: <logo-qa>') && str_contains($mime, 'muestra.txt'), 'imagen CID y adjunto incluidos');
            gmailExpect(str_contains(str_replace(["\r", "\n"], '', $mime), base64_encode(file_get_contents(__FILE__))), 'adjunto completo');
            return ['ok' => $code === 200, 'codigo_http' => $code, 'json' => $hasId ? ['id' => '18ab54_qatest'] : []];
        });
    $data = ['usuario_id' => 7, 'remitente' => 'qa@gmail.com', 'destinatario' => 'externo@institucion.test', 'asunto' => 'Prueba Gmail API',
        'cuerpo' => "Texto íntegro\nSegunda línea", 'adjuntos' => [['ruta' => __FILE__, 'nombre' => 'muestra.txt', 'mime' => 'text/plain']],
        'imagenes_embebidas' => [['ruta' => $fixture, 'cid' => 'logo-qa', 'nombre' => 'logo.png', 'mime' => 'image/png']],
        'html_adicional' => '<img src="cid:logo-qa" alt="Prueba">', 'idempotency_key' => 'gmail-api-0001'];
    $r = $sender->enviar($data);
    gmailExpect($r['ok'] && $r['proveedor'] === 'GMAIL_API' && $r['message_id'] === '18ab54_qatest' && !$r['entrega_confirmada'], 'aceptación con identificador, sin afirmar recepción');
    gmailExpect(!$sender->enviar($data)['ok'] && $calls === ['GET', 'POST'], 'sin duplicado');
    $owner = 'otra@gmail.com'; $data['idempotency_key'] = 'gmail-api-0002'; $before = count($calls);
    gmailExpect($sender->enviar($data)['estado_entrega'] === 'RECHAZADO' && count($calls) === $before + 1, 'otra identidad bloqueada sin POST');
    $owner = 'qa@gmail.com'; $verified = false; $data['idempotency_key'] = 'gmail-api-0003';
    gmailExpect($sender->enviar($data)['estado_entrega'] === 'RECHAZADO', 'dirección no verificada bloqueada');
    $verified = true; $code = 403; $data['idempotency_key'] = 'gmail-api-0004';
    gmailExpect($sender->enviar($data)['estado_entrega'] === 'RECHAZADO', 'rechazo del proveedor');
    $code = 503; $data['idempotency_key'] = 'gmail-api-0005';
    gmailExpect($sender->enviar($data)['estado_entrega'] === 'DESCONOCIDO', 'respuesta incierta');
    $before = count($calls); $code = 200;
    gmailExpect(!$sender->enviar($data)['ok'] && count($calls) === $before, 'sin fallback ni repetición incierta');
    $hasId = false; $data['idempotency_key'] = 'gmail-api-0006';
    gmailExpect($sender->enviar($data)['estado_entrega'] === 'DESCONOCIDO', 'sin identificador no confirmar aceptación');
    echo "OK: Gmail HTTPS simulado con MIME real, identidad verificada, destinatario externo, imagen/adjunto íntegros, aceptación/rechazo/incertidumbre y deduplicación. Sin envío real.\n";
} finally {
    unlink($fixture);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); } rmdir($work);
}
