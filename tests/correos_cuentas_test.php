<?php
// Transporte simulado: no conexiones a proveedores ni base de datos.
require dirname(__DIR__) . '/app/services/CorreoSalidaInstitucionalService.php';
function checkMail($condition, $label) { if (!$condition) { throw new RuntimeException($label); } }
class CuentaMailFake extends \PHPMailer\PHPMailer\PHPMailer {
    public $calls = 0;
    public function send() { $this->calls++; return true; }
}
$work = sys_get_temp_dir() . '/impe-cuentas-' . bin2hex(random_bytes(6)); mkdir($work, 0700, true);
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $work); putenv('IMPE_MAIL_STATE_DIR=' . $work . '/state');
$account = ['activo' => true, 'usuarios' => [7], 'transport' => 'smtp', 'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'prueba@gmail.com', 'smtp_password' => 'CLAVE_FICTICIA'];
$accounts = ['prueba@gmail.com' => $account,
    'prueba@institucion.test' => array_replace($account, ['smtp_host' => 'smtp.institucion.test', 'smtp_username' => 'prueba@institucion.test']),
    'prueba@outlook.com' => array_replace($account, ['smtp_host' => 'smtp.office365.com', 'smtp_username' => 'prueba@outlook.com',
        'auth_method' => 'oauth2', 'oauth_provider' => 'microsoft', 'client_id' => 'CLIENTE_QA', 'refresh_token' => 'REFRESH_QA']),
    'prueba@hostinger.test' => ['activo' => true, 'usuarios' => [7], 'transport' => 'hostinger_api', 'api_token' => 'TOKEN_QA']];
file_put_contents($work . '/mail_accounts.local.php', '<?php return ' . var_export(['accounts' => $accounts], true) . ';');
$fixture = __FILE__;
try {
    $resolver = new CorreoCuentaConectadaService(static function ($id) { return ['correo' => 'registrado@hostinger.test', 'estado' => 1]; });
    $mail = new CuentaMailFake(true);
    $apiCalls = [];
    $apiStatus = 200;
    $sender = new CorreoSalidaInstitucionalService(static function () use ($mail) { return $mail; }, $resolver,
        static function ($method, $url, $token, $payload) use (&$apiCalls, &$apiStatus) {
            $apiCalls[] = $method;
            if ($method === 'GET') { return ['ok' => true, 'json' => ['mailboxes' => [
                ['address' => 'otro@hostinger.test', 'resource_id' => 'NO'], ['address' => 'prueba@hostinger.test', 'resource_id' => 'SI']]]]; }
            checkMail(str_ends_with($url, '/SI/send') && $token === 'TOKEN_QA', 'buzón exacto');
            checkMail(base64_decode($payload['attachments'][0]['content']) === file_get_contents(__FILE__), 'adjunto API completo');
            return ['ok' => $apiStatus === 200, 'codigo_http' => $apiStatus];
        });
    $data = ['usuario_id' => 7, 'remitente' => 'prueba@gmail.com', 'destinatario' => 'externo@universidad.test',
        'asunto' => 'Prueba ficticia', 'cuerpo' => 'Contenido completo',
        'adjuntos' => [['ruta' => $fixture, 'nombre' => 'prueba.txt', 'mime' => 'text/plain']], 'idempotency_key' => 'prueba-0001'];
    $result = $sender->enviar($data);
    checkMail($result['ok'] && $result['estado_entrega'] === 'ACEPTADO' && !$result['entrega_confirmada'], 'aceptación sin afirmar entrega');
    checkMail($mail->From === 'prueba@gmail.com' && $mail->Username === $mail->From && count($mail->getAttachments()) === 1, 'identidad y adjunto SMTP');
    $result = $sender->enviar($data);
    checkMail(!$result['ok'] && $result['codigo_http'] === 409 && $mail->calls === 1, 'sin envío duplicado');
    $data['idempotency_key'] = 'prueba-0002'; $data['usuario_id'] = 8;
    checkMail($sender->enviar($data)['codigo_http'] === 403 && $mail->calls === 1, 'cuenta ajena bloqueada');
    $data['usuario_id'] = 7; $data['remitente'] = 'sinconexion@gmail.com';
    checkMail(!$sender->enviar($data)['ok'] && $mail->calls === 1, 'Gmail no conectado no suplanta');
    foreach (['prueba@institucion.test', 'prueba@outlook.com'] as $from) {
        $data['remitente'] = $from;
        $data['idempotency_key'] = 'prueba-' . hash('sha256', $from);
        checkMail($sender->enviar($data)['ok'] && $mail->From === $from, 'selección institucional/Microsoft');
    }
    checkMail($mail->AuthType === 'XOAUTH2', 'Microsoft usa XOAUTH2');
    $data['remitente'] = 'prueba@hostinger.test';
    $data['idempotency_key'] = 'prueba-hostinger-0001';
    checkMail($sender->enviar($data)['ok'] && $apiCalls === ['GET', 'POST'], 'Hostinger API');
    $apiStatus = 401; $data['idempotency_key'] = 'prueba-0003';
    checkMail($sender->enviar($data)['estado_entrega'] === 'RECHAZADO', 'API rechazo explícito');
    $apiStatus = 503; $data['idempotency_key'] = 'prueba-0004';
    checkMail($sender->enviar($data)['estado_entrega'] === 'DESCONOCIDO', 'API incertidumbre');
    $before = count($apiCalls); $apiStatus = 200;
    checkMail(!$sender->enviar($data)['ok'] && count($apiCalls) === $before && $mail->calls === 3, 'sin fallback ni reintento incierto');
    $_SESSION['usuario_id'] = 8;
    checkMail($sender->enviar($data)['codigo_http'] === 403, 'identidad de sesión'); unset($_SESSION['usuario_id']);
    echo "OK: cuentas autorizadas, Gmail/Microsoft/institucional SMTP simulado, Hostinger API simulada, adjuntos, identidad, rechazos e idempotencia. Sin envíos reales.\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); } rmdir($work);
}
