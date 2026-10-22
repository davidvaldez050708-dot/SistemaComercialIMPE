<?php
require_once dirname(__DIR__, 2) . '/config/private_config.php';

/** Transporte comercial aislado de SMTP, login y recuperación. */
final class CorreoHostingerApiService
{
    public const BASE = 'https://api.mail.hostinger.com';
    public const VERSION = 'hostinger-comercial-orden-v4';
    public const DOMINIO = 'rededucativamexico.org';
    private $request;
    private $descubrimiento = [];
    private $orden = null;
    public function __construct(?callable $request=null) { $this->request=$request; }

    public function configuracion(): array
    {
        $central=impeLeerConfigPrivada('mail_central.local.php');
        if(($central['activo']??true)!==true) { throw new RuntimeException('HOSTINGER_DESACTIVADO'); }
        $token=trim((string)getenv('HOSTINGER_MAIL_API_TOKEN'));
        $source='entorno HOSTINGER_MAIL_API_TOKEN';
        if($token==='' && ($central['transport']??'')==='hostinger_api') {
            $token=trim((string)($central['api_token']??''));$source='mail_central.local.php';
        }
        // Las configuraciones antiguas no se ejecutan si ya hay una credencial central.
        if($token==='') {
            $path=impeRutaConfigPrivada('hostinger_mail_config.php');
            if($path) { require_once $path; }
            if(defined('HOSTINGER_MAIL_API_TOKEN')) { $token=trim((string)HOSTINGER_MAIL_API_TOKEN);$source='hostinger_mail_config.php / constante HOSTINGER_MAIL_API_TOKEN'; }
        }
        if($token==='') {
            $legacy=impeLeerConfigPrivada('marketing_mail.local.php');
            $token=trim((string)($legacy['hostinger_mail_api_token']??''));$source='marketing_mail.local.php';
        }
        if($token==='' || strlen($token)>16384 || preg_match('/[\x00-\x20\x7f]/',$token)) { throw new RuntimeException('HOSTINGER_TOKEN_AUSENTE'); }
        $base=defined('HOSTINGER_MAIL_API_BASE_URL')?HOSTINGER_MAIL_API_BASE_URL:(getenv('HOSTINGER_MAIL_API_BASE_URL')?:self::BASE);
        if(rtrim((string)$base,'/')!==self::BASE) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
        return ['token'=>$token,'policy'=>$central,'source'=>$source];
    }

