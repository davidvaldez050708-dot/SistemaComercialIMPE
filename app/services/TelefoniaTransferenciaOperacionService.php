<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
require_once __DIR__.'/../helpers/TelefoniaAsignacionFechaHelper.php';

/** La consulta y la entrega son etapas distintas. Nunca genera eventos PBX. */
final class TelefoniaTransferenciaOperacionService
{
    private mysqli $db;
    private array $conciliadas=[];
    public function __construct(?mysqli $db=null){$this->db=$db??(new Database())->connect();}
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
    public function iniciar(int $user,string $ref,int $destino,string $ext,string $modo,int $protocolo=1): array {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)||!in_array($modo,['DIRECTA','CONSULTADA'],true))throw new DomainException('Espera a que se identifique la llamada antes de transferir.');
        $a=$this->fila("SELECT te.extension,GREATEST(te.created_at,te.updated_at) AS desde FROM telefonia_extensiones te JOIN usuarios u ON u.id=te.usuario_id AND u.estado=1 WHERE te.usuario_id=? AND te.activo=1 AND te.proveedor='ZADARMA'",[$user]);
        $d=$this->fila("SELECT extension FROM telefonia_extensiones WHERE usuario_id=? AND extension=? AND activo=1 AND proveedor='ZADARMA' AND permite_entrantes=1",[$destino,$ext]);
        if(!$a||!$d||$a['extension']===$ext)throw new DomainException('El destino ya no está disponible.');
        $inicio=$this->fila("SELECT evento,caller_id,destination FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? AND internal=? AND evento IN ('NOTIFY_INTERNAL','NOTIFY_OUT_START') AND received_at>=? ORDER BY id LIMIT 1",[$ref,$a['extension'],TelefoniaAsignacionFechaHelper::aMexico((string)$a['desde'])]);
        if(!$inicio)throw new DomainException('Aún no se confirma la llamada de tu extensión. Espera unos segundos.');
        $this->db->begin_transaction();
        try{
            // La clave única y el bloqueo evitan dos transferencias concurrentes.
            $row=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE origen_usuario=? AND referencia=? FOR UPDATE',[$user,$ref]);
            if($row && (int)($row['protocolo']??1)>=2 && $protocolo<2)throw new DomainException('El teléfono debe usar la versión actual para reintentar esta transferencia.');
            if($row && !in_array($row['estado'],['DEVUELTA','FALLIDA'],true))throw new DomainException('Esta llamada ya tiene una transferencia en curso.');
            $max=$this->fila('SELECT COALESCE(MAX(id),0) AS id FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ?',[$ref]);
            $now=gmdate('Y-m-d H:i:s');
            $numero=(string)($inicio['evento']==='NOTIFY_OUT_START'?$inicio['destination']:$inicio['caller_id']);
            if($row){
                $this->guardar((int)$row['id'],['destino_usuario'=>$destino,'destino_extension'=>$ext,'modo'=>$modo,'estado'=>'SOLICITADA','numero'=>$numero,'intento'=>(int)$row['intento']+1,'protocolo'=>$protocolo>=2?2:1,'evento_desde'=>$max['id'],'sdk_consulta'=>null,'destino_atendida'=>null,'destino_fin'=>null,'entregada_at'=>null,'created_at'=>$now]);
                $id=(int)$row['id'];
            }else{
                $s=$this->db->prepare("INSERT INTO telefonia_transferencia_operaciones (origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,modo,estado,evento_desde,created_at,updated_at,protocolo) VALUES (?,?,?,?,?,?,?,'SOLICITADA',?,?,?,?)");
                $values=[$user,$a['extension'],$destino,$ext,$ref,$numero,$modo,$max['id'],$now,$now,$protocolo>=2?2:1];
                $s->bind_param('sssssssssss',...$values);$s->execute();$id=(int)$this->db->insert_id;$s->close();
            }
            $this->db->commit();return $this->obtener($user,$id);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function obtener(int $user,int $id,int $intento=0): ?array {
        $row=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND (origen_usuario=? OR destino_usuario=?)',[$id,$user,$user]);
        if($row && $intento>0 && (int)$row['intento']!==$intento)throw new DomainException('La transferencia cambió de intento.');
        return $row?$this->actualizar($row,$user):null;
    }
    public function paraDestino(int $user,string $caller,string $sdk): ?array {
        if(!preg_match('/^[0-9]{3,6}$/D',$caller)||!preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$sdk))return null;
        $s=$this->db->prepare("SELECT * FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND origen_extension=?
            AND EXISTS(SELECT 1 FROM telefonia_extensiones te WHERE te.usuario_id=telefonia_transferencia_operaciones.destino_usuario
                AND te.extension=BINARY telefonia_transferencia_operaciones.destino_extension
                AND te.proveedor='ZADARMA' AND te.activo=1 AND te.permite_entrantes=1)
            AND estado IN ('SOLICITADA','TIMBRANDO','CONSULTANDO','ENTREGANDO','ENVIADA','ENTREGADA','DEVUELTA','SIN_RESPUESTA','SIN_CONFIRMAR') AND created_at>=? AND (sdk_consulta IS NULL OR sdk_consulta=?) ORDER BY id DESC LIMIT 2");
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
    private function evaluarEventos(array $r,array $events): array {
        $marker=(int)($r['evento_desde']??0);
        $facts=TelefoniaPbxSegmentosService::hechos($events,(string)$r['destino_extension'],(string)$r['origen_extension'],$marker);
        $recent=$marker>0?array_values(array_filter($events,static fn($e)=>(int)($e['id']??0)>$marker)):$events;
        $patch=[];
        $utc=static function(string $local): ?string {
            if($local==='')return null;
            return (new DateTimeImmutable($local,new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $endedAt=$utc((string)($facts['end_at']?:$facts['fin_intento_at']));
        foreach($recent as $e){
            $p=json_decode((string)$e['payload_json'],true)?:[];
            if($e['evento']==='NOTIFY_INTERNAL' && $e['internal']===$r['destino_extension'] && ($p['transfer_from']??'')===$r['origen_extension'] && $r['estado']==='SOLICITADA')$patch['estado']='TIMBRANDO';
            if($e['evento']==='NOTIFY_INTERNAL' && $e['internal']===$r['origen_extension'] && ($p['transfer_from']??'')===$r['destino_extension'] && !in_array($r['estado'],['ENTREGADA','FALLIDA'],true))$patch['estado']='DEVUELTA';
            if($e['evento']==='NOTIFY_END' && $e['internal']===$r['destino_extension'] && in_array(strtolower($e['disposition']),['no answer','no-answer','cancel','busy'],true) && empty($r['entregada_at']) && empty($r['destino_atendida'])){
                $patch['estado']='DEVUELTA';if($endedAt!==null)$patch['destino_fin']=$r['destino_fin']??$endedAt;
            }
        }
        if($r['modo']==='DIRECTA'){
            $returned=false;$ringing=false;
            foreach($recent as $e){
                $p=json_decode((string)$e['payload_json'],true)?:[];
                if($e['evento']==='NOTIFY_INTERNAL'&&$e['internal']===$r['origen_extension']&&($p['transfer_from']??'')===$r['destino_extension'])$returned=true;
                if($e['evento']==='NOTIFY_INTERNAL'&&$e['internal']===$r['destino_extension']&&($p['transfer_from']??'')===$r['origen_extension'])$ringing=true;
            }
            $patch=[];
            if($facts['conectada']){
                $patch['estado']='ENTREGADA';
                $answeredAt=$utc((string)$facts['answer_at']);
                // Un END propio con duración oficial conserva la compatibilidad sin inventar tiempos del destinatario a partir del origen.
                if($answeredAt===null&&$endedAt!==null&&$facts['segundos']!==null&&$facts['segundos']>0)$answeredAt=gmdate('Y-m-d H:i:s',strtotime($endedAt.' UTC')-(int)$facts['segundos']);
                if($answeredAt!==null&&$endedAt!==null&&$answeredAt>$endedAt)$answeredAt=null;
                if(empty($r['destino_atendida'])&&$answeredAt!==null)$patch['destino_atendida']=$answeredAt;
                if($facts['finalizada']&&$endedAt!==null&&empty($r['destino_fin']))$patch['destino_fin']=$endedAt;
            }elseif($returned)$patch['estado']='DEVUELTA';
            elseif($facts['finalizada']){ $patch['estado']='SIN_RESPUESTA';if($endedAt!==null&&empty($r['destino_fin']))$patch['destino_fin']=$endedAt; }
            elseif($facts['intento_finalizado']){
                $patch['estado']=$r['estado']==='ENTREGADA'?'ENTREGADA':'SIN_CONFIRMAR';
                if($endedAt!==null&&empty($r['destino_fin']))$patch['destino_fin']=$endedAt;
            }
            elseif(empty($r['destino_atendida']) && strtotime($r['created_at'].' UTC')<time()-120 && !in_array($r['estado'],['FALLIDA','DEVUELTA'],true))$patch['estado']='SIN_CONFIRMAR';
            elseif($ringing&&$r['estado']==='SOLICITADA')$patch['estado']='TIMBRANDO';
        }elseif($facts['finalizada']&&$endedAt!==null&&empty($r['destino_fin'])){
            $patch['destino_fin']=$endedAt;
        }
        // El fin del diálogo de consulta no equivale al fin de la conversación del origen.
        $globalEnd=((bool)$facts['finalizada']||(bool)$facts['intento_finalizado'])&&($r['modo']==='DIRECTA'||!empty($r['entregada_at']));
        return [$patch,$globalEnd];
    }
    private function eventosDeOperacion(string $ref): array {
        $s=$this->db->prepare('SELECT id,evento,internal,disposition,duration,received_at,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $s->bind_param('s',$ref);$s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $events;
    }
    private function recuperarDiario(array $r):array {
        $key=$r['id'].':'.$r['intento'];
        if($r['estado']==='ENTREGADA'&&(int)($r['protocolo']??1)>=2&&!empty($r['sdk_consulta'])&&!isset($this->conciliadas[$key])){
            require_once __DIR__.'/TelefoniaSesionLocalService.php';
            $this->conciliadas[$key]=true;
            (new TelefoniaSesionLocalService($this->db))->conciliarTransferenciaPersistida($r);
        }
        return $r;
    }
    private function actualizar(array $r,int $user): array {
        $events=$this->eventosDeOperacion((string)$r['referencia']);
        [$patch,$globalEnd]=$this->evaluarEventos($r,$events);
        $changed=array_filter($patch,static fn($value,$key)=>($r[$key]??null)!==$value,ARRAY_FILTER_USE_BOTH);
        // Los sondeos estables son lecturas: no actualizan timestamps ni toman bloqueo de fila.
        if(!$changed){$r['llamada_finalizada']=$globalEnd;return $this->recuperarDiario($r);}
        $this->db->begin_transaction();
        try{
            // La misma ID puede tener otro intento o destinatario al volver a consultar.
            // Revalidar alcance y generación bajo el bloqueo antes de escribir o devolver datos.
            $current=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND intento=? AND (origen_usuario=? OR destino_usuario=?) FOR UPDATE',[$r['id'],(int)$r['intento'],$user,$user]);
            if(!$current)throw new DomainException('La transferencia cambió de intento. Recupera el estado de tu llamada.');
            $events=$this->eventosDeOperacion((string)$current['referencia']);
            [$patch,$globalEnd]=$this->evaluarEventos($current,$events);
            $changed=array_filter($patch,static fn($value,$key)=>($current[$key]??null)!==$value,ARRAY_FILTER_USE_BOTH);
            if($changed)$this->guardar((int)$current['id'],$changed);
            $r=array_merge($current,$changed);$r['llamada_finalizada']=$globalEnd;
            $this->db->commit();return $this->recuperarDiario($r);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function accion(int $user,int $id,string $action,int $intento=0,int $protocolo=1): array {
        $this->db->begin_transaction();
        try{
            $r=$this->fila('SELECT * FROM telefonia_transferencia_operaciones WHERE id=? AND (origen_usuario=? OR destino_usuario=?) FOR UPDATE',[$id,$user,$user]);
            if(!$r)throw new DomainException('No tienes acceso a esta transferencia.');
            if(((int)($r['protocolo']??1)>=2 && $protocolo<2) ||
                ($protocolo>=2 && ($intento<1 || (int)$r['intento']!==$intento)))
                throw new DomainException('La acción pertenece a otro intento de transferencia.');
            $source=(int)$r['origen_usuario']===$user;$now=gmdate('Y-m-d H:i:s');$patch=[];
            if(($source&&$action==='LIBERADA'&&in_array($r['estado'],['ENVIADA','ENTREGADA','SIN_RESPUESTA','SIN_CONFIRMAR'],true)) ||
               ($source&&$action==='COMPLETAR'&&$r['estado']==='ENTREGANDO') ||
               (!$source&&$action==='ATENDIDA'&&$r['estado']==='CONSULTANDO') ||
               (!$source&&$action==='TERMINADA'&&$r['destino_fin'])){
                $this->db->commit();return $this->obtener($user,$id,(int)$r['intento']);
            }
            if($source && $action==='COMPLETAR' && $r['modo']==='CONSULTADA' && $r['estado']==='CONSULTANDO' && $r['destino_atendida'])$patch=['estado'=>'ENTREGANDO'];
            elseif($source && $action==='LIBERADA' && in_array($r['estado'],['SOLICITADA','TIMBRANDO','ENTREGANDO','CONSULTANDO'],true)){
                $patch=$r['modo']==='DIRECTA'?['estado'=>'ENVIADA','entregada_at'=>$now]:($r['destino_atendida']?['estado'=>'ENTREGADA','entregada_at'=>$now]:['estado'=>'FALLIDA']);
            }
            elseif($source && $action==='FALLO' && empty($r['entregada_at']))$patch=['estado'=>'FALLIDA'];
            elseif(!$source && $action==='ATENDIDA' && $r['modo']==='CONSULTADA' && in_array($r['estado'],['SOLICITADA','TIMBRANDO'],true))$patch=['estado'=>'CONSULTANDO','destino_atendida'=>$now];
            elseif(!$source && $action==='TERMINADA')$patch=$r['modo']==='DIRECTA'?[]:['destino_fin'=>$now]+(empty($r['entregada_at'])&&$r['estado']!=='ENTREGANDO'?['estado'=>'DEVUELTA']:[]);
            else throw new DomainException('La transferencia cambió. Actualiza su estado antes de continuar.');
            if($patch)$this->guardar($id,$patch);$this->db->commit();return $this->obtener($user,$id,(int)$r['intento']);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function delUsuario(int $user): array {
        $s=$this->db->prepare('SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario=? OR destino_usuario=?) AND created_at>=? ORDER BY id DESC LIMIT 100');
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);$s->bind_param('iis',$user,$user,$since);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return array_map(fn($r)=>$this->actualizar($r,$user),$rows);
    }
    /** Lectura agrupada sólo para la campana; los cambios reales conservan el bloqueo de fila. */
    public function delUsuarioParaAvisos(int $user): array {
        $s=$this->db->prepare('SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario=? OR destino_usuario=?) AND created_at>=? ORDER BY id DESC LIMIT 100');
        $since=gmdate('Y-m-d H:i:s',time()-30*86400);$s->bind_param('iis',$user,$user,$since);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return $this->actualizarLoteParaAvisos($rows,$user);
    }
    public function porIdsParaAvisos(int $user,array $ids): array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));$result=[];
        foreach(array_chunk($ids,100) as $lote){
            $s=$this->db->prepare('SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario=? OR destino_usuario=?) AND id IN ('.implode(',',array_fill(0,count($lote),'?')).')');
            $s->bind_param(str_repeat('i',2+count($lote)),$user,$user,...$lote);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            foreach($this->actualizarLoteParaAvisos($rows,$user) as $row)$result[(int)$row['id']]=$row;
        }
        return $result;
    }
    private function actualizarLoteParaAvisos(array $rows,int $user): array {
        if(!$rows)return [];
        $desde=[];
        foreach($rows as $row){$ref=(string)$row['referencia'];$desde[$ref]=isset($desde[$ref])?min($desde[$ref],(int)$row['evento_desde']):(int)$row['evento_desde'];}
        $conditions=[];$args=[];
        foreach($desde as $ref=>$id){$conditions[]='(pbx_call_id=BINARY ?)';$args[]=(string)$ref;}
        $s=$this->db->prepare('SELECT pbx_call_id,id,evento,internal,disposition,duration,received_at,payload_json FROM telefonia_zadarma_eventos WHERE '.implode(' OR ',$conditions).' ORDER BY id');
        $s->bind_param(str_repeat('s',count($desde)),...$args);$s->execute();$events=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $porReferencia=[];
        foreach($events as $event)$porReferencia[(string)$event['pbx_call_id']][]=$event;
        $result=[];
        foreach($rows as $row){
            $events=$porReferencia[(string)$row['referencia']]??[];
            [$patch,$globalEnd]=$this->evaluarEventos($row,$events);
            $changed=false;foreach($patch as $key=>$value)if(($row[$key]??null)!==$value){$changed=true;break;}
            if($changed)$row=$this->actualizar($row,$user);
            else $row['llamada_finalizada']=$globalEnd;
            $result[]=$row;
        }
        return $result;
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
