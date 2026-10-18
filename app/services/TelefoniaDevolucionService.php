<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaDisponibilidadService.php';
require_once __DIR__.'/TelefoniaRecepcionMarketingService.php';
require_once __DIR__.'/../models/SeguimientoVinculacionModel.php';
/** Intentos propios de devolución; nunca acreditan una verificación por contestar. */
final class TelefoniaDevolucionService
{
    private mysqli $db;
    private TelefoniaDisponibilidadService $disponibilidad;
    public const RESULTADOS=['CONTACTO'=>'Persona atendió','BUZON'=>'Buzón de voz','SIN_RESPUESTA'=>'Sin respuesta','NO_DISPONIBLE'=>'Fuera de servicio / no disponible','NUMERO_INCORRECTO'=>'Número incorrecto','LLAMAR_DESPUES'=>'Solicitó llamar después'];
    public function __construct(?mysqli $db=null){
        $this->db=$db??(new Database())->connect();$this->disponibilidad=new TelefoniaDisponibilidadService($this->db);
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_devolucion_intentos (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,solicitud_id BIGINT UNSIGNED NOT NULL,version INT NOT NULL,
          usuario_id INT NOT NULL,extension VARCHAR(12) NOT NULL,numero VARCHAR(40) NOT NULL,
          desde_evento_id BIGINT NOT NULL,pbx_call_id VARCHAR(100) NULL,estado VARCHAR(20) NOT NULL DEFAULT 'PREPARADO',
          resultado VARCHAR(30) NOT NULL DEFAULT '',identificacion VARCHAR(24) NOT NULL DEFAULT '',
          seguimiento_id INT NOT NULL DEFAULT 0,interaccion_id INT NULL,institucion_nombre VARCHAR(150) NOT NULL DEFAULT '',
          observacion VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
          UNIQUE KEY uk_dev_llamada(usuario_id,pbx_call_id),KEY idx_dev_pendiente(usuario_id,estado,resultado),
          KEY idx_dev_solicitud(solicitud_id,version,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function one(string $sql,array $v=[]): ?array {$s=$this->db->prepare($sql);if($v)$s->bind_param(str_repeat('s',count($v)),...$v);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;}
    private function ext(int $user): array {
        $s=(new TelefoniaExtensionService($this->db))->resolverParaUsuario($user);
        if(!$s||empty($s['permite_entrantes']))throw new DomainException('Tu extensión no está habilitada.');return $s;
    }
    private function task(int $user,int $id): array {
        $t=$this->disponibilidad->obtener($user,$id);
        if(!$t||(int)$t['destino_usuario']!==$user||!in_array($t['devolucion'],['PENDIENTE','ATENDIDA'],true))throw new DomainException('La devolución no te corresponde.');return $t;
    }
    public function listar(int $user): array {
        $tasks=$this->disponibilidad->devoluciones($user,true);
        foreach($tasks as &$t){
            $route=json_decode($t['ruta_json'],true)?:[];$t['institucion_nombre']=(string)($route['institucion_nombre']??'');$t['seguimiento_id']=(int)($route['seguimiento_id']??0);unset($t['ruta_json']);
            $t['intento']=$this->one('SELECT * FROM telefonia_devolucion_intentos WHERE solicitud_id=? AND version=? AND usuario_id=? ORDER BY id DESC LIMIT 1',[$t['id'],$t['version'],$user]);
            if($t['intento'] && $t['intento']['resultado']!==''){
                $t['seguimiento_id']=(int)$t['intento']['seguimiento_id'];
                $t['institucion_nombre']=$t['intento']['institucion_nombre'];
            }
            if($t['seguimiento_id']>0 && !$this->one('SELECT id FROM seguimientos_vinculacion WHERE id=? AND analista_id=? AND activo=1',[$t['seguimiento_id'],$user]))$t['seguimiento_id']=0;
        }unset($t);return $tasks;
    }
    public function preparar(int $user,int $id): array {
        $task=$this->task($user,$id);$ext=$this->ext($user);
        if($task['devolucion']!=='PENDIENTE'||empty($ext['permite_salientes']))throw new DomainException('No se puede llamar desde esta devolución.');
        $number=preg_replace('/[^0-9+]/','',$task['telefono']);
        if(strlen(preg_replace('/\D/','',$number))<8)throw new DomainException('Revisa el teléfono de devolución.');
        $this->db->begin_transaction();try{
            $this->one('SELECT usuario_id FROM telefonia_extensiones WHERE usuario_id=? FOR UPDATE',[$user]);
            if($this->one("SELECT id FROM telefonia_devolucion_intentos WHERE usuario_id=? AND extension=? AND resultado='' AND estado<>'CANCELADO' LIMIT 1",[$user,$ext['extension']]))throw new DomainException('Registra o revisa la devolución anterior antes de iniciar otra.');
            $max=$this->one("SELECT COALESCE(MAX(id),0) id FROM telefonia_zadarma_eventos WHERE internal=? AND evento='NOTIFY_OUT_START'",[$ext['extension']]);
            $s=$this->db->prepare("INSERT INTO telefonia_devolucion_intentos (solicitud_id,version,usuario_id,extension,numero,desde_evento_id,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $args=[$id,$task['version'],$user,$ext['extension'],$number,$max['id']];$s->bind_param('ssssss',...$args);$s->execute();$attempt=(int)$this->db->insert_id;$s->close();$this->db->commit();return $this->estado($user,$attempt);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function estado(int $user,int $id): array {
        $ext=$this->ext($user);$r=$this->one('SELECT * FROM telefonia_devolucion_intentos WHERE id=? AND usuario_id=? AND extension=?',[$id,$user,$ext['extension']]);
        if(!$r)throw new DomainException('El intento no te corresponde.');$task=$this->task($user,(int)$r['solicitud_id']);
        if((int)$task['version']!==(int)$r['version'])throw new DomainException('La devolución cambió de responsable.');
        if(!$r['pbx_call_id'] && $r['estado']!=='CANCELADO'){
            $from=(new DateTimeImmutable($r['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'));
            $a=$from->modify('-5 seconds')->format('Y-m-d H:i:s');$b=$from->modify('+2 minutes')->format('Y-m-d H:i:s');
            $suffix=substr(preg_replace('/\D/','',$r['numero']),-10);
            $s=$this->db->prepare("SELECT pbx_call_id,MIN(id) first_id FROM telefonia_zadarma_eventos WHERE evento='NOTIFY_OUT_START' AND internal=? AND id>? AND received_at BETWEEN ? AND ? AND RIGHT(destination_digits,10)=? GROUP BY pbx_call_id ORDER BY first_id LIMIT 2");
            $s->bind_param('sisss',$r['extension'],$r['desde_evento_id'],$a,$b,$suffix);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            if(count($rows)===1){
                $ref=$rows[0]['pbx_call_id'];$s=$this->db->prepare("UPDATE telefonia_devolucion_intentos SET pbx_call_id=?,estado='EN_CURSO',updated_at=UTC_TIMESTAMP() WHERE id=? AND pbx_call_id IS NULL AND estado='PREPARADO'");$s->bind_param('si',$ref,$id);$s->execute();$s->close();$r=$this->one('SELECT * FROM telefonia_devolucion_intentos WHERE id=?',[$id]);
            }
        }
        $call=null;
        if($r['pbx_call_id']){
            $s=$this->db->prepare('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');$s->bind_param('s',$r['pbx_call_id']);$s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            foreach(TelefoniaRecepcionMarketingService::agruparEventos($events,$r['extension'],true) as $c)if($c['pbx_call_id']===$r['pbx_call_id'] && $c['tipo']==='Saliente')$call=$c;
        }
        $r['finalizada']=!empty($call['finalizada']);$r['contestada']=$r['finalizada'] && in_array($call['estado_cierre']??'', ['Completada','Atendida'],true) && (int)($call['segundos']??0)>0;
        $r['estado_llamada']=$call['estado_cierre']??($r['estado']==='CANCELADO'?'Cancelada':'Esperando registro de la llamada');
        $r['fecha_inicio']=$call['fecha']??'';$r['fecha_fin']=$call['fecha_fin']??'';
        $r['duracion']=(int)($call['segundos']??0);$r['puede_registrar']=$r['finalizada']&&$r['resultado']==='';return $r;
    }
    public function cancelar(int $user,int $id): void {
        $r=$this->estado($user,$id);if($r['pbx_call_id'])throw new DomainException('La llamada ya comenzó. Espera su resultado.');
        $s=$this->db->prepare("UPDATE telefonia_devolucion_intentos SET estado='CANCELADO',updated_at=UTC_TIMESTAMP() WHERE id=? AND usuario_id=? AND pbx_call_id IS NULL");$s->bind_param('ii',$id,$user);$s->execute();$s->close();
    }
    public function registrar(int $user,int $id,array $data): array {
        $attempt=$this->estado($user,$id);$code=(string)($data['resultado']??'');$note=VerificacionInstitucionalService::limpiarObservacion((string)($data['observacion']??''));
        if(!isset(self::RESULTADOS[$code])||mb_strlen($note)>500)throw new DomainException('Selecciona un resultado y revisa la nota.');
        if(!$attempt['puede_registrar'])throw new DomainException('Espera la confirmación del fin de la llamada; el resultado guardado es definitivo.');
        if(in_array($code,['CONTACTO','LLAMAR_DESPUES'],true)&&!$attempt['contestada'])throw new DomainException('Esta llamada no tiene una conversación confirmada.');
        $kind=(string)($data['identificacion']??'SIN_IDENTIFICAR');$sid=0;$name='';
        if(!in_array($kind,['EXISTENTE','NUEVA','SIN_IDENTIFICAR'],true))throw new DomainException('Selecciona cómo identificar la institución.');
        if($kind==='EXISTENTE'){
            $sid=(int)($data['seguimiento_id']??0);$row=$this->one("SELECT nombre_entidad FROM seguimientos_vinculacion WHERE id=? AND analista_id=? AND activo=1 AND estado_seguimiento<>'DESCARTADO'",[$sid,$user]);
            if(!$row)throw new DomainException('Selecciona un expediente propio y activo.');$name=$row['nombre_entidad'];
        }elseif($kind==='NUEVA'){$name=trim((string)($data['institucion_nombre']??''));if(mb_strlen($name)<2||mb_strlen($name)>150)throw new DomainException('Escribe el nombre de la institución.');}
        $this->db->begin_transaction();try{
            $row=$this->one('SELECT resultado FROM telefonia_devolucion_intentos WHERE id=? AND usuario_id=? FOR UPDATE',[$id,$user]);
            $task=$this->one('SELECT devolucion FROM telefonia_disponibilidad WHERE id=? AND destino_usuario=? AND version=? FOR UPDATE',[$attempt['solicitud_id'],$user,$attempt['version']]);
            if(!$row||$row['resultado']!==''||!$task||$task['devolucion']!=='PENDIENTE')throw new DomainException('El resultado ya se guardó o la devolución cambió.');
            $interactionId=null;
            if($sid>0){
                $model=new SeguimientoVinculacionModel($this->db);
                $results=['CONTACTO'=>'CONTACTADO','BUZON'=>'BUZON_VOZ','SIN_RESPUESTA'=>'SIN_RESPUESTA','NO_DISPONIBLE'=>'FUERA_SERVICIO','NUMERO_INCORRECTO'=>'NUMERO_INCORRECTO','LLAMAR_DESPUES'=>'SOLICITO_LLAMAR_DESPUES'];
                $interactionId=$model->registrarInteraccionManual($sid,$user,[
                    'canal'=>'LLAMADA_IP','resultado'=>$results[$code],
                    'fecha_inicio'=>$attempt['fecha_inicio'],'fecha_fin'=>$attempt['fecha_fin'],
                    'telefono_destino'=>$attempt['numero'],'proveedor_externo'=>'ZADARMA',
                    'id_externo'=>$attempt['pbx_call_id'],'duracion_segundos'=>$attempt['duracion'],
                    'notas'=>trim("Devolución solicitada por recepción.\n".$note),
                    'preservar_estado'=>true,'descartar'=>0,'verificacion_detalles'=>[]
                ],true);
                if(!$interactionId)throw new DomainException('No se pudo guardar el resultado en el expediente. Inténtalo nuevamente.');
            }
            $s=$this->db->prepare("UPDATE telefonia_devolucion_intentos SET estado='FINALIZADO',resultado=?,identificacion=?,seguimiento_id=?,interaccion_id=?,institucion_nombre=?,observacion=?,updated_at=UTC_TIMESTAMP() WHERE id=?");$s->bind_param('ssiissi',$code,$kind,$sid,$interactionId,$name,$note,$id);$s->execute();$s->close();
            if(in_array($code,['CONTACTO','NUMERO_INCORRECTO'],true)){$s=$this->db->prepare("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA',updated_at=UTC_TIMESTAMP() WHERE id=? AND destino_usuario=?");$s->bind_param('ii',$attempt['solicitud_id'],$user);$s->execute();$s->close();}
            $this->db->commit();return $this->estado($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
