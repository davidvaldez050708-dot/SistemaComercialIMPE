<?php
// Integración dirigida: resolución central, API simulada e historial en memoria.
require __DIR__.'/correos_marketing_test.php';
function ciCheck($ok,string $label): void { if(!$ok) { throw new RuntimeException($label); } }
$work=sys_get_temp_dir().'/impe-central-identity-'.bin2hex(random_bytes(5));mkdir($work,0700,true);
putenv('IMPE_PRIVATE_CONFIG_DIR='.$work);putenv('IMPE_MAIL_STATE_DIR='.$work.'/state');putenv('HOSTINGER_MAIL_API_TOKEN=QA_CENTRAL_TOKEN');
$posts=[];$http=204;$getHttp=200;
$identity=static function($id){return ['correo'=>'qa.contacto@rededucativamexico.org','nombre'=>'Analista','apellidos'=>'Prueba','estado'=>1,'rol_estado'=>1,'rol_id'=>$id];};
$request=static function($method,$url,$token,$payload)use(&$posts,&$http,&$getHttp){
    ciCheck($token==='QA_CENTRAL_TOKEN','Token central privado');
    if($method==='GET') { return ['ok'=>$getHttp===200,'codigo_http'=>$getHttp,'json'=>['data'=>['mailboxes'=>[['address'=>'qa.central@rededucativamexico.org','resourceId'=>'ACcentral']]]]]; }
    ciCheck($url===CorreoHostingerApiService::BASE.'/api/v1/mailboxes/ACcentral/send','POST siempre desde el ID real autorizado');
    ciCheck(!isset($payload['from']) && !isset($payload['replyTo']) && !isset($payload['headers']),'Sin falsificar From/Reply-To ni enviar campos no documentados');
    $posts[]=$payload;return ['ok'=>$http===204,'codigo_http'=>$http];
};
$sender=new CorreoSalidaInstitucionalService(null,new CorreoCuentaConectadaService($identity),$request);
$base=['usuario_id'=>4,'remitente'=>'qa.contacto@rededucativamexico.org','remitente_real_confirmado'=>'qa.central@rededucativamexico.org',
    'nombre_remitente'=>'Nombre no confiable','destinatario'=>'qa@gmail.com','asunto'=>'Mensaje ficticio', 'cuerpo'=>"Texto original\nSegunda línea",
    'adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'prueba.txt','mime'=>'text/plain']]];
try {
    $preview=$sender->prepararSalida($base['remitente'],4);
    ciCheck($preview['ok'] && $preview['salida']['modo']==='central' && $preview['salida']['contacto_autorizado']===$base['remitente'],'Contacto propio registrado sin conexión individual');
    ciCheck($preview['salida']['respuestas_a']==='qa.central@rededucativamexico.org' && !$preview['salida']['reply_to_personalizado'],'Respuestas reales al central; sin prometer Reply-To personal');
    foreach(['qa@gmail.com','qa@outlook.com','qa@institucion.test'] as $i=>$to) {
        $d=$base;$d['destinatario']=$to;$d['idempotency_key']='central-domains-'.$i;
        $r=$sender->enviar($d);ciCheck($r['ok'] && $r['identidad_envio']['remitente_real']==='qa.central@rededucativamexico.org','Identidad real y destinatario externo');
        $p=end($posts);ciCheck(str_starts_with($p['text'],$base['cuerpo']) && str_contains($p['text'],'Contacto institucional autorizado: '.$base['remitente']),'Conserva texto y contacto propio con nota explícita');
        ciCheck($p['displayName']==='Analista Prueba' && base64_decode($p['attachments'][0]['content'])===file_get_contents(__FILE__),'Nombre del usuario real y adjunto íntegro');
        $n=count($posts);$sender->enviar($d);ciCheck(count($posts)===$n,'Idempotencia sin duplicar POST');
    }
    $d=$base;$d['remitente']='qa.central@rededucativamexico.org';$d['idempotency_key']='central-direct';
    $r=$sender->enviar($d);ciCheck($r['ok'] && $r['identidad_envio']['modo']==='directo' && end($posts)['text']===$base['cuerpo'],'Buzón directo conserva texto y From real');
    $d=$base;$d['remitente']='qa.ajeno@rededucativamexico.org';$d['idempotency_key']='central-unverified';
    $r=$sender->enviar($d);$p=end($posts);
    ciCheck($r['ok'] && $r['identidad_envio']['contacto_autorizado']==='' && !str_contains(json_encode($p),'qa.ajeno'),'Dirección ajena no se atribuye ni se comunica como contacto');
    $n=count($posts);$d=$base;$d['perfil_marketing']=true;unset($d['remitente_real_confirmado']);
    ciCheck($sender->enviar($d)['codigo_http']===409 && count($posts)===$n,'Marketing exige mostrar y confirmar la salida central');
    $d['remitente_real_confirmado']='qa.otra@rededucativamexico.org';
    ciCheck($sender->enviar($d)['codigo_http']===409 && count($posts)===$n,'No sustituir el buzón visible después de confirmarlo');
    foreach([2,3,4,5] as $id) { ciCheck($sender->prepararSalida($base['remitente'],$id)['ok'],'Resolución compartida, sin configurar cuentas por perfil'); }
    ciCheck(!$sender->prepararSalida('ajeno@gmail.com',4)['ok'],'El campo sigue limitado al dominio institucional');
    file_put_contents($work.'/mail_central.local.php',"<?php return ['user_senders'=>[4=>[]]];");
    ciCheck(!$sender->prepararSalida($base['remitente'],4)['ok'],'No eludir restricciones de salida reales');
    file_put_contents($work.'/mail_central.local.php',"<?php return ['from_address'=>'inexistente@rededucativamexico.org'];");
    ciCheck(!$sender->prepararSalida($base['remitente'],4)['ok'],'Preferencia central no autorizada no se reemplaza silenciosamente');unlink($work.'/mail_central.local.php');
    $db=new MarketingDb();$service=new CorreoMarketingService($db,$sender,$identity);
    $data=['remitente'=>$base['remitente'],'remitente_real_confirmado'=>'qa.central@rededucativamexico.org','destinatario'=>'qa@gmail.com','asunto'=>'Historial central','cuerpo'=>$base['cuerpo'],'idempotency_key'=>'central-history'];
    $saved=$service->guardarBorrador(4,$data);ciCheck($saved['ok'] && $db->rows[1]['cuerpo']===$base['cuerpo'],'Borrador conserva texto original');
    $data['correo_id']=1;$r=$service->enviar(4,$data);
    ciCheck($r['ok'] && $db->rows[1]['estado']==='ENVIADO' && $db->rows[1]['usuario_id']===4,'Historial aceptado ligado al redactor');
    ciCheck($db->rows[1]['cuerpo']===end($posts)['text'] && str_contains($db->rows[1]['cuerpo'],'Remitente real: qa.central@rededucativamexico.org'),'Historial conserva nota de identidad realmente transmitida');
    $n=count($posts);ciCheck($service->enviar(4,$data)===$r && count($posts)===$n && $db->sentWrites===1,'Un envío, un historial y una nota, sin duplicados');
    $rejected=$data;unset($rejected['correo_id']);$rejected['idempotency_key']='central-rejected-draft';
    $saved=$service->guardarBorrador(4,$rejected);$rejected['correo_id']=$saved['correo_id'];$http=403;
    $r=$service->enviar(4,$rejected);$http=204;
    ciCheck(!$r['ok'] && $db->rows[$saved['correo_id']]['estado']==='BORRADOR' && $db->rows[$saved['correo_id']]['cuerpo']===$base['cuerpo'],'Rechazo central conserva borrador sin añadir una nota de envío inexistente');
    $http=503;$d=$base;$d['idempotency_key']='central-uncertain';$r=$sender->enviar($d);$n=count($posts);$http=204;
    ciCheck($r['estado_entrega']==='DESCONOCIDO','Resultado incierto identificado');$sender->enviar($d);
    ciCheck(count($posts)===$n,'Sin reintento ni cambio de cuenta después de incertidumbre');
    $getHttp=401;ciCheck(!$sender->prepararSalida($base['remitente'],4)['ok'] && count($posts)===$n,'Token rechazado bloquea sin transmitir');
    echo "OK: salida directa/central, contacto propio/ajeno, From y respuestas reales, confirmación, perfiles, destinos, adjuntos, borrador e historial sin duplicados. API y BD simuladas.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); }rmdir($work);
}
