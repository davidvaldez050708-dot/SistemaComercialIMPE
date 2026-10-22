<?php
/**
 * Asociación de sólo lectura entre interacción WebRTC y transferencia PBX.
 *
 * La recepción autenticada registró el PBX y el expediente destinatario.
 * Nunca se enlaza por teléfono u hora solamente: se comprueban transferencia,
 * participante, respuesta, finalización y grabación firmada de la PBX.
 * Ante cualquier ambigüedad se devuelve null sin alterar la interacción.
 */
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
require_once __DIR__.'/TelefoniaGrabacionesTransferenciaInstitucionService.php';

class TelefoniaGrabacionExpedienteTransferidaService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /** Sólo consultas: nunca cambia proveedores, resultados, vínculos ni archivos. */
    public function inspeccionar(int $usuarioId,int $seguimientoId,int $interaccionId): array
    {
        $reply=static fn($estado,$mensaje,$pbx=null,$ids=[])=>[
            'estado'=>$estado,'mensaje'=>$mensaje,'pbx_call_id'=>$pbx,'segmentos'=>count($ids)];
        $q=$this->db->prepare("SELECT usuario_id,proveedor_externo,id_externo,resultado,notas FROM interacciones_vinculacion WHERE id=? AND seguimiento_id=? AND canal='LLAMADA_IP'");
        $q->bind_param('ii',$interaccionId,$seguimientoId);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        if(!$row||(int)$row['usuario_id']!==$usuarioId)return $reply('ASOCIACION_PENDIENTE','El audio requiere verificar la participación de tu extensión en esta llamada.');
        if(in_array(strtoupper((string)$row['resultado']),['NO_CONTESTO','SIN_RESPUESTA','OCUPADO','BUZON_VOZ','FUERA_SERVICIO','NUMERO_INCORRECTO'],true)||
            preg_match('/\[(BUZON_VOZ|FUERA_SERVICIO)\]/',(string)$row['notas']))return $reply('SOLO_HISTORIAL','Este intento se conserva sin reproducción de audio.');
        $proveedor=strtoupper((string)$row['proveedor_externo']);$pbx=null;
        if($proveedor==='ZADARMA_WEBRTC'){
            // La interacción conserva WEBRTC-{session_id}. Preferir el vínculo
            // exacto con la transferencia PBX confirmada, igual que Recibidas.
            $pbx=$this->buscarTransferenciaExacta(
                $usuarioId,$seguimientoId,$interaccionId,(string)$row['id_externo']);
            if(!$pbx)$pbx=$this->buscar($usuarioId,$seguimientoId,$interaccionId);
        }
        elseif($proveedor==='ZADARMA'){
            $pbx=(string)$row['id_externo'];
            // Una saliente ordinaria conserva su reproductor y autorización actuales.
            if(preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx)){
                $q=$this->db->prepare('SELECT id FROM telefonia_analista_entrantes WHERE usuario_id=? AND interaccion_id=? AND seguimiento_id=? AND pbx_call_id=? LIMIT 1');
                $q->bind_param('iiis',$usuarioId,$interaccionId,$seguimientoId,$pbx);$q->execute();$incoming=$q->get_result()->fetch_assoc();$q->close();
                if(!$incoming)return $reply('NO_APLICA','');
            }
        }else return $reply('NO_APLICA','');
        if(!$pbx||!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx))return $reply('ASOCIACION_PENDIENTE','Falta confirmar la referencia de la centralita para esta conversación.');
        $q=$this->db->prepare("SELECT extension,GREATEST(created_at,updated_at) desde FROM telefonia_extensiones WHERE usuario_id=? AND proveedor='ZADARMA' AND activo=1 AND permite_entrantes=1 LIMIT 1");
        $q->bind_param('i',$usuarioId);$q->execute();$assignment=$q->get_result()->fetch_assoc();$q->close();
        if(!$assignment)return $reply('PARTICIPACION_PENDIENTE','Falta verificar la extensión que atendió esta llamada.');
        $q=$this->db->prepare('SELECT evento,internal,received_at,call_id_with_rec,payload_json,is_recorded FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $extension=(string)$assignment['extension'];
        // Excepción EXCLUSIVA: salida 100 -> Analista 101 acreditada y
        // vinculada al mismo expediente. El END del 100 no acredita el fin
        // del 101, pero tampoco debe ocultar los dos NOTIFY_RECORD firmados.
        if(str_starts_with($pbx,'out_')){
            $institucion=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);
            if($institucion->identidad($pbx,$usuarioId,$extension,$seguimientoId)){
                try {
                    $verified=$institucion->verificar($pbx,$usuarioId,$extension,$seguimientoId);
                    if($verified['ids']){
                        $r=$reply('REPORTADA',
                            'Grabaciones reportadas para la transferencia institucional; disponibilidad al abrir.',
                            $pbx,$verified['ids']);
                        $r['tipo_audio']='INSTITUCION_SALIENTE';
                        return $r;
                    }
                    return $reply('AUDIO_PENDIENTE','Aún no hay grabaciones reportadas para esta transferencia.');
                }catch(DomainException $ignored){
                    return $reply('PARTICIPACION_PENDIENTE',
                        'Falta acreditar la respuesta de tu extensión en la transferencia.');
                }
            }
        }
        $proof=TelefoniaPbxSegmentosService::transferencia($events,$extension);
        $arrival=(bool)array_filter($events,static fn($e)=>$e['evento']==='NOTIFY_INTERNAL'&&$e['internal']===$extension);
        if((!$arrival&&$proof['origen']==='')||!$proof['atendida']||$proof['answer_at']<(string)$assignment['desde'])
            return $reply('RESPUESTA_PENDIENTE','Falta el evento de respuesta de tu extensión; todavía no se habilita el audio.');
        $end=$proof['finalizada'];
        $ids=TelefoniaPbxSegmentosService::deEventos($events,$extension,$proof['origen']!==''?[$proof['origen']]:[])['segmentos'];
        if(!$end)return $reply('CIERRE_PENDIENTE','Falta confirmar el cierre de la llamada en tu extensión.');
        if(!$ids)return $reply('AUDIO_PENDIENTE','La centralita aún no ha confirmado el archivo de audio de esta llamada.');
        if(count($ids)>8)return $reply('AUDIO_PENDIENTE','La grabación requiere revisar sus segmentos antes de reproducirla.');
        return $reply('REPORTADA','Audio reportado por la centralita; su disponibilidad se comprueba al abrirlo.',$pbx,$ids);
    }

    /**
     * Une la interacción existente con la misma sesión RTCSession utilizada
     * durante la entrega, y de allí a la referencia de transferencia PBX.
     * No utiliza teléfono, fechas, ni un PBX proporcionado por el navegador.
     */
    public function buscarTransferenciaExacta(int $usuarioId,int $seguimientoId,
                                               int $interaccionId,string $externo): ?string
    {
        $session=self::sessionIdExterno($externo);
        if($session===null)return null;
        $audios=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);
        // Si el registro PBX ya conservó la interacción exacta, no exigir
        // también una sesión WebRTC "ended" para habilitar sus dos tramos.
        $q=$this->db->prepare("SELECT pbx_call_id,extension
            FROM telefonia_analista_entrantes
            WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?
              AND pbx_call_id LIKE 'out_%'
            LIMIT 2");
        $q->bind_param('iii',$usuarioId,$seguimientoId,$interaccionId);
        $q->execute();$directas=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if(count($directas)>1)return null;
        if(count($directas)===1){
            $pbxDirecto=(string)$directas[0]['pbx_call_id'];
            try{
                $ok=$audios->verificar($pbxDirecto,$usuarioId,
                    (string)$directas[0]['extension'],$seguimientoId);
                if($ok['ids'])return $pbxDirecto;
            }catch(DomainException $ignored){ /* sin privilegio PBX */ }
        }
        $stmt=$this->db->prepare("SELECT session_id,extension
            FROM telefonia_analista_webrtc_sesiones
            WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?
              AND session_id=BINARY ? AND clasificacion='VINCULADA'
              AND fecha_atendida IS NOT NULL
            LIMIT 2");
        $stmt->bind_param('iiis',$usuarioId,$seguimientoId,$interaccionId,$session);
        $stmt->execute();$sesiones=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if(count($sesiones)!==1)return null;
        $extension=(string)$sesiones[0]['extension'];
        if(!preg_match('/^[0-9]{3,6}$/D',$extension))return null;
        $stmt=$this->db->prepare("SELECT referencia,sdk_consulta
            FROM telefonia_transferencia_operaciones
            WHERE destino_usuario=? AND destino_extension=BINARY ?
              AND sdk_consulta IS NOT NULL
              AND (sdk_consulta=BINARY ? OR CONCAT(sdk_consulta,'-external')=BINARY ?)
            LIMIT 3");
        $stmt->bind_param('isss',$usuarioId,$extension,$session,$session);
        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if(count($rows)!==1||
            !self::sdkDeTransferenciaCoincide((string)$rows[0]['sdk_consulta'],$session))return null;
        $pbx=(string)$rows[0]['referencia'];
        if(!preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx))return null;
        try {
            $verified=$audios->verificar($pbx,$usuarioId,$extension,$seguimientoId);
            return $verified['ids']?$pbx:null;
        } catch (DomainException $ignored) {
            return null;
        }
    }

    /** Nunca aceptar coincidencia por prefijo ni por proximidad temporal. */
    public static function sdkDeTransferenciaCoincide(string $sdkConsulta,string $sesion): bool
    {
        return $sdkConsulta!=='' &&
            ($sdkConsulta===$sesion || $sdkConsulta.'-external'===$sesion);
    }

    /** El prefijo de interacción WebRTC no debe confundirse con el PBX. */
    public static function sessionIdExterno(string $externo): ?string
    {
        if(!str_starts_with($externo,'WEBRTC-'))return null;
        $id=substr($externo,7);
        // Misma convención usada al registrar la sesión telefónica.
        return preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$id) ? $id : null;
    }

    public static function numero10(string $numero): string
    {
        $digits = preg_replace('/\\D+/', '', $numero) ?: '';
        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    /**
     * WebRTC almacena fecha_inicio en UTC. Los webhooks pueden recibirse con
     * zona de México o UTC, según la configuración real del hosting.
     * La fecha candidata siempre se interpreta en ambas zonas explícitas.
     */
    public static function distanciaSegundos(string $inicioUtc, string $evento): ?int
    {
        $utc = new DateTimeZone('UTC');
        $mexico = new DateTimeZone('America/Mexico_City');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $inicioUtc, $utc);
        if (!$start || $start->format('Y-m-d H:i:s') !== $inicioUtc) {
            return null;
        }
        $best = null;
        foreach ([$utc, $mexico] as $zone) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $evento, $zone);
            if (!$time || $time->format('Y-m-d H:i:s') !== $evento) continue;
            $delta = abs($time->getTimestamp() - $start->getTimestamp());
            $best = $best === null ? $delta : min($best, $delta);
        }
        return $best;
    }

    public function buscar(int $usuarioId, int $seguimientoId, int $interaccionId): ?string
    {
        if ($usuarioId <= 0 || $seguimientoId <= 0 || $interaccionId <= 0) {
            return null;
        }
        // El usuario debe ser el mismo analista que atendió y registró esta
        // sesión WebRTC; un supervisor que consulte el expediente no adquiere
        // automáticamente acceso al audio de otra extensión.
        $stmt = $this->db->prepare(
            "SELECT session_id, extension, numero_origen, fecha_inicio
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND seguimiento_id = ? AND interaccion_id = ?
                AND clasificacion = 'VINCULADA' AND estado = 'ended'
                AND fecha_atendida IS NOT NULL AND fecha_fin IS NOT NULL
              LIMIT 2"
        );
        $stmt->bind_param('iii', $usuarioId, $seguimientoId, $interaccionId);
        $stmt->execute();
        $sesiones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($sesiones) !== 1) return null;

        $sesion = $sesiones[0];
        $extension = (string)$sesion['extension'];
        $numero10 = self::numero10((string)$sesion['numero_origen']);
        $inicioUtc = (string)$sesion['fecha_inicio'];
        if ($numero10 === '' || !preg_match('/^[0-9]{3,6}$/D', $extension)) {
            return null;
        }

        // Vincular únicamente transferencias asociadas por Recepción al MISMO
        // expediente y a la MISMA extensión del analista, nunca estadísticas
        // buscadas por un teléfono aislado.
        $fuentes=[];
        foreach(['telefonia_transferencia_contextos_v2'=>'referencia_llamada','telefonia_transferencia_contextos'=>'pbx_call_id'] as $nombre=>$campo){
            $existe=$this->db->query("SHOW TABLES LIKE '$nombre'");
            if($existe&&$existe->num_rows>0)$fuentes[]="SELECT $campo AS pbx_call_id,destino_usuario_id,destino_extension,seguimiento_id,telefono10,created_at FROM $nombre";
        }
        if($this->db->query("SHOW TABLES LIKE 'telefonia_disponibilidad'")->num_rows){
            $fuentes[]="SELECT d.referencia AS pbx_call_id,d.destino_usuario AS destino_usuario_id,d.destino_extension,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.seguimiento_id')) AS UNSIGNED) AS seguimiento_id,
                RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(d.numero,'+',''),' ',''),'-',''),'(',''),')',''),10) AS telefono10,d.created_at
                FROM telefonia_disponibilidad d
                WHERE JSON_VALID(d.ruta_json) AND d.estado IN('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')
                  AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.tipo'))='INSTITUCION'
                  AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.referencia_llamada'))=BINARY d.referencia
                  AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.responsable_id'))=CAST(d.destino_usuario AS CHAR)
                  AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.extension'))=BINARY d.destino_extension";
        }
        $tieneTablaContextos=count($fuentes)>0;
        $tabla='('.implode(' UNION ALL ',$fuentes).') AS contextos_verificados';
        $campoPbx='pbx_call_id';
        $transferencias = [];
        if ($tieneTablaContextos) {
            $stmt = $this->db->prepare(
                "SELECT $campoPbx AS pbx_call_id, created_at
                   FROM $tabla
                  WHERE destino_usuario_id = ? AND destino_extension = ?
                    AND seguimiento_id = ? AND telefono10 = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                  ORDER BY created_at DESC LIMIT 120"
            );
            $stmt->bind_param('isis', $usuarioId, $extension, $seguimientoId, $numero10);
            $stmt->execute();
            $transferencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Sin contexto institucional verificable no adivinar por numero/hora.
        if (count($transferencias) === 0) return null;

        $candidatas = [];
        foreach ($transferencias as $contexto) {
            $pbx = trim((string)$contexto['pbx_call_id']);
            if (!preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbx)) continue;
            if($tieneTablaContextos){
                $q=$this->db->prepare("SELECT DISTINCT destino_usuario_id,destino_extension,seguimiento_id FROM $tabla WHERE pbx_call_id=? LIMIT 2");
                $q->bind_param('s',$pbx);$q->execute();$owners=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
                if(count($owners)>1)return null;
                if($owners&&((int)$owners[0]['destino_usuario_id']!==$usuarioId||(string)$owners[0]['destino_extension']!==$extension||(int)$owners[0]['seguimiento_id']!==$seguimientoId))continue;
            }
            $stmt = $this->db->prepare(
                "SELECT
                    MIN(CASE WHEN evento IN ('NOTIFY_INTERNAL','NOTIFY_ANSWER') AND internal = ?
                        THEN received_at END) AS llegada,
                    SUM(CASE WHEN evento = 'NOTIFY_ANSWER' AND internal = ?
                        THEN 1 ELSE 0 END) AS respondida,
                    SUM(CASE WHEN evento IN ('NOTIFY_END','NOTIFY_OUT_END')
                        THEN 1 ELSE 0 END) AS finalizada,
                    SUM(CASE WHEN evento = 'NOTIFY_RECORD'
                         AND TRIM(COALESCE(call_id_with_rec,'')) <> ''
                        THEN 1 ELSE 0 END) AS con_audio
                   FROM telefonia_zadarma_eventos
                  WHERE pbx_call_id = ?"
            );
            $stmt->bind_param('sss', $extension, $extension, $pbx);
            $stmt->execute();
            $e = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (empty($e['llegada']) || (int)($e['respondida'] ?? 0) < 1 ||
                (int)($e['con_audio'] ?? 0) < 1) {
                continue;
            }
            // El OUT_END de Recepción puede ser el único END o incluso
            // faltar. Sólo en SALIENTE institucional verificada se permite
            // buscar asociación sin END, jamás por proximidad a otro PBX.
            if ((int)($e['finalizada'] ?? 0) < 1) {
                if(!preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx))continue;
                $svc=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);
                if(!$svc->identidad($pbx,$usuarioId,$extension,$seguimientoId))continue;
                try{
                    if(!$svc->verificar($pbx,$usuarioId,$extension,$seguimientoId)['ids'])continue;
                }catch(DomainException $ignored){continue;}
            }
            // La llegada PBX de esta extensión coincide con el inicio
            // observado por el navegador con un margen máximo de 90 segundos.
            $delta = self::distanciaSegundos($inicioUtc, (string)$e['llegada']);
            if ($delta === null || $delta > 90) continue;
            $candidatas[$pbx] = true;
            if (count($candidatas) > 1) return null;
        }
        if (count($candidatas) !== 1) return null;

        // Dos sesiones cercanas del MISMO número podrían pertenecer a
        // distintas transferencias. En tal caso no se selecciona ninguna.
        $stmt = $this->db->prepare(
            "SELECT session_id, numero_origen, fecha_inicio
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND extension = ? AND estado = 'ended'
                AND fecha_inicio >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
              ORDER BY fecha_inicio DESC LIMIT 300"
        );
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $otras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($otras as $otra) {
            if ((string)$otra['session_id'] === (string)$sesion['session_id'] ||
                self::numero10((string)$otra['numero_origen']) !== $numero10) {
                continue;
            }
            $a = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                $inicioUtc, new DateTimeZone('UTC'));
            $b = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                (string)$otra['fecha_inicio'], new DateTimeZone('UTC'));
            if ($a && $b && abs($a->getTimestamp() - $b->getTimestamp()) <= 180) {
                return null;
            }
        }
        return array_key_first($candidatas);
    }
}
