<?php

declare(strict_types=1);
require_once __DIR__ . '/../helpers/SeguimientoTerritorialScope.php';
require_once __DIR__.'/TelefoniaDisponibilidadService.php';
require_once __DIR__.'/TelefoniaRecepcionMarketingService.php';
require_once __DIR__.'/TelefoniaEntrantesAnalistaService.php';
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
          observacion VARCHAR(500) NOT NULL DEFAULT '',datos_json TEXT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
          UNIQUE KEY uk_dev_llamada(usuario_id,pbx_call_id),KEY idx_dev_pendiente(usuario_id,estado,resultado),
          KEY idx_dev_solicitud(solicitud_id,version,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $column=$this->db->query("SHOW COLUMNS FROM telefonia_devolucion_intentos LIKE 'datos_json'");
        if(!$column->num_rows){try{$this->db->query('ALTER TABLE telefonia_devolucion_intentos ADD COLUMN datos_json TEXT NULL');}catch(mysqli_sql_exception $e){if($e->getCode()!==1060)throw $e;}}

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
            if($t['seguimiento_id']>0 && !$this->one('SELECT id FROM seguimientos_vinculacion WHERE id=? AND ' . SeguimientoTerritorialScope::analista('seguimientos_vinculacion') . ' AND activo=1',[$t['seguimiento_id'],$user]))$t['seguimiento_id']=0;
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
        $attempt=$this->estado($user,$id);
        $aliases=['CONTACTO'=>'CONTACTO_CORRECTO','BUZON'=>'BUZON_VOZ','NO_DISPONIBLE'=>'FUERA_SERVICIO','LLAMAR_DESPUES'=>'SOLICITO_LLAMAR_DESPUES'];
        $code=$aliases[$data['resultado']??'']??(string)($data['resultado']??'');$data['resultado']=$code;
        $allowed=['CONTACTO_CORRECTO','CONTACTO_REFERIDO','BUZON_VOZ','FUERA_SERVICIO','SIN_RESPUESTA','OCUPADO','NUMERO_INCORRECTO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO','OTRO'];
        $note=VerificacionInstitucionalService::limpiarObservacion((string)($data['observacion']??''));
        if(!in_array($code,$allowed,true)||mb_strlen($note)>500)throw new DomainException('Selecciona un resultado y limita la nota a 500 caracteres.');
        if(!$attempt['puede_registrar'])throw new DomainException('Espera la confirmación del fin de la llamada; el resultado guardado es definitivo.');
        $negative=in_array($code,['BUZON_VOZ','FUERA_SERVICIO','SIN_RESPUESTA','OCUPADO','NUMERO_INCORRECTO'],true);
        if(!$negative&&!$attempt['contestada'])throw new DomainException('Esta llamada no tiene una conversación confirmada.');
        $legacy=['EXISTENTE'=>'existente','NUEVA'=>'identificada','SIN_IDENTIFICAR'=>'sin_identificar'];
        $decision=(string)($data['decision']??($legacy[$data['identificacion']??'SIN_IDENTIFICAR']??''));$data['decision']=$decision;
        if(!in_array($decision,['existente','nueva','identificada','sin_identificar'],true))throw new DomainException('Selecciona cómo identificar la institución.');
        $person=VerificacionInstitucionalService::limpiarObservacion((string)($data['persona_atendio']??''));$data['persona_atendio']=$person;
        if(mb_strlen($person)>140)throw new DomainException('El nombre de contacto es demasiado largo.');
        $next=(string)($data['proxima_accion']??'');$nextAt=trim((string)($data['proxima_accion_at']??''));
        if(!in_array($next,['','Volver a llamar','Enviar WhatsApp','Revisar correo','Preparar oficio'],true))throw new DomainException('La próxima acción no es válida.');
        if($code==='SOLICITO_LLAMAR_DESPUES'&&$nextAt==='')throw new DomainException('Indica la fecha de la próxima llamada.');
        if($nextAt!==''&&(!strtotime($nextAt)||strtotime($nextAt)<=time()))throw new DomainException('La próxima acción debe ser futura.');
        $sid=0;$name='';$interactionId=null;
        if($decision==='identificada'){$name=trim((string)($data['institucion_nombre']??''));if(mb_strlen($name)<2||mb_strlen($name)>150)throw new DomainException('Escribe el nombre de la institución.');}
        // Construir servicios y estructura antes de iniciar la transacción de atención.
        $registration=new TelefoniaEntrantesAnalistaService($this->db);(new TelefoniaVerificacionEvidenciaService($this->db))->asegurarEstructura();
        $this->db->begin_transaction();try{
            $row=$this->one('SELECT resultado FROM telefonia_devolucion_intentos WHERE id=? AND usuario_id=? FOR UPDATE',[$id,$user]);
            $task=$this->one('SELECT devolucion FROM telefonia_disponibilidad WHERE id=? AND destino_usuario=? AND version=? FOR UPDATE',[$attempt['solicitud_id'],$user,$attempt['version']]);
            if(!$row||$row['resultado']!==''||!$task||$task['devolucion']!=='PENDIENTE')throw new DomainException('El resultado ya se guardó o la devolución cambió.');
            $atencion=[];
            if(in_array($decision,['existente','nueva'],true)){
                $call=['extension'=>$attempt['extension'],'estado'=>'ended','caller_id'=>$attempt['numero'],
                    'call_start'=>$attempt['fecha_inicio'],'fecha_evento'=>$attempt['fecha_inicio'],
                    'answer_at'=>$attempt['contestada']?date('Y-m-d H:i:s',strtotime($attempt['fecha_fin'])-$attempt['duracion']):'',
                    'end_at'=>$attempt['fecha_fin'],'duration'=>$attempt['duracion'],
                    'origen_registro'=>'Devolución solicitada por recepción.'];
                $atencion=$registration->registrarAtencionEnExpediente($user,$call,$data,false,$attempt['pbx_call_id']);
                $sid=(int)$atencion['seguimiento_id'];$interactionId=(int)$atencion['interaccion_id'];
                $name=$this->one('SELECT nombre_entidad FROM seguimientos_vinculacion WHERE id=?',[$sid])['nombre_entidad'];
            }
            $kind=['existente'=>'EXISTENTE','nueva'=>'NUEVA','identificada'=>'IDENTIFICADA','sin_identificar'=>'SIN_IDENTIFICAR'][$decision];
            $details=json_encode(array_intersect_key($data,array_flip(['decision','resultado','persona_atendio','proxima_accion','proxima_accion_at','tipo_entidad','telefono_capturado','correo_capturado','nuevo_contacto_nombre','nuevo_contacto_cargo','nuevo_telefono_contacto','nuevo_correo_contacto'])),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $s=$this->db->prepare("UPDATE telefonia_devolucion_intentos SET estado='FINALIZADO',resultado=?,identificacion=?,seguimiento_id=?,interaccion_id=?,institucion_nombre=?,observacion=?,datos_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?");$s->bind_param('ssiisssi',$code,$kind,$sid,$interactionId,$name,$note,$details,$id);$s->execute();$s->close();
            if(!$negative && $code!=='SOLICITO_LLAMAR_DESPUES' || $code==='NUMERO_INCORRECTO'){$s=$this->db->prepare("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA',updated_at=UTC_TIMESTAMP() WHERE id=? AND destino_usuario=?");$s->bind_param('ii',$attempt['solicitud_id'],$user);$s->execute();$s->close();}
            $this->db->commit();return $this->estado($user,$id)+['atencion'=>$atencion];
        }catch(Throwable $e){$this->db->rollback();throw $e;}

    }
}
