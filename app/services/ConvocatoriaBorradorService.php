<?php
require_once __DIR__.'/AliadoRegistroService.php';

/** Edición privada por propietario. La única publicación continúa en guardarProgramasAliado. */
final class ConvocatoriaBorradorService
{
    private mysqli $db;
    private bool $schemaReady=false;
    public function __construct(mysqli $db) { $this->db=$db; }
    private function query(string $sql,array $values=[]): mysqli_stmt
    {
        $q=$this->db->prepare($sql);
        if($values){$types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$values));$q->bind_param($types,...$values);}
        $q->execute();return $q;
    }
    public function contexto(string $tipo,int $id): array
    {
        if(!isset($_SESSION['usuario_id'])||!puedeCrearConvocatoriaDesdeConvenio())throw new DomainException('No tienes permiso para editar borradores.',403);
        $context=(new AliadoRegistroService($this->db))->opcionesConvocatoria($tipo,$id);
        if((int)($context['origen']['estado_id']??0)<1)throw new DomainException('El aliado no tiene territorio válido.',422);
        return $context;
    }
    private function requireSchema(): void
    {
        if($this->schemaReady)return;
        foreach(['convocatoria_borradores','convocatoria_borrador_imagenes','convocatoria_borrador_operaciones'] as $table){
            $q=$this->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->get_result()->fetch_assoc();
            if(!$q||$q['ENGINE']!=='InnoDB')throw new DomainException('Falta instalar la migración aditiva de borradores V4.',503);
        }
        $this->schemaReady=true;
    }
    public function abrir(string $tipo,int $id): array
    {
        $context=$this->contexto($tipo,$id);$this->requireSchema();
        $user=(int)$_SESSION['usuario_id'];$key=hash('sha256',"$user:$tipo:$id");
        $this->query("INSERT INTO convocatoria_borradores (propietario_id,aliado_tipo,aliado_id,territorio_id,clave_activa,datos,actualizado_por) VALUES (?,?,?,?,?,'{}',?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",[$user,$tipo,$id,(int)$context['origen']['estado_id'],$key,$user]);
        $row=$this->query('SELECT * FROM convocatoria_borradores WHERE clave_activa=?',[$key])->get_result()->fetch_assoc();
        return ['contexto'=>$context,'borrador'=>$this->vista($row)];
    }
    private function row(int $id,bool $lock=false): array
    {
        $this->requireSchema();
        $row=$this->query('SELECT * FROM convocatoria_borradores WHERE id=? AND propietario_id=?'.($lock?' FOR UPDATE':''),[$id,(int)($_SESSION['usuario_id']??0)])->get_result()->fetch_assoc();
        if(!$row)throw new DomainException('Borrador no disponible.',404);
        $context=$this->contexto($row['aliado_tipo'],(int)$row['aliado_id']);
        if((int)$row['territorio_id']!==(int)$context['origen']['estado_id'])throw new DomainException('El territorio del aliado cambió. Revisa el borrador antes de continuar.',409);
        $row['contexto']=$context;return $row;
    }
    private function opciones(array $context): array
    {
        $map=[];foreach($context['opciones_convocatoria'] as $p)$map[$p['codigo']][]=$p['clasificacion'];return $map;
    }
    private function vista(array $row): array
    {
        $images=[];
        foreach($this->query('SELECT * FROM convocatoria_borrador_imagenes WHERE borrador_id=?',[(int)$row['id']])->get_result()->fetch_all(MYSQLI_ASSOC) as $i){
            $images[$i['programa_codigo']]=['referencia'=>$i['referencia'],'nombre'=>$i['nombre'],'mime'=>$i['mime'],'bytes'=>(int)$i['bytes'],'url'=>BASE_URL.'index.php?controller=crearConvocatoria&action=imagenBorrador&id='.$row['id'].'&programa='.rawurlencode($i['programa_codigo']).'&ref='.$i['referencia']];
        }
        $data=json_decode($row['datos'],true,512,JSON_THROW_ON_ERROR);$complete=[];
        foreach($data as $code=>$fields)$complete[$code]=(bool)($fields['completo']??false);
        return ['id'=>(int)$row['id'],'propietario_id'=>(int)$row['propietario_id'],'aliado_tipo'=>$row['aliado_tipo'],'aliado_id'=>(int)$row['aliado_id'],'territorio_id'=>(int)$row['territorio_id'],'version'=>(int)$row['version'],'estado'=>$row['estado'],'programas'=>(object)$data,'imagenes'=>(object)$images,'completitud'=>(object)$complete,'creado_at'=>$row['creado_at'],'actualizado_at'=>$row['actualizado_at'],'actualizado_por'=>(int)$row['actualizado_por'],'resultado'=>json_decode($row['resultado']??'null',true)];
    }
    public function cargar(int $id): array { return $this->vista($this->row($id)); }
    private function validarVersion(array $row,int $version): void
    {
        if($row['estado']!=='borrador')throw new DomainException('Este borrador ya fue publicado.',409);
        if((int)$row['version']!==$version)throw new DomainException('Otra pestaña modificó el borrador. Conserva tus cambios y carga la versión del servidor antes de continuar.',409);
    }
    private function recalcular(int $id): void
    {
        $data=json_decode($this->query('SELECT datos FROM convocatoria_borradores WHERE id=?',[$id])->get_result()->fetch_row()[0],true,512,JSON_THROW_ON_ERROR);
        $images=array_column($this->query('SELECT programa_codigo FROM convocatoria_borrador_imagenes WHERE borrador_id=?',[$id])->get_result()->fetch_all(MYSQLI_ASSOC),'programa_codigo');
        foreach($data as $code=>&$fields){
            $dates=true;foreach(['fecha_inicio','fecha_termino'] as $name){$value=$fields[$name]??'';$parts=explode('-',$value);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)($parts[1]??0),(int)($parts[2]??0),(int)($parts[0]??0)))$dates=false;}
            $fields['completo']=$dates&&trim($fields['titulo']??'')!==''&&$fields['fecha_termino']>=$fields['fecha_inicio']&&in_array($fields['estado']??'', ['0','1'],true)&&!empty($fields['clasificacion'])&&in_array($code,$images,true);
        }unset($fields);
        $this->query('UPDATE convocatoria_borradores SET datos=? WHERE id=?',[json_encode((object)$data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
    }
    private function guardarOperacion(int $id,string $key,string $type,string $hash,array $response): void
    {
        $this->query('INSERT INTO convocatoria_borrador_operaciones (borrador_id,operacion,tipo,peticion_sha256,respuesta) VALUES (?,?,?,?,?)',[$id,$key,$type,$hash,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    private function cambio(int $id,int $version,string $key,string $type,string $hash,callable $fn): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new DomainException('Falta una clave de operación válida.',422);
        $this->db->begin_transaction();
        try{
            $row=$this->row($id,true);
            $old=$this->query('SELECT * FROM convocatoria_borrador_operaciones WHERE borrador_id=? AND operacion=?',[$id,$key])->get_result()->fetch_assoc();
            if($old){if($old['tipo']!==$type||!hash_equals($old['peticion_sha256'],$hash))throw new DomainException('La operación fue reutilizada con otros datos.',409);$this->db->commit();$cached=json_decode($old['respuesta'],true,512,JSON_THROW_ON_ERROR);foreach(['programas','imagenes','completitud'] as $field)$cached[$field]=(object)$cached[$field];return $cached;}
            $this->validarVersion($row,$version);$fn($row);
            $this->recalcular($id);
            $this->query('UPDATE convocatoria_borradores SET version=version+1,actualizado_at=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=?',[(int)$_SESSION['usuario_id'],$id]);
            $result=$this->vista($this->row($id));$this->guardarOperacion($id,$key,$type,$hash,$result);
            $this->db->commit();return $result;
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function guardar(int $id,int $version,string $key,array $data): array
    {
        if(count($data)>30)throw new DomainException('Máximo 30 programas por borrador.',422);
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>40000)throw new DomainException('Borrador demasiado grande.',422);
        return $this->cambio($id,$version,$key,'guardar',hash('sha256',$json),function($row)use($data,$id){
            $options=$this->opciones($row['contexto']);$clean=[];$previous=json_decode($row['datos'],true,512,JSON_THROW_ON_ERROR);
            foreach($data as $code=>$fields){
                if(!is_string($code)||ProgramaEducativo::codigo($code)!==$code||!isset($options[$code])||!is_array($fields))throw new DomainException('Programa no autorizado para el aliado.',422);
                foreach(['titulo'=>180,'fecha_inicio'=>10,'fecha_termino'=>10,'estado'=>1,'clasificacion'=>140] as $field=>$max){
                    $v=$fields[$field]??'';if(!is_string($v)||mb_strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$v))throw new DomainException('Campo de borrador inválido.',422);$clean[$code][$field]=$v;
                }
                if(!in_array($clean[$code]['estado'],['','0','1'],true)||($clean[$code]['clasificacion']!==''&&!in_array($clean[$code]['clasificacion'],$options[$code],true)))throw new DomainException('Disponibilidad o clasificación no autorizada.',422);
                $old=$previous[$code]??[];$same=$clean[$code]===array_intersect_key($old,$clean[$code]);
                $clean[$code]['actualizado_at']=$same?($old['actualizado_at']??date('Y-m-d H:i:s')):date('Y-m-d H:i:s');
                $clean[$code]['actualizado_por']=$same?($old['actualizado_por']??(int)$_SESSION['usuario_id']):(int)$_SESSION['usuario_id'];
            }
            $this->query('UPDATE convocatoria_borradores SET datos=? WHERE id=?',[json_encode((object)$clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
        });
    }
    private function root(): string
    {
        $web=realpath(__DIR__.'/../..');$dir=getenv('IMPE_CONVOCATORIA_DRAFT_DIR')?:dirname($web).'/impe-private/convocatorias-borradores';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear el almacenamiento privado.');
        $resolved=realpath($dir);$a=str_replace('\\','/',$resolved).'/';$b=str_replace('\\','/',$web).'/';
        if(stripos($a,$b)===0||is_link($dir))throw new RuntimeException('El almacenamiento de borradores debe estar fuera del sitio público.');
        return $resolved;
    }
    private function path(int $id,string $ref): string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$ref)||$id<1)throw new DomainException('Imagen inválida.',404);
        $folder=$this->root().'/'.$id;
        if(is_link($folder))throw new RuntimeException('Ruta privada inválida.');
        return $folder.'/'.$ref;
    }
    private function validarImagen(string $file,int $bytes): string
    {
        if($bytes<1||$bytes>5*1024*1024||!is_file($file)||filesize($file)!==$bytes)throw new DomainException('La imagen debe pesar como máximo 5 MB.',422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);$size=@getimagesize($file);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$size||$size['mime']!==$mime)throw new DomainException('La imagen debe ser JPEG, PNG o WebP válida.',422);
        return $mime;
    }
    public function subir(int $id,int $version,string $key,string $code,array $file): array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('No se recibió una imagen subida válida.',422);
        $mime=$this->validarImagen($file['tmp_name'],(int)$file['size']);$hash=hash_file('sha256',$file['tmp_name']);$newPath=null;$oldPath=null;
        try{$result=$this->cambio($id,$version,$key,'imagen',hash('sha256',$code.':'.$hash),function($row)use($id,$code,$file,$mime,$hash,&$newPath,&$oldPath){
            if(!isset($this->opciones($row['contexto'])[$code])||ProgramaEducativo::codigo($code)!==$code)throw new DomainException('Programa no autorizado.',422);
            $old=$this->query('SELECT * FROM convocatoria_borrador_imagenes WHERE borrador_id=? AND programa_codigo=?',[$id,$code])->get_result()->fetch_assoc();
            if($old&&hash_equals($old['sha256'],$hash)&&is_file($this->path($id,$old['referencia'])))return;
            if($old)$oldPath=$this->path($id,$old['referencia']);
            $ref=bin2hex(random_bytes(32));$path=$this->path($id,$ref);
            if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
            if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('No se pudo guardar la imagen privada.');
            chmod($path,0600);$newPath=$path;
            $name=mb_substr(basename(str_replace('\\','/',(string)$file['name'])),0,180);
            $this->query('INSERT INTO convocatoria_borrador_imagenes (borrador_id,programa_codigo,referencia,sha256,nombre,mime,bytes,actualizado_por) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE referencia=VALUES(referencia),sha256=VALUES(sha256),nombre=VALUES(nombre),mime=VALUES(mime),bytes=VALUES(bytes),actualizado_at=CURRENT_TIMESTAMP,actualizado_por=VALUES(actualizado_por)',[$id,$code,$ref,$hash,$name,$mime,(int)$file['size'],(int)$_SESSION['usuario_id']]);
            $data=json_decode($row['datos'],true,512,JSON_THROW_ON_ERROR);$data[$code]['actualizado_at']=date('Y-m-d H:i:s');$data[$code]['actualizado_por']=(int)$_SESSION['usuario_id'];
            $this->query('UPDATE convocatoria_borradores SET datos=? WHERE id=?',[json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
        });}catch(Throwable $e){$this->limpiarArchivo($newPath);throw $e;}
        // Una limpieza fallida nunca debe eliminar el archivo nuevo ya confirmado.
        $this->limpiarArchivo($oldPath);return $result;
    }
    public function imagen(int $id,string $code,string $ref=''): array
    {
        $row=$this->row($id);if(!isset($this->opciones($row['contexto'])[$code]))throw new DomainException('Imagen no disponible.',404);
        $image=$this->query('SELECT * FROM convocatoria_borrador_imagenes WHERE borrador_id=? AND programa_codigo=?',[$id,$code])->get_result()->fetch_assoc();
        if(!$image||($ref!==''&&!hash_equals($image['referencia'],$ref)))throw new DomainException('Imagen no disponible.',404);
        $image['ruta']=$this->path($id,$image['referencia']);$this->validarImagen($image['ruta'],(int)$image['bytes']);
        if(!hash_equals($image['sha256'],hash_file('sha256',$image['ruta'])))throw new RuntimeException('La imagen almacenada no coincide con su hash.');
        return $image;
    }
    public function copiarImagenPublica(int $id,string $code): array
    {
        $image=$this->imagen($id,$code);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']];
        $folder=ROOT_PATH.'/public/uploads/convocatorias';if(!is_dir($folder))mkdir($folder,0775,true);
        $relative='public/uploads/convocatorias/convocatoria_'.date('YmdHis').'_'.bin2hex(random_bytes(6)).'.'.$ext;
        if(!copy($image['ruta'],ROOT_PATH.'/'.$relative))throw new RuntimeException('No se pudo copiar la imagen definitiva.');
        return ['ruta'=>$relative,'nueva'=>true,'error'=>''];
    }
    /** Invocar dentro de la transacción del modelo que publica el lote. */
    public function paraPublicar(int $id,int $version): array
    {
        $row=$this->row($id,true);if($row['estado']==='publicado')return $row;
        $this->validarVersion($row,$version);return $row;
    }
    public function publicado(int $id,array $result): void
    {
        $updated=$this->query("UPDATE convocatoria_borradores SET estado='publicado',clave_activa=NULL,resultado=?,version=version+1,actualizado_at=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=? AND estado='borrador'",[json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$_SESSION['usuario_id'],$id]);
        if($updated->affected_rows!==1)throw new RuntimeException('No se confirmó el borrador publicado.');
    }
    public function descartar(int $id,int $version,string $key): array
    {
        $paths=[];$result=$this->cambio($id,$version,$key,'descartar',hash('sha256','descartar'),function($row)use($id,&$paths){
            foreach($this->query('SELECT referencia FROM convocatoria_borrador_imagenes WHERE borrador_id=?',[$id])->get_result()->fetch_all(MYSQLI_ASSOC) as $image)$paths[]=$this->path($id,$image['referencia']);
            $this->query("UPDATE convocatoria_borradores SET estado='descartado',clave_activa=NULL,datos='{}' WHERE id=?",[$id]);
            $this->query('DELETE FROM convocatoria_borrador_imagenes WHERE borrador_id=?',[$id]);
        });foreach($paths as $path)$this->limpiarArchivo($path);return $result;
    }
    private function limpiarArchivo(?string $path): void
    {
        if(!$path||!is_file($path))return;
        try{if(!@unlink($path))error_log('[borrador_v4] Limpieza de archivo privado obsoleto pendiente.');}
        catch(Throwable $error){error_log('[borrador_v4] Limpieza de archivo privado obsoleto pendiente.');}
    }
}
