<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
require_once __DIR__.'/TelefoniaExtensionService.php';
require_once __DIR__.'/TelefoniaContactosService.php';
require_once __DIR__.'/../helpers/VentasPendientesPolicy.php';

/** Devoluciones comerciales. No crea ni consulta expedientes de Vinculación. */
final class TelefoniaDevolucionVentasService
{
    private mysqli $db;
    private TelefoniaVentasLlamadasService $calls;
    public function __construct(?mysqli $db=null) {$this->db=$db??(new Database())->connect();$this->calls=new TelefoniaVentasLlamadasService($this->db);}
    private function rows(string $sql,array $args=[]): array {
        $s=$this->db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r;
    }
    private function requireSchema(int $uid): void {
        $this->calls->autorizar($uid);$this->calls->requerir();
        if(!$this->db->query("SHOW TABLES LIKE 'telefonia_ventas_devolucion_intentos'")->num_rows)throw new DomainException('Falta aplicar la migración de devoluciones comerciales.');
    }
    public function tareas(int $uid): array {
        $this->requireSchema($uid);
        // El destinatario queda congelado; cambiar de extensión no transfiere su historial.
        $rows=$this->rows("SELECT d.* FROM telefonia_disponibilidad d WHERE d.destino_usuario=? AND d.devolucion IN ('PENDIENTE','ATENDIDA') ORDER BY d.updated_at DESC,d.id DESC",[$uid]);
        $out=[];
        foreach($rows as $r){$route=json_decode($r['ruta_json'],true)?:[];
            if(($route['tipo']??'')!=='PROSPECTO'||(int)($route['responsable_id']??0)!==$uid||(string)($route['extension']??'')!==$r['destino_extension']||(string)($route['referencia_llamada']??'')!==$r['referencia'])continue;
            $r['nombre']=(string)($r['contacto']?:($route['institucion_nombre']??''));$r['estado_id']=(int)($route['estado_id']??0);
            $r['plazo']=VentasPendientesPolicy::devolucion($r);
            unset($r['ruta_json']);$out[]=$r;
        }
        return $out;
    }
    private function task(int $uid,int $id): array {
        foreach($this->tareas($uid) as $t)if((int)$t['id']===$id)return $t;
        throw new DomainException('La devolución no te corresponde.');
    }
    public function listar(int $uid): array {
        $tasks=$this->tareas($uid);
        $attempts=$this->rows("SELECT a.*,r.resultado FROM telefonia_ventas_devolucion_intentos a LEFT JOIN telefonia_ventas_resultados r ON r.usuario_id=a.usuario_id AND r.extension=BINARY a.extension AND r.pbx_call_id=BINARY a.pbx_call_id WHERE a.usuario_id=? ORDER BY a.id DESC",[$uid]);
        $last=[];foreach($attempts as $a){$key=$a['solicitud_id'].':'.$a['version'];if(!isset($last[$key]))$last[$key]=$a;}
        foreach($tasks as &$t)$t['intento']=$last[$t['id'].':'.$t['version']]??null;unset($t);return $tasks;
    }
    public function preparar(int $uid,int $id): array {
        $this->requireSchema($uid);$task=$this->task($uid,$id);
        // Se consulta la asignación real, sin el fallback de la prueba histórica.
        $ext=$this->rows("SELECT t.extension FROM telefonia_extensiones t JOIN usuarios u ON u.id=t.usuario_id
            WHERE t.usuario_id=? AND t.proveedor='ZADARMA' AND t.activo=1 AND t.permite_salientes=1
            AND EXISTS(SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=u.rol_id AND p.codigo='telefonia.salientes' AND p.estado=1)",[$uid]);
        if($task['devolucion']!=='PENDIENTE'||count($ext)!==1)throw new DomainException('Necesitas una devolución pendiente y una extensión propia con llamadas salientes.');
        $number=TelefoniaContactosService::normalizarTelefono((string)$task['telefono']);$extension=$ext[0]['extension'];
        $this->db->begin_transaction();try{
            $this->rows('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[$uid]);
            $locked=$this->rows("SELECT id FROM telefonia_disponibilidad WHERE id=? AND destino_usuario=? AND version=? AND devolucion='PENDIENTE' FOR UPDATE",[$id,$uid,$task['version']]);
            if(!$locked)throw new DomainException('La devolución cambió. Actualiza la bandeja.');
            if($this->rows("SELECT a.id FROM telefonia_ventas_devolucion_intentos a LEFT JOIN telefonia_ventas_resultados r ON r.usuario_id=a.usuario_id AND r.pbx_call_id=BINARY a.pbx_call_id AND r.extension=BINARY a.extension WHERE a.usuario_id=? AND a.estado<>'CANCELADO' AND r.id IS NULL LIMIT 1",[$uid]))throw new DomainException('Revisa o registra el intento anterior antes de iniciar otro.');
            if($this->rows("SELECT c.id FROM telefonia_ventas_llamadas c LEFT JOIN telefonia_ventas_resultados r ON r.usuario_id=c.usuario_id AND r.pbx_call_id=BINARY c.pbx_call_id AND r.extension=BINARY c.extension WHERE c.usuario_id=? AND r.id IS NULL LIMIT 1",[$uid]))throw new DomainException('Registra tus llamadas pendientes antes de iniciar una devolución.');
            $max=$this->rows("SELECT COALESCE(MAX(id),0) n FROM telefonia_zadarma_eventos WHERE internal=? AND evento='NOTIFY_OUT_START'",[$extension])[0]['n'];
            $s=$this->db->prepare('INSERT INTO telefonia_ventas_devolucion_intentos (solicitud_id,version,usuario_id,extension,numero,desde_evento_id,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $args=[$id,$task['version'],$uid,$extension,$number,$max];$s->bind_param('ssssss',...$args);$s->execute();$attempt=(int)$this->db->insert_id;$s->close();$this->db->commit();
            return $this->estado($uid,$attempt);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function estado(int $uid,int $id): array {
        $this->requireSchema($uid);$rows=$this->rows('SELECT * FROM telefonia_ventas_devolucion_intentos WHERE id=? AND usuario_id=?',[$id,$uid]);
        if(count($rows)!==1)throw new DomainException('El intento no te corresponde.');$a=$rows[0];$t=$this->task($uid,(int)$a['solicitud_id']);
        if((int)$a['version']!==(int)$t['version'])throw new DomainException('La devolución cambió de responsable.');
        $ambiguous=false;
        if(!$a['pbx_call_id']&&$a['estado']!=='CANCELADO'){
            $from=(new DateTimeImmutable($a['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'));
            $candidates=$this->rows("SELECT e.pbx_call_id,e.destination FROM telefonia_zadarma_eventos e JOIN telefonia_ventas_llamadas c ON c.pbx_call_id=BINARY e.pbx_call_id AND c.extension=BINARY e.internal AND c.usuario_id=?
                WHERE e.evento='NOTIFY_OUT_START' AND e.internal=? AND e.id>? AND e.received_at BETWEEN ? AND ? GROUP BY e.pbx_call_id,e.destination",[$uid,$a['extension'],$a['desde_evento_id'],$from->modify('-5 seconds')->format('Y-m-d H:i:s'),$from->modify('+2 minutes')->format('Y-m-d H:i:s')]);
            $refs=[];foreach($candidates as $e){try{if(TelefoniaContactosService::normalizarTelefono($e['destination'])===$a['numero'])$refs[$e['pbx_call_id']]=true;}catch(InvalidArgumentException $ignored){}}
            $ambiguous=count($refs)>1;
            if(count($refs)===1){$ref=(string)array_key_first($refs);
                $s=$this->db->prepare("UPDATE telefonia_ventas_devolucion_intentos SET pbx_call_id=?,estado='EN_CURSO',updated_at=UTC_TIMESTAMP() WHERE id=? AND usuario_id=? AND pbx_call_id IS NULL AND estado='PREPARADO'");
                try{$s->bind_param('sii',$ref,$id,$uid);$s->execute();}catch(mysqli_sql_exception $e){if($e->getCode()!==1062)throw $e;}finally{$s->close();}
                $a=$this->rows('SELECT * FROM telefonia_ventas_devolucion_intentos WHERE id=? AND usuario_id=?',[$id,$uid])[0];
            }
        }
        $a['ambigua']=$ambiguous;$a['llamada']=$a['pbx_call_id']?$this->calls->obtener($uid,$a['pbx_call_id'],$a['extension']):null;return $a;
    }
    public function reconciliar(int $uid): void {
        $this->requireSchema($uid);
        foreach($this->rows("SELECT id FROM telefonia_ventas_devolucion_intentos WHERE usuario_id=? AND estado='PREPARADO' AND pbx_call_id IS NULL",[$uid]) as $a)$this->estado($uid,(int)$a['id']);
    }
    public function cancelar(int $uid,int $id): void {
        $a=$this->estado($uid,$id);if($a['pbx_call_id']||$a['ambigua'])throw new DomainException('Hay evidencia de marcación. Revisa el historial antes de cancelar.');
        $s=$this->db->prepare("UPDATE telefonia_ventas_devolucion_intentos SET estado='CANCELADO',updated_at=UTC_TIMESTAMP() WHERE id=? AND usuario_id=? AND pbx_call_id IS NULL");$s->bind_param('ii',$id,$uid);$s->execute();$s->close();
    }
    /** Se ejecuta dentro de la transacción que inserta el resultado comercial. */
    public function aplicarResultado(int $uid,array $call,string $code): void {
        $a=$this->rows("SELECT * FROM telefonia_ventas_devolucion_intentos WHERE usuario_id=? AND extension=? AND pbx_call_id=? AND estado<>'CANCELADO' FOR UPDATE",[$uid,$call['extension'],$call['pbx_call_id']]);
        require_once __DIR__.'/TelefoniaResultadoVentasCatalogo.php';
        $close=TelefoniaResultadoVentasCatalogo::conPersona($code)||in_array($code,['CONVERSACION_PERSONA','INFORMACION_ENVIADA_WHATSAPP','INTERESADO','SOLICITA_INFORMACION','NO_INTERESADO','NUMERO_INEXISTENTE'],true);
        if(!$a){
            // Una respuesta PBX tardía puede superar una devolución automática por falta de respuesta.
            // No cerrar una devolución capturada manualmente ni una conversación sin resultado.
            if($close&&$call['conectada']&&!empty($call['solicitud_id'])){
                $s=$this->db->prepare("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA',updated_at=UTC_TIMESTAMP() WHERE id=? AND destino_usuario=? AND destino_extension=? AND referencia=? AND estado='TRANSFERIDA' AND devolucion='PENDIENTE' AND nota LIKE 'Transferencia no contestada.%'");
                $s->bind_param('iiss',$call['solicitud_id'],$uid,$call['extension'],$call['pbx_call_id']);$s->execute();$s->close();
            }
            return;
        }$a=$a[0];
        if($close){$s=$this->db->prepare("UPDATE telefonia_disponibilidad SET devolucion='ATENDIDA',updated_at=UTC_TIMESTAMP() WHERE id=? AND version=? AND destino_usuario=? AND devolucion='PENDIENTE'");$s->bind_param('iii',$a['solicitud_id'],$a['version'],$uid);$s->execute();$s->close();}
    }
}
