<?php
declare(strict_types=1);

/** Transporte común del SDK PHP. No modifica el SDK ni controla audio/SIP. */
class TelefoniaZadarmaApi extends \Zadarma_API\Api
{
    private string $impeKey;
    private string $impeUrl;
    private $impeTransport;
    private ?string $impeCacheDirectory;
    private int $impeCode=0;
    private array $impeLimits=[];
    private static ?float $impeDeadline=null;

    public function __construct($key,$secret,$isSandbox=false,?callable $transport=null,?string $cacheDirectory=null)
    {
        parent::__construct($key,$secret,$isSandbox);
        $this->impeKey=(string)$key;
        $this->impeUrl=$isSandbox?self::SANDBOX_URL:self::PROD_URL;
        $this->impeTransport=$transport;
        $this->impeCacheDirectory=$cacheDirectory;
    }

    public function getHttpCode(){return $this->impeCode;}
    public function getLimits(){return $this->impeLimits;}

    /** Datos auxiliares derivados de evidencia; la autorización se relee antes de usarlos. */
    public function cachePrivada(string $scope,string $identity,?array $value=null):?array
    {
        if($scope!=='audio-transferido'||!preg_match('/^[a-f0-9]{64}$/D',$identity))
            throw new InvalidArgumentException('Ámbito de caché telefónica inválido.');
        $account=hash('sha256',$this->impeUrl.'|'.$this->impeKey);
        $handle=$this->impeLock($this->impeDirectory().'/'.$account.'-'.$scope.'-'.$identity.'.json',$value!==null);
        if($handle===null)return null;
        try{
            if($value!==null){
                if(strlen(json_encode($value,JSON_THROW_ON_ERROR))>1048576)
                    throw new InvalidArgumentException('Evidencia auxiliar demasiado grande.');
                $this->impeWrite($handle,['until'=>time()+3600,'value'=>$value]);return $value;
            }
            $cached=$this->impeRead($handle);
            return (int)($cached['until']??0)>time()&&is_array($cached['value']??null)?$cached['value']:null;
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }

    private function impeDirectory():string
    {
        $directory=$this->impeCacheDirectory;
        if($directory===null){
            $config=function_exists('impeRutaConfigPrivada')?impeRutaConfigPrivada('zadarma_config.php'):null;
            if(!$config)throw new RuntimeException('No está disponible el almacenamiento privado de telefonía.');
            $directory=dirname($config).'/telefonia-provider-cache';
        }
        if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de telefonía.');
        return $directory;
    }

    public function call($method,$params=[],$requestType='get',$format='json')
    {
        if(!is_string($method)||!preg_match('#^/v1/[A-Za-z0-9_/-]+$#D',$method)||
            !is_array($params)||array_filter($params,'is_object'))
            throw new InvalidArgumentException('Consulta de telefonía inválida.');
        $type=strtoupper((string)$requestType);
        if(!in_array($type,['GET','POST','PUT','DELETE'],true))throw new InvalidArgumentException('Método de telefonía inválido.');
        $now=microtime(true);
        self::$impeDeadline??=min($now+8,(float)($_SERVER['REQUEST_TIME_FLOAT']??$now)+10);
        $remaining=(int)floor((self::$impeDeadline-$now)*1000);
        if($remaining<50)throw new RuntimeException('Terminó el plazo de consulta al proveedor. La evidencia sigue pendiente.');
        $params['format']=$format;
        // Firma idéntica a Client::getAuthHeader del SDK instalado. El secreto
        // permanece en encodeSignature; no se publica ni se registra en logs.
        $signed=$params;ksort($signed);
        $query=http_build_query($signed,'','&',PHP_QUERY_RFC1738);
        $authorization=$this->impeKey.':'.$this->encodeSignature($method.$query.md5($query));
        $body=http_build_query($params,'','&',PHP_QUERY_RFC1738);
        $request=['url'=>$this->impeUrl.$method.($type==='GET'?'?'.$body:''),
            'method'=>$type,'headers'=>['Authorization: '.$authorization],
            'body'=>$type==='GET'?null:$body,'timeout_ms'=>$remaining,
            'connect_timeout_ms'=>min(3000,$remaining),'verify_peer'=>true,'verify_host'=>2];
        $wire=$this->impeCoordinated($method,$type,$query,$request);
        if(!is_array($wire)||!is_string($wire['body']??null)||strlen($wire['body'])>4194304)
            throw new RuntimeException('Respuesta de telefonía incompleta.');
        $this->impeCode=(int)($wire['status']??0);$this->impeLimits=[];
        foreach((array)($wire['headers']??[]) as $name=>$value){
            if(preg_match('/^X-RateLimit-([a-z]+)$/i',(string)$name,$match)&&ctype_digit((string)$value))
                $this->impeLimits[$match[1]]=(int)$value;
        }
        if($this->impeCode<200||$this->impeCode>=300)
            throw new RuntimeException('El proveedor no pudo confirmar la consulta de telefonía.', $this->impeCode);
        return $wire['body'];
    }

    /** Caché exacta y presupuesto compartidos; nunca ampliar intervalos ni omitir autorización. */
    private function impeCoordinated(string $method,string $type,string $query,array $request):array
    {
        // Las pruebas de firma aisladas no necesitan disco. Los tests de
        // coordinación inyectan explícitamente su directorio sintético.
        if($this->impeTransport && $this->impeCacheDirectory===null)return ($this->impeTransport)($request);
        $directory=$this->impeDirectory();
        $account=hash('sha256',$this->impeUrl.'|'.$this->impeKey);
        $statistics=str_starts_with($method,'/v1/statistics/');
        $record=$method==='/v1/pbx/record/request/';
        $cacheable=$type==='GET'&&($statistics||$record);
        $handle=null;
        try{
            $path=null;
            if($cacheable){
                $path=$directory.'/'.$account.'-'.hash('sha256',$type.'|'.$method.'|'.$query).'.json';
                $read=$this->impeLock($path,false);
                if($read!==null){
                    try{$cached=$this->impeRead($read);}
                    finally{flock($read,LOCK_UN);fclose($read);}
                    if((int)($cached['until']??0)>time()&&is_array($cached['wire']??null))return $cached['wire'];
                }
            }
            // Reservar el cupo antes de liberar el candado: entre procesos no
            // se duplica el último cupo. No se mantiene durante la petición HTTP.
            $budget=$this->impeLock($directory.'/'.$account.'-budget.json');
            try{
                $now=microtime(true);$state=$this->impeRead($budget);
                $recent=static fn($times)=>array_values(array_filter((array)$times,static fn($t)=>is_numeric($t)&&$t>$now-60));
                $all=$recent($state['all']??[]);$stats=$recent($state['statistics']??[]);
                if((float)($state['pause_until']??0)>$now || count($all)>=100 || ($statistics&&count($stats)>=3))
                    throw new RuntimeException('El proveedor tiene consultas pendientes. Se conservará la evidencia para reintentar.',429);
                if($path!==null){
                    // Crear la reserva de consulta sólo si queda presupuesto.
                    // Un parámetro distinto rechazado no genera otro archivo vacío.
                    $handle=$this->impeLock($path);
                    $cached=$this->impeRead($handle);
                    if((int)($cached['until']??0)>time()&&is_array($cached['wire']??null))return $cached['wire'];
                }
                $all[]=$now;if($statistics)$stats[]=$now;
                $this->impeWrite($budget,['all'=>$all,'statistics'=>$stats]);
            }finally{flock($budget,LOCK_UN);fclose($budget);}
            $wire=$this->impeTransport?($this->impeTransport)($request):$this->impeCurl($request);
            if((int)($wire['status']??0)===429){
                $budget=$this->impeLock($directory.'/'.$account.'-budget.json');
                try{$state=$this->impeRead($budget);$state['pause_until']=microtime(true)+60;$this->impeWrite($budget,$state);}
                finally{flock($budget,LOCK_UN);fclose($budget);}
            }
            if($handle && (int)($wire['status']??0)===200 && is_string($wire['body']??null) && strlen($wire['body'])<=4194304){
                $json=json_decode($wire['body'],true);
                // Sólo respuestas JSON correctas; nunca cachear HTML de un proxy
                // ni errores como si acreditaran una llamada o una grabación.
                if(is_array($json)&&($json['status']??'')==='success'){
                    parse_str($query,$parameters);
                    $ttl=$statistics?20:min(30,max(0,(int)($parameters['lifetime']??300)-5));
                    $this->impeWrite($handle,['until'=>time()+$ttl,'wire'=>$wire]);
                }
            }
            return $wire;
        }finally{if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}
    }

    private function impeLock(string $path,bool $write=true)
    {
        if(!$write&&!is_file($path))return null;
        $handle=@fopen($path,$write?'c+b':'rb');
        if($handle===false)throw new RuntimeException('No se pudo reservar la consulta telefónica.');
        if($write)@chmod($path,0600);
        if(!flock($handle,($write?LOCK_EX:LOCK_SH)|LOCK_NB)){fclose($handle);throw new RuntimeException('Esta consulta telefónica ya se está comprobando.');}
        return $handle;
    }
    private function impeRead($handle):array
    {
        rewind($handle);$raw=stream_get_contents($handle,4500001);
        if(!is_string($raw)||strlen($raw)>4500000)return [];
        $data=json_decode($raw,true);return is_array($data)?$data:[];
    }
    private function impeWrite($handle,array $data):void
    {
        $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if(!ftruncate($handle,0)||fwrite($handle,$raw)!==strlen($raw)||!fflush($handle))
            throw new RuntimeException('No se pudo conservar el estado de la consulta telefónica.');
    }

    private function impeCurl(array $request): array
    {
        $headers=[];$body='';$curl=curl_init();
        if($curl===false)throw new RuntimeException('No fue posible preparar la consulta telefónica.');
        $options=[CURLOPT_URL=>$request['url'],CURLOPT_CUSTOMREQUEST=>$request['method'],
            CURLOPT_CONNECTTIMEOUT_MS=>$request['connect_timeout_ms'],CURLOPT_TIMEOUT_MS=>$request['timeout_ms'],
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$request['headers'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int{
                if(str_starts_with($line,'HTTP/'))$headers=[];
                elseif(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[trim($key)]=trim($value);}
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>4194304)return 0;
                $body.=$chunk;return strlen($chunk);
            }];
        if($request['body']!==null)$options[CURLOPT_POSTFIELDS]=$request['body'];
        try{
            curl_setopt_array($curl,$options);
            if(curl_exec($curl)===false)throw new RuntimeException('La consulta al proveedor no terminó dentro de su plazo.');
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'headers'=>$headers,'body'=>$body];
        }finally{curl_close($curl);}
    }
}
