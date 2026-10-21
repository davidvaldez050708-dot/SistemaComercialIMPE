<?php
// Configuración privada y autenticación simulada. Sin red, BD ni credenciales reales.
require dirname(__DIR__) . '/tools/configurar_correo_hostinger.php';
require dirname(__DIR__) . '/app/services/CorreoSalidaInstitucionalService.php';
require dirname(__DIR__) . '/app/services/MailService.php';
function setupExpect($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
function setupError(callable $f, string $code) {
    try { $f(); throw new LogicException('No rechazó ' . $code); }
    catch(RuntimeException $e) { setupExpect($e->getMessage() === $code, 'Código: ' . $e->getMessage()); }
}
$work = sys_get_temp_dir() . '/impe-mail-setup-' . bin2hex(random_bytes(6)); mkdir($work, 0700, true);
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $work); putenv('IMPE_MAIL_STATE_DIR=' . $work . '/state');
$accepted = true; $probes = [];
$loadUser = static function($email) { return ['id'=>71,'correo'=>'empleado@gmail.com','estado'=>1,'rol_estado'=>1]; };
$setup = new CorreoConfiguracionHostinger($work, static function($a) use (&$accepted,&$probes) {
    $probes[]=$a;
    return $accepted && $a['transport']==='hostinger_api' ? ['addresses'=>['asignado@hostinger.test','otro@hostinger.test']] : $accepted;
}, $loadUser);
class SetupSmtpQA extends \PHPMailer\PHPMailer\PHPMailer { public function send() { return $this->preSend(); } }
$recovery = ['email'=>'qa.recuperacion@gmail.com','host'=>'smtp.gmail.com','port'=>587,'tls'=>'tls','secret'=>'ABCD EFGH IJKL MNOP'];
try {
    $r = $setup->configurar('--recovery', $recovery);
    setupExpect($r['respaldo']===null && $r['autenticacion'], 'Primera configuración privada autenticada');
    $a = MailService::cuentaRecuperacion();
    setupExpect($a['correo']===$recovery['email'] && $a['smtp_host']==='smtp.gmail.com' && $a['smtp_password']==='ABCDEFGHIJKLMNOP', 'La recuperación web/CLI lee el archivo dedicado y la clave Gmail normalizada');
    $old = file_get_contents($r['ruta']); $accepted=false;
    setupError(static function() use($setup,$recovery){$setup->configurar('--recovery',$recovery+['x'=>'x']);}, 'MAIL_SETUP_AUTH');
    setupExpect(file_get_contents($r['ruta'])===$old, 'Autenticación rechazada no sustituye configuración');
    $accepted=true; $new=$recovery; $new['secret']='CLAVE_NUEVA_QA';
    $r2=$setup->configurar('--recovery',$new);
    setupExpect(file_get_contents($r2['respaldo'])===$old, 'Respaldo exacto de configuración anterior');
    $api=$setup->configurar('--hostinger',['secret'=>'TOKEN_HOSTINGER_QA']);
    $central=(new CorreoCuentaConectadaService())->central();
    setupExpect($central['api_token']==='TOKEN_HOSTINGER_QA' && $central['correo']==='', 'Token API compartido utilizable, sin remitente arbitrario');
    $setup->configurar('--hostinger',['secret'=>'TOKEN_HOSTINGER_QA','email'=>'empleado@gmail.com']);
    $mappedResolver=new CorreoCuentaConectadaService(static function($id){return ['correo'=>'empleado@gmail.com','estado'=>1];});
    $mappedResolver->configurarConsultaApi(static function(){return ['ok'=>true,'json'=>['data'=>['mailboxes'=>[
        ['address'=>'asignado@hostinger.test','resourceId'=>'ACuno'],['address'=>'otro@hostinger.test','resourceId'=>'ACdos']]]]];});
    setupExpect($mappedResolver->resolver('asignado@hostinger.test',71)['identidad']['remitente_visible']==='asignado@hostinger.test','Usuario registrado Gmail puede elegir el buzón Hostinger asociado explícitamente por administración');
    setupError(static function()use($mappedResolver){$mappedResolver->resolver('asignado@hostinger.test',73);}, 'MAIL_CENTRAL_FROM');
    $account=$recovery;$account['email']='empleado@gmail.com';
    file_put_contents($work.'/mail_accounts.local.php',"<?php return ['limits'=>['per_user_hour'=>12],'accounts'=>[['correo'=>'empleado@gmail.com','usuarios'=>[72]],'otra@institucion.test'=>['activo'=>false]]];");
    $setup->configurar('--account',$account);
    $stored=impeLeerConfigPrivada('mail_accounts.local.php');
    setupExpect($stored['limits']['per_user_hour']===12 && isset($stored['accounts']['otra@institucion.test']) && count($stored['accounts'])===2, 'Conserva otras cuentas/ajustes sin duplicar la dirección configurada');
    $resolver=new CorreoCuentaConectadaService(static function($id){return ['correo'=>'empleado@gmail.com','estado'=>1];});
    $result=$resolver->resolver('empleado@gmail.com',71);
    setupExpect($result['ok'] && $result['account']['transport']==='smtp' && $result['identidad']['remitente_visible']==='empleado@gmail.com', 'Gmail SMTP autorizado no obliga a crear una aplicación OAuth ni cambia From');
    setupExpect($resolver->resolver('empleado@gmail.com',72)['ok'] && !$resolver->resolver('empleado@gmail.com',73)['ok'], 'Permisos previos conservados y otro usuario rechazado');
    $mail=new SetupSmtpQA(true);
    $sender=new CorreoSalidaInstitucionalService(static function()use($mail){return $mail;},$resolver);
    $pre=$sender->identidad('empleado@gmail.com',71);
    $sent=$sender->enviar(['usuario_id'=>71,'remitente'=>'empleado@gmail.com','destinatario'=>'destino@institucion.test','asunto'=>'QA SMTP propio','cuerpo'=>'Contenido completo',
        'confirmar_identidad'=>true,'identidad_envio'=>$pre['identidad_envio'],'idempotency_key'=>'setup-smtp-0001','adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'muestra.txt','mime'=>'text/plain']]]);
    setupExpect($sent['ok'] && $mail->From==='empleado@gmail.com' && $mail->Username==='empleado@gmail.com' && count($mail->getAttachments())===1, 'Envío SMTP propio con identidad firmada y adjunto, sin depender de la API/OAuth');
    setupExpect($mail->Password==='ABCDEFGHIJKLMNOP' && MailService::cuentaRecuperacion()['smtp_password']==='CLAVE_NUEVA_QA', 'Credenciales comerciales y de recuperación separadas');
    $stored['accounts']['empleado@gmail.com']['activo']=false;
    file_put_contents($work.'/mail_accounts.local.php','<?php return '.var_export($stored,true).';');
    setupExpect(!$resolver->resolver('empleado@gmail.com',71)['ok'], 'Desactivación administrativa sin fallback a OAuth/API');
    $stored['accounts']['propio@hostinger.test']=['activo'=>true,'usuarios'=>[71],'transport'=>'hostinger_api','api_token'=>'TOKEN_PROPIO_QA'];
    file_put_contents($work.'/mail_accounts.local.php','<?php return '.var_export($stored,true).';');
    unlink($api['ruta']);
    $resolver->configurarConsultaApi(static function($method,$url,$token,$payload){
        setupExpect($token==='TOKEN_PROPIO_QA','Token específico de la cuenta configurada');
        return ['ok'=>true,'json'=>['data'=>['mailboxes'=>[['address'=>'propio@hostinger.test','resourceId'=>'ACqa']]]]];
    });
    setupExpect($resolver->resolver('propio@hostinger.test',71)['identidad']['remitente_visible']==='propio@hostinger.test','Cuenta API previamente configurada funciona sin cuenta central adicional');
    setupError(static function()use($setup,$account){$account['email']='ajeno@gmail.com';$setup->configurar('--account',$account);}, 'MAIL_SETUP_USER');
    $inside=dirname(__DIR__).'/tmp/no-crear-configuracion-'.bin2hex(random_bytes(4));
    setupError(static function()use($inside){(new CorreoConfiguracionHostinger($inside,static function(){return true;}))->configurar('--hostinger',['secret'=>'QA']);}, 'MAIL_SETUP_NOT_PRIVATE');
    setupExpect(!file_exists($inside), 'Rechazo dentro del repositorio antes de crear carpetas');
    $traversal=$work.'/nuevo/../escape';
    setupError(static function()use($traversal){(new CorreoConfiguracionHostinger($traversal,static function(){return true;}))->configurar('--hostinger',['secret'=>'QA']);}, 'MAIL_SETUP_NOT_PRIVATE');
    foreach(glob($work.'/*.tmp-*') as $f){throw new RuntimeException('Temporal restante');}
    echo "OK: configuración privada Gmail/Hostinger, autenticación previa, respaldo exacto, cuenta SMTP por usuario, precedencia, conservación de otras cuentas y rechazo sin sobrescritura. Sin red ni BD real.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($work);
}
