<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/TelefoniaExtensionService.php';
require_once __DIR__.'/ZadarmaWebhookEventStoreService.php';
require_once __DIR__.'/ProspectoNoContactarV3.php';

/** Bitácora común de sesiones SDK. Nunca es evidencia firmada del proveedor.
 * Reutiliza la tabla histórica de WebRTC, sin otro motor ni tabla paralela.
 * Su esquema se prepara explícitamente; ninguna petición ejecuta DDL.
 */
class TelefoniaSesionLocalService
{
    protected mysqli $db;
    private array $assignments=[];
    private ?array $internalExtensions=null;
    public function __construct(?mysqli $db=null)
    {
        $this->db=$db??Database::compartida();
    }
    public static function validarSesion(string $id):bool
    {
        return (bool)preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$id);
    }
    protected function asignacion(int $user,string $direction):array
    {
        if($user<1||!in_array($direction,['ENTRANTE','SALIENTE'],true))throw new InvalidArgumentException('Sesión telefónica inválida.');
        $a=$this->assignments[$user]??=(new TelefoniaExtensionService($this->db))->resolverParaUsuario($user);
        $cap=$direction==='SALIENTE'?'permite_salientes':'permite_entrantes';
        if(!$a||empty($a[$cap])||!preg_match('/^[0-9]{3,6}$/D',(string)$a['extension']))
            throw new DomainException('No hay una extensión habilitada para esta sesión.');
        return $a;
    }
    protected function extension(int $user,string $direction='ENTRANTE'):string
    {
        return (string)$this->asignacion($user,$direction)['extension'];
    }
    public function esNumeroInterno(string $number,string $extension):bool
    {
        if(!preg_match('/^[0-9]{3,6}$/D',$number)||!preg_match('/^[0-9]{3,6}$/D',$extension)||$number===$extension)return false;
        if($this->internalExtensions===null){
            $rows=$this->db->query("SELECT extension FROM telefonia_extensiones WHERE proveedor='ZADARMA' AND activo=1")->fetch_all(MYSQLI_ASSOC);
            $this->internalExtensions=array_fill_keys(array_column($rows,'extension'),true);
        }
        return isset($this->internalExtensions[$number]);
    }
    public function prepararSaliente(int $user,string $number):array
    {
        $formats=ProspectoNoContactarV3::formatos($number);
        if(!$formats)throw new InvalidArgumentException('Destino telefónico inválido.');
        $a=$this->asignacion($user,'SALIENTE');$ext=(string)$a['extension'];
        ProspectoNoContactarV3::exigirPermitido($this->db,$number);
        $marker=(new ZadarmaWebhookEventStoreService($this->db))->ultimoInicioSalienteId($ext);
        $token='sdk-'.bin2hex(random_bytes(16));$number=(string)$formats[0];
        $since=$a['asignacion_desde']??null;
        $s=$this->db->prepare("INSERT INTO telefonia_analista_webrtc_sesiones
            (usuario_id,extension,session_id,numero_origen,direccion,estado,fecha_inicio,
             protocolo,emitida,evento_desde,asignacion_desde)
            VALUES(?,?,?,?,'SALIENTE','preparada',UTC_TIMESTAMP(),2,0,?,?)");
        $s->bind_param('isssis',$user,$ext,$token,$number,$marker,$since);$s->execute();$s->close();
        return ['session_id'=>$token,'after_id'=>$marker,'server_time'=>time(),'extension'=>$ext];
    }
    /** Proyección PBX propia para una atención sin diálogo SDK; nunca crea una sesión. */
    public function obtenerPbxEntrantePropia(int $user,string $reference):array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$reference))throw new InvalidArgumentException('Referencia PBX inválida.');
        $assignment=$this->asignacion($user,'ENTRANTE');$ext=(string)$assignment['extension'];
        require_once __DIR__.'/../helpers/TelefoniaAsignacionFechaHelper.php';
        require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
        require_once __DIR__.'/TelefoniaLimpiezaGuard.php';
        $since=TelefoniaAsignacionFechaHelper::aMexico((string)($assignment['asignacion_desde']??''));
        if(TelefoniaLimpiezaGuard::bloqueada($this->db,$reference,$ext))throw new DomainException('La atención no está disponible.');
        $s=$this->db->prepare('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $s->bind_param('s',$reference);$s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $own=null;
        foreach($events as $event){
            if((string)$event['internal']===$ext&&in_array($event['evento'],['NOTIFY_INTERNAL','NOTIFY_ANSWER'],true)&&
                (string)$event['received_at']>=$since&&($event['call_start']===null||(string)$event['call_start']>=$since)){
                $own=$event;break;
            }
        }
        if($own===null)throw new DomainException('La referencia no acredita una entrante de tu asignación actual.');
        $s=$this->db->prepare('SELECT origen_extension,numero,evento_desde FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND destino_extension=BINARY ? AND referencia=BINARY ? LIMIT 2');
        $s->bind_param('iss',$user,$ext,$reference);$s->execute();$ops=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($ops)>1)throw new DomainException('La atención contiene operaciones incompatibles.');
        $op=$ops[0]??null;
        $facts=TelefoniaPbxSegmentosService::hechos($events,$ext,(string)($op['origen_extension']??''),(int)($op['evento_desde']??0));
        $number=(string)($own['caller_id']??'');
        if($op&&$facts['answer_at']!=='')$number=(string)$op['numero'];
        return ['pbx_call_id'=>$reference,'extension'=>$ext,'fuente'=>'EVIDENCIA_PBX','zona_horaria'=>'America/Mexico_City',
            'finalizada'=>(bool)$facts['finalizada'],'intento_finalizado'=>(bool)$facts['intento_finalizado'],
            'contestada'=>$facts['answer_at']!=='','interna'=>$this->esNumeroInterno($number,$ext),
            'segundos'=>$facts['segundos'],'answer_at'=>$facts['answer_at'],'end_at'=>$facts['end_at']];
    }
    public function obtener(int $user,string $id,bool $lock=false):?array
    {
        if($user<1||!self::validarSesion($id))throw new InvalidArgumentException('Sesión telefónica inválida.');
        $s=$this->db->prepare('SELECT * FROM telefonia_analista_webrtc_sesiones WHERE usuario_id=? AND session_id=BINARY ?'.($lock?' FOR UPDATE':''));
        $s->bind_param('is',$user,$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return $row?:null;
    }
    public function registrarEvento(int $user,array $data,int $protocol=1):void
    {
        $id=trim((string)($data['session_id']??''));$event=(string)($data['evento']??'');
        if(!self::validarSesion($id)||!in_array($event,['ringing','dialed','answered','ended','declined','missed','failed','transfer_link'],true))
            throw new InvalidArgumentException('Evento WebRTC inválido.');
        $seq=$protocol>=2?filter_var($data['secuencia']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]):0;
        if($protocol>=2&&!is_int($seq))throw new InvalidArgumentException('Secuencia de sesión inválida.');
        $number=trim((string)($data['numero']??''));if(!preg_match('/^[+0-9*#]{0,40}$/D',$number))$number='';
        $name=mb_substr(trim((string)($data['caller_name']??'')),0,100,'UTF-8');
        $sip=trim((string)($data['sdk_id']??''));
        if($sip!==''&&!preg_match('/^[A-Za-z0-9@._:+-]{1,180}$/D',$sip))throw new InvalidArgumentException('Diálogo SDK inválido.');
        $seconds=filter_var($data['duracion_observada_segundos']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
        $hash=hash('sha256',json_encode([$event,$number,$name,$sip,$seconds],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $operation=$attempt=null;
        if($event==='transfer_link'){
            if($protocol<2)throw new InvalidArgumentException('Este vínculo requiere el registro común.');
            $operation=filter_var($data['operacion_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $attempt=filter_var($data['intento']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!is_int($operation)||!is_int($attempt))throw new InvalidArgumentException('Operación de transferencia inválida.');
            // Mantener intacto el hash de los eventos antiguos para sus reintentos.
            $hash=hash('sha256',$hash.':'.$operation.':'.$attempt);
        }
        $this->db->begin_transaction();
        try{
            $row=$this->obtener($user,$id,true);
            $direction=$row['direccion']??($protocol>=2?(string)($data['direccion']??'ENTRANTE'):'ENTRANTE');
            $a=$this->asignacion($user,$direction);$ext=(string)$a['extension'];
            if(!$row){
                if($event!=='ringing'||$direction!=='ENTRANTE')throw new DomainException('Espera a que se confirme el inicio de esta sesión.');
                $since=$a['asignacion_desde']??null;$p=$protocol>=2?2:1;
                $s=$this->db->prepare("INSERT IGNORE INTO telefonia_analista_webrtc_sesiones
                    (usuario_id,extension,session_id,numero_origen,caller_name,fecha_inicio,direccion,protocolo,emitida,asignacion_desde)
                    VALUES(?,?,?,?,?,UTC_TIMESTAMP(),'ENTRANTE',?,1,?)");
                $s->bind_param('issssis',$user,$ext,$id,$number,$name,$p,$since);$s->execute();$s->close();
                $row=$this->obtener($user,$id,true);
            }
            if(!$row||(string)$row['extension']!==$ext)throw new DomainException('La sesión pertenece a otra asignación de teléfono.');
            if(!empty($row['asignacion_desde']) &&
                (string)$row['asignacion_desde']!==(string)($a['asignacion_desde']??''))
                throw new DomainException('La asignación de teléfono cambió desde esta sesión.');
            if((int)$row['protocolo']>=2&&$protocol<2)throw new DomainException('El host telefónico administra esta sesión.');
            if((int)$row['protocolo']<2&&$protocol>=2)
                throw new DomainException('Esta sesión usa el registro anterior del teléfono.');
            if($protocol>=2 && $seq<=(int)$row['ultima_secuencia']){
                if($seq===(int)$row['ultima_secuencia']&&!hash_equals((string)$row['ultimo_evento_hash'],$hash))throw new DomainException('La misma secuencia contiene otro evento.');
                $this->db->commit();return;
            }
            if($protocol>=2 && $seq!==(int)$row['ultima_secuencia']+1)
                throw new DomainException('Falta confirmar un evento anterior de esta sesión.');
            $terminal=$row['fecha_fin']!==null;$values=[];
            if($event==='transfer_link')$values=$this->vinculoTransferencia($user,$row,$a,$operation,$attempt);
            if(!$terminal){
                if($event==='dialed'){
                    if($direction!=='SALIENTE')throw new DomainException('Esta sesión no es saliente.');
                    if(in_array($row['estado'],['preparada','ringing'],true))$values=['estado'=>'ringing','emitida'=>1];
                }elseif($event==='answered'){
                    $values=['estado'=>'answered','emitida'=>1];
                    if($row['fecha_atendida']===null)$values['fecha_atendida']=gmdate('Y-m-d H:i:s');
                }elseif(in_array($event,['ended','declined','missed','failed'],true)){
                    // END y respuesta local no se convierten en ANSWER/END PBX.
                    $values=['estado'=>$event==='ended'&&$row['fecha_atendida']===null?'missed':$event,'fecha_fin'=>gmdate('Y-m-d H:i:s')];
                    if(is_int($seconds)&&$row['fecha_atendida']!==null)$values['duracion_observada_segundos']=$seconds;
                }
                if($protocol>=2&&$direction==='ENTRANTE'&&$event==='ended'&&$row['fecha_atendida']!==null&&
                    ($row['clasificacion']??'')==='PENDIENTE'&&$row['seguimiento_id']===null&&$row['interaccion_id']===null&&
                    $this->esNumeroInterno((string)$row['numero_origen'],$ext))
                    $values['clasificacion']='INTERNA';
            }
            if($sip!==''&&empty($row['sdk_id']))$values['sdk_id']=$sip;
            elseif($sip!==''&&$sip!==(string)$row['sdk_id'])throw new DomainException('El evento pertenece a otro diálogo SDK.');
            if($protocol>=2)$values+=['ultima_secuencia'=>$seq,'ultimo_evento_hash'=>$hash];
            $values=array_filter($values,static fn($value,$field)=>!array_key_exists($field,$row)||(string)$row[$field]!== (string)$value,ARRAY_FILTER_USE_BOTH);
            if($values){
                $sets=[];$args=[];foreach($values as $field=>$value){$sets[]=$field.'=?';$args[]=$value;}
                $args[]=$user;$args[]=$id;
                $s=$this->db->prepare('UPDATE telefonia_analista_webrtc_sesiones SET '.implode(',',$sets).',updated_at=UTC_TIMESTAMP() WHERE usuario_id=? AND session_id=BINARY ?');
                $s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$s->close();
            }
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollback();throw $error;}
    }
    /** Sólo el diálogo reservado por la operación puede recibir su referencia.
     * Funciona después del END local, sin reabrirlo ni acreditar un resultado.
     */
    private function vinculoTransferencia(int $user,array $row,array $assignment,int $id,int $attempt):array
    {
        if($row['direccion']!=='ENTRANTE'||empty($row['sdk_id'])||empty($row['fecha_atendida']))
            throw new DomainException('Falta la observación del diálogo entrante atendido.');
        $s=$this->db->prepare('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND intento=? AND destino_usuario=? FOR UPDATE');
        $s->bind_param('iii',$id,$attempt,$user);$s->execute();$op=$s->get_result()->fetch_assoc();$s->close();
        if(!$op||$op['destino_extension']!==$row['extension']||$op['sdk_consulta']!==$row['session_id']||
            $op['estado']!=='ENTREGADA'||($op['modo']==='CONSULTADA'&&empty($op['entregada_at'])))
            throw new DomainException('La operación no acredita la entrega a este diálogo.');
        if(!empty($row['pbx_call_id'])&&$row['pbx_call_id']!==$op['referencia'])
            throw new DomainException('El diálogo ya pertenece a otra referencia PBX.');
        require_once __DIR__.'/../helpers/TelefoniaAsignacionFechaHelper.php';
        require_once __DIR__.'/TelefoniaLimpiezaGuard.php';
        if(TelefoniaLimpiezaGuard::bloqueada($this->db,$op['referencia'],$row['extension']))
            throw new DomainException('La atención no está disponible.');
        $since=TelefoniaAsignacionFechaHelper::aMexico((string)($assignment['asignacion_desde']??''));
        $s=$this->db->prepare("SELECT id FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? AND internal=BINARY ? AND evento='NOTIFY_ANSWER' AND id>? AND received_at>=? AND (call_start IS NULL OR call_start>=?) ORDER BY id LIMIT 1");
        $s->bind_param('ssiss',$op['referencia'],$row['extension'],$op['evento_desde'],$since,$since);
        $s->execute();$answer=$s->get_result()->fetch_assoc();$s->close();
        if(!$answer)throw new DomainException('Falta evidencia PBX de respuesta propia en este intento.');
        if(!empty($row['pbx_inicio_evento'])&&(int)$row['pbx_inicio_evento']!==(int)$answer['id'])
            throw new DomainException('El diálogo ya está asociado a otro tramo.');
        return ['pbx_call_id'=>$op['referencia'],'pbx_inicio_evento'=>(int)$answer['id']];
    }
}
