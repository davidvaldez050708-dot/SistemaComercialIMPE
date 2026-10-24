<?php
require_once __DIR__.'/AliadoHistorialService.php';
require_once __DIR__.'/../models/AliadoModel.php';

/** Avance por publicación y respuesta humana son datos independientes. */
class AliadoAvanceService
{
    public const RESPUESTAS = [
        'ESPERANDO_RESPUESTA'=>'Sin respuesta registrada',
        'SOLICITA_INFORMACION'=>'Solicita información',
        'DIFUSION_CONFIRMADA'=>'La institución confirmó la difusión',
        'NO_PARTICIPARA'=>'No participará',
        'SIN_RESPUESTA'=>'Contactado, sin respuesta',
    ];
    private mysqli $db;
    private AliadoModel $modelo;
    private array $eventosCache = [];
    private ?bool $catalogoListo = null;
    public function __construct(mysqli $db) { $this->db=$db; $this->modelo=new AliadoModel($db); }
    private function query(string $sql,array $params=[])
    {
        $s=$this->db->prepare($sql);
        if($params)$s->bind_param(str_repeat('s',count($params)),...$params);
        $s->execute();return $s->get_result();
    }
    public function respuestasManualesDisponibles(): bool
    {
        return (bool)$this->query("SHOW TABLES LIKE 'aliados_manuales_respuestas'")->num_rows;
    }
    private function autorizar(string $tipo,int $id): array
    {
        if(empty($_SESSION['usuario_id']))throw new DomainException('Inicia sesión nuevamente.',401);
        if(!tienePermiso('aliados.ver')||!tieneAlgunPermiso(['convocatorias.ver','aliados.ver_historial']))throw new DomainException('No tienes permiso para consultar las convocatorias del aliado.',403);
        if(!in_array($tipo,['manual','institucional'],true)||$id<=0)throw new DomainException('Origen de aliado inválido.',422);
        if($tipo==='manual')return (new AliadoRegistroService($this->db))->origen($tipo,$id,puedeConsultarAliadosNacional());
        $a=$this->modelo->obtenerAliado($id,(int)$_SESSION['usuario_id'],puedeConsultarAliadosNacional());
        if(!$a)throw new DomainException('No tienes acceso a este aliado.',403);
        return $a;
    }
    private function vigentes(string $tipo,int $id): array
    {
        if ($this->catalogoListo === null) {
            $columnas=array_column($this->query('SHOW COLUMNS FROM convocatorias')->fetch_all(MYSQLI_ASSOC),'Field');
            $this->catalogoListo=!array_diff(['id','titulo','imagen','fecha_inicio','fecha_termino','estado','tipo_convocatoria','subtipo_convocatoria'],$columnas);
        }
        return $this->catalogoListo?$this->modelo->obtenerConvocatoriasAsociadas($tipo,$id,false):[];
    }
    private function eventos(string $tipo,int $id): array
    {
        $key=$tipo.':'.$id;
        if(isset($this->eventosCache[$key]))return $this->eventosCache[$key];
        if($tipo==='manual') {
            if(!$this->query("SHOW TABLES LIKE 'aliados_manuales_difusiones'")->num_rows)return [];
            return array_map([AliadoHistorialService::class,'normalizarManual'],$this->query('SELECT d.*,CONCAT_WS(" ",u.nombre,u.apellidos) responsable FROM aliados_manuales_difusiones d LEFT JOIN usuarios u ON u.id=d.registrado_por WHERE d.aliado_id=? ORDER BY d.realizado_at DESC,d.id DESC',[$id])->fetch_all(MYSQLI_ASSOC));
        }
        return $this->query('SELECT convocatoria_id,convocatoria_titulo,canal,estado_envio,enviado_at,id FROM aliados_convocatorias_envios WHERE seguimiento_id=? ORDER BY enviado_at DESC,id DESC',[$id])->fetch_all(MYSQLI_ASSOC);
    }
    public static function combinar(array $publicaciones,array $eventos): array
    {
        $porConvocatoria=[];
        foreach($eventos as $e) {
            $cid=(int)($e['convocatoria_id']??0);$canal=strtoupper((string)($e['canal']??''));
            if($canal==='WHATSAPP')$canal='WHATSAPP_MANUAL';
            if(!$cid||!in_array($e['estado_envio']??'',['ENVIADO','COMPARTIDO'],true)||!in_array($canal,['CORREO','WHATSAPP_MANUAL'],true))continue;
            if(!isset($porConvocatoria[$cid][$canal]))$porConvocatoria[$cid][$canal]=['fecha'=>$e['enviado_at'],'id'=>(int)($e['id']??0)];
        }
        $seen=[];$items=[];$compartidas=0;
        foreach($publicaciones as $p) {
            $cid=(int)$p['id'];if(isset($seen[$cid]))continue;$seen[$cid]=true;
            $p['difusion_canales']=$porConvocatoria[$cid]??[];
            $p['compartida']=(bool)$p['difusion_canales'];
            if($p['compartida'])$compartidas++;
            $items[]=$p;
        }
        return ['total'=>count($items),'compartidas'=>$compartidas,'pendientes'=>count($items)-$compartidas,'convocatorias'=>$items];
    }
    public function publicaciones(string $tipo,int $id,array $publicaciones): array
    {
        return self::combinar($publicaciones,$this->eventos($tipo,$id));
    }
    private function respuestas(string $tipo,int $id): array
    {
        if($tipo==='manual') {
            if(!$this->respuestasManualesDisponibles())return [];
            $rows=$this->query('SELECT r.*,CONCAT_WS(" ",u.nombre,u.apellidos) responsable FROM aliados_manuales_respuestas r LEFT JOIN usuarios u ON u.id=r.usuario_id WHERE r.manual_id=? ORDER BY r.id DESC',[$id])->fetch_all(MYSQLI_ASSOC);
        } else {
            if(!$this->modelo->seguimientoConvocatoriasDisponible())return [];
            // Un reenvío abre otro contacto, pero conserva la última respuesta humana.
            $rows=$this->query('SELECT s.*,COALESCE(hum.estado_nuevo,s.estado) estado,COALESCE(hum.nota,s.nota) nota,COALESCE(hum.created_at,s.updated_at) updated_at,CONCAT_WS(" ",u.nombre,u.apellidos) responsable,(SELECT MAX(e.id) FROM aliados_convocatorias_seguimiento_eventos e WHERE e.seguimiento_convocatoria_id=s.id) version FROM aliados_convocatorias_seguimientos s LEFT JOIN aliados_convocatorias_seguimiento_eventos hum ON hum.id=(SELECT e.id FROM aliados_convocatorias_seguimiento_eventos e JOIN aliados_convocatorias_seguimientos anterior ON anterior.id=e.seguimiento_convocatoria_id WHERE anterior.seguimiento_id=s.seguimiento_id AND anterior.convocatoria_id=s.convocatoria_id AND e.estado_anterior IS NOT NULL ORDER BY e.id DESC LIMIT 1) LEFT JOIN usuarios u ON u.id=hum.usuario_id WHERE s.seguimiento_id=? AND s.activo=1 ORDER BY s.id DESC',[$id])->fetch_all(MYSQLI_ASSOC);
        }
        $result=[];foreach($rows as $r){$cid=(int)$r['convocatoria_id'];if(!isset($result[$cid]))$result[$cid]=$r;}
        return $result;
    }
    public function consultar(string $tipo,int $id): array
    {
        $aliado=$this->autorizar($tipo,$id);
        $avance=$this->publicaciones($tipo,$id,$this->vigentes($tipo,$id));
        $respuestas=$this->respuestas($tipo,$id);
        $disponible=$tipo==='manual'?$this->respuestasManualesDisponibles():$this->modelo->seguimientoConvocatoriasDisponible();
        foreach($avance['convocatorias'] as &$p){
            $r=$respuestas[(int)$p['id']]??[];
            $state=(string)($r['estado']??'ESPERANDO_RESPUESTA');
            $p['respuesta']=['estado'=>$state,'etiqueta'=>self::RESPUESTAS[$state]??'Sin respuesta registrada','nota'=>$r['nota']??'','responsable'=>$r['responsable']??'','fecha'=>($tipo==='manual'?($r['created_at']??''):($r['updated_at']??'')),'version'=>(int)($tipo==='manual'?($r['id']??0):($r['version']??0))];
            $p['puede_responder']=$p['compartida']&&$disponible&&tienePermiso('aliados.seguimiento_convocatorias')&&(int)($_SESSION['rol_id']??0)!==1;
        }unset($p);
        $ultima=null;foreach($this->eventos($tipo,$id) as $e)if(in_array($e['estado_envio']??'',['ENVIADO','COMPARTIDO'],true)){$ultima=$e;break;}
        return ['ok'=>true,'ultima_difusion'=>$ultima,'aliado'=>$aliado,'origen_tipo'=>$tipo,'origen_id'=>$id,'avance'=>$avance,'respuestas_disponibles'=>$disponible,'estados_respuesta'=>self::RESPUESTAS];
    }
    public function decorarListado(array $filas): array
    {
        // El historial se obtiene en lote por origen, no una consulta por fila.
        foreach (['manual','institucional'] as $tipo) {
            $ids=[];
            foreach($filas as $f)if(($f['origen_tipo']??'institucional')===$tipo){$id=(int)($tipo==='manual'?($f['origen_id']??$f['id']):($f['seguimiento_id']??0));if($id>0)$ids[]=$id;}
            $ids=array_values(array_unique($ids));if(!$ids)continue;
            foreach($ids as $id)$this->eventosCache[$tipo.':'.$id]=[];
            $marks=implode(',',array_fill(0,count($ids),'?'));
            if($tipo==='manual') {
                if(!$this->query("SHOW TABLES LIKE 'aliados_manuales_difusiones'")->num_rows)continue;
                $rows=$this->query("SELECT * FROM aliados_manuales_difusiones WHERE aliado_id IN ($marks) ORDER BY realizado_at DESC,id DESC",$ids)->fetch_all(MYSQLI_ASSOC);
                foreach($rows as $r)$this->eventosCache['manual:'.$r['aliado_id']][]=AliadoHistorialService::normalizarManual($r);
            } else {
                $rows=$this->query("SELECT * FROM aliados_convocatorias_envios WHERE seguimiento_id IN ($marks) ORDER BY enviado_at DESC,id DESC",$ids)->fetch_all(MYSQLI_ASSOC);
                foreach($rows as $r)$this->eventosCache['institucional:'.$r['seguimiento_id']][]=$r;
            }
        }
        foreach($filas as &$f){
            $tipo=(string)($f['origen_tipo']??'institucional');$id=(int)($tipo==='manual'?($f['origen_id']??$f['id']):($f['seguimiento_id']??0));
            if($id<=0)continue;
            $a=$this->publicaciones($tipo,$id,$this->vigentes($tipo,$id));
            unset($a['convocatorias']);$f['difusion_avance']=$a;
            if($tipo==='manual')$f['seguimiento_convocatoria_estado']='';
        }unset($f);return $filas;
    }
    public function guardarRespuesta(string $tipo,int $id,array $datos): array
    {
        $this->autorizar($tipo,$id);
        if(!tienePermiso('aliados.seguimiento_convocatorias')||(int)($_SESSION['rol_id']??0)===1)throw new DomainException('No tienes permiso para registrar respuestas.',403);
        $cid=(int)($datos['convocatoria_id']??0);$estado=(string)($datos['estado']??'');$nota=trim((string)($datos['nota']??''));
        if(!isset(self::RESPUESTAS[$estado])||mb_strlen($nota)<3||mb_strlen($nota)>1000||!preg_match('/^\d+$/D',(string)($datos['version']??'')))throw new DomainException('Selecciona una respuesta y escribe una nota de 3 a 1000 caracteres.',422);
        $lock='impe-respuesta-'.substr(hash('sha256',$tipo.':'.$id.':'.$cid),0,40);
        if(!(int)$this->query('SELECT GET_LOCK(?,3) adquirido',[$lock])->fetch_assoc()['adquirido'])throw new DomainException('Otra persona está actualizando esta respuesta. Intenta nuevamente.',409);
        try {
            $actual=$this->consultar($tipo,$id);$p=null;
            foreach($actual['avance']['convocatorias'] as $c)if((int)$c['id']===$cid)$p=$c;
            if(!$p||!$p['compartida'])throw new DomainException('La convocatoria debe pertenecer al aliado, estar vigente y haberse compartido.',409);
            if(!$actual['respuestas_disponibles'])throw new DomainException('Falta instalar la estructura de respuestas del aliado.',503);
            $usuario=(int)$_SESSION['usuario_id'];$this->db->begin_transaction();
            if($tipo==='manual') {
                $r=$this->query('SELECT id FROM aliados_manuales_respuestas WHERE manual_id=? AND convocatoria_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$id,$cid])->fetch_assoc();
                if((int)($r['id']??0)!==(int)$datos['version'])throw new DomainException('La respuesta cambió. Vuelve a abrir el avance antes de guardar.',409);
                $this->query('INSERT INTO aliados_manuales_respuestas (manual_id,convocatoria_id,estado,nota,usuario_id) VALUES (?,?,?,?,?)',[$id,$cid,$estado,$nota,$usuario]);
            } else {
                $r=$this->query('SELECT * FROM aliados_convocatorias_seguimientos WHERE seguimiento_id=? AND convocatoria_id=? AND activo=1 ORDER BY id DESC LIMIT 1 FOR UPDATE',[$id,$cid])->fetch_assoc();
                if(!$r) {
                    if((int)$datos['version']!==0)throw new DomainException('La respuesta cambió. Vuelve a abrir el avance.',409);
                    $envio=$this->query("SELECT id FROM aliados_convocatorias_envios WHERE seguimiento_id=? AND convocatoria_id=? AND estado_envio IN ('ENVIADO','COMPARTIDO') ORDER BY enviado_at DESC,id DESC LIMIT 1",[$id,$cid])->fetch_assoc();
                    if(!$envio)throw new DomainException('No hay un envío confirmado para esta convocatoria.',409);
                    $responsable=(int)($actual['aliado']['cuenta_clave_usuario_id']??$usuario);if(!$responsable)$responsable=$usuario;
                    $this->query("INSERT INTO aliados_convocatorias_seguimientos (envio_id,seguimiento_id,convocatoria_id,responsable_usuario_id,estado,activo) VALUES (?,?,?,?,'ESPERANDO_RESPUESTA',1)",[$envio['id'],$id,$cid,$responsable]);
                    $r=['id'=>$this->db->insert_id,'estado'=>'ESPERANDO_RESPUESTA','proximo_seguimiento_at'=>null];
                }
                $version=(int)$this->query('SELECT COALESCE(MAX(id),0) FROM aliados_convocatorias_seguimiento_eventos WHERE seguimiento_convocatoria_id=?',[$r['id']])->fetch_row()[0];
                if($version!==(int)$datos['version'])throw new DomainException('La respuesta cambió. Vuelve a abrir el avance antes de guardar.',409);
                $cerrado=in_array($estado,['DIFUSION_CONFIRMADA','NO_PARTICIPARA'],true)?date('Y-m-d H:i:s'):null;
                $proximo=$cerrado?null:$r['proximo_seguimiento_at'];
                $this->query('UPDATE aliados_convocatorias_seguimientos SET estado=?,nota=?,proximo_seguimiento_at=?,cerrado_at=?,updated_at=NOW() WHERE id=?',[$estado,$nota,$proximo,$cerrado,$r['id']]);
                $this->query('INSERT INTO aliados_convocatorias_seguimiento_eventos (seguimiento_convocatoria_id,usuario_id,estado_anterior,estado_nuevo,nota,proximo_seguimiento_at) VALUES (?,?,?,?,?,?)',[$r['id'],$usuario,$r['estado'],$estado,$nota,$proximo]);
            }
            $this->db->commit();
            return $this->consultar($tipo,$id);
        } catch(Throwable $e){$this->db->rollback();throw $e;}
        finally{$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