    public function buzones(string $token): array
    {
        $this->descubrimiento=[];
        $this->orden=null;
        // /me devuelve la colección completa: el contrato oficial no admite paginación aquí.
        $r=$this->solicitar('GET',self::BASE.'/api/v1/me',$token,null);
        if(empty($r['ok']) || (int)($r['codigo_http']??0)!==200) { throw new RuntimeException('HOSTINGER_CONSULTA_'.(int)($r['codigo_http']??0)); }
        $boxes=$r['json']['data']['mailboxes']??null;
        if(!is_array($boxes)) { throw new RuntimeException('HOSTINGER_RESPUESTA_INVALIDA'); }
        $order=$r['json']['data']['orderResourceId']??null;
        if(is_string($order) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$order)) { $this->orden=$order; }
        $out=[];$valid=[];$ids=[];
        foreach($boxes as $box) {
            if(!is_array($box)) { throw new RuntimeException('HOSTINGER_RESPUESTA_INVALIDA'); }
            $email=strtolower(trim((string)($box['address']??'')));$id=(string)($box['resourceId']??'');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$id)) { throw new RuntimeException('HOSTINGER_RESPUESTA_INVALIDA'); }
            if((isset($valid[$email]) && $valid[$email]!==$id) || (isset($ids[$id]) && $ids[$id]!==$email)) { throw new RuntimeException('HOSTINGER_RESPUESTA_INVALIDA'); }
            $valid[$email]=$id;$ids[$id]=$email;
            if($this->institucional($email)) { $out[$email]=$id; }
        }
        $this->descubrimiento=['version'=>self::VERSION,'dominio'=>self::DOMINIO,'total_api'=>count($valid),'total_institucional'=>count($out)];
        if(!$valid) { throw new RuntimeException('HOSTINGER_SIN_BUZONES'); }
        if(!$out) { throw new RuntimeException('HOSTINGER_SIN_BUZONES_INSTITUCIONALES'); }
        ksort($out,SORT_STRING);
        return $out;
    }

    public function descubrimiento(): array { return $this->descubrimiento; }
    public function orden(): ?string { return $this->orden; }
    public function institucional(string $email): bool
    {
        return filter_var($email,FILTER_VALIDATE_EMAIL)!==false && strtolower(substr(strrchr($email,'@'),1))===self::DOMINIO;
    }

    public function permitidos(int $user,array $identity): array
    {
        $a=$this->configuracion();$boxes=$this->buzones($a['token']);$policy=$a['policy'];$lists=[];
        foreach(['allowed_senders','user_senders','role_senders'] as $field) {
            if(array_key_exists($field,$policy) && !is_array($policy[$field])) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
        }
        if(array_key_exists('allowed_senders',$policy)) { $lists[]=$policy['allowed_senders']; }
        if(array_key_exists($user,$policy['user_senders']??[])) { $lists[]=$policy['user_senders'][$user]; }
        foreach([(string)($identity['rol_id']??''),(string)($identity['rol']??'')] as $role) {
            if($role!=='' && array_key_exists($role,$policy['role_senders']??[])) { $lists[]=$policy['role_senders'][$role]; }
        }
        foreach($lists as $list) {
            if(!is_array($list)) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
            $boxes=array_intersect_key($boxes,array_flip(array_map(static function($v){return strtolower(trim((string)$v));},$list)));
        }
        // Restricciones administrativas existentes: nunca eludir desactivación/propiedad.
        $accounts=impeLeerConfigPrivada('mail_accounts.local.php')['accounts']??[];
        if(!is_array($accounts)) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
        foreach($accounts as $key=>$entry) {
            if(!is_array($entry)) { continue; }
            $email=strtolower(trim((string)($entry['correo']??(is_string($key)?$key:''))));
            if(empty($entry['activo']) || !in_array($user,array_map('intval',(array)($entry['usuarios']??[])),true)) { unset($boxes[$email]); }
        }
        return ['account'=>['transport'=>'hostinger_api','api_token'=>$a['token'],'api_base_url'=>self::BASE],'boxes'=>$boxes,
            'central_preferido'=>strtolower(trim((string)($policy['from_address']??(defined('HOSTINGER_MAIL_FROM_ADDRESS')?HOSTINGER_MAIL_FROM_ADDRESS:(getenv('HOSTINGER_MAIL_FROM_ADDRESS')?:''))))),
            'diagnostico'=>$this->descubrimiento+['total_permitidos'=>count($boxes),'restricciones_locales'=>count($lists)>0 || count($accounts)>0]];
    }

    public function externo(string $email): bool { return preg_match('/@(gmail|googlemail|outlook|hotmail|live|msn|yahoo)\.[a-z.]+$/iD',$email)===1; }

    public function fallo(Throwable $e): array
    {
        $code=preg_match('/^HOSTINGER_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'HOSTINGER_CONFIG_INVALIDA';
        error_log('MAIL_COMMERCIAL code='.$code.' exception='.get_class($e));
        $messages=[
            'HOSTINGER_TOKEN_AUSENTE'=>'Falta el token central de Hostinger Mail API. El administrador debe configurar HOSTINGER_MAIL_API_TOKEN en hostinger_mail_config.php o api_token en mail_central.local.php.',
            'HOSTINGER_DESACTIVADO'=>'El envío comercial está desactivado en la configuración central.',
            'HOSTINGER_CONSULTA_401'=>'Hostinger rechazó el token de Mail API. Revisa su vigencia.',
            'HOSTINGER_CONSULTA_403'=>'El token no tiene autorización para consultar esos buzones Hostinger.',
            'HOSTINGER_CONSULTA_429'=>'Hostinger limitó temporalmente las consultas. Espera antes de volver a intentar.',
            'HOSTINGER_SIN_BUZONES'=>'El token no devuelve buzones autorizados. Revisa su orden y el alcance de buzones en Hostinger.',
            'HOSTINGER_SIN_BUZONES_INSTITUCIONALES'=>'El token es válido, pero Hostinger no devuelve buzones de @rededucativamexico.org. Revisa su orden y los buzones autorizados.',
            'HOSTINGER_SESION_INVALIDA'=>'La sesión no está autorizada para enviar.',
            'HOSTINGER_RESPUESTA_INVALIDA'=>'Hostinger devolvió una respuesta de buzones no válida.'
        ];
        return ['ok'=>false,'version'=>self::VERSION,'codigo_http'=>$code==='HOSTINGER_SESION_INVALIDA'?403:503,'codigo_tecnico'=>$code,
            'estado_entrega'=>'RECHAZADO','entrega_confirmada'=>false,'mensaje'=>$messages[$code]??'No fue posible validar Hostinger Mail API. Revisa el diagnóstico comercial del servidor.'];
    }

    public function solicitar(string $method,string $url,string $token,?array $payload): array
    {
        if($this->request) { return ($this->request)($method,$url,$token,$payload); }
        if(!function_exists('curl_init')) { throw new RuntimeException('HOSTINGER_CURL_AUSENTE'); }
        if($url!==self::BASE.'/api/v1/me' && !preg_match('~^https://api\.mail\.hostinger\.com/api/v1/mailboxes/[a-zA-Z0-9_-]{1,128}/send$~D',$url)) { throw new RuntimeException('HOSTINGER_CONFIG_INVALIDA'); }
        $c=curl_init($url);$body='';
        curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token],
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($c,$part)use(&$body){if(strlen($body)+strlen($part)>1048576)return 0;$body.=$part;return strlen($part);}]);
        if($payload!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR)); }
        try {
            $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
            error_log('MAIL_COMMERCIAL method='.$method.' http='.$status.' completed='.($ok!==false?'1':'0'));
            return ['ok'=>$ok!==false&&$status>=200&&$status<300,'codigo_http'=>$status,'incierto'=>$method==='POST'&&$ok===false,'json'=>json_decode($body,true)?:[]];
        } finally { curl_close($c); }
    }
}
