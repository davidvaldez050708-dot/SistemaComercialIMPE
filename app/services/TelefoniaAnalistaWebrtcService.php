<?php
require_once __DIR__.'/TelefoniaSesionLocalService.php';
require_once __DIR__.'/TelefoniaTransferenciaOperacionService.php';
require_once __DIR__.'/../helpers/TelefoniaFechaHoraHelper.php';

/** Adaptador histórico institucional. El ciclo SDK lo registra el servicio común. */
class TelefoniaAnalistaWebrtcService extends TelefoniaSesionLocalService
{
    public function registrarEvento(int $usuarioId,array $datos,int $protocol=1):void
    {
        if($protocol!==1)throw new InvalidArgumentException('Usa el registro común del host para esta sesión.');
        $datos['direccion']='ENTRANTE';$id=trim((string)($datos['session_id']??''));$evento=(string)($datos['evento']??'');
        if(!in_array($evento,['ringing','answered','ended','declined','missed'],true))throw new InvalidArgumentException('Evento WebRTC no válido.');
        if($evento==='ended'){
            $row=$this->obtener($usuarioId,$id);
            if(!$row||$row['direccion']!=='ENTRANTE'||!in_array($row['estado'],['answered','ended'],true))throw new RuntimeException('No se confirmó el inicio de conversación WebRTC.');
            $seconds=filter_var($datos['duracion_observada_segundos']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>7200]]);
            $end=$row['fecha_fin']??gmdate('Y-m-d H:i:s');
            $span=strtotime($end.' UTC')-strtotime($row['fecha_inicio'].' UTC');
            if(!is_int($seconds)||$seconds>max(1,$span+3))unset($datos['duracion_observada_segundos']);
        }
        parent::registrarEvento($usuarioId,$datos,1);
        if($evento==='ringing'){
            (new TelefoniaTransferenciaOperacionService($this->db))->paraDestino($usuarioId,trim((string)($datos['numero']??'')),$id);
        }
        if($evento==='ended'){
            $row=$this->obtener($usuarioId,$id);$extension=(string)$row['extension'];
            if($row['clasificacion']==='PENDIENTE'&&$this->esNumeroInterno((string)$row['numero_origen'],$extension)){
                $stmt=$this->db->prepare("UPDATE telefonia_analista_webrtc_sesiones SET clasificacion='INTERNA'
                    WHERE usuario_id=? AND session_id=BINARY ? AND extension=? AND direccion='ENTRANTE'
                      AND estado='ended' AND clasificacion='PENDIENTE' AND seguimiento_id IS NULL AND interaccion_id IS NULL");
                $stmt->bind_param('iss',$usuarioId,$id,$extension);$stmt->execute();$stmt->close();
            }
        }
    }
    public function esSesionInterna(int $usuarioId, string $sessionId): bool
    {
        if (!self::validarSesion($sessionId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT numero_origen, extension
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND session_id = BINARY ? AND direccion = 'ENTRANTE' AND estado = 'ended'
              LIMIT 1"
        );
        $stmt->bind_param('is', $usuarioId, $sessionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila && $this->esNumeroInterno(
            (string)$fila['numero_origen'], (string)$fila['extension']
        );
    }

    public function obtenerFinalizada(int $usuarioId, string $id): array
    {
        if (!self::validarSesion($id)) {
            throw new InvalidArgumentException('Sesión WebRTC no válida.');
        }
        $extension = $this->extension($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT * FROM telefonia_analista_webrtc_sesiones
             WHERE usuario_id = ? AND session_id = BINARY ? AND extension = ? AND direccion = 'ENTRANTE'
             LIMIT 1"
        );
        $stmt->bind_param('iss', $usuarioId, $id, $extension);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['fecha_fin'] === null) {
            throw new DomainException('La llamada WebRTC aún no figura como finalizada.');
        }
        return $row;
    }

    public static function duracion(array $row): int
    {
        $inicio = strtotime((string)($row['fecha_atendida'] ?? ''));
        $fin = strtotime((string)($row['fecha_fin'] ?? ''));
        $tiempoServidor = $inicio !== false && $fin !== false && $fin > $inicio
            ? $fin - $inicio : 0;
        return max($tiempoServidor, (int)($row['duracion_observada_segundos'] ?? 0));
    }

    public function listar(int $usuarioId): array
    {
        $extension = $this->extension($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT * FROM telefonia_analista_webrtc_sesiones
             WHERE usuario_id = ? AND extension = ? AND direccion = 'ENTRANTE'
               AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY fecha_inicio DESC, id DESC LIMIT 75"
        );
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(function (array $row) use ($extension): array {
            $interna = ($row['clasificacion'] ?? '') === 'INTERNA' ||
                $this->esNumeroInterno((string)($row['numero_origen'] ?? ''), $extension);
            return [
                'pbx_call_id' => (string)$row['session_id'],
                'pbx_reference' => (string)($row['pbx_call_id']??''),
                'numero' => (string)$row['numero_origen'],
                'fecha' => TelefoniaFechaHoraHelper::aMexico((string)$row['fecha_inicio']),
                'duracion' => self::duracion($row),
                'estado_pbx' => $row['fecha_fin'] !== null ? 'ended' : 'ringing',
                'contestada' => $row['fecha_atendida'] !== null,
                'clasificacion' => (string)$row['clasificacion'],
                'seguimiento_id' => (int)($row['seguimiento_id'] ?? 0),
                'interaccion_id' => (int)($row['interaccion_id'] ?? 0),
                'fuente' => 'SDK_WEBRTC',
                'tipo_llamada' => $interna ? 'INTERNA' : 'ENTRANTE',
                'estado_webrtc' => (string)$row['estado']
            ];
        }, $rows);
    }
}
