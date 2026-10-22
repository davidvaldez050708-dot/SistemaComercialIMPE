<?php
// Diagnóstico y configuración exclusivamente comerciales. Ningún envío ni acceso a SMTP.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/app/services/CorreoHostingerApiService.php';

function guardarTokenHostingerComercial(string $token,?callable $probe=null): array
{
    if($token==='' || strlen($token)>16384 || preg_match('/[\x00-\x20\x7f]/',$token)) { throw new RuntimeException('HOSTINGER_TOKEN_AUSENTE'); }
    $api=new CorreoHostingerApiService();
    $boxes=$probe ? $probe($token) : $api->buzones($token);
    if(!$boxes) { throw new RuntimeException('HOSTINGER_SIN_BUZONES'); }
    $root=realpath(dirname(__DIR__));
    $dir=rtrim((string)(getenv('IMPE_PRIVATE_CONFIG_DIR')?:dirname($root).'/impe-private'),'/\\');
    $normal=str_replace('\\','/',$dir);
    if((!str_starts_with($normal,'/') && !preg_match('/^[a-zA-Z]:\//D',$normal)) || preg_match('~(^|/)\.{1,2}(/|$)~',$normal)) { throw new RuntimeException('HOSTINGER_RUTA_PRIVADA'); }
    for($p=$dir;dirname($p)!==$p;$p=dirname($p)) { if(is_link($p)) { throw new RuntimeException('HOSTINGER_RUTA_PRIVADA'); } }
    $ancestor=$dir;while(!file_exists($ancestor) && dirname($ancestor)!==$ancestor) { $ancestor=dirname($ancestor); }
    $ancestor=realpath($ancestor);$documentRoot=trim((string)($_SERVER['DOCUMENT_ROOT']??''));
    foreach([$root,$documentRoot!==''?realpath($documentRoot):false] as $public) {
        if($ancestor && $public && str_starts_with(strtolower($ancestor.DIRECTORY_SEPARATOR),strtolower($public.DIRECTORY_SEPARATOR))) { throw new RuntimeException('HOSTINGER_RUTA_PRIVADA'); }
    }
    if(!is_dir($dir) && !mkdir($dir,0700,true)) { throw new RuntimeException('HOSTINGER_ESCRITURA_PRIVADA'); }
    $dir=realpath($dir);$path=$dir.'/mail_central.local.php';$lockPath=$dir.'/.hostinger-comercial.lock';
    if(is_link($path) || is_link($lockPath)) { throw new RuntimeException('HOSTINGER_RUTA_PRIVADA'); }
    $lock=fopen($lockPath,'c+b');if(!$lock) { throw new RuntimeException('HOSTINGER_ESCRITURA_PRIVADA'); }chmod($lockPath,0600);
    try {
        if(!flock($lock,LOCK_EX|LOCK_NB)) { throw new RuntimeException('HOSTINGER_CONFIG_OCUPADA'); }
        $data=is_file($path)?require $path:impeLeerConfigPrivada('mail_central.local.php');
        if(!is_array($data)) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
        if(is_file($path) && ($data['transport']??'')==='hostinger_api' && ($data['activo']??true)===true
            && hash_equals((string)($data['api_token']??''),$token)) {
            return ['ruta'=>$path,'respaldo'=>null,'buzones'=>count($boxes),'sin_cambios'=>true];
        }
        $backup=null;
        if(is_file($path)) {
            $backup=$path.'.respaldo-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            if(!copy($path,$backup) || !chmod($backup,0600) || hash_file('sha256',$path)!==hash_file('sha256',$backup)) { throw new RuntimeException('HOSTINGER_RESPALDO'); }
        }
        $data=array_replace($data,['transport'=>'hostinger_api','activo'=>true,'api_token'=>$token]);
        $text="<?php\n// Configuración privada comercial. No publicar.\nreturn ".var_export($data,true).";\n";
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(8));
        try {
            $h=fopen($tmp,'xb');if(!$h) { throw new RuntimeException('HOSTINGER_ESCRITURA_PRIVADA'); }
            try { if(!chmod($tmp,0600) || fwrite($h,$text)!==strlen($text) || !fflush($h)) { throw new RuntimeException('HOSTINGER_ESCRITURA_PRIVADA'); } }
            finally { fclose($h); }
            if(!rename($tmp,$path)) { throw new RuntimeException('HOSTINGER_ESCRITURA_PRIVADA'); }
        } finally { if(is_file($tmp)) { unlink($tmp); } }
        return ['ruta'=>$path,'respaldo'=>$backup,'buzones'=>count($boxes)];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function diagnosticarHostingerComercial(CorreoHostingerApiService $api, string $remitente=''): array
{
    $config=$api->configuracion();$boxes=$api->buzones($config['token']);$d=$api->descubrimiento();
    $lines=['Fuente efectiva del token: '.$config['source'],
        'API autorizados: '.$d['total_api'].'; institucionales @'.CorreoHostingerApiService::DOMINIO.': '.$d['total_institucional'],
        'Consulta completa de GET /api/v1/me; sin caché local ni paginación en este endpoint.'];
    foreach(['allowed_senders','user_senders','role_senders'] as $field) {
        $lines[]='Restricción '.$field.': '.(array_key_exists($field,$config['policy'])?'configurada; revisar intersección con el usuario/rol':'ausente');
    }
    $accounts=impeLeerConfigPrivada('mail_accounts.local.php')['accounts']??[];
    if(!is_array($accounts)) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
    $lines[]='Restricciones previas en mail_accounts.local.php: '.count($accounts).' entradas (no se crean cuentas nuevas).';
    foreach(array_keys($boxes) as $email) { $lines[]='Buzón API: '.$email; }
    $lines[]='Si falta un buzón: autorizarlo en el token Mail API de la misma orden de Hostinger; compartir dominio no lo autoriza.';
    if($remitente!=='') {
        $remitente=strtolower(trim($remitente));
        if(!$api->institucional($remitente)) { $lines[]='Remitente solicitado: dominio/formato no institucional; rechazado.'; }
        else { $lines[]='Remitente solicitado: '.(isset($boxes[$remitente])?'devuelto por Hostinger; se aplican además restricciones del usuario/rol.':'NO devuelto por Hostinger; no se permite enviar.'); }
    }
    return $lines;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    ini_set('display_errors','0');$api=new CorreoHostingerApiService();
    try {
        $mode=$argv[1]??'--check';
        if($mode==='--guardar-token') {
            $r=guardarTokenHostingerComercial(trim((string)getenv('IMPE_HOSTINGER_TOKEN_SETUP')));
            echo 'OK: token validado y guardado fuera del sitio público; buzones='.$r['buzones'].PHP_EOL;
            if($r['respaldo']) { echo 'Restaurar configuración comercial: cp -p -- '.escapeshellarg($r['respaldo']).' '.escapeshellarg($r['ruta']).PHP_EOL; }
        } elseif($mode==='--check') {
            echo 'Versión comercial: '.CorreoHostingerApiService::VERSION.'; PHP '.PHP_VERSION.' SAPI '.PHP_SAPI.PHP_EOL;
            foreach(['hostinger_mail_config.php','mail_central.local.php','marketing_mail.local.php'] as $file) {
                $path=impeRutaConfigPrivada($file);
                echo $file.': '.($path!==null?'legible ('.(realpath(dirname($path))===realpath(dirname(__DIR__).'/config')?'config de compatibilidad':'directorio privado').')':'no encontrado').PHP_EOL;
            }
            foreach(['app/services/CorreoHostingerApiService.php','app/services/CorreoCuentaConectadaService.php','public/javascript/correos_marketing.js'] as $codeFile) {
                echo 'Código SHA256 '.$codeFile.': '.hash_file('sha256',dirname(__DIR__).'/'.$codeFile).PHP_EOL;
            }
            $sender='';
            if(isset($argv[2])) {
                if(!str_starts_with($argv[2],'--remitente=') || count($argv)>3) { throw new RuntimeException('HOSTINGER_MODO_INVALIDO'); }
                $sender=substr($argv[2],12);
            }
            foreach(diagnosticarHostingerComercial($api,$sender) as $line) { echo $line.PHP_EOL; }
        } else { throw new RuntimeException('HOSTINGER_MODO_INVALIDO'); }
        echo "Sin envío de mensajes ni cambios en recuperación, SMTP o base de datos.\n";
    } catch(Throwable $e) {
        $r=$api->fallo($e);fwrite(STDERR,$r['codigo_tecnico'].': '.$r['mensaje'].PHP_EOL);exit(2);
    }
}
