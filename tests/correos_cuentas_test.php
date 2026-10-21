<?php
// Transporte central simulado: ningún proveedor ni usuario real.
require dirname(__DIR__) . '/app/services/CorreoSalidaInstitucionalService.php';
set_error_handler(static function ($severity,$message,$file,$line) { if ($severity===E_DEPRECATED) { throw new ErrorException($message,0,$severity,$file,$line); } return false; });
function checkMail($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
class CentralMailQA extends \PHPMailer\PHPMailer\PHPMailer {
    public static $calls = 0;
    public function send() { self::$calls++; return $this->preSend(); }
}
$work = sys_get_temp_dir() . '/impe-central-' . bin2hex(random_bytes(6)); mkdir($work, 0700, true);
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $work); putenv('IMPE_MAIL_STATE_DIR=' . $work . '/state');
$account = ['activo'=>true,'correo'=>'central@institucion.test','nombre'=>'Institución QA','transport'=>'smtp',
    'smtp_host'=>'smtp.institucion.test','smtp_port'=>587,'smtp_encryption'=>'tls',
    'smtp_username'=>'central@institucion.test','smtp_password'=>'CLAVE_QA_PRIVADA'];
$write = static function ($a) use ($work) { file_put_contents($work . '/mail_central.local.php', '<?php return ' . var_export($a,true) . ';'); };
$write($account);
try {
    $resolver = new CorreoCuentaConectadaService(static function ($id) { return ['correo'=>'contacto' . $id . '@gmail.com','estado'=>1]; });
    $last = null; $apiCalls=[]; $apiStatus=204;
    $sender = new CorreoSalidaInstitucionalService(static function () use (&$last) { return $last = new CentralMailQA(true); }, $resolver,
        static function ($method,$url,$token,$payload) use (&$apiCalls,&$apiStatus) {
            $apiCalls[]=$method;
            if ($method==='GET') { return ['ok'=>true,'json'=>['data'=>['mailboxes'=>[['address'=>'central@institucion.test','resourceId'=>'CENTRAL'],['address'=>'otro@institucion.test','resourceId'=>'NO']]]]]; }
            checkMail(str_ends_with($url,'/CENTRAL/send') && $token==='TOKEN_QA','buzón central exacto');
            checkMail(!isset($payload['replyTo']) && !isset($payload['reply_to']),'no inventar encabezados API');
            checkMail(base64_decode($payload['attachments'][0]['content'])===file_get_contents(__FILE__),'adjunto API íntegro');
            return ['ok'=>$apiStatus===204,'codigo_http'=>$apiStatus];
        });
    $d=['usuario_id'=>7,'remitente'=>'contacto7@gmail.com','destinatario'=>'externo@educacion.test','asunto'=>'QA','cuerpo'=>'Contenido íntegro',
        'adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'muestra.txt','mime'=>'text/plain']],'idempotency_key'=>'central-0001','confirmar_identidad'=>true];
    $pre=$sender->identidad($d['remitente'],7);
    checkMail($pre['ok'] && !str_contains(json_encode($pre),'CLAVE_QA_PRIVADA') && !str_contains(json_encode($pre),'smtp_password'),'preflight sin secretos');
    checkMail($pre['identidad']['remitente_visible']===$account['correo'] && $pre['identidad']['reply_to']===$d['remitente'],'From visible y Reply-To de contacto registrado');
    checkMail(!$sender->enviar($d)['ok'] && CentralMailQA::$calls===0,'no enviar sin identidad revisada');
    $d['identidad_envio']=$pre['identidad_envio'];
    $r=$sender->enviar($d);
    checkMail($r['ok'] && !$r['entrega_confirmada'] && $last->From===$account['correo'] && $last->Username===$account['correo'],'central autenticada sin suplantación');
    checkMail(in_array($d['remitente'], array_column($last->getReplyToAddresses(), 0), true),'Reply-To SMTP: '.json_encode($last->getReplyToAddresses()));
    checkMail(count($last->getAttachments())===1,'adjunto SMTP');
    checkMail(!$sender->enviar($d)['ok'] && CentralMailQA::$calls===1,'doble clic sin duplicados');
    foreach (['qa@gmail.com','qa@outlook.com','qa@universidad.test'] as $i=>$to) {
        $d['destinatario']=$to; $d['usuario_id']=8; $d['remitente']='contacto8@gmail.com'; $d['idempotency_key']='dominio-000'.$i;
        $d['identidad_envio']=$sender->identidad($d['remitente'],8)['identidad_envio'];
        checkMail($sender->enviar($d)['ok'] && $last->getToAddresses()[0][0]===$to,'dominio destinatario libre y otro perfil/usuario sin conexiones');
    }
    $alien=$sender->identidad('ajeno@gmail.com',7);
    checkMail($alien['identidad']['reply_to']==='contacto7@gmail.com','no Reply-To ajeno');
    checkMail(!$sender->comprobarIdentidad('contacto7@gmail.com',7,$alien['identidad_envio'])['ok'],'token ligado al contacto');
    $write($account+['authorized_from'=>['contacto7@gmail.com']]);
    checkMail($sender->identidad('contacto7@gmail.com',7)['identidad']['remitente_visible']==='contacto7@gmail.com','alias administrado para su titular');
    $write(array_replace($account,['transport'=>'hostinger_api','api_token'=>'TOKEN_QA']));
    $d['usuario_id']=7; $d['remitente']='contacto7@gmail.com'; $d['idempotency_key']='api-central-0001';
    $pre=$sender->identidad($d['remitente'],7); $d['identidad_envio']=$pre['identidad_envio'];
    checkMail(!$pre['identidad']['reply_to_aplicado'] && $pre['identidad']['aviso']!=='','limitación API visible antes de enviar');
    checkMail($sender->enviar($d)['ok'] && $apiCalls===['GET','POST'],'API central sin conexión personal');
    $apiStatus=503; $d['idempotency_key']='api-unknown-0001';
    checkMail($sender->enviar($d)['estado_entrega']==='DESCONOCIDO','incertidumbre API'); $before=count($apiCalls);
    checkMail(!$sender->enviar($d)['ok'] && count($apiCalls)===$before,'no reintento incierto ni fallback');
    $write(array_replace($account,['transport'=>'hostinger_api','api_token'=>'TOKEN_QA','reply_smtp'=>$account]));
    $d['idempotency_key']='api-smtp-reply-0001'; $d['identidad_envio']=$sender->identidad($d['remitente'],7)['identidad_envio'];
    checkMail($sender->enviar($d)['ok'] && count($apiCalls)===$before && in_array($d['remitente'], array_column($last->getReplyToAddresses(), 0), true),'SMTP central seleccionado antes de API para Reply-To');
    $_SESSION['usuario_id']=8; checkMail($sender->identidad('contacto7@gmail.com',7)['codigo_http']===403,'sesión ajena bloqueada'); unset($_SESSION['usuario_id']);
    $write([]); $d['idempotency_key']='sin-config-0001'; $count=CentralMailQA::$calls;
    checkMail(!$sender->enviar($d)['ok'] && CentralMailQA::$calls===$count,'configuración ausente no simula envío');
    echo "OK: central SMTP/API, From visible, Reply-To verificado, varios dominios/usuarios, adjuntos, alias, autorización e idempotencia. Simulación sin proveedores.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); } rmdir($work);
}
