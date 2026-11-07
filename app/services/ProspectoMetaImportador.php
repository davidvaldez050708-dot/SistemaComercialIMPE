<?php
require_once __DIR__.'/ProspectoMetaArchivo.php';
require_once __DIR__.'/ProspectoCentralRepository.php';
require_once __DIR__.'/ProspectoMetaFormularioService.php';
require_once __DIR__.'/ProspectoDistribucionMotor.php';

/**
 * Carga manual de leads Meta: validación, vista previa y lotes transaccionales.
 * Jamás fusiona por teléfono, asigna asesores ni toca formularios originales.
 */
final class ProspectoMetaImportador
{
    private const SESSION='prospectos_meta_carga';
    private const RESUMEN='prospectos_meta_resultado';
    private const EXPIRA=86400;
    private const CHUNK=75;

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf_prospectos_meta']))
            $_SESSION['csrf_prospectos_meta']=bin2hex(random_bytes(32));
        return $_SESSION['csrf_prospectos_meta'];
    }

    public static function verificarCsrf($token): void
    {
        if (!is_string($token)||!hash_equals(self::csrf(),$token))
            throw new DomainException('La sesión de seguridad expiró. Recarga la página.');
    }

    public static function puedeImportar(): bool
    {
        // Los permisos se asignan por rol desde Roles y permisos.
        // Ningún nombre de rol condiciona el acceso a esta operación.
        return isset($_SESSION['usuario_id'])
            && tienePermiso('prospectos.ver_todos')
            && tienePermiso('prospectos.importar_meta');
    }

    private static function dir(): string
    {
        // Carpeta de modo privado fuera de public_html.
        $dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.
            'crm-prospectos-meta-'.substr(hash('sha256',__DIR__),0,16);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
            throw new RuntimeException('No se pudo preparar el almacenamiento temporal.');
        @chmod($dir,0700);
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[] as $stale) {
            if (!is_link($stale) && is_file($stale) && (int)filemtime($stale)<time()-self::EXPIRA) @unlink($stale);
        }
        return $dir;
    }

    private static function borrarAnterior(): void
    {
        $s=$_SESSION[self::SESSION]??null;
        if (is_array($s)&&isset($s['ruta'],$s['token'])&&preg_match('/^[a-f0-9]{32}$/D',(string)$s['token'])) {
            $expected=self::dir().DIRECTORY_SEPARATOR.$s['token'].'.json';
            if (hash_equals($expected,(string)$s['ruta']) && is_file($expected)) @unlink($expected);
        }
        unset($_SESSION[self::SESSION]);
    }

    public static function cancelar(): void
    {
        $stage=self::carga();
        // No cancelar a medias para no ocultar los lotes ya confirmados.
        if ($stage && (int)($_SESSION[self::SESSION]['avance']??0)>0)
            throw new DomainException('La carga ya inició. Termina o reanuda los lotes pendientes antes de iniciar otra.');
        self::borrarAnterior();
    }

    public static function carga(): ?array
    {
        $meta=$_SESSION[self::SESSION]??null;
        if (!is_array($meta)||empty($meta['ruta'])||empty($meta['token'])) return null;
        $expected=self::dir().DIRECTORY_SEPARATOR.$meta['token'].'.json';
        if (!preg_match('/^[a-f0-9]{32}$/D',(string)$meta['token'])
            || !hash_equals($expected,(string)$meta['ruta'])
            || (int)($meta['usuario_id']??0)!==(int)($_SESSION['usuario_id']??-1)
            || (int)($meta['creado']??0)+self::EXPIRA<time()) {
            self::borrarAnterior();return null;
        }
        if (!is_file($expected)||filesize($expected)>12000000) {self::borrarAnterior();return null;}
        $json=file_get_contents($expected);
        if ($json===false || !hash_equals((string)($meta['sha256']??''),hash('sha256',$json))) {
            self::borrarAnterior();return null;
        }
        $data=json_decode($json,true);
        if (!is_array($data) || !is_array($data['filas']??null) || !is_array($data['campanias']??null))
            {self::borrarAnterior();return null;}
        // Actualizar sólo la representación en memoria de vistas previas previas
        // a la agrupación por formulario. El archivo original no se reescribe.
        return ProspectoMetaClasificacion::agrupar($data);
    }

    public static function resumenCargar(?array $data=null,?mysqli $db=null): ?array
    {
        $data=$data??self::carga();
        if (!$data) return null;
        $stage=$_SESSION[self::SESSION];
        $defaults=[];
        if ($db&&$stage['programas']===null) {
            $known=array_column((new ProspectoMetaFormularioService($db))->asociaciones(),null,'formulario_id');
            foreach ($data['formularios'] as $f) if ($f['id']!==''&&!empty($known[$f['id']]['programa_codigo'])) $defaults[$f['clave']]=$known[$f['id']];
        }
        return [
            'token'=>$stage['token'],'archivo'=>$stage['archivo'],
            'total_filas'=>$data['total_filas'],'validas'=>$data['validas'],
            'repetidas_archivo'=>$data['repetidas_archivo'],'rechazadas'=>$data['rechazadas'],
            'errores'=>$data['errores'],'muestra'=>$data['muestra'],
            'campanias'=>$data['campanias'],'formularios'=>$data['formularios'],'ya_existentes'=>$stage['ya_existentes'],
            'avance'=>$stage['avance'],'nuevos'=>$stage['nuevos'],
            'repetidos'=>$stage['repetidos'],'programas_seleccionados'=>$stage['programas'],
            'clasificacion_bloqueada'=>$stage['programas']!==null,
            'asociaciones'=>$defaults,
        ];
    }

    public static function resultado(): ?array
    {
        $result=$_SESSION[self::RESUMEN]??null;
        return is_array($result)?$result:null;
    }

    public static function preparar(array $file,mysqli $db): array
    {
        if (self::carga() && (int)($_SESSION[self::SESSION]['avance']??0)>0)
            throw new DomainException('Hay una importación inconclusa. Reanúdala antes de seleccionar otro archivo.');
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null)
            || !is_uploaded_file($file['tmp_name']))
            throw new DomainException('No se recibió el archivo correctamente. Revisa el límite de carga del servidor.');
        if (($file['size']??0)<10 || $file['size']>ProspectoMetaArchivo::MAX_BYTES)
            throw new DomainException('El archivo debe ser menor a 8 MB.');
        $archivo=basename(str_replace('\\','/',(string)($file['name']??'archivo.csv')));
        if (mb_strlen($archivo)>140 || !mb_check_encoding($archivo,'UTF-8'))
            throw new DomainException('Nombre de archivo no válido.');
        $datos=ProspectoMetaArchivo::leer($file['tmp_name'],$archivo);
        $repo=new ProspectoCentralRepository($db);
        if (!$repo->estructura()['disponible']) throw new DomainException('El almacén central no está disponible.');
        // Consulta en lotes, no una consulta por fila ni comparaciones por teléfono.
        $ids=array_column($datos['filas'],'id');
        $yaVistos=[];
        foreach (array_chunk($ids,250) as $chunk) {
            $in=implode(',',array_fill(0,count($chunk),'?'));
            $sql="SELECT COALESCE(meta_lead_id,identificador_origen) lead FROM prospecto_origenes
                WHERE tipo_origen='META_ADS' AND (meta_lead_id IN ($in) OR
                (tipo_registro='LEAD' AND identificador_origen IN ($in)))";
            foreach ($repo->consulta($sql,array_merge($chunk,$chunk))->fetch_all(MYSQLI_ASSOC) as $existing)
                $yaVistos[$existing['lead']]=true;
        }
        $existentes=count($yaVistos);
        $json=json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($json)>12000000) throw new DomainException('La vista previa supera el límite de seguridad.');
        self::borrarAnterior();
        $token=bin2hex(random_bytes(16));
        $path=self::dir().DIRECTORY_SEPARATOR.$token.'.json';
        if (file_put_contents($path,$json,LOCK_EX)!==strlen($json))
            throw new RuntimeException('No fue posible guardar la vista previa temporal.');
        @chmod($path,0600);
        $_SESSION[self::SESSION]=[
            'token'=>$token,'ruta'=>$path,'sha256'=>hash('sha256',$json),
            'usuario_id'=>(int)$_SESSION['usuario_id'],'archivo'=>$archivo,'creado'=>time(),
            'avance'=>0,'nuevos'=>0,'repetidos'=>0,'ya_existentes'=>$existentes,'programas'=>null,
        ];
        unset($_SESSION[self::RESUMEN]);
        return self::resumenCargar($datos,$db);
    }

    public static function programas(mysqli $db): array
    {
        $repo=new ProspectoCentralRepository($db);
        return $repo->consulta('SELECT codigo,nombre FROM formulario_programas ORDER BY nombre LIMIT 500')->fetch_all(MYSQLI_ASSOC);
    }

    public static function procesar(mysqli $db,array $mapa,?array $formularios=null,array $excepciones=[]): array
    {
        $data=self::carga();
        if (!$data) throw new DomainException('La vista previa venció. Vuelve a seleccionar el archivo.');
        $meta=&$_SESSION[self::SESSION];
        if ($meta['programas']===null) {
            $advanced=ProspectoMetaFormularioService::autorizado();
            if (!$advanced&&($excepciones||($formularios===null&&$mapa)))
                throw new DomainException('La clasificación excepcional requiere permiso para configurar formularios.');
            if ($formularios!==null) foreach ($data['formularios'] as $f) {
                if (!is_string($formularios[$f['clave']]??null)||$formularios[$f['clave']]==='')
                    throw new DomainException('Selecciona el programa educativo de cada formulario antes de confirmar.');
            }
            if (!$advanced&&$formularios===null) throw new DomainException('Recarga la vista previa para verificar los programas por formulario.');
            $meta['programas']=ProspectoMetaClasificacion::resolver($data,$mapa,$formularios,$excepciones,self::programas($db));
        }
        $offset=(int)$meta['avance'];$total=count($data['filas']);
        if ($offset>=$total) return self::finalizar($data,$db);
        $repo=new ProspectoCentralRepository($db);
        if (!$repo->estructura()['disponible']) throw new DomainException('El almacén central no está disponible.');
        $estados=[];
        foreach ($repo->consulta('SELECT id,nombre FROM estados')->fetch_all(MYSQLI_ASSOC) as $estado) {
            $k=self::normalizarEstado($estado['nombre']);
            if (!isset($estados[$k])) $estados[$k]=(int)$estado['id'];
            else $estados[$k]=null; // Ambigüedad: no asignar automáticamente.
        }
        $guardados=0;$duplicados=0;
        $batch=array_slice($data['filas'],$offset,self::CHUNK);
        $db->begin_transaction();
        try {
            foreach ($batch as $r) {
                // Reconocer también leads importados con el identificador histórico META.
                if ($repo->consulta("SELECT id FROM prospecto_origenes WHERE tipo_origen='META_ADS'
                    AND (meta_lead_id=? OR (tipo_registro='LEAD' AND identificador_origen=?)) LIMIT 1",
                    [$r['id'],$r['id']])->num_rows) {$duplicados++;continue;}
                $estadoOriginal=$r['estado']!==''?$r['estado']:null;
                $estadoCodigo=$estadoOriginal!==null?($estados[self::normalizarEstado($estadoOriginal)]??null):null;
                $pid=$repo->crearPersona([
                    'nombre_completo'=>$r['full_name'],'telefono_original'=>$r['phone_number'],
                    'estado_id'=>$estadoCodigo,'estado_provincia'=>$estadoOriginal,
                ]);
                $oid=$repo->guardarOrigen($pid,[
                    'tipo_origen'=>'META_ADS','sistema_origen'=>'META_ADS','tipo_registro'=>'LEAD',
                    'identificador_origen'=>$r['id'],'datos_originales_ref'=>'meta:lead:'.($r['id_original']??$r['id']),
                    'formulario_id'=>$r['form_id']?:null,'formulario_nombre'=>$r['form_name']?:null,
                    'campania_id'=>$r['campaign_id']?:null,'campania_nombre'=>$r['campaign_name']?:null,
                    'conjunto_id'=>$r['adset_id']?:null,'conjunto_nombre'=>$r['adset_name']?:null,
                    'anuncio_id'=>$r['ad_id']?:null,'anuncio_nombre'=>$r['ad_name']?:null,
                    'meta_lead_id'=>$r['id'],'plataforma'=>$r['platform']?:null,
                    'fecha_registro_original'=>$r['fecha_utc'],
                    'fecha_registro_texto'=>$r['created_time'],'zona_horaria_origen'=>'UTC',
                    'fuente_hash'=>hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                    'lote_importacion_ref'=>$meta['token'],
                ]);
                $program=ProspectoMetaClasificacion::programaFila($meta['programas'],$r);
                if ($program && $program['codigo']) {
                    $repo->interes($pid,$oid,$program['codigo'],$program['nombre'],$r['fecha_utc']);
                }
                $guardados++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();throw $e;
        }
        $meta['avance']+=count($batch);
        $meta['nuevos']+=$guardados;
        $meta['repetidos']+=$duplicados;
        if ($meta['avance']>=$total) return self::finalizar($data,$db);
        return [
            'ok'=>true,'terminado'=>false,'avance'=>$meta['avance'],'total'=>$total,
            'nuevos'=>$meta['nuevos'],'repetidos'=>$meta['repetidos'],
            'rechazados'=>$data['rechazadas'],'repetidos_archivo'=>$data['repetidas_archivo'],
        ];
    }

    private static function finalizar(array $data,mysqli $db): array
    {
        $meta=$_SESSION[self::SESSION];
        // Sólo un archivo CONFIRMADO íntegramente entra a la cola de reparto.
        // Un CSV/XLSX Bachillerato y otro Titulación generan dos lotes
        // independientes; no se reparte un lote parcialmente importado.
        (new ProspectoDistribucionMotor($db))->confirmarLoteMeta(
            (string)$meta['token'],(int)$meta['usuario_id'],(int)$meta['nuevos']
        );
        $out=[
            'ok'=>true,'terminado'=>true,'avance'=>$meta['avance'],
            'total'=>$data['validas'],'nuevos'=>$meta['nuevos'],'repetidos'=>$meta['repetidos'],
            'rechazados'=>$data['rechazadas'],'repetidos_archivo'=>$data['repetidas_archivo'],
            'campanias'=>count($data['campanias']),
        ];
        self::borrarAnterior();
        $_SESSION[self::RESUMEN]=$out;
        return $out;
    }

    private static function normalizarEstado(string $s): string
    {
        $s=mb_strtolower(trim($s),'UTF-8');
        $normal=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
        return preg_replace('/[^a-z0-9]+/','',strtolower($normal===false?$s:$normal));
    }
}
