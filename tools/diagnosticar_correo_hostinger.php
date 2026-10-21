<?php
// Sólo SSH/CLI. No muestra credenciales ni permite enviar desde una petición web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/services/CorreoSalidaInstitucionalService.php';
require dirname(__DIR__) . '/config/mail_config.php';
$options = getopt('', ['recovery', 'account:', 'user:', 'probe', 'send-test', 'to:', 'domain:', 'dkim:']);
function flagMail($label, $ok) { echo $label . ': ' . ($ok ? 'OK' : 'FALTA/REVISAR') . PHP_EOL; }
try {
    echo 'PHP ' . PHP_VERSION . ' SAPI ' . PHP_SAPI . PHP_EOL;
    foreach (['openssl', 'curl', 'mbstring', 'mysqli'] as $ext) { flagMail('extension ' . $ext, extension_loaded($ext)); }
    flagMail('PHPMailer', class_exists('PHPMailer\\PHPMailer\\PHPMailer'));
    foreach ((new CorreoConexionOAuthService())->disponibles() as $provider => $ready) {
        echo 'OAuth ' . $provider . ' (opcional): ' . ($ready ? 'aplicación configurada' : 'desactivado; falta aplicación autorizada') . PHP_EOL;
    }
    if (isset($options['account'])) {
        $from = strtolower(trim($options['account'])); $user = (int)($options['user'] ?? 0);
        $result = (new CorreoCuentaConectadaService())->resolver($from, $user, true);
        if (empty($result['ok'])) { echo 'Cuenta: ' . $result['mensaje'] . PHP_EOL; exit(2); }
        $a = $result['account'];
    } else {
        $a = impeLeerConfigPrivada('password_reset_mail.local.php') ?: ['correo' => MAIL_FROM_ADDRESS,
            'smtp_host' => MAIL_HOST, 'smtp_port' => MAIL_PORT, 'smtp_encryption' => MAIL_ENCRYPTION,
            'smtp_username' => MAIL_USERNAME, 'smtp_password' => MAIL_PASSWORD];
        $from = (string)($a['correo'] ?? $a['smtp_username'] ?? '');
        $user = 0;
        echo "Cuenta dedicada de recuperación (no modifica usuarios ni contraseñas).\n";
    }
    $transport = $a['transport'] ?? 'smtp';
    echo 'Transporte: ' . $transport . PHP_EOL;
    flagMail('Identidad configurada', filter_var($from, FILTER_VALIDATE_EMAIL) !== false);
    if ($transport === 'smtp') {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        CorreoSmtpTransport::configurar($mail, $a);
        echo 'SMTP ' . $mail->Host . ':' . $mail->Port . ' ' . $mail->SMTPSecure . PHP_EOL;
        flagMail('TLS y certificados verificados', $mail->SMTPOptions['ssl']['verify_peer'] && $mail->SMTPOptions['ssl']['verify_peer_name']);
        flagMail('DNS SMTP', filter_var($mail->Host, FILTER_VALIDATE_IP) || gethostbyname($mail->Host) !== $mail->Host);
        if (isset($options['probe'])) {
            // EHLO/TLS/AUTH, sin MAIL/RCPT/DATA. OAuth puede renovar su token privado.
            $connected = $mail->smtpConnect(); flagMail('Conexión TLS y autenticación', $connected); $mail->smtpClose();
            if (!$connected) { exit(2); }
        }
    } elseif ($transport === 'gmail_api') {
        flagMail('OAuth Gmail configurado', !empty($a['refresh_token']) && !empty($a['client_id']));
        if (isset($options['probe'])) {
            $token = (new CorreoOAuthTokenProvider($a))->obtenerAccessToken();
            $allowed = CorreoGmailApiTransport::identidad($from, $token);
            flagMail('Identidad Google verificada por HTTPS', $allowed);
            if (!$allowed) { exit(2); }
        }
    } elseif ($transport === 'hostinger_api') {
        flagMail('Token API configurado', !empty($a['api_token']));
        if (isset($options['probe'])) {
            if (($a['api_base_url'] ?? '') !== 'https://api.mail.hostinger.com') { throw new RuntimeException('MAIL_API_BASE'); }
            $c = curl_init('https://api.mail.hostinger.com/api/v1/me');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $a['api_token']],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25]);
            $body = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
            $json = json_decode((string)$body, true); $allowed = false;
            foreach (($json['data']['mailboxes'] ?? []) as $box) { if (strcasecmp((string)($box['address'] ?? ''), $from) === 0) { $allowed = true; } }
            echo 'HTTP consulta API: ' . $code . PHP_EOL; flagMail('Buzón autorizado', $code === 200 && $allowed);
            if ($code !== 200 || !$allowed) { exit(2); }
        }
    }
    if (isset($options['domain'])) {
        $domain = $options['domain']; $selector = $options['dkim'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $domain . $selector)) { throw new RuntimeException('MAIL_DNS_INPUT'); }
        $spf = false;
        foreach (dns_get_record($domain, DNS_TXT) ?: [] as $record) { if (str_starts_with($record['txt'] ?? '', 'v=spf1')) { $spf = true; } }
        flagMail('Registro SPF presente (no certifica alineación)', $spf);
        flagMail('Registro DMARC presente', (bool)dns_get_record('_dmarc.' . $domain, DNS_TXT));
        if ($selector !== '') { flagMail('Registro DKIM presente', (bool)dns_get_record($selector . '._domainkey.' . $domain, DNS_TXT | DNS_CNAME)); }
    }
    if (isset($options['send-test'])) {
        $to = trim((string)($options['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('MAIL_TEST_RECIPIENT'); }
        $subject = 'IMPE prueba autorizada ' . gmdate('Y-m-d H:i:s');
        $body = 'Mensaje ficticio de diagnóstico. No contiene una contraseña ni cambia ninguna cuenta. Confirmar recepción manualmente.';
        if ($user > 0) {
            $r = (new CorreoSalidaInstitucionalService())->enviar(['usuario_id' => $user, 'perfil_marketing' => true,
                'remitente' => $from, 'destinatario' => $to, 'asunto' => $subject, 'cuerpo' => $body,
                'idempotency_key' => 'qa-cli:' . bin2hex(random_bytes(16))]);
        } else {
            $mail->setFrom($from); $mail->addAddress($to); $mail->Subject = $subject; $mail->Body = $body;
            $r = CorreoSmtpTransport::enviar($mail, 'recovery_test');
        }
        echo 'Resultado: ' . ($r['estado_entrega'] ?? 'RECHAZADO') . '; entrega al destinatario NO confirmada.' . PHP_EOL;
        if (empty($r['ok'])) { exit(2); }
    } else { echo "Sin envío de mensajes. Usa --probe para comprobar TLS/autenticación desde SSH.\n"; }
} catch (Throwable $e) {
    error_log('MAIL_DIAGNOSTIC_FAILED exception=' . get_class($e));
    echo "Diagnóstico incompleto. Revisar configuración, permisos privados, certificados, autenticación o restricciones salientes. No se muestran secretos.\n";
    exit(2);
}
