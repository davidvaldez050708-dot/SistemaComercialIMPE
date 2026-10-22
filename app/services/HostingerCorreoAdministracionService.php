<?php
require_once __DIR__.'/CorreoHostingerApiService.php';

/** Administración central sólo por CLI. El CRM web no necesita la credencial de hPanel. */
final class HostingerCorreoAdministracionService
{
    public const BASE='https://developers.hostinger.com';
    private $request;
    public function __construct(?callable $request=null)
    {
        if(PHP_SAPI!=='cli') { throw new RuntimeException('HOSTINGER_ADMIN_SOLO_CLI'); }
        $this->request=$request;
    }
    public function configuracion(): array
    {
        $token=trim((string)getenv('HOSTINGER_API_TOKEN'));$source='entorno HOSTINGER_API_TOKEN';
        if($token==='') { $a=impeLeerConfigPrivada('hostinger_admin.local.php');$token=trim((string)($a['api_token']??''));$source='hostinger_admin.local.php'; }
        $this->validarToken($token);
        return ['token'=>$token,'source'=>$source];
    }
    private function validarToken(string $token): void
    {
        if($token==='' || strlen($token)>16384 || preg_match('/[\x00-\x20\x7f]/',$token)) { throw new RuntimeException('HOSTINGER_ADMIN_TOKEN_AUSENTE'); }
    }
    private function id(string $id): string
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$id)) { throw new RuntimeException('HOSTINGER_ADMIN_ID_INVALIDO'); }
        return $id;
    }
    private function coleccion(string $path,array $query,string $token): array
    {
        $rows=[];
        for($page=1;$page<=100;$page++) {
            $r=$this->solicitar('GET',$path.'?'.http_build_query($query+['page'=>$page,'per_page'=>100],'','&',PHP_QUERY_RFC3986),$token);
            $data=$r['data']??null;$meta=$r['meta']??null;
            if(!is_array($data) || !array_is_list($data) || !is_array($meta) || (int)($meta['current_page']??0)!==$page
                || !is_numeric($meta['total']??null) || (int)$meta['total']<0 || (int)$meta['total']>10000
                || !is_numeric($meta['per_page']??null) || (int)$meta['per_page']<1) { throw new RuntimeException('HOSTINGER_ADMIN_RESPUESTA_INVALIDA'); }
            foreach($data as $row) { if(!is_array($row)) { throw new RuntimeException('HOSTINGER_ADMIN_RESPUESTA_INVALIDA'); }$rows[]=$row; }
            if(count($rows)>10000) { throw new RuntimeException('HOSTINGER_ADMIN_LIMITE'); }
            if($page*(int)$meta['per_page']>=(int)$meta['total']) {
                if(count($rows)!==(int)$meta['total']) { throw new RuntimeException('HOSTINGER_ADMIN_PAGINACION_INCOMPLETA'); }
                return $rows;
            }
            if(!$data) { throw new RuntimeException('HOSTINGER_ADMIN_PAGINACION_INCOMPLETA'); }
        }
        throw new RuntimeException('HOSTINGER_ADMIN_LIMITE');
    }
    public function ordenes(string $token): array
    {
        $out=[];
        foreach($this->coleccion('/api/mail/v1/orders',['domain'=>CorreoHostingerApiService::DOMINIO],$token) as $r) {
            if(strtolower((string)($r['domain']['name']??''))!==CorreoHostingerApiService::DOMINIO) { throw new RuntimeException('HOSTINGER_ADMIN_DOMINIO_DISTINTO'); }
            $id=$this->id((string)($r['id']??''));$out[$id]=['id'=>$id,'domain'=>CorreoHostingerApiService::DOMINIO,'status'=>(string)($r['status']??'')];
        }
        return $out;
    }
    public function buzones(string $token,string $order): array
    {
        $out=[];$api=new CorreoHostingerApiService();$ids=[];
        foreach($this->coleccion('/api/mail/v1/orders/'.$this->id($order).'/mailboxes',['sort'=>'address'],$token) as $r) {
            $email=strtolower(trim((string)($r['address']??'')));$id=$this->id((string)($r['id']??''));
            if(!$api->institucional($email) || (isset($out[$email]) && $out[$email]['id']!==$id) || (isset($ids[$id]) && $ids[$id]!==$email)) { throw new RuntimeException('HOSTINGER_ADMIN_RESPUESTA_INVALIDA'); }
            $out[$email]=['id'=>$id,'status'=>(string)($r['status']??'')];$ids[$id]=$email;
        }
        ksort($out,SORT_STRING);return $out;
    }
    public function alcances(string $token,string $order): array
    {
        $out=[];
        foreach($this->coleccion('/api/mail/v1/api-tokens',['order_id'=>$this->id($order)],$token) as $r) {
            if(($r['order_id']??null)!==$order || !is_bool($r['scope']['has_all_mailboxes']??null)) { throw new RuntimeException('HOSTINGER_ADMIN_RESPUESTA_INVALIDA'); }
            $out[]=['id'=>$this->id((string)($r['id']??'')),'todos_actuales_y_futuros'=>$r['scope']['has_all_mailboxes']];
        }
        return $out;
    }
    public function diagnostico(string $admin,CorreoHostingerApiService $mail,string $mailToken): array
    {
        $boxes=$mail->buzones($mailToken);$mailOrder=$mail->orden();
        if($mailOrder===null) { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_MAIL_AUSENTE'); }
        $orders=$this->ordenes($admin);$result=[];
        foreach($orders as $id=>$order) {
            $actual=$this->buzones($admin,$id);$scopes=$this->alcances($admin,$id);$entries=[];
            foreach($actual as $address=>$box) {
                $enabled=$id===$mailOrder && isset($boxes[$address]) && hash_equals($box['id'],$boxes[$address]);
                $entries[]=['address'=>$address,'status'=>$box['status'],'from_mail_api'=>$enabled];
            }
            $result[]=$order+['misma_orden_mail'=>$id===$mailOrder,'buzones_administrativos'=>count($actual),'buzones'=>$entries,
                'tokens_de_orden'=>count($scopes),'tokens_alcance_total'=>count(array_filter($scopes,static function($s){return $s['todos_actuales_y_futuros'];}))];
        }
        return ['dominio'=>CorreoHostingerApiService::DOMINIO,'orden_mail_api'=>$mailOrder,'buzones_mail_api'=>count($boxes),'ordenes'=>$result,
            'nota'=>'Existencia administrativa no autoriza From ni acredita propiedad del usuario. Sólo Mail API y los permisos del CRM autorizan el envío. Los alcances listados no identifican cuál secreto está instalado.'];
    }
    public function crearTokenOrden(string $token,string $order,string $confirmar): array
    {
        if(!hash_equals($order,$confirmar)) { throw new RuntimeException('HOSTINGER_ADMIN_CONFIRMACION_REQUERIDA'); }
        $orders=$this->ordenes($token);
        if(!isset($orders[$order]) || $orders[$order]['status']!=='active') { throw new RuntimeException('HOSTINGER_ADMIN_ORDEN_NO_ACTIVA'); }
        $r=$this->solicitar('POST','/api/mail/v1/orders/'.$this->id($order).'/api-tokens',$token,
            ['name'=>'CRM IMPE - orden institucional','scope'=>['has_all_mailboxes'=>true]]);
        // El recurso oficial devuelve token en claro una sola vez. Nunca se imprime.
        $a=is_array($r['data']??null)?$r['data']:$r;
        $secret=(string)($a['token']??'');$this->validarToken($secret);
        return ['api_token'=>$secret,'token_id'=>$this->id((string)($a['id']??'')),'order_id'=>$order,
            'has_all_mailboxes'=>($a['scope']['has_all_mailboxes']??false)===true];
    }
    public function solicitar(string $method,string $path,string $token,?array $payload=null): array
    {
        $this->validarToken($token);
        $parts=parse_url($path);$p=$parts['path']??'';
        $valid=$method==='GET' && ($p==='/api/mail/v1/orders' || $p==='/api/mail/v1/api-tokens' || preg_match('~^/api/mail/v1/orders/[a-zA-Z0-9_-]{1,128}/mailboxes$~D',$p));
        $valid=$valid || ($method==='POST' && preg_match('~^/api/mail/v1/orders/[a-zA-Z0-9_-]{1,128}/api-tokens$~D',$p));
        if(!$valid || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment'])) { throw new RuntimeException('HOSTINGER_ADMIN_ENDPOINT_INVALIDO'); }
        if($this->request) { $r=($this->request)($method,self::BASE.$path,$token,$payload); }
        else {
            if(!function_exists('curl_init')) { throw new RuntimeException('HOSTINGER_ADMIN_CURL_AUSENTE'); }
            $c=curl_init(self::BASE.$path);$body='';
            curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token],
                CURLOPT_USERAGENT=>'CRM-IMPE-Hostinger-Admin/1.0',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($c,$s)use(&$body){if(strlen($body)+strlen($s)>2097152)return 0;$body.=$s;return strlen($s);}]);
            if($payload!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR)); }
            try { $ok=curl_exec($c);$r=['ok'=>$ok!==false,'http'=>(int)curl_getinfo($c,CURLINFO_HTTP_CODE),'json'=>json_decode($body,true)]; }
            finally { curl_close($c); }
        }
        $status=(int)($r['http']??0);
        if(empty($r['ok']) || $status<200 || $status>=300) { throw new RuntimeException('HOSTINGER_ADMIN_HTTP_'.$status.($method==='POST'?'_NO_REPETIR_SIN_REVISAR':'')); }
        if(!is_array($r['json']??null)) { throw new RuntimeException('HOSTINGER_ADMIN_RESPUESTA_INVALIDA'); }
        return $r['json'];
    }
}
