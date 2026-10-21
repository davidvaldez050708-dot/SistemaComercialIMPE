<?php
// Pruebas dirigidas; ningún correo sale de este proceso.
define('ROOT_PATH',dirname(__DIR__)); define('BASE_URL','https://crm.example.test/');
require ROOT_PATH.'/app/services/MailService.php';
function mailExpect($condition,$label) { if (!$condition) { throw new RuntimeException($label); } }
class SmtpPrueba extends CorreoSmtpSession {
    public $replyCode=0;
    public function getError() { return ['smtp_code'=>$this->replyCode]; }
}
class MailPrueba extends \PHPMailer\PHPMailer\PHPMailer {
    public $mode='ok';
    public function send() {
        if ($this->mode==='ok') { return true; }
        $smtp=$this->getSMTPInstance();
        $smtp->dataStarted=$this->mode!=='auth'; $smtp->replyCode=$this->mode==='unknown'?0:535;
        throw new RuntimeException('NO_REGISTRAR_PASSWORD_TOKEN_Y_DESTINATARIO');
    }
}
$work=sys_get_temp_dir().'/impe-mail-'.bin2hex(random_bytes(6)); mkdir($work,0700,true);
putenv('IMPE_MAIL_STATE_DIR='.$work.'/state'); putenv('IMPE_PRIVATE_CONFIG_DIR='.$work);
$account=['correo'=>'recuperacion@gmail.com','nombre'=>'Recuperación', 'smtp_host'=>'smtp.gmail.com','smtp_port'=>587,
    'smtp_encryption'=>'tls','smtp_username'=>'recuperacion@gmail.com','smtp_password'=>'SECRETO_PRUEBA'];
file_put_contents($work.'/password_reset_mail.local.php','<?php return '.var_export($account,true).';');
try {
    $mail=new MailPrueba(true); $mail->setSMTPInstance(new SmtpPrueba());
    $service=new MailService(static function() use($mail) { return $mail; });
    mailExpect($service->enviarPasswordTemporal('destino@institucion.test','Persona de Prueba','Temporal_QA'),'dedicated recovery send');
    mailExpect($mail->From==='recuperacion@gmail.com' && $mail->Username==='recuperacion@gmail.com','dedicated identity');
    mailExpect($mail->SMTPSecure==='tls' && $mail->SMTPOptions['ssl']['verify_peer'] && !$mail->SMTPOptions['ssl']['allow_self_signed'],'TLS verification');
    mailExpect(strpos($mail->Body,'Temporal_QA')!==false && strpos($mail->AltBody,'30 minutos')!==false,'recovery body and validity unchanged');
    foreach (['auth'=>'RECHAZADO','unknown'=>'DESCONOCIDO'] as $mode=>$expected) {
        $mail->mode=$mode; $result=CorreoSmtpTransport::enviar($mail,'test');
        mailExpect(!$result['ok'] && $result['estado_entrega']===$expected && !$result['entrega_confirmada'],'SMTP outcome '.$mode);
    }
    $oauth=$account+['oauth_provider'=>'google','client_id'=>'CLIENTE_QA','refresh_token'=>'REFRESH_QA'];
    $calls=0;
    $provider=new CorreoOAuthTokenProvider($oauth,static function($url,$fields) use(&$calls) {
        $calls++; mailExpect($url==='https://oauth2.googleapis.com/token','Google token endpoint');
        return ['access_token'=>'ACCESS_QA','expires_in'=>3600,'refresh_token'=>'REFRESH_ROTADO_QA'];
    });
    mailExpect(base64_decode($provider->getOauth64())==="user=recuperacion@gmail.com\001auth=Bearer ACCESS_QA\001\001",'XOAUTH2 payload');
    $provider->getOauth64(); mailExpect($calls===1,'cached token');
    $oauth['oauth_provider']='microsoft';
    $provider=new CorreoOAuthTokenProvider($oauth,static function($url,$fields) {
        mailExpect($url==='https://login.microsoftonline.com/common/oauth2/v2.0/token' && strpos($fields['scope'],'SMTP.Send')!==false,'Microsoft token endpoint and scope');
        return ['access_token'=>'ACCESS_MS_QA','expires_in'=>3600];
    });
    mailExpect(strpos(base64_decode($provider->getOauth64()),'ACCESS_MS_QA')!==false,'Microsoft OAuth');
    $bad=$account; $bad['smtp_encryption']='none';
    try { CorreoSmtpTransport::configurar(new MailPrueba(),$bad); throw new LogicException('insecure configuration accepted'); }
    catch (RuntimeException $e) { mailExpect($e->getMessage()==='MAIL_SMTP_CONFIG','no cleartext SMTP'); }
    echo "OK: recuperación dedicada, plantilla/vigencia, TLS, rechazo/incertidumbre y OAuth Google/Microsoft simulado. Sin envíos reales.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); } rmdir($work);
}
