<?php
declare(strict_types=1);

/** Medición temporal por fases; no registra identidad, parámetros ni SQL. */
final class ImpePerfilPeticion
{
    private float $inicio;
    private float $ultimo;
    private array $fases=[];
    private array $mysql=[];
    private string $privado;
    private string $ruta;
    private string $metodo;
    private bool $finalizado=false;

    public static function iniciar(string $sitio): ?self
    {
        $privado=dirname($sitio).'/impe-private';
        $flag=$privado.'/perfil-peticiones-activo.json';
        if(!is_file($flag)||is_link($flag))return null;
        $raw=@file_get_contents($flag,false,null,0,1024);
        $datos=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($datos))return null;
        $vence=(int)($datos['hasta']??0);$ahora=time();
        if(($datos['version']??null)!==1||$vence<=$ahora||$vence>(int)@filemtime($flag)+3660)return null;
        return new self($privado);
    }

    private function __construct(string $privado)
    {
        $this->inicio=$this->ultimo=microtime(true);$this->privado=$privado;
        $this->ruta=self::nombre($_GET['controller']??'login').'.'.self::nombre($_GET['action']??'mostrarLogin');
        $this->metodo=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?'POST':'GET';
        $this->mysql=self::estadisticas();
        register_shutdown_function(function():void{$this->terminar();});
    }

    private static function nombre($valor): string
    {
        return is_string($valor)&&preg_match('/^[a-zA-Z0-9_]{1,70}$/D',$valor)?$valor:'otra';
    }

    private static function estadisticas(): array
    {
        return function_exists('mysqli_get_client_stats')?(mysqli_get_client_stats()?:[]):[];
    }

    public function marca(string $fase): void
    {
        if(!in_array($fase,['sesion','config','autorizacion','controlador','despacho'],true))return;
        $ahora=microtime(true);$ms=round(max(0,$ahora-$this->ultimo)*1000,2);
        $this->fases[$fase]=$ms;$this->ultimo=$ahora;
        if(!headers_sent())header('Server-Timing: impe_'.$fase.';dur='.number_format($ms,2,'.',''),false);
    }

    public function terminar(): void
    {
        if($this->finalizado)return;$this->finalizado=true;
        $ahora=microtime(true);$total=round(max(0,$ahora-$this->inicio)*1000,2);
        $fase=isset($this->fases['despacho'])?'accion':'salida_anticipada';
        $this->fases[$fase]=round(max(0,$ahora-$this->ultimo)*1000,2);
        $stats=self::estadisticas();$delta=fn($key)=>max(0,(int)($stats[$key]??0)-(int)($this->mysql[$key]??0));
        $row=['utc'=>gmdate('c'),'ruta'=>$this->ruta,'metodo'=>$this->metodo,'http'=>http_response_code()?:200,
            'total_ms'=>$total,'fases_ms'=>$this->fases,'sql_envios'=>$delta('com_query')+$delta('com_stmt_execute'),
            'conexiones'=>$delta('connect_success'),'reutilizaciones'=>$delta('connection_reused'),
            'admision_env'=>(bool)getenv('IMPE_DB_ADMISION_DIR')];
        $path=$this->privado.'/perfil-peticiones.jsonl';
        if(is_link($path))return;
        $file=@fopen($path,'c+b');if(!$file)return;
        try{
            if(!@flock($file,LOCK_EX|LOCK_NB))return;
            @chmod($path,0600);$stat=fstat($file);
            if(($stat['size']??0)>1048576)ftruncate($file,0);
            fseek($file,0,SEEK_END);fwrite($file,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            fflush($file);flock($file,LOCK_UN);
        }finally{fclose($file);}
    }

    public static function informe(string $sitio): void
    {
        $path=dirname($sitio).'/impe-private/perfil-peticiones.jsonl';
        $lines=is_file($path)&&!is_link($path)?@file($path,FILE_IGNORE_NEW_LINES):[];
        $grupos=[];
        foreach($lines?:[] as $line){
            $r=json_decode($line,true);if(!is_array($r)||!is_string($r['ruta']??null)||!preg_match('/^[a-zA-Z0-9_.]{3,145}$/D',$r['ruta']))continue;
            if(strtotime((string)($r['utc']??''))<time()-3600)continue;
            $key=(($r['metodo']??'GET')==='POST'?'POST':'GET').' '.$r['ruta'];$grupos[$key][]=$r;
        }
        if(!$grupos){echo "SIN_MUESTRAS: abre varios módulos y repite esta lectura.\n";return;}
        uasort($grupos,static fn($a,$b)=>max(array_column($b,'total_ms'))<=>max(array_column($a,'total_ms')));
        echo "PERFIL_ULTIMA_HORA: milisegundos, peticiones del CRM, sin datos personales\n";
        foreach(array_slice($grupos,0,16,true) as $ruta=>$rows){
            usort($rows,static fn($a,$b)=>$a['total_ms']<=>$b['total_ms']);$last=$rows[count($rows)-1];$total=(float)$last['total_ms'];
            echo $ruta.' N='.count($rows).' MEDIANA_MS='.(float)$rows[intdiv(count($rows),2)]['total_ms'].' MAX_MS='.$total."\n";
            echo '  MAXIMO: ';foreach($last['fases_ms']??[] as $f=>$v){if(preg_match('/^[a-z_]+$/D',(string)$f))echo $f.'='.(float)$v.' ';}
            echo 'SQL='.(int)($last['sql_envios']??0).' CONEXIONES='.(int)($last['conexiones']??0).' REUTILIZADAS='.(int)($last['reutilizaciones']??0).' ADMISION='.(!empty($last['admision_env'])?'SI':'NO')."\n";
        }
    }
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ImpePerfilPeticion::informe(dirname(__DIR__,2));
}
