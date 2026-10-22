<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';

/** La consulta y la entrega son etapas distintas. Nunca genera eventos PBX. */
final class TelefoniaTransferenciaOperacionService
{
    private mysqli $db;
    private static ?WeakMap $ready=null;
    public function __construct(?mysqli $db=null){$this->db=$db??(new Database())->connect();$this->estructura();}
    private function estructura(): void {
        self::$ready??=new WeakMap();if(isset(self::$ready[$this->db]))return;
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_transferencia_operaciones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, origen_usuario INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL, destino_usuario INT NOT NULL,
            destino_extension VARCHAR(12) NOT NULL, referencia VARCHAR(100) NOT NULL,
            numero VARCHAR(40) NOT NULL, modo VARCHAR(16) NOT NULL, estado VARCHAR(24) NOT NULL,
            intento INT NOT NULL DEFAULT 1, evento_desde BIGINT NOT NULL DEFAULT 0,
            sdk_consulta VARCHAR(100) NULL, destino_atendida DATETIME NULL,
            destino_fin DATETIME NULL, entregada_at DATETIME NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_tr_operacion (origen_usuario,referencia),
            KEY idx_tr_destino (destino_usuario,estado,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$ready[$this->db]=true;
    }
    private function fila(string $sql,array $args=[]): ?array {
        $s=$this->db->prepare($sql);
        if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
    }
    private function guardar(int $id,array $values): void {
        $sets=[];$args=[];
        foreach($values as $key=>$value){$sets[]="$key=?";$args[]=$value;}
        $args[]=gmdate('Y-m-d H:i:s');$args[]=$id;
        $s=$this->db->prepare('UPDATE telefonia_transferencia_operaciones SET '.implode(',',$sets).',updated_at=? WHERE id=?');
        $s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$s->close();
    }
    public function iniciar(int $user,string $ref,int $destino,string $ext,string $modo): array {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)||!in_array($modo,['DIRECTA','CONSULTADA'],true))throw new DomainException('Espera a que se identifique la llamada antes de transferir.');
        $a=$this->fila("SELECT te.extension,GREATEST(te.created_at,te.updated_at) AS desde FROM telefonia_extensiones te JOIN usuarios u ON u.id=te.usuario_id AND u.estado=1 WHERE te.usuario_id=? AND te.activo=1 AND te.proveedor='ZADARMA'",[$user]);
        $d=$this->fila("SELECT extension FROM telefonia_extensiones WHERE usuario_id=? AND extension=? AND activo=1 AND proveedor='ZADARMA' AND permite_entrantes=1",[$destino,$ext]);
        if(!$a||!$d||$a['extension']===$ext)throw new DomainException('El destino ya no está disponible.');
        $inicio=$this->fila("SELECT evento,caller_id,destination FROM telefonia_zadarma_eventos WHERE pbx_call_id=? AND internal=? AND evento IN ('NOTIFY_INTERNAL','NOTIFY_OUT_START') AND received_at>=? ORDER BY id LIMIT 1",[$ref,$a['extension'],$a['desde']]);
        if(!$inicio)throw new DomainException('Aún no se confirma la llamada de tu extensión. Espera unos segundos.');
        $this->db->begin_transaction();
        try{
            // La clave única y el bloqueo evitan dos transferencias concurrentes.
            $row=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE origen_usuario=? AND referencia=? FOR UPDATE',[$user,$ref]);
            if($row && !in_array($row['estado'],['DEVUELTA','FALLIDA'],true))throw new DomainException('Esta llamada ya tiene una transferencia en curso.');
            $max=$this->fila('SELECT COALESCE(MAX(id),0) AS id FROM telefonia_zadarma_eventos WHERE pbx_call_id=?',[$ref]);
            $now=gmdate('Y-m-d H:i:s');
            $numero=(string)($inicio['evento']==='NOTIFY_OUT_START'?$inicio['destination']:$inicio['caller_id']);
            if($row){
                $this->guardar((int)$row['id'],['destino_usuario'=>$destino,'destino_extension'=>$ext,'modo'=>$modo,'estado'=>'SOLICITADA','numero'=>$numero,'intento'=>(int)$row['intento']+1,'evento_desde'=>$max['id'],'sdk_consulta'=>null,'destino_atendida'=>null,'destino_fin'=>null,'entregada_at'=>null,'created_at'=>$now]);
                $id=(int)$row['id'];
            }else{
                $s=$this->db->prepare("INSERT INTO telefonia_transferencia_operaciones (origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,modo,estado,evento_desde,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'SOLICITADA',?,?,?)");
                $values=[$user,$a['extension'],$destino,$ext,$ref,$numero,$modo,$max['id'],$now,$now];
                $s->bind_param('ssssssssss',...$values);$s->execute();$id=(int)$this->db->insert_id;$s->close();
            }
            $this->db->commit();return $this->obtener($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function obtener(int $user,int $id): ?array {
        $row=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND (origen_usuario=? OR destino_usuario=?)',[$id,$user,$user]);
        return $row?$this->actualizar($row):null;
    }
    public function paraDestino(int $user,string $caller,string $sdk): ?array {
        if(!preg_match('/^[0-9]{3,6}$/D',$caller)||!preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$sdk))return null;
        $s=$this->db->prepare("SELECT * FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND origen_extension=? AND estado IN ('SOLICITADA','TIMBRANDO','CONSULTANDO','ENTREGANDO','ENVIADA','ENTREGADA','DEVUELTA','SIN_RESPUESTA','SIN_CONFIRMAR') AND created_at>=? AND (sdk_consulta IS NULL OR sdk_consulta=?) ORDER BY id DESC LIMIT 2");
        $since=gmdate('Y-m-d H:i:s',time()-120);$s->bind_param('isss',$user,$caller,$since,$sdk);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($rows)!==1)return null;
        $r=$rows[0];
        if(empty($r['sdk_consulta'])){
            $s=$this->db->prepare('UPDATE telefonia_transferencia_operaciones SET sdk_consulta=? WHERE id=? AND sdk_consulta IS NULL');
            $s->bind_param('si',$sdk,$r['id']);$s->execute();$s->close();
        }
        $bound=$this->obtener($user,(int)$r['id']);
        return ($bound['sdk_consulta']??null)===$sdk?$bound:null;
    }
    /** Vincular un INVITE al envío exacto sólo cuando la PBX acredita respuesta propia viva.
     * No usa teléfono ni proximidad de fechas; una oferta aceptada sola no es evidencia. */
    public function paraDestinoConfirmado(int $user,int $request,string $sdk): ?array {
        if($request<=0||!preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$sdk))return null;
        $s=$this->db->prepare("SELECT o.*,d.ruta_json verified_route,
           EXISTS(SELECT 1 FROM telefonia_zadarma_eventos a WHERE a.pbx_call_id=BINARY o.referencia AND a.internal=BINARY o.destino_extension AND a.evento='NOTIFY_ANSWER' AND a.id>o.evento_desde) AS answered,
           EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
            JOIN telefonia_zadarma_eventos a ON a.pbx_call_id=BINARY o.referencia
                AND a.evento='NOTIFY_ANSWER' AND a.internal=BINARY o.destino_extension
                AND a.id>o.evento_desde AND e.id>a.id
            WHERE e.pbx_call_id=BINARY o.referencia
              AND ((e.evento='NOTIFY_END' AND
                  (e.internal=BINARY o.destino_extension OR
                   (JSON_VALID(e.payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal'))=BINARY o.destino_extension)))
                OR (e.evento='NOTIFY_OUT_END' AND
                  (e.internal=BINARY o.destino_extension OR
                   (JSON_VALID(e.payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal'))=BINARY o.destino_extension) OR
                   (e.internal=BINARY o.origen_extension AND
                    (NOT JSON_VALID(e.payload_json) OR
                     COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal')),'') IN ('',o.origen_extension))))))) AS ended
          FROM telefonia_transferencia_operaciones o
          JOIN telefonia_disponibilidad d ON d.id=? AND d.destino_usuario=o.destino_usuario
           AND d.origen_usuario=o.origen_usuario AND d.origen_extension=BINARY o.origen_extension AND d.destino_extension=BINARY o.destino_extension AND d.referencia=BINARY o.referencia
          WHERE o.destino_usuario=? AND d.estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR')
           AND (o.sdk_consulta IS NULL OR o.sdk_consulta=?)
           AND EXISTS(SELECT 1 FROM telefonia_extensiones t WHERE t.usuario_id=o.destino_usuario AND t.extension=BINARY o.destino_extension AND t.activo=1 AND t.permite_entrantes=1 AND t.proveedor='ZADARMA')
           LIMIT 2");
        $s->bind_param('iis',$request,$user,$sdk);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($rows)!==1)return null;$r=$rows[0];
        $route=json_decode((string)$r['verified_route'],true)?:[];
        if(($route['tipo']??'')!=='PROSPECTO'||(int)($route['responsable_id']??0)!==$user||(string)($route['extension']??'')!==$r['destino_extension']||(string)($route['referencia_llamada']??'')!==$r['referencia'])return null;
        // Reservar la identidad SDK mientras la operación vive, sin devolver
        // referencia ni acreditar respuesta. Permite conciliar el mismo SDK
        // si ANSWER y END llegan juntos después del cierre local.
        if($r['ended']&&$r['sdk_consulta']!==$sdk)return null;
        $s=$this->db->prepare('UPDATE telefonia_transferencia_operaciones SET sdk_consulta=? WHERE id=? AND sdk_consulta IS NULL');
        $s->bind_param('si',$sdk,$r['id']);$s->execute();$s->close();
        if(!$r['answered'])return null;
        $bound=$this->obtener($user,(int)$r['id']);return ($bound['sdk_consulta']??null)===$sdk?$bound:null;
    }
    private function actualizar(array $r): array {
        $this->db->begin_transaction();
        try{
        // Los sondeos no pueden sobrescribir una entrega confirmada en otra petición.
        $r=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? FOR UPDATE',[$r['id']])??$r;
        $s=$this->db->prepare('SELECT evento,internal,disposition,duration,received_at,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=? AND id>? ORDER BY id');
        $s->bind_param('si',$r['referencia'],$r['evento_desde']);$s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $patch=[];$globalEnd=false;
        foreach($events as $e){
            $p=json_decode((string)$e['payload_json'],true)?:[];
            if($e['evento']==='NOTIFY_INTERNAL' && $e['internal']===$r['destino_extension'] && ($p['transfer_from']??'')===$r['origen_extension'] && $r['estado']==='SOLICITADA')$patch['estado']='TIMBRANDO';
            if($e['evento']==='NOTIFY_INTERNAL' && $e['internal']===$r['origen_extension'] && ($p['transfer_from']??'')===$r['destino_extension'] && !in_array($r['estado'],['ENTREGADA','FALLIDA'],true))$patch['estado']='DEVUELTA';
            if($e['evento']==='NOTIFY_END' && $e['internal']===$r['destino_extension'] && in_array(strtolower($e['disposition']),['no answer','no-answer','cancel','busy'],true) && empty($r['entregada_at']) && empty($r['destino_atendida'])){$patch['estado']='DEVUELTA';$patch['destino_fin']=gmdate('Y-m-d H:i:s');}
            if(in_array($e['evento'],['NOTIFY_END','NOTIFY_OUT_END'],true) && ($e['internal']==='' || ($p['last_internal']??'')===$r['destino_extension']))$globalEnd=true;
        }
        if($r['modo']==='DIRECTA'){
            $answered=null;$ended=null;$returned=false;$ringing=false;
            foreach($events as $e){
                $p=json_decode((string)$e['payload_json'],true)?:[];
                if($e['evento']==='NOTIFY_INTERNAL'&&$e['internal']===$r['origen_extension']&&($p['transfer_from']??'')===$r['destino_extension'])$returned=true;
                if($e['internal']!==$r['destino_extension'])continue;
                if($e['evento']==='NOTIFY_INTERNAL'&&($p['transfer_from']??'')===$r['origen_extension'])$ringing=true;
                if($e['evento']==='NOTIFY_ANSWER')$answered=$e['received_at'];
                if($e['evento']==='NOTIFY_END'){
                    $ended=$e['received_at'];
                    if((int)$e['duration']>0&&strtolower($e['disposition'])==='answered')$answered=$answered?:date('Y-m-d H:i:s',strtotime($ended)-(int)$e['duration']);
                }
            }
            // Disponibilidad y liberación de origen nunca acreditan respuesta del destino.
            $patch=[];
            if($answered){$patch=['estado'=>'ENTREGADA','destino_atendida'=>(new DateTimeImmutable($answered,new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];if($ended)$patch['destino_fin']=(new DateTimeImmutable($ended,new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            elseif($returned)$patch=['estado'=>'DEVUELTA'];
            elseif($ended)$patch=['estado'=>'SIN_RESPUESTA','destino_fin'=>(new DateTimeImmutable($ended,new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            elseif(empty($r['destino_atendida']) && strtotime($r['created_at'].' UTC')<time()-120 && !in_array($r['estado'],['FALLIDA','DEVUELTA'],true))$patch=['estado'=>'SIN_CONFIRMAR'];
            elseif($ringing&&$r['estado']==='SOLICITADA')$patch=['estado'=>'TIMBRANDO'];
        }
        if($patch){$this->guardar((int)$r['id'],$patch);$r=array_merge($r,$patch);}
        $r['llamada_finalizada']=$globalEnd;
        $this->db->commit();return $r;
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function accion(int $user,int $id,string $action): array {
        $this->db->begin_transaction();
        try{
            $r=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND (origen_usuario=? OR destino_usuario=?) FOR UPDATE',[$id,$user,$user]);
            if(!$r)throw new DomainException('No tienes acceso a esta transferencia.');
            $source=(int)$r['origen_usuario']===$user;$now=gmdate('Y-m-d H:i:s');$patch=[];
            if(($source&&$action==='LIBERADA'&&in_array($r['estado'],['ENVIADA','ENTREGADA','SIN_RESPUESTA','SIN_CONFIRMAR'],true)) ||
               ($source&&$action==='COMPLETAR'&&$r['estado']==='ENTREGANDO') ||
               (!$source&&$action==='ATENDIDA'&&$r['estado']==='CONSULTANDO') ||
               (!$source&&$action==='TERMINADA'&&$r['destino_fin'])){
                $this->db->commit();return $this->obtener($user,$id);
            }
            if($source && $action==='COMPLETAR' && $r['modo']==='CONSULTADA' && $r['estado']==='CONSULTANDO' && $r['destino_atendida'])$patch=['estado'=>'ENTREGANDO'];
            elseif($source && $action==='LIBERADA' && in_array($r['estado'],['SOLICITADA','TIMBRANDO','ENTREGANDO','CONSULTANDO'],true)){
                $patch=$r['modo']==='DIRECTA'?['estado'=>'ENVIADA','entregada_at'=>$now]:($r['destino_atendida']?['estado'=>'ENTREGADA','entregada_at'=>$now]:['estado'=>'FALLIDA']);
            }
            elseif($source && $action==='FALLO' && empty($r['entregada_at']))$patch=['estado'=>'FALLIDA'];
            elseif(!$source && $action==='ATENDIDA' && $r['modo']==='CONSULTADA' && in_array($r['estado'],['SOLICITADA','TIMBRANDO'],true))$patch=['estado'=>'CONSULTANDO','destino_atendida'=>$now];
            elseif(!$source && $action==='TERMINADA')$patch=$r['modo']==='DIRECTA'?[]:['destino_fin'=>$now]+(empty($r['entregada_at'])&&$r['estado']!=='ENTREGANDO'?['estado'=>'DEVUELTA']:[]);
            else throw new DomainException('La transferencia cambió. Actualiza su estado antes de continuar.');
            if($patch)$this->guardar($id,$patch);$this->db->commit();return $this->obtener($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function delUsuario(int $user): array {
        $s=$this->db->prepare('SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario=? OR destino_usuario=?) AND created_at>=? ORDER BY id DESC LIMIT 100');
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);$s->bind_param('iis',$user,$user,$since);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return array_map(fn($r)=>$this->actualizar($r),$rows);
    }
    public function tramoDestino(int $user,string $ref,array $call): array {
        $r=$this->fila('SELECT numero,entregada_at FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND referencia=? AND entregada_at IS NOT NULL',[$user,$ref]);
        if(!$r)return $call;
        $call['caller_id']=$r['numero'];
        if(!empty($call['answer_at'])){
            $at=(new DateTimeImmutable($r['entregada_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $call['answer_at']=max($call['answer_at'],$at);
            $call['duration']=!empty($call['end_at'])?max(0,strtotime($call['end_at'])-strtotime($call['answer_at'])):0;
        }
        return $call;
    }
}
