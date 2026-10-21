<?php
// Sólo lo invoca correos_smtp_tls_test.py contra su servidor local efímero.
if (PHP_SAPI !== 'cli' || $argc !== 3) { exit(2); }
define('ROOT_PATH', dirname(__DIR__)); define('BASE_URL', 'https://crm.example.test/');
require ROOT_PATH . '/app/services/MailService.php';
require ROOT_PATH . '/app/services/CorreoSalidaInstitucionalService.php';
$dir = $argv[1]; $port = (int)$argv[2];
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $dir); putenv('IMPE_MAIL_STATE_DIR=' . $dir . '/state');
$a = ['correo' => 'qa@example.test', 'activo' => true, 'usuarios' => [7], 'transport' => 'smtp',
    'smtp_host' => 'localhost', 'smtp_port' => $port, 'smtp_encryption' => 'tls',
    'smtp_username' => 'qa@example.test', 'smtp_password' => 'FICTICIO', 'smtp_ca_file' => $dir . '/cert.pem'];
file_put_contents($dir . '/password_reset_mail.local.php', '<?php return ' . var_export($a, true) . ';');
file_put_contents($dir . '/mail_accounts.local.php', '<?php return ' . var_export(['accounts' => ['qa@example.test' => $a]], true) . ';');
// Un certificado no confiable debe impedir AUTH y DATA.
$bad = $a; unset($bad['smtp_ca_file']);
$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
CorreoSmtpTransport::configurar($mail, $bad); $mail->setFrom($a['correo']); $mail->addAddress('qa@externo.test');
$mail->Subject = 'TLS negativo'; $mail->Body = 'No debe salir';
if (CorreoSmtpTransport::enviar($mail, 'tls_negative')['ok']) { throw new RuntimeException('TLS no verificado'); }
if (!(new MailService())->enviarPasswordTemporal('recuperacion@externo.test', 'Persona ficticia', 'QA_NO_VALIDA')) {
    throw new RuntimeException('SMTP recuperación local');
}
$sender = new CorreoSalidaInstitucionalService();
$data = ['usuario_id' => 7, 'remitente' => $a['correo'], 'destinatario' => 'comercial@educacion.test',
    'asunto' => 'Prueba TLS con acentos', 'cuerpo' => "Contenido íntegro\nSegunda línea",
    'adjuntos' => [['ruta' => __FILE__, 'nombre' => 'muestra.txt', 'mime' => 'text/plain']],
    'idempotency_key' => 'tls-local-0001'];
$result = $sender->enviar($data);
if (!$result['ok'] || $result['entrega_confirmada']) { throw new RuntimeException('SMTP comercial local'); }
$oauth = $a + ['auth_method' => 'oauth2', 'oauth_provider' => 'google', 'client_id' => 'CLIENTE_LOCAL_QA', 'refresh_token' => 'REFRESH_LOCAL_QA'];
CorreoEstadoPrivado::ejecutar('oauth', CorreoOAuthTokenProvider::claveCuenta($oauth), static function ($old, $save) {
    $save(['access_token' => 'ACCESS_LOCAL_QA', 'refresh_token' => 'REFRESH_LOCAL_QA', 'expires_at' => time() + 3600]);
});
$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
CorreoSmtpTransport::configurar($mail, $oauth); $mail->setFrom($a['correo']); $mail->addAddress('oauth@universidad.test');
$mail->Subject = 'OAuth SMTP local'; $mail->Body = 'Prueba ficticia XOAUTH2';
if (!CorreoSmtpTransport::enviar($mail, 'oauth_local')['ok']) { throw new RuntimeException('XOAUTH2 local'); }
echo "OK: PHPMailer real, STARTTLS verificado, AUTH y DATA contra servidor local.\n";
