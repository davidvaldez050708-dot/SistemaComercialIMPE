<?php
require_once __DIR__ . '/../helpers/ZadarmaPbxIdentifierHelper.php';
require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
/**
 * Relación uno-a-uno entre sesión WebRTC interna y grabación PBX real.
 * El SDK conserva su identificador original. Nunca se asocia audio por hora
 * en una solicitud HTTP: el vínculo exige identidad PBX persistida y eventos propios.
 */
final class TelefoniaInternaGrabacionService
{
    private mysqli $db;

    public function __construct(mysqli $db,private ?object $recordings=null)
    {
        $this->db = $db;
    }

    public function asegurarEstructura(): void
    {
        if(PHP_SAPI!=='cli')throw new LogicException('La estructura de audio requiere preparación explícita por CLI.');
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_webrtc_grabaciones_internas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            session_id VARCHAR(100) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            call_id_with_rec VARCHAR(100) NOT NULL,
            extension_origen VARCHAR(12) NOT NULL,
            extension_destino VARCHAR(12) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_interna_sdk (usuario_id, session_id),
            UNIQUE KEY uq_interna_pbx (pbx_call_id),
            UNIQUE KEY uq_interna_audio (call_id_with_rec),
            KEY idx_interna_usuario (usuario_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    public function porUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id FROM telefonia_webrtc_grabaciones_internas
             WHERE usuario_id = ?"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_fill_keys(array_column($filas, 'session_id'), true);
    }

    public function obtener(int $usuarioId, string $sessionId): ?array
    {
        if ($usuarioId <= 0 || !preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId)) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT v.session_id, v.pbx_call_id, v.call_id_with_rec,
                    v.extension_origen, v.extension_destino
             FROM telefonia_webrtc_grabaciones_internas v
             INNER JOIN telefonia_analista_webrtc_sesiones s
                ON s.usuario_id = v.usuario_id
               AND s.session_id = v.session_id
               AND s.extension = v.extension_destino
               AND s.numero_origen = v.extension_origen
             WHERE v.usuario_id = ? AND v.session_id = ?
               AND s.estado = 'ended' AND s.clasificacion = 'INTERNA'
               AND s.seguimiento_id IS NULL AND s.interaccion_id IS NULL
             LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    /**
     * Conciliación a demanda de una llamada INTERNA ya finalizada. Sólo
     * relaciona el audio con la identidad PBX persistida en el diario SDK.
     * No crea interacciones institucionales ni expone identificadores.
     */
    public function sincronizar(int $usuarioId, string $sessionId): bool
    {
        if ($usuarioId <= 0 ||
            !preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId)) {
            throw new DomainException('Sesión telefónica inválida.');
        }
        if ($this->obtener($usuarioId, $sessionId)) {
            return true;
        }
        $stmt = $this->db->prepare(
            "SELECT s.extension, s.numero_origen, s.estado,
                    s.clasificacion, s.seguimiento_id, s.interaccion_id,
                    s.fecha_inicio, s.fecha_atendida, s.fecha_fin,
                    s.protocolo,s.pbx_call_id,s.pbx_inicio_evento
               FROM telefonia_analista_webrtc_sesiones s
              WHERE s.usuario_id = ? AND s.session_id = ?
              LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$session || $session['estado'] !== 'ended' ||
            $session['clasificacion'] !== 'INTERNA' ||
            $session['seguimiento_id'] !== null ||
            $session['interaccion_id'] !== null) {
            throw new DomainException('Sólo se buscan grabaciones de llamadas internas finalizadas y sin expediente.');
        }
        $orig = (string)$session['numero_origen'];
        $dest = (string)$session['extension'];
        if (!preg_match('/^[0-9]{3,6}$/D', $orig) ||
            !preg_match('/^[0-9]{3,6}$/D', $dest) || $orig === $dest) {
            throw new DomainException('Las extensiones internas no son válidas.');
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT extension) AS extensiones
               FROM telefonia_extensiones
              WHERE extension IN (?, ?) AND proveedor = 'ZADARMA' AND activo = 1"
        );
        $stmt->bind_param('ss', $orig, $dest);
        $stmt->execute();
        $activas = (int)($stmt->get_result()->fetch_assoc()['extensiones'] ?? 0);
        $stmt->close();
        if ($activas !== 2) {
            throw new DomainException('Las extensiones no están ambas activas.');
        }
        $pbx=(string)($session['pbx_call_id']??'');
        if((int)($session['protocolo']??0)!==2||!ZadarmaPbxIdentifierHelper::pbxValido($pbx)||empty($session['pbx_inicio_evento']))
            throw new DomainException('Falta acreditar la referencia PBX exacta de esta sesión. No se enlazó audio por hora o duración.');
        $events=$this->eventos($pbx);
        $recordId=self::referenciaVerificada($session,$events);
        require_once __DIR__.'/ZadarmaRecordingService.php';
        $provider=$this->recordings??new ZadarmaRecordingService(false);
        $record=$provider->obtenerGrabacionPorCallId($recordId);
        if(!is_array($record)||!is_string($record['link']??null)||!filter_var($record['link'],FILTER_VALIDATE_URL)||
            !str_starts_with(strtolower($record['link']),'https://'))throw new DomainException('La grabación todavía no está disponible.');

