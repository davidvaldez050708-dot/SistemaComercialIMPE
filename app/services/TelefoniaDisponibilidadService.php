<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/TelefoniaTransferenciaOperacionService.php';

/** Confirmación en el CRM: no inicia llamadas, no pone al cliente en espera. */
final class TelefoniaDisponibilidadService
{
    private mysqli $db;
    public function __construct(?mysqli $db=null){
        $this->db=$db??(new Database())->connect();
        new TelefoniaTransferenciaOperacionService($this->db);
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_presencia (
            usuario_id INT PRIMARY KEY,extension VARCHAR(12) NOT NULL,ocupado TINYINT NOT NULL,
            updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_disponibilidad (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,origen_usuario INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL,destino_usuario INT NOT NULL,destino_extension VARCHAR(12) NOT NULL,
            referencia VARCHAR(100) NOT NULL,numero VARCHAR(40) NOT NULL,ruta_json TEXT NOT NULL,
            estado VARCHAR(20) NOT NULL,version INT NOT NULL DEFAULT 1,expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
            contacto VARCHAR(100) NOT NULL DEFAULT '',telefono VARCHAR(40) NOT NULL DEFAULT '',
            nota VARCHAR(500) NOT NULL DEFAULT '',devolucion VARCHAR(12) NOT NULL DEFAULT '',
            UNIQUE KEY uk_disp_origen(origen_usuario,referencia),KEY idx_disp_destino(destino_usuario,estado,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function one(string $sql,array $values=[]): ?array {
        $s=$this->db->prepare($sql);if($values)$s->bind_param(str_repeat('s',count($values)),...$values);
        $s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
    }
    private function change(int $id,array $values): void {
        if (($values['devolucion'] ?? '') === 'PENDIENTE') {
            $actual=$this->one('SELECT ruta_json FROM telefonia_disponibilidad WHERE id=?',[$id]);
            $ruta=json_decode((string)($values['ruta_json'] ?? $actual['ruta_json'] ?? '{}'),true) ?: [];
            if (($ruta['tipo'] ?? '') === 'PROSPECTO') {
                $ruta['devolucion_solicitada_at']=gmdate('Y-m-d H:i:s');
                $values['ruta_json']=json_encode($ruta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
        }
        $sets=[];$args=[];foreach($values as $key=>$value){$sets[]="$key=?";$args[]=$value;}
        $args[]=gmdate('Y-m-d H:i:s');$args[]=$id;
        $s=$this->db->prepare('UPDATE telefonia_disponibilidad SET '.implode(',',$sets).',updated_at=? WHERE id=?');
        $s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$s->close();
    }
    private function extension(int $user): array {
        $r=$this->one("SELECT t.*,GREATEST(t.created_at,t.updated_at) AS desde FROM telefonia_extensiones t JOIN usuarios u ON u.id=t.usuario_id AND u.estado=1 WHERE t.usuario_id=? AND t.activo=1 AND t.proveedor='ZADARMA' AND t.permite_entrantes=1",[$user]);
        if(!$r)throw new DomainException('Tu extensión no está habilitada.');return $r;
    }
    private function expire(): void {
        $this->db->query("UPDATE telefonia_disponibilidad SET estado='VENCIDA',updated_at=UTC_TIMESTAMP() WHERE estado IN ('PENDIENTE','ACEPTADA') AND expires_at<=UTC_TIMESTAMP()");
    }
    private function live(int $user,string $ref): array {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref))throw new DomainException('Espera a que se identifique la llamada.');
        $ext=$this->extension($user);
        $r=$this->one("SELECT evento,caller_id,destination FROM telefonia_zadarma_eventos WHERE pbx_call_id=? AND internal=? AND evento IN ('NOTIFY_INTERNAL','NOTIFY_OUT_START') AND received_at>=? ORDER BY id LIMIT 1",[$ref,$ext['extension'],$ext['desde']]);
        if(!$r)throw new DomainException('Aún no se confirma la llamada de tu extensión.');
        if($this->one("SELECT id FROM telefonia_transferencia_operaciones WHERE origen_usuario=? AND referencia=? AND estado NOT IN ('DEVUELTA','FALLIDA') LIMIT 1",[$user,$ref]))throw new DomainException('Esta llamada ya tiene una transferencia en curso o completada.');
        if($this->one("SELECT id FROM telefonia_zadarma_eventos WHERE pbx_call_id=? AND internal=? AND evento IN ('NOTIFY_OUT_END','NOTIFY_END') LIMIT 1",[$ref,$ext['extension']]))throw new DomainException('Esta llamada ya terminó.');
        return ['extension'=>$ext['extension'],'numero'=>$r['evento']==='NOTIFY_OUT_START'?$r['destination']:$r['caller_id']];
    }
    public function presencia(int $user,bool $ready): void {
        $ext=$this->extension($user);$busy=$ready?0:1;
        $s=$this->db->prepare('INSERT INTO telefonia_presencia VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE extension=VALUES(extension),ocupado=VALUES(ocupado),updated_at=UTC_TIMESTAMP()');
        $s->bind_param('isi',$user,$ext['extension'],$busy);$s->execute();$s->close();
        if($busy){$s=$this->db->prepare("UPDATE telefonia_disponibilidad SET estado='RECHAZADA',updated_at=UTC_TIMESTAMP() WHERE destino_usuario=? AND destino_extension=? AND estado='ACEPTADA'");$s->bind_param('is',$user,$ext['extension']);$s->execute();$s->close();}
    }
    private function available(int $user,string $ext): bool {
        $r=$this->one('SELECT * FROM telefonia_presencia WHERE usuario_id=? AND extension=? AND ocupado=0 AND updated_at>=? FOR UPDATE',[$user,$ext,gmdate('Y-m-d H:i:s',time()-15)]);
        return (bool)$r;
    }
    public static function normalizarNombreProspecto(string $nombre): string {
        $nombre=trim($nombre);
        if(mb_strlen($nombre,'UTF-8')<2 || mb_strlen($nombre,'UTF-8')>150){
            throw new DomainException('Registra el nombre del prospecto o de quien llama antes de solicitar disponibilidad.');
        }
        return $nombre;
    }
    public function solicitar(int $user,array $route): array {
        $call=$this->live($user,(string)$route['referencia_llamada']);$dest=$this->extension((int)$route['responsable_id']);
        if($dest['extension']!==$route['extension'] || (int)$route['responsable_id']===$user)throw new DomainException('El responsable ya no está disponible.');
        if(($route['tipo']??'')==='INSTITUCION'){
            if((int)($route['seguimiento_id']??0)>0){
                $inst=$this->one("SELECT nombre_entidad FROM seguimientos_vinculacion WHERE id=? AND analista_id=? AND estado_id=? AND activo=1 AND estado_seguimiento<>'DESCARTADO'",[$route['seguimiento_id'],$route['responsable_id'],$route['estado_id']]);
                if(!$inst)throw new DomainException('El expediente no corresponde al responsable seleccionado.');$route['institucion_nombre']=$inst['nombre_entidad'];
            }elseif(mb_strlen(trim((string)($route['institucion_nombre']??'')))<2 || mb_strlen((string)$route['institucion_nombre'])>150)throw new DomainException('Escribe el nombre de la institución o persona que llama.');
        }
        if(($route['tipo']??'')==='PROSPECTO'){
            // La identificación es un dato que proporcionó Recepción, no
            // acredita conversación ni crea prospectos o expedientes.
            $route['institucion_nombre']=self::normalizarNombreProspecto((string)($route['institucion_nombre']??''));
        }
        $this->expire();$this->db->begin_transaction();
        try{
            // Serializar también solicitudes simultáneas de la misma cuenta.
            $this->one('SELECT usuario_id FROM telefonia_extensiones WHERE usuario_id=? FOR UPDATE',[$user]);
            $r=$this->one('SELECT * FROM telefonia_disponibilidad WHERE origen_usuario=? AND referencia=? FOR UPDATE',[$user,$route['referencia_llamada']]);
            if($r && (in_array($r['estado'],['PENDIENTE','ACEPTADA','ENVIANDO','TRANSFERIDA'],true)||$r['devolucion']==='PENDIENTE')){
                $this->db->commit();return $this->obtener($user,(int)$r['id']);
            }
            $now=gmdate('Y-m-d H:i:s');$until=gmdate('Y-m-d H:i:s',time()+45);$json=json_encode($route,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($r){$id=(int)$r['id'];$this->change($id,['destino_usuario'=>$route['responsable_id'],'destino_extension'=>$route['extension'],'numero'=>$call['numero'],'ruta_json'=>$json,'estado'=>'PENDIENTE','version'=>(int)$r['version']+1,'expires_at'=>$until,'created_at'=>$now]);}
            else {
                $s=$this->db->prepare("INSERT INTO telefonia_disponibilidad (origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'PENDIENTE',?,?,?)");
                $args=[$user,$call['extension'],$route['responsable_id'],$route['extension'],$route['referencia_llamada'],$call['numero'],$json,$until,$now,$now];
                $s->bind_param('ssssssssss',...$args);$s->execute();$id=(int)$this->db->insert_id;$s->close();
            }
            $this->db->commit();return $this->obtener($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function obtener(int $user,int $id): ?array {
        $this->expire();$ext=$this->extension($user);
        $r=$this->one('SELECT * FROM telefonia_disponibilidad WHERE id=? AND ((origen_usuario=? AND origen_extension=?) OR (destino_usuario=? AND destino_extension=?))',[$id,$user,$ext['extension'],$user,$ext['extension']]);
        if(!$r)return null;
        if(in_array($r['estado'],['ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR'],true)){
            $opRow=$this->one('SELECT id FROM telefonia_transferencia_operaciones WHERE origen_usuario=? AND referencia=?',[$r['origen_usuario'],$r['referencia']]);
            $op=$opRow?(new TelefoniaTransferenciaOperacionService($this->db))->obtener($user,(int)$opRow['id']):null;
            $state=['ENVIADA'=>'ENVIADA','ENTREGADA'=>'TRANSFERIDA','FALLIDA'=>'FALLIDA','SIN_RESPUESTA'=>'SIN_RESPUESTA','SIN_CONFIRMAR'=>'SIN_CONFIRMAR','DEVUELTA'=>'FALLIDA'][$op['estado']??'']??'';
            $patch=[];if($state&&$state!==$r['estado'])$patch['estado']=$state;
            if($state==='SIN_RESPUESTA'&&$r['devolucion']===''&&strlen(preg_replace('/\D/','',$r['numero']))>=7){
                $route=json_decode($r['ruta_json'],true)?:[];
                $patch+=['contacto'=>mb_substr(trim((string)($route['institucion_nombre']??''))?:'Contacto de la transferencia',0,100),'telefono'=>$r['numero'],
                    'nota'=>'Transferencia no contestada. Coordina con recepción (Ext. '.$r['origen_extension'].') antes de devolver la llamada.','devolucion'=>'PENDIENTE'];
            }
            if($patch){$this->change($id,$patch);$r=array_merge($r,$patch);}
        }
        $r['segundos']=max(0,strtotime($r['expires_at'].' UTC')-time());
        $route=json_decode($r['ruta_json'],true)?:[];$r['responsable']=(string)($route['nombre_destino']??'Responsable');
        $r['institucion_nombre']=(string)($route['institucion_nombre']??'');$r['seguimiento_id']=(int)($route['seguimiento_id']??0);
        unset($r['ruta_json']);return $r;
    }
    public function delOrigen(int $user,string $ref): ?array {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref))return null;
        $ext=$this->extension($user);
        $r=$this->one('SELECT id FROM telefonia_disponibilidad WHERE origen_usuario=? AND origen_extension=? AND referencia=?',[$user,$ext['extension'],$ref]);
        return $r?$this->obtener($user,(int)$r['id']):null;
    }
    public function entrante(int $user): ?array {
        $this->expire();$ext=$this->extension($user);
        if($this->one("SELECT id FROM telefonia_disponibilidad WHERE destino_usuario=? AND (estado='ACEPTADA' OR (estado='ENVIANDO' AND updated_at>=?)) LIMIT 1",[$user,gmdate('Y-m-d H:i:s',time()-30)]))return null;
        $r=$this->one("SELECT id FROM telefonia_disponibilidad WHERE destino_usuario=? AND destino_extension=? AND estado='PENDIENTE' ORDER BY id LIMIT 1",[$user,$ext['extension']]);
        return $r?$this->obtener($user,(int)$r['id']):null;
    }
    public function accion(int $user,int $id,int $version,string $action,array $data=[]): array {
        $this->expire();$ext=$this->extension($user);$this->db->begin_transaction();
        try{
            // Bloquear presencia antes de reservar evita aceptar dos llamadas a la vez.
            $ready=$this->available($user,$ext['extension']);
            $r=$this->one('SELECT * FROM telefonia_disponibilidad WHERE id=? AND version=? AND ((origen_usuario=? AND origen_extension=?) OR (destino_usuario=? AND destino_extension=?)) FOR UPDATE',[$id,$version,$user,$ext['extension'],$user,$ext['extension']]);
            if(!$r)throw new DomainException('La solicitud cambió o no te corresponde.');
            $source=(int)$r['origen_usuario']===$user;$patch=[];
            if(!$source && in_array($action,['ACEPTAR','RECHAZAR'],true) && $r['estado']==='PENDIENTE'){
                if($action==='ACEPTAR'){
                    if(!$ready)throw new DomainException('Tu teléfono está ocupado o desconectado.');
                    $other=$this->one("SELECT id FROM telefonia_disponibilidad WHERE destino_usuario=? AND id<>? AND (estado='ACEPTADA' OR (estado IN ('ENVIANDO','TRANSFERIDA') AND updated_at>=?)) LIMIT 1",[$user,$id,gmdate('Y-m-d H:i:s',time()-15)]);
                    if($other)throw new DomainException('Ya aceptaste otra llamada.');
                    $this->live((int)$r['origen_usuario'],$r['referencia']);
                    $patch=['estado'=>'ACEPTADA','expires_at'=>gmdate('Y-m-d H:i:s',time()+30)];
                }else $patch=['estado'=>'RECHAZADA'];
            }elseif($source && $action==='CANCELAR' && in_array($r['estado'],['PENDIENTE','ACEPTADA'],true))$patch=['estado'=>'CANCELADA'];
            elseif($source && $action==='DEVOLUCION' && in_array($r['estado'],['RECHAZADA','VENCIDA','CANCELADA','FALLIDA'],true)){
                $name=trim((string)($data['contacto']??''));$phone=trim((string)($data['telefono']??''));$note=trim((string)($data['nota']??''));
                if($name===''||mb_strlen($name)>100||!preg_match('/^\+?[0-9 ()-]{7,40}$/D',$phone)||mb_strlen($note)>500)throw new DomainException('Completa el nombre y un teléfono válido; la nota admite 500 caracteres.');
                if($r['devolucion']==='PENDIENTE')throw new DomainException('La devolución ya fue registrada.');
                $patch=['estado'=>'DEVOLUCION','contacto'=>$name,'telefono'=>$phone,'nota'=>$note,'devolucion'=>'PENDIENTE'];
            }elseif(!$source && $action==='ATENDIDA' && in_array($r['devolucion'],['PENDIENTE','ATENDIDA'],true))$patch=['devolucion'=>'ATENDIDA'];
            elseif($source && $action==='FALLO' && $r['estado']==='ENVIANDO')$patch=['estado'=>'FALLIDA'];
            else throw new DomainException('Esta solicitud ya no permite esa acción.');
            $this->change($id,$patch);$this->db->commit();return $this->obtener($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function rutaAceptada(int $user,int $id,int $version): array {
        $r=$this->obtener($user,$id);
        if(!$r||(int)$r['origen_usuario']!==$user||(int)$r['version']!==$version||$r['estado']!=='ACEPTADA')throw new DomainException('Solicita y espera la aceptación del responsable antes de transferir.');
        return json_decode($this->one('SELECT ruta_json FROM telefonia_disponibilidad WHERE id=?',[$id])['ruta_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public function consumir(int $user,int $id,int $version,string $ref): void {
        $this->expire();$this->db->begin_transaction();
        try{
            $this->live($user,$ref);
            $row=$this->one('SELECT destino_usuario,destino_extension FROM telefonia_disponibilidad WHERE id=?',[$id]);
            if(!$row||!$this->available((int)$row['destino_usuario'],$row['destino_extension']))throw new DomainException('El responsable dejó de estar disponible. Solicita confirmación de nuevo.');
            $r=$this->one("SELECT * FROM telefonia_disponibilidad WHERE id=? AND version=? AND origen_usuario=? AND referencia=? AND estado='ACEPTADA' AND expires_at>UTC_TIMESTAMP() FOR UPDATE",[$id,$version,$user,$ref]);
            if(!$r)throw new DomainException('La aceptación caducó o ya se utilizó.');
            $this->change($id,['estado'=>'ENVIANDO']);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function devoluciones(int $user,bool $historial=false): array {
        $ext=$this->extension($user);
        $sync=$this->db->prepare("SELECT id FROM telefonia_disponibilidad WHERE (destino_usuario=? OR origen_usuario=?) AND estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR') ORDER BY id DESC LIMIT 100");
        $sync->bind_param('ii',$user,$user);$sync->execute();$rows=$sync->get_result()->fetch_all(MYSQLI_ASSOC);$sync->close();foreach($rows as $row)$this->obtener($user,(int)$row['id']);
        $filter=$historial?"devolucion IN ('PENDIENTE','ATENDIDA')":"devolucion='PENDIENTE'";
        $s=$this->db->prepare("SELECT * FROM telefonia_disponibilidad WHERE destino_usuario=? AND destino_extension=? AND $filter ORDER BY id DESC LIMIT 100");
        $s->bind_param('is',$user,$ext['extension']);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
}
