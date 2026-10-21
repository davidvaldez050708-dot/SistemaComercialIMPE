"""Recuperación con configuración migrada y destinatarios ficticios. Sin red ni BD.
Uso: python tests/correos_recuperacion_config_test.py RUTA_PHP
"""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
php = sys.argv[1]
entry = r'''
define('ROOT_PATH', $argv[1]); define('BASE_URL', 'https://crm.example.test/');
require ROOT_PATH . '/app/services/MailService.php';
class RecoveryMailQA extends \PHPMailer\PHPMailer\PHPMailer {
    public $called = false;
    public function send() { $this->called = true; return true; }
}
$mail = new RecoveryMailQA(true);
$service = new MailService(static function () use ($mail) { return $mail; });
$ok = $service->enviarPasswordTemporal('registrado@institucion.test', 'Persona ficticia', 'TEMPORAL_QA_PRIVADA');
echo json_encode(['ok' => $ok, 'called' => $mail->called, 'host' => $mail->Host, 'port' => $mail->Port,
    'tls' => $mail->SMTPSecure, 'from' => $mail->From, 'to' => $mail->getToAddresses(),
    'content' => str_contains($mail->Body, 'TEMPORAL_QA_PRIVADA') && str_contains($mail->AltBody, '30 minutos'),
    'verified_tls' => $mail->SMTPOptions['ssl']['verify_peer'] ?? false]);
'''
with tempfile.TemporaryDirectory(prefix='impe-recovery-config-') as tmp:
    private = Path(tmp)
    env = {k: v for k, v in os.environ.items() if not k.startswith('MAIL_')}
    env.update(IMPE_PRIVATE_CONFIG_DIR=tmp, IMPE_MAIL_STATE_DIR=str(private / 'state'),
               MAIL_USERNAME='recuperacion@gmail.com', MAIL_PASSWORD='CLAVE_QA_PRIVADA')

    def run(changes=None):
        r = subprocess.run([php, '-r', entry, str(root)], env=env | (changes or {}), cwd=root,
                           capture_output=True, text=True, encoding='utf-8', timeout=15)
        assert r.returncode == 0, 'Falló el proceso PHP de prueba'
        for secret in ['CLAVE_QA_PRIVADA', 'TEMPORAL_QA_PRIVADA', 'registrado@institucion.test']:
            assert secret not in r.stderr, 'El log expuso un dato privado'
        return json.loads(r.stdout), r.stderr

    a, log = run()
    assert a['ok'] and a['called'] and a['host'] == 'smtp.gmail.com' and a['port'] == 587 and a['tls'] == 'tls'
    assert a['from'] == 'recuperacion@gmail.com' and a['to'] == [['registrado@institucion.test', 'Persona ficticia']]
    assert a['content'] and a['verified_tls'], 'Contenido y destinatario registrados, vigencia y TLS conservados'

    a, _ = run({'MAIL_HOST': 'smtp.institucion.test', 'MAIL_PORT': '465', 'MAIL_ENCRYPTION': 'ssl'})
    assert a['host'] == 'smtp.institucion.test' and a['port'] == 465 and a['tls'] == 'ssl', 'Respetar servidor explícito'

    (private / 'mail_config.local.php').write_text("<?php define('MAIL_HOST','smtp.privado.test');", encoding='utf-8')
    a, _ = run({'MAIL_HOST': 'smtp.entorno.test'})
    assert a['host'] == 'smtp.privado.test', 'Conservar precedencia del PHP privado existente'
    (private / 'mail_config.local.php').unlink()

    dedicated = private / 'password_reset_mail.local.php'
    dedicated.write_text("""<?php return ['correo'=>'cuenta@dedicada.test','smtp_host'=>'smtp.dedicada.test',
        'smtp_username'=>'cuenta@dedicada.test','smtp_password'=>'CLAVE_QA_PRIVADA','smtp_port'=>465,'smtp_encryption'=>'ssl'];""", encoding='utf-8')
    (private / 'marketing_mail.local.php').write_text("<?php return ['smtp_host'=>'smtp.NO_USAR.test'];", encoding='utf-8')
    a, _ = run()
    assert a['ok'] and a['from'] == 'cuenta@dedicada.test' and a['host'] == 'smtp.dedicada.test', 'Cuenta dedicada independiente de Marketing'

    dedicated.write_text('<?php return [];', encoding='utf-8')
    a, log = run()
    assert not a['ok'] and not a['called'] and 'MAIL_RESET_CONFIG' in log, 'Archivo dedicado inválido no usa otra cuenta'
    dedicated.unlink()
    a, log = run({'MAIL_PASSWORD': ''})
    assert not a['ok'] and not a['called'] and 'MAIL_SMTP_CONFIG' in log, 'Falta de credenciales no simula envío'
    a, log = run({'MAIL_USERNAME': '', 'MAIL_PASSWORD': ''})
    assert not a['ok'] and not a['called'] and 'MAIL_RESET_IDENTITY' in log, 'Configuración ausente detectada sin secretos'

print('OK: recuperación Gmail migrada, SMTP explícito/SSL, prioridad privada/dedicada, destinatario y contenido, TLS y fallos sin envío ni secretos. Sin red ni BD.')
