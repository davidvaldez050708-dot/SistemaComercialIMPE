<?php
require_once __DIR__.'/ProspectoCentralRepository.php';

/** Configuración explícita por ID Meta. Cada modificación crea una versión. */
final class ProspectoMetaFormularioService
{
    private mysqli $db;
    private ProspectoCentralRepository $repo;
    public function __construct(mysqli $db){$this->db=$db;$this->repo=new ProspectoCentralRepository($db);}
    public static function autorizado(): bool
    {
        return !empty($_SESSION['usuario_id'])&&tienePermiso('prospectos.ver_todos')&&tienePermiso('prospectos.configurar_formularios');
    }
    public static function detectar(array $file): void
    {
        if (!self::autorizado()) throw new DomainException('No tienes permiso para configurar formularios.');
        require_once __DIR__.'/ProspectoMetaArchivo.php';
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))
            throw new DomainException('Selecciona el archivo original para detectar sus formularios.');
        $name=basename(str_replace('\\','/',(string)($file['name']??'')));
        if (!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>140) throw new DomainException('Nombre de archivo no válido.');
        $data=ProspectoMetaArchivo::leer($file['tmp_name'],$name);
        // Guardar únicamente los formularios detectados, nunca nombres o teléfonos de leads.
        $_SESSION['prospectos_formularios_detectados']=['usuario_id'=>(int)$_SESSION['usuario_id'],'creado'=>time(),'formularios'=>$data['formularios']];
    }
    private static function detectadosSesion(): array
    {
        $d=$_SESSION['prospectos_formularios_detectados']??[];
        return (int)($d['usuario_id']??0)===(int)($_SESSION['usuario_id']??-1)&&(int)($d['creado']??0)>time()-86400?($d['formularios']??[]):[];
    }
    public function disponible(): bool
    {
        $row=$this->repo->consulta("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prospecto_formulario_programas'")->fetch_assoc();
        if (!$row) return false;
        if (strtoupper($row['ENGINE'])!=='INNODB') throw new RuntimeException('Configuración de formularios incompatible.');
        $fields=$this->db->query('SHOW FULL COLUMNS FROM prospecto_formulario_programas')->fetch_all(MYSQLI_ASSOC);
        $names=array_column($fields,null,'Field');
        foreach (['id','formulario_id','formulario_nombre','programa_codigo','programa_nombre','version','usuario_id','created_at'] as $name)
            if (!isset($names[$name])) throw new RuntimeException('Configuración de formularios incompleta.');
        foreach (['id'=>'bigint unsigned','formulario_id'=>'varchar(190)','formulario_nombre'=>'varchar(255)','programa_codigo'=>'varchar(80)','programa_nombre'=>'varchar(255)','version'=>'int unsigned','usuario_id'=>'int','created_at'=>'datetime'] as $name=>$type) {
            $actual=preg_replace('/\b(bigint|int)\([0-9]+\)/','$1',strtolower($names[$name]['Type']));
            $nullable=in_array($name,['programa_codigo','programa_nombre'],true)?'YES':'NO';
            if ($actual!==$type||$names[$name]['Null']!==$nullable) throw new RuntimeException('Estructura de asociaciones incompatible.');
        }
        if (!str_contains($names['id']['Extra'],'auto_increment')) throw new RuntimeException('Historial de asociaciones incompatible.');
        if ($names['formulario_id']['Collation']!=='utf8mb4_bin') throw new RuntimeException('Identificador de formulario incompatible.');
        $keys=$this->db->query('SHOW INDEX FROM prospecto_formulario_programas')->fetch_all(MYSQLI_ASSOC);
        $unique=array_values(array_filter($keys,static fn($k)=>$k['Key_name']==='uq_formulario_version'&&!(bool)$k['Non_unique']));
        if (array_column($unique,'Column_name')!==['formulario_id','version']) throw new RuntimeException('Versionado de formularios incompatible.');
        return true;
    }
    public function asociaciones(): array
    {
        if (!$this->disponible()) return [];
        return $this->repo->consulta('SELECT x.* FROM prospecto_formulario_programas x JOIN (SELECT formulario_id,MAX(version) version FROM prospecto_formulario_programas GROUP BY formulario_id) v ON v.formulario_id=x.formulario_id AND v.version=x.version ORDER BY x.formulario_nombre,x.formulario_id')->fetch_all(MYSQLI_ASSOC);
    }
    public function detectados(array $preview=[]): array
    {
        $out=[];
        foreach ($this->repo->consulta("SELECT o.formulario_id id,o.formulario_nombre nombre FROM prospecto_origenes o JOIN (SELECT formulario_id,MAX(id) id FROM prospecto_origenes WHERE tipo_origen='META_ADS' AND formulario_id IS NOT NULL AND formulario_id<>'' GROUP BY formulario_id) f ON f.id=o.id")->fetch_all(MYSQLI_ASSOC) as $r)
            $out[$r['id']]=['id'=>$r['id'],'nombre'=>$r['nombre']?:'Formulario sin nombre'];
        foreach (array_merge(self::detectadosSesion(),$preview) as $f) if ($f['id']!=='') $out[$f['id']]=['id'=>$f['id'],'nombre'=>$f['nombre']];
        foreach ($this->asociaciones() as $a) $out[$a['formulario_id']]=($out[$a['formulario_id']]??['id'=>$a['formulario_id'],'nombre'=>$a['formulario_nombre']])+['asociacion'=>$a];
        return array_values($out);
    }
    public function historial(): array
    {
        return $this->disponible()?$this->repo->consulta('SELECT formulario_id,formulario_nombre,programa_nombre,version,usuario_id,created_at FROM prospecto_formulario_programas ORDER BY id DESC LIMIT 50')->fetch_all(MYSQLI_ASSOC):[];
    }
    public function guardar(string $id,string $code,int $version,array $preview=[]): void
    {
        if (!self::autorizado()) throw new DomainException('No tienes permiso para configurar formularios.');
        if (!$this->disponible()) throw new DomainException('La configuración de formularios aún no está instalada.');
        if ($id===''||mb_strlen($id)>190||mb_strlen($code)>80||$version<0) throw new DomainException('Configuración de formulario no válida.');
        $detected=array_column($this->detectados($preview),null,'id');
        if (!isset($detected[$id])) throw new DomainException('Selecciona un ID de formulario detectado en el historial o archivo revisado.');
        $this->db->begin_transaction();
        try {
            $current=$this->repo->consulta('SELECT * FROM prospecto_formulario_programas WHERE formulario_id=BINARY ? ORDER BY version DESC LIMIT 1 FOR UPDATE',[$id])->fetch_assoc();
            if ((int)($current['version']??0)!==$version) throw new DomainException('La asociación cambió. Recarga para revisar su versión actual.');
            $program=$code!==''?$this->repo->consulta('SELECT codigo,nombre FROM formulario_programas WHERE codigo=BINARY ? FOR UPDATE',[$code])->fetch_assoc():null;
            if ($code!==''&&!$program) throw new DomainException('Selecciona un programa del catálogo real.');
            if ($current&&($current['programa_codigo']??'')===$code){$this->db->commit();return;}
            if (!$current&&$code==='') throw new DomainException('Selecciona un programa para asociar el formulario.');
            $this->repo->ejecutar('INSERT INTO prospecto_formulario_programas(formulario_id,formulario_nombre,programa_codigo,programa_nombre,version,usuario_id,created_at) VALUES (?,?,?,?,?,?,?)',
                [$id,$detected[$id]['nombre'],$program['codigo']??null,$program['nombre']??null,$version+1,(int)$_SESSION['usuario_id'],gmdate('Y-m-d H:i:s')]);
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();if($e instanceof mysqli_sql_exception&&in_array($e->getCode(),[1062,1213],true))throw new DomainException('Otro usuario modificó la asociación. Recarga y vuelve a verificar.');throw $e;}
    }
}
