<?php
// Administración central por SSH. Nunca se ejecuta como endpoint web.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');umask(0077);
require_once dirname(__DIR__).'/app/services/HostingerCorreoAdministracionService.php';
require_once __DIR__.'/diagnosticar_hostinger_comercial.php';

function hostingerGuardarPrivado(string $file,array $data,bool $createOnly=false): array
{
    if(!in_array($file,['hostinger_admin.local.php','hostinger_mail_pending.local.php'],true)) { throw new RuntimeException('HOSTINGER_ADMIN_ARCHIVO_INVALIDO'); }
    $root=realpath(dirname(__DIR__));
    $default=(PHP_OS_FAMILY==='Windows'?dirname($root,2):dirname($root)).'/impe-private';
    $dir=rtrim((string)(getenv('IMPE_PRIVATE_CONFIG_DIR')?:$default),'/\\');
    $normal=str_replace('\\','/',$dir);
    if((!str_starts_with($normal,'/') && !preg_match('/^[a-zA-Z]:\//D',$normal)) || preg_match('~(^|/)\.{1,2}(/|$)~',$normal)) { throw new RuntimeException('HOSTINGER_ADMIN_RUTA_PRIVADA'); }
    for($p=$dir;dirname($p)!==$p;$p=dirname($p)) { if(is_link($p)) { throw new RuntimeException('HOSTINGER_ADMIN_RUTA_PRIVADA'); } }
    $ancestor=$dir;while(!file_exists($ancestor) && dirname($ancestor)!==$ancestor) { $ancestor=dirname($ancestor); }
    $ancestor=realpath($ancestor);$documentRoot=trim((string)($_SERVER['DOCUMENT_ROOT']??''));
    foreach([$root,$documentRoot!==''?realpath($documentRoot):false] as $public) {
        if($ancestor && $public && str_starts_with(strtolower($ancestor.DIRECTORY_SEPARATOR),strtolower($public.DIRECTORY_SEPARATOR))) { throw new RuntimeException('HOSTINGER_ADMIN_RUTA_PRIVADA'); }
    }
    if(!is_dir($dir) && !mkdir($dir,0700,true)) { throw new RuntimeException('HOSTINGER_ADMIN_ESCRITURA_PRIVADA'); }
    $dir=realpath($dir);$path=$dir.'/'.$file;$lockPath=$dir.'/.impe-hostinger-admin.lock';
    if(is_link($path) || is_link($lockPath)) { throw new RuntimeException('HOSTINGER_ADMIN_RUTA_PRIVADA'); }
    $lock=fopen($lockPath,'c+b');if(!$lock) { throw new RuntimeException('HOSTINGER_ADMIN_ESCRITURA_PRIVADA'); }chmod($lockPath,0600);
    try {
        if(!flock($lock,LOCK_EX|LOCK_NB)) { throw new RuntimeException('HOSTINGER_ADMIN_CONFIG_OCUPADA'); }
        if($createOnly && file_exists($path)) { throw new RuntimeException('HOSTINGER_ADMIN_PENDIENTE_EXISTENTE_NO_REPETIR'); }
        $backup=null;
        if($file==='hostinger_admin.local.php' && is_file($path)) {
            $backup=$path.'.respaldo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            if(!copy($path,$backup) || !chmod($backup,0600) || hash_file('sha256',$path)!==hash_file('sha256',$backup)) { throw new RuntimeException('HOSTINGER_ADMIN_RESPALDO'); }
        }
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        try {
            $h=fopen($tmp,'xb');if(!$h) { throw new RuntimeException('HOSTINGER_ADMIN_ESCRITURA_PRIVADA'); }
            try {
                $text="<?php\n// Privado. No publicar.\nreturn ".var_export($data,true).";\n";
                if(!chmod($tmp,0600) || fwrite($h,$text)!==strlen($text) || !fflush($h)) { throw new RuntimeException('HOSTINGER_ADMIN_ESCRITURA_PRIVADA'); }
            } finally { fclose($h); }
            if(!rename($tmp,$path)) { throw new RuntimeException('HOSTINGER_ADMIN_ESCRITURA_PRIVADA'); }
        }
        finally { if(is_file($tmp)) { unlink($tmp); } }
        return ['ruta'=>$path,'respaldo'=>$backup];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function hostingerOrdenUnica(HostingerCorreoAdministracionService $admin,string $token): string
{
    $orders=$admin->ordenes($token);
    if(count($orders)!==1) { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_DOMINIO_NO_UNICA'); }
    $order=reset($orders);
    if($order['status']!=='active') { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_NO_ACTIVA'); }
    return $order['id'];
}

function hostingerPrepararTokenOrden(HostingerCorreoAdministracionService $admin,string $adminToken,string $confirmDomain): array
{
    if(!hash_equals(CorreoHostingerApiService::DOMINIO,$confirmDomain)) { throw new RuntimeException('HOSTINGER_ADMIN_CONFIRMACION_REQUERIDA'); }
    $order=hostingerOrdenUnica($admin,$adminToken);
    // Reserva persistente antes del POST: una respuesta incierta nunca causa otro token.
    hostingerGuardarPrivado('hostinger_mail_pending.local.php',['estado'=>'solicitud_iniciada','order_id'=>$order],true);
    $pending=$admin->crearTokenOrden($adminToken,$order,$order);
    hostingerGuardarPrivado('hostinger_mail_pending.local.php',$pending+['estado'=>'pendiente']);
    if(!$pending['has_all_mailboxes']) { throw new RuntimeException('HOSTINGER_ADMIN_ALCANCE_TOTAL_NO_CONFIRMADO'); }
    return ['orden'=>$order,'estado'=>'pendiente','nota'=>'Token nuevo guardado fuera de public_html, sin sustituir el token activo. Activar sólo tras validación.'];
}

function hostingerActivarTokenOrden(HostingerCorreoAdministracionService $admin,string $adminToken,CorreoHostingerApiService $mail): array
{
    $pending=impeLeerConfigPrivada('hostinger_mail_pending.local.php');
    if(($pending['estado']??'')!=='pendiente' || ($pending['has_all_mailboxes']??false)!==true) { throw new RuntimeException('HOSTINGER_ADMIN_PENDIENTE_NO_VALIDO'); }
    if(trim((string)getenv('HOSTINGER_MAIL_API_TOKEN'))!=='') { throw new RuntimeException('HOSTINGER_ADMIN_ACTUALIZAR_TOKEN_ENTORNO_PRIMERO'); }
    if((impeLeerConfigPrivada('mail_central.local.php')['activo']??true)!==true) { throw new RuntimeException('HOSTINGER_ADMIN_COMERCIAL_DESACTIVADO'); }
    $order=hostingerOrdenUnica($admin,$adminToken);
    if(!hash_equals($order,(string)($pending['order_id']??''))) { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_DISTINTA'); }
    $boxes=$mail->buzones((string)($pending['api_token']??''));
    if($mail->orden()!==$order) { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_DISTINTA'); }
    foreach($admin->buzones($adminToken,$order) as $address=>$box) {
        if($box['status']==='active' && (!isset($boxes[$address]) || !hash_equals($box['id'],$boxes[$address]))) { throw new RuntimeException('HOSTINGER_ADMIN_BUZONES_INCOMPLETOS'); }
    }
    $saved=guardarTokenHostingerComercial($pending['api_token'],static function()use($boxes){return $boxes;});
    unset($pending['api_token']);$pending['estado']='activado';hostingerGuardarPrivado('hostinger_mail_pending.local.php',$pending);
    return $saved;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    try {
        $admin=new HostingerCorreoAdministracionService();$mode=$argv[1]??'--plan';
        if($mode==='--guardar-admin') {
            $token=trim((string)getenv('IMPE_HOSTINGER_ADMIN_SETUP'));hostingerOrdenUnica($admin,$token);
            $r=hostingerGuardarPrivado('hostinger_admin.local.php',['api_token'=>$token]);
            echo "OK: credencial administrativa validada y guardada de forma privada.\n";
        } else {
            $config=$admin->configuracion();$r=[];
            if($mode==='--check-admin') { $order=hostingerOrdenUnica($admin,$config['token']);echo 'OK: Hostinger API reconoce la orden institucional '.$order.PHP_EOL; }
            elseif($mode==='--plan') {
                $mail=new CorreoHostingerApiService();$mc=$mail->configuracion();$report=$admin->diagnostico($config['token'],$mail,$mc['token']);
                foreach($report['ordenes'] as &$row) { unset($row['buzones']); }unset($row);
                echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
                echo "SOLO LECTURA. Crear otro token amplía acceso a todos los buzones actuales y futuros de esa orden, incluidas operaciones de lectura/gestión que permita Mail API. No se realizó esa operación.\n";
            } elseif($mode==='--preparar-token-orden' && ($argv[2]??'')==='--confirmar-dominio='.CorreoHostingerApiService::DOMINIO && count($argv)===3) {
                $r=hostingerPrepararTokenOrden($admin,$config['token'],CorreoHostingerApiService::DOMINIO);echo json_encode($r,JSON_UNESCAPED_UNICODE).PHP_EOL;
            } elseif($mode==='--activar-pendiente' && count($argv)===2) {
                $r=hostingerActivarTokenOrden($admin,$config['token'],new CorreoHostingerApiService());echo 'OK: token de orden activado; buzones verificados='.$r['buzones'].PHP_EOL;
            } else { throw new RuntimeException('HOSTINGER_ADMIN_MODO_INVALIDO'); }
        }
        if(!empty($r['respaldo'])) { echo 'Restaurar credencial anterior: cp -p -- '.escapeshellarg($r['respaldo']).' '.escapeshellarg($r['ruta']).PHP_EOL; }
        echo "Sin envíos de mensajes ni cambios de recuperación.\n";
    } catch(Throwable $e) {
        $code=preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'HOSTINGER_ADMIN_ERROR_CONFIG';
        fwrite(STDERR,'Código seguro: '.$code.". No se muestran credenciales. Ante un POST incierto, revisar los tokens en Hostinger antes de repetir.\n");exit(2);
    }
}