        $this->db->begin_transaction();
        try {
            $lock = $this->db->prepare(
                "SELECT clasificacion, seguimiento_id, interaccion_id, estado,
                        extension, numero_origen, protocolo,pbx_call_id,pbx_inicio_evento
                   FROM telefonia_analista_webrtc_sesiones
                  WHERE usuario_id = ? AND session_id = ? FOR UPDATE"
            );
            $lock->bind_param('is', $usuarioId, $sessionId);
            $lock->execute();
            $rowNow = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if (!$rowNow || $rowNow['clasificacion'] !== 'INTERNA' ||
                $rowNow['estado'] !== 'ended' ||
                $rowNow['seguimiento_id'] !== null ||
                $rowNow['interaccion_id'] !== null ||
                $rowNow['extension'] !== $dest ||
                $rowNow['numero_origen'] !== $orig ||
                (int)$rowNow['protocolo']!==2 || !hash_equals($pbx,(string)$rowNow['pbx_call_id']) ||
                (int)$rowNow['pbx_inicio_evento']!==(int)$session['pbx_inicio_evento']) {
                throw new DomainException('El estado de la sesión cambió durante la búsqueda.');
            }
            if(self::referenciaVerificada($rowNow,$this->eventos($pbx))!==$recordId)
                throw new DomainException('La evidencia del audio cambió durante la consulta.');
            $this->registrar($usuarioId,$sessionId,$pbx,$recordId,$orig,$dest);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            if($e instanceof mysqli_sql_exception&&$e->getCode()===1062){
                $existing=$this->obtener($usuarioId,$sessionId);
                if($existing&&hash_equals($pbx,(string)$existing['pbx_call_id'])&&hash_equals($recordId,(string)$existing['call_id_with_rec']))return true;
            }
            throw $e;
        }
    }

    private function eventos(string $pbx): array
    {
        $q=$this->db->prepare('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();return $rows;
    }

    public static function referenciaVerificada(array $session,array $events): string
    {
        $orig=(string)$session['numero_origen'];$dest=(string)$session['extension'];$ref=(string)($session['pbx_call_id']??'');$proof=false;$source=false;
        if((int)($session['protocolo']??0)!==2||$orig===$dest||!preg_match('/^[0-9]{3,6}$/D',$orig)||!preg_match('/^[0-9]{3,6}$/D',$dest))
            throw new DomainException('La identidad interna requiere verificación.');
        foreach($events as $event){
            if(!ZadarmaPbxIdentifierHelper::pbxValido($ref)||!hash_equals($ref,(string)($event['pbx_call_id']??'')))throw new DomainException('La referencia PBX no corresponde a esta sesión.');
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            foreach([$event['internal']??'',$payload['last_internal']??'',$payload['transfer_from']??''] as $member)
                if((string)$member!==''&&!in_array((string)$member,[$orig,$dest],true))throw new DomainException('La llamada tiene otros participantes; no se eligió una grabación.');
            if((int)$event['id']===(int)$session['pbx_inicio_evento']&&(string)$event['internal']===$dest&&
                in_array($event['evento'],['NOTIFY_INTERNAL','NOTIFY_ANSWER'],true))$proof=true;
            if((string)$event['internal']===$orig&&in_array($event['evento'],['NOTIFY_OUT_START','NOTIFY_INTERNAL'],true))$source=true;
        }
        $facts=TelefoniaPbxSegmentosService::hechos($events,$dest,$orig);
        $ids=TelefoniaPbxSegmentosService::deEventos($events,$dest,[$orig])['segmentos'];
        if(!$proof||!$source||!$facts['conectada']||!$facts['finalizada']||count($ids)!==1)
            throw new DomainException('Falta evidencia propia inequívoca de cierre y grabación.');
        return $ids[0];
    }

    public function registrar(
        int $usuarioId, string $sessionId, string $pbxCallId,
        string $callId, string $origen, string $destino
    ): void {
        if (!preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId) ||
            !ZadarmaPbxIdentifierHelper::pbxValido($pbxCallId) ||
            !ZadarmaPbxIdentifierHelper::callIdValido($callId)) {
            throw new DomainException('El identificador de grabación no es válido.');
        }
        if (!preg_match('/^sdk-[a-z0-9-]{12,94}$/D', $sessionId) ||
            !preg_match('/^\d{3,6}$/D', $origen) ||
            !preg_match('/^\d{3,6}$/D', $destino) || $origen === $destino) {
            throw new DomainException('La llamada no es una sesión interna válida.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_webrtc_grabaciones_internas
              (usuario_id, session_id, pbx_call_id, call_id_with_rec,
               extension_origen, extension_destino)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssss', $usuarioId, $sessionId, $pbxCallId, $callId, $origen, $destino);
        $stmt->execute();
        $stmt->close();
    }
}
