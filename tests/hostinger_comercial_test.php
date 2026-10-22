<?php
// API simulada e historial en memoria. No contacta Hostinger, SMTP ni BD real.
require __DIR__.'/correos_marketing_test.php';
require dirname(__DIR__).'/tools/diagnosticar_hostinger_comercial.php';
function hc($value,$label) { if(!$value) { throw new RuntimeException($label); } }
$work=sys_get_temp_dir().'/impe-hostinger-commercial-'.bin2hex(random_bytes(5));mkdir($work,0700,true);
putenv('IMPE_PRIVATE_CONFIG_DIR='.$work);putenv('IMPE_MAIL_STATE_DIR='.$work.'/state');
putenv('HOSTINGER_MAIL_API_TOKEN=TOKEN_QA_PRIVADO');
$write=static function($name,$a)use($work){file_put_contents($work.'/'.$name,'<?php return '.var_export($a,true).';');};
$loader=static function($id){return ['correo'=>'persona@gmail.com','estado'=>1,'rol_estado'=>1,'rol_id'=>$id,'rol'=>$id===4?'Marketing':($id===2?'Analista':'Cuenta Clave')];};
$calls=[];$http=204;$getHttp=200;$allowed=true;$expectedBox='ACuno';
$request=static function($method,$url,$token,$payload)use(&$calls,&$http,&$getHttp,&$allowed,&$expectedBox){
    hc(in_array($token,['TOKEN_QA_PRIVADO','TOKEN_CENTRAL_QA','TOKEN_ARCHIVO_QA'],true),'Carga del token privado');$calls[]=$method;
    if($method==='GET') {
        hc($url===CorreoHostingerApiService::BASE.'/api/v1/me','Endpoint oficial de buzones');
        return ['ok'=>$getHttp===200,'codigo_http'=>$getHttp,'json'=>['data'=>['mailboxes'=>$allowed?[
            ['address'=>'uno@rededucativamexico.org','resourceId'=>'ACuno'],['address'=>'dos@rededucativamexico.org','resourceId'=>'ACdos']]:[]]]];
    }
    hc($url===CorreoHostingerApiService::BASE.'/api/v1/mailboxes/'.$expectedBox.'/send','Envío desde el buzón EXACTO');
    hc(base64_decode($payload['attachments'][0]['content'])===file_get_contents(__FILE__),'Adjunto íntegro');
    hc($payload['text']==="Contenido completo\nSegunda línea" && !isset($payload['from']) && !isset($payload['replyTo']),'Contenido conservado y sin campos inventados');
    return ['ok'=>$http>=200&&$http<300,'codigo_http'=>$http];
};
try {
    $resolver=new CorreoCuentaConectadaService($loader);
    $sender=new CorreoSalidaInstitucionalService(null,$resolver,$request);
    $public=$sender->remitentes(4);
    hc($public['ok'] && count($public['remitentes'])===2 && !str_contains(json_encode($public),'TOKEN_QA_PRIVADO'),'Buzones automáticos, sin token en respuesta pública');
    foreach([2,3,4] as $role) { hc($sender->validarRemitente('uno@rededucativamexico.org',$role)['ok'],'Mismo transporte para perfiles autorizados'); }
    foreach(['persona@gmail.com','persona@outlook.com','ajena@otro.test'] as $from) { hc(!$sender->validarRemitente($from,4)['ok'],'Remitente externo/no autorizado bloqueado'); }
    $base=['usuario_id'=>4,'remitente'=>'uno@rededucativamexico.org','destinatario'=>'prueba@gmail.com','asunto'=>'Prueba comercial',
        'cuerpo'=>"Contenido completo\nSegunda línea",'adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'documento.txt','mime'=>'text/plain']]];
    foreach(['prueba@gmail.com','prueba@outlook.com','prueba@universidad.test'] as $i=>$to) {
        $d=$base;$d['destinatario']=$to;$d['idempotency_key']='commercial-domain-'.$i;
        hc($sender->enviar($d)['ok'],'Destinatario de cualquier dominio válido');
        $before=count(array_filter($calls,static function($m){return $m==='POST';}));
        hc(!$sender->enviar($d)['ok'] && count(array_filter($calls,static function($m){return $m==='POST';}))===$before,'Sin envío duplicado');
    }
    $write('mail_central.local.php',['allowed_senders'=>['uno@rededucativamexico.org'],'user_senders'=>[3=>[]]]);
    hc(!$sender->validarRemitente('dos@rededucativamexico.org',4)['ok'] && !$sender->validarRemitente('uno@rededucativamexico.org',3)['ok'],'Restricciones centrales globales/usuario');
    $write('mail_central.local.php',['role_senders'=>['Marketing'=>['dos@rededucativamexico.org']]]);
    hc(!$sender->validarRemitente('uno@rededucativamexico.org',4)['ok'],'Restricción por perfil');
    $write('mail_central.local.php',[]);
    $write('mail_accounts.local.php',['accounts'=>['uno@rededucativamexico.org'=>['activo'=>false,'usuarios'=>[4]]]]);
    hc(!$sender->validarRemitente('uno@rededucativamexico.org',4)['ok'],'No eludir desactivación administrativa previa');unlink($work.'/mail_accounts.local.php');
    foreach([401,403,429,500] as $code) { $getHttp=$code;$before=count($calls);hc(!$sender->validarRemitente('uno@rededucativamexico.org',4)['ok'] && count($calls)===$before+1,'Error real de consulta, sin POST'); }
    $getHttp=200;$allowed=false;hc(!$sender->validarRemitente('uno@rededucativamexico.org',4)['ok'],'API sin buzones');$allowed=true;
    $http=403;$d=$base;$d['idempotency_key']='commercial-rejected';hc($sender->enviar($d)['estado_entrega']==='RECHAZADO','Rechazo no es envío');
    $http=503;$d['idempotency_key']='commercial-unknown';hc($sender->enviar($d)['estado_entrega']==='DESCONOCIDO','Incertidumbre conservada');$before=count(array_filter($calls,static function($m){return $m==='POST';}));$http=204;
    hc(!$sender->enviar($d)['ok'] && count(array_filter($calls,static function($m){return $m==='POST';}))===$before,'Sin reintento ni fallback tras incertidumbre');
    $_SESSION['usuario_id']=2;hc($sender->validarRemitente('uno@rededucativamexico.org',4)['codigo_http']===403,'Sesión/usuario vinculados');unset($_SESSION['usuario_id']);
    $db=new MarketingDb();$marketing=new CorreoMarketingService($db,$sender,$loader);
    $data=['remitente'=>'uno@rededucativamexico.org','destinatario'=>'destino@universidad.test','asunto'=>'Historial QA','cuerpo'=>'Texto ficticio','idempotency_key'=>'marketing-real-api-qa'];
    // El doble de API dedicado a historial no necesita adjunto en esta redacción.
    $transport=new CorreoSalidaInstitucionalService(null,new CorreoCuentaConectadaService($loader),static function($method,$url,$token,$payload){return $method==='GET'
        ? ['ok'=>true,'codigo_http'=>200,'json'=>['data'=>['mailboxes'=>[['address'=>'uno@rededucativamexico.org','resourceId'=>'ACuno']]]]]
        : ['ok'=>true,'codigo_http'=>204];});
    $marketing=new CorreoMarketingService($db,$transport,$loader);
    hc($marketing->guardarBorrador(4,$data)['ok'],'Guardar borrador independiente de la cuenta personal');
    $data['correo_id']=1;$sent=$marketing->enviar(4,$data);
    hc($sent['ok'] && $db->rows[1]['estado']==='ENVIADO' && $db->rows[1]['proveedor']==='HOSTINGER_MAIL_API','Marketing/historial sólo tras 204');
    hc($marketing->enviar(4,$data)===$sent && $db->sentWrites===1 && count($db->rows)===1,'Un registro y un resultado idempotente');
    putenv('HOSTINGER_MAIL_API_TOKEN');$missing=$sender->validarRemitente('uno@rededucativamexico.org',4);
    hc(($missing['codigo_tecnico']??'')==='HOSTINGER_TOKEN_AUSENTE','Ausencia diferenciada de rechazo del proveedor');
    $write('mail_central.local.php',['transport'=>'hostinger_api','api_token'=>'TOKEN_CENTRAL_QA']);hc($sender->validarRemitente('uno@rededucativamexico.org',4)['ok'],'Compatibilidad con archivo comercial privado');
    unlink($work.'/mail_central.local.php');file_put_contents($work.'/hostinger_mail_config.php',"<?php define('HOSTINGER_MAIL_API_TOKEN','TOKEN_ARCHIVO_QA');");
    hc($sender->validarRemitente('uno@rededucativamexico.org',4)['ok'],'Variable existente en hostinger_mail_config.php');
    $oldCwd=getcwd();chdir(dirname($work));$_SERVER['DOCUMENT_ROOT']='';
    guardarTokenHostingerComercial('TOKEN_GUARDADO_QA',static function(){return ['uno@rededucativamexico.org'=>'ACuno'];});
    $before=file_get_contents($work.'/mail_central.local.php');
    try {guardarTokenHostingerComercial('RECHAZADO',static function(){throw new RuntimeException('HOSTINGER_CONSULTA_401');});throw new LogicException('Debió rechazar');}catch(RuntimeException $e){}
    hc(file_get_contents($work.'/mail_central.local.php')===$before,'Token rechazado no sustituye configuración');chdir($oldCwd);
    echo "OK: token privado, remitentes centrales, permisos, perfiles, dominios destinatarios, adjuntos, errores/API, borradores e historial idempotente. Sin envíos ni BD reales.\n";
} finally {
    if(isset($oldCwd))chdir($oldCwd);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($work);
}
