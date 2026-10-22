<?php
require dirname(__DIR__).'/tools/hostinger_orden_correo.php';
function atCheck($ok,string $label): void { if(!$ok) { throw new RuntimeException($label); } }
$dir=sys_get_temp_dir().'/impe-admin-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
putenv('IMPE_PRIVATE_CONFIG_DIR='.$dir);putenv('HOSTINGER_API_TOKEN');putenv('HOSTINGER_MAIL_API_TOKEN');
$posts=0;$readPages=[];$adminHttp=200;$postUncertain=false;
$admin=new HostingerCorreoAdministracionService(static function($method,$url,$token,$payload)use(&$posts,&$readPages,&$adminHttp,&$postUncertain){
    atCheck($token==='ADMIN_QA','Sólo token administrativo en Hostinger API');atCheck(str_starts_with($url,'https://developers.hostinger.com/api/mail/v1/'),'Base administrativa oficial');
    $p=parse_url($url);parse_str($p['query']??'',$q);$page=(int)($q['page']??1);
    if($method==='POST') {
        atCheck($p['path']==='/api/mail/v1/orders/ORqa/api-tokens' && $payload['scope']===['has_all_mailboxes'=>true],'Creación explícita del alcance completo en la orden verificada');$posts++;
        if($postUncertain) { return ['ok'=>false,'http'=>0,'json'=>null]; }
        return ['ok'=>true,'http'=>201,'json'=>['id'=>'token-new','token'=>'MAIL_NEW_QA','scope'=>['has_all_mailboxes'=>true]]];
    }
    if($adminHttp!==200) { return ['ok'=>false,'http'=>$adminHttp,'json'=>['error'=>'No imprimir respuesta del proveedor']]; }
    $data=[];$total=1;$per=100;
    if($p['path']==='/api/mail/v1/orders') {
        atCheck(($q['domain']??'')===CorreoHostingerApiService::DOMINIO,'Filtro institucional oficial');
        $data=[['id'=>'ORqa','status'=>'active','domain'=>['name'=>CorreoHostingerApiService::DOMINIO]]];
    } elseif(str_ends_with($p['path'],'/mailboxes')) {
        $readPages[]=$page;$total=2;$per=1;
        $data=[['id'=>$page===1?'ACuno':'ACdos','address'=>$page===1?'qa.uno@rededucativamexico.org':'qa.dos@rededucativamexico.org','status'=>'active']];
    } elseif($p['path']==='/api/mail/v1/api-tokens') {
        atCheck(($q['order_id']??'')==='ORqa','Filtro oficial de tokens por orden');$total=2;
        $data=[['id'=>'token-one','order_id'=>'ORqa','scope'=>['has_all_mailboxes'=>false]],['id'=>'token-two','order_id'=>'ORqa','scope'=>['has_all_mailboxes'=>false]]];
    } else { throw new RuntimeException('Endpoint no esperado'); }
    return ['ok'=>true,'http'=>200,'json'=>['data'=>$data,'meta'=>['current_page'=>$page,'per_page'=>$per,'total'=>$total]]];
});
$newComplete=true;$newOrder='ORqa';
$mail=new CorreoHostingerApiService(static function($method,$url,$token,$payload)use(&$newComplete,&$newOrder){
    atCheck($method==='GET' && $url===CorreoHostingerApiService::BASE.'/api/v1/me','Sólo lectura en Mail API durante la autorización');
    atCheck(in_array($token,['MAIL_OLD_QA','MAIL_NEW_QA'],true),'No mezclar token hPanel con Mail API');
    $boxes=[['address'=>'qa.uno@rededucativamexico.org','resourceId'=>'ACuno']];
    if($token==='MAIL_NEW_QA' && $newComplete) { $boxes[]=['address'=>'qa.dos@rededucativamexico.org','resourceId'=>'ACdos']; }
    return ['ok'=>true,'codigo_http'=>200,'json'=>['data'=>['orderResourceId'=>$token==='MAIL_NEW_QA'?$newOrder:'ORqa','mailboxes'=>$boxes]]];
});
try {
    hostingerGuardarPrivado('hostinger_admin.local.php',['api_token'=>'ADMIN_QA']);
    atCheck($admin->configuracion()['token']==='ADMIN_QA','Token administrativo separado y privado');
    $original=['transport'=>'hostinger_api','activo'=>true,'api_token'=>'MAIL_OLD_QA','allowed_senders'=>['qa.uno@rededucativamexico.org']];
    file_put_contents($dir.'/mail_central.local.php','<?php return '.var_export($original,true).';');$old=file_get_contents($dir.'/mail_central.local.php');
    file_put_contents($dir.'/password_reset_mail.local.php','<?php // FICTICIO NO LEER');$reset=hash_file('sha256',$dir.'/password_reset_mail.local.php');
    $r=$admin->diagnostico('ADMIN_QA',$mail,'MAIL_OLD_QA');
    atCheck($r['buzones_mail_api']===1 && $r['ordenes'][0]['buzones_administrativos']===2 && $r['ordenes'][0]['tokens_alcance_total']===0,'Distingue existencia de alcance de envío');
    $byAddress=array_column($r['ordenes'][0]['buzones'],null,'address');
    atCheck($byAddress['qa.dos@rededucativamexico.org']['from_mail_api']===false && in_array(2,$readPages,true),'Incluye páginas adicionales y buzón ausente de Mail API');
    atCheck(!str_contains(json_encode($r),'ADMIN_QA') && !str_contains(json_encode($r),'MAIL_OLD_QA') && $posts===0,'Diagnóstico sin secretos ni mutaciones');
    try {hostingerPrepararTokenOrden($admin,'ADMIN_QA','otro.test');throw new LogicException('Confirmación omitida');} catch(RuntimeException $e) {atCheck($posts===0,'Sin crear token para un dominio no confirmado');}
    hostingerPrepararTokenOrden($admin,'ADMIN_QA',CorreoHostingerApiService::DOMINIO);
    atCheck($posts===1 && file_get_contents($dir.'/mail_central.local.php')===$old,'Token pendiente no sobrescribe el activo');
    try {hostingerPrepararTokenOrden($admin,'ADMIN_QA',CorreoHostingerApiService::DOMINIO);throw new LogicException('Creación repetida');} catch(RuntimeException $e) {atCheck($posts===1,'No repetir creación tras reserva persistente');}
    $newComplete=false;
    try {hostingerActivarTokenOrden($admin,'ADMIN_QA',$mail);throw new LogicException('Activó buzones incompletos');} catch(RuntimeException $e) {atCheck($e->getMessage()==='HOSTINGER_ADMIN_BUZONES_INCOMPLETOS' && file_get_contents($dir.'/mail_central.local.php')===$old,'Un alcance incompleto no sustituye la credencial activa');}
    $newComplete=true;$newOrder='ORotro';
    try {hostingerActivarTokenOrden($admin,'ADMIN_QA',$mail);throw new LogicException('Activó otra orden');} catch(RuntimeException $e) {atCheck($e->getMessage()==='HOSTINGER_ADMIN_ORDEN_DISTINTA' && file_get_contents($dir.'/mail_central.local.php')===$old,'Una orden ajena no sustituye la credencial activa');}
    $newOrder='ORqa';
    $saved=hostingerActivarTokenOrden($admin,'ADMIN_QA',$mail);$new=impeLeerConfigPrivada('mail_central.local.php');
    atCheck($new['api_token']==='MAIL_NEW_QA' && $new['allowed_senders']===$original['allowed_senders'] && file_get_contents($saved['respaldo'])===$old,'Activación validada, restricciones conservadas y respaldo exacto');
    atCheck(!isset(impeLeerConfigPrivada('hostinger_mail_pending.local.php')['api_token']),'El pendiente activado no conserva otra copia del secreto');
    unlink($dir.'/hostinger_mail_pending.local.php');$postUncertain=true;
    try {hostingerPrepararTokenOrden($admin,'ADMIN_QA',CorreoHostingerApiService::DOMINIO);throw new LogicException('POST incierto aceptado');} catch(RuntimeException $e) {atCheck($e->getMessage()==='HOSTINGER_ADMIN_HTTP_0_NO_REPETIR_SIN_REVISAR','No se repite un POST de resultado incierto');}
    $beforeRetry=$posts;
    try {hostingerPrepararTokenOrden($admin,'ADMIN_QA',CorreoHostingerApiService::DOMINIO);throw new LogicException('Reintentó POST incierto');} catch(RuntimeException $e) {atCheck($posts===$beforeRetry && impeLeerConfigPrivada('hostinger_mail_pending.local.php')['estado']==='solicitud_iniciada','Reserva persistente protege contra creación duplicada');}
    atCheck(hash_file('sha256',$dir.'/password_reset_mail.local.php')===$reset,'Credencial de recuperación intacta');
    $adminHttp=403;
    try {$admin->ordenes('ADMIN_QA');throw new LogicException('Aceptó 403');} catch(RuntimeException $e) {atCheck($e->getMessage()==='HOSTINGER_ADMIN_HTTP_403','Error seguro sin cuerpo del proveedor');}
    putenv('IMPE_PRIVATE_CONFIG_DIR='.dirname(__DIR__).'/tmp/privado-no-permitido');
    try {hostingerGuardarPrivado('hostinger_admin.local.php',['api_token'=>'NO_ESCRIBIR']);throw new LogicException('Escribió en webroot');} catch(RuntimeException $e) {atCheck($e->getMessage()==='HOSTINGER_ADMIN_RUTA_PRIVADA','No se escriben credenciales dentro del sitio');}
    echo "OK: dos tokens separados, endpoints oficiales, paginación, pertenencia/From, plan de lectura, creación explícita única, activación validada, respaldo y recuperación intacta. Todo simulado.\n";
} finally {
    putenv('IMPE_PRIVATE_CONFIG_DIR');
    foreach(glob($dir.'/*')?:[] as $p) { unlink($p); }foreach(glob($dir.'/.*')?:[] as $p) { if(is_file($p)) { unlink($p); } }rmdir($dir);
}
