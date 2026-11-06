<?php
/** Alias públicos de Registro. Conserva el destino y nunca cambia tokens. */
final class RegistroEnlaceService
{
    public const RUTA = 'registro/obtener-beca/';
    public const RUTA_ANTERIOR = 'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/';
    private mysqli $db;
    private ?bool $lista=null;
    public function __construct(mysqli $db) { $this->db=$db; }
    public function disponible(): bool
    {
        return $this->lista ??= (bool)$this->db->query("SHOW TABLES LIKE 'registro_enlaces_amigables'")->num_rows;
    }
    private function consulta(string $sql,array $args=[]): mysqli_stmt
    {
        $q=$this->db->prepare($sql);if($args){$types=str_repeat('s',count($args));$q->bind_param($types,...$args);}$q->execute();return $q;
    }
    public function resolver(string $codigo): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{12}$/D',$codigo))throw new DomainException('Enlace de Registro no válido.',404);
        if(!$this->disponible())throw new DomainException('Registro temporalmente no disponible.',503);
        $row=$this->consulta('SELECT parametros FROM registro_enlaces_amigables WHERE codigo=?',[$codigo])->get_result()->fetch_assoc();
        if(!$row)throw new DomainException('Enlace de Registro no encontrado.',404);
        $params=json_decode($row['parametros'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($params)||($params['controller']??'')!=='formularioPublico'||($params['action']??'')!=='registro')throw new RuntimeException('Destino de Registro inválido.');
        return $params;
    }
    public function parametros(string $url): ?array
    {
        $p=parse_url($url);$base=parse_url(BASE_URL);
        if(!is_array($p)||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||
           strtolower($p['scheme']??'')!==strtolower($base['scheme'])||strtolower($p['host']??'')!==strtolower($base['host'])||($p['port']??null)!==($base['port']??null))return null;
        $prefix=$base['path']??'/';$path=$p['path']??'/';parse_str($p['query']??'',$params);
        $rutaAlias=null;
        foreach([self::RUTA,self::RUTA_ANTERIOR] as $ruta)if(str_starts_with($path,$prefix.$ruta)){$rutaAlias=$ruta;break;}
        if($rutaAlias!==null){
            $code=substr($path,strlen($prefix.$rutaAlias));
            $stored=$this->resolver(rtrim($code,'/'));
            // Contexto añadido por el generador general; no sustituir identidad ni ruta.
            foreach(['controller','action','aliado'] as $key)if(isset($params[$key])&&($stored[$key]??null)!==$params[$key])return null;
            $params=array_merge($params,$stored);
        }elseif($path!==$prefix.'index.php'||($params['controller']??'')!=='formularioPublico'||($params['action']??'')!=='registro')return null;
        if(strlen($url)>8192||count($params)>32)return null;
        foreach($params as $key=>$value)if(!is_string($value)||strlen($key)>80||strlen($value)>2048)return null;
        ksort($params,SORT_STRING);return $params;
    }
    public function acortar(string $url): string
    {
        // Antes de instalar la migración los generadores siguen siendo operativos.
        if($url===''||!$this->disponible())return $url;
        $params=$this->parametros($url);if($params===null)return $url;
        $json=json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
        for($attempt=0;$attempt<5;$attempt++){
            $row=$this->consulta('SELECT codigo FROM registro_enlaces_amigables WHERE destino_hash=UNHEX(?)',[$hash])->get_result()->fetch_assoc();
            if($row)return BASE_URL.self::RUTA.$row['codigo'];
            $code=rtrim(strtr(base64_encode(random_bytes(9)),'+/','-_'),'=');
            // La clave única de destino serializa dos generadores simultáneos.
            // Una colisión de código jamás reemplaza el destino ya publicado.
            $this->consulta('INSERT INTO registro_enlaces_amigables(codigo,destino_hash,parametros) VALUES(?,UNHEX(?),?) ON DUPLICATE KEY UPDATE codigo=codigo',[$code,$hash,$json]);
        }
        throw new RuntimeException('No se pudo reservar un enlace de Registro.');
    }
    public function equivalentes(string $a,string $b): bool
    {
        if($a===$b)return true;if(!$this->disponible())return false;
        $left=$this->parametros($a);$right=$this->parametros($b);return $left!==null&&$left===$right;
    }
    public function filas(array $rows): array
    {
        foreach($rows as &$row)if(isset($row['enlace_registro']))$row['enlace_registro']=$this->acortar((string)$row['enlace_registro']);
        return $rows;
    }
}
