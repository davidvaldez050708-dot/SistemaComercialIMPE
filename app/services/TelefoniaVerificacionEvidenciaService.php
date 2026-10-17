<?php
/** Evidencia por dato. La telefonía pendiente nunca obtiene crédito por texto libre. */
final class TelefoniaVerificacionEvidenciaService
{
    private $db;
    public function __construct($db) { $this->db=$db; }

    // Ejecutar antes de abrir la transacción comercial: DDL puede hacer COMMIT.
    public function asegurarEstructura(): void
    {
        if (!$this->db->query("CREATE TABLE IF NOT EXISTS telefonia_verificacion_evidencias (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            interaccion_id INT NOT NULL, usuario_id INT NOT NULL, seguimiento_id INT NOT NULL,
            campo VARCHAR(20) NOT NULL, valor_anterior VARCHAR(180) NOT NULL DEFAULT '',
            valor_confirmado VARCHAR(180) NOT NULL DEFAULT '', accion VARCHAR(20) NOT NULL,
            informante VARCHAR(140) NOT NULL, estado VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL DEFAULT '', sdk_session_id VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            UNIQUE KEY uk_verificacion_dato (interaccion_id,campo),
            KEY idx_verificacion_usuario (usuario_id,seguimiento_id,estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
            throw new RuntimeException('No se pudo preparar el registro de verificaciones.');
        }
    }

    public function guardar(int $interaccion,int $usuario,int $seguimiento,array $detalles,
        string $informante,bool $confirmada,string $pbx='',string $sdk=''): void
    {
        $estado=$confirmada?'CONFIRMADA':'PENDIENTE';
        foreach ($detalles as $dato) {
            $campo=$dato['campo'];$anterior=$dato['anterior'];$valor=$dato['valor'];$accion=$dato['accion'];
            $stmt=$this->db->prepare('INSERT INTO telefonia_verificacion_evidencias
                (interaccion_id,usuario_id,seguimiento_id,campo,valor_anterior,valor_confirmado,accion,
                 informante,estado,pbx_call_id,sdk_session_id,confirmed_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL))');
            $si=$confirmada?1:0;
            $stmt->bind_param('iiissssssssi',$interaccion,$usuario,$seguimiento,$campo,$anterior,$valor,
                $accion,$informante,$estado,$pbx,$sdk,$si);
            if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar la evidencia de verificación.');
            $stmt->close();
        }
    }

    /** Llamar únicamente tras verificar la pierna PBX propia y la confirmación del analista. */
    public function confirmar(int $usuario,int $interaccion,string $sdk,string $pbx,array $call): bool
    {
        if (empty($call['answer_at']) || empty($call['end_at']) ||
            strtotime($call['end_at'])<=strtotime($call['answer_at'])) return false;
        $stmt=$this->db->prepare("SELECT id,seguimiento_id,notas FROM interacciones_vinculacion
            WHERE id=? AND usuario_id=? AND proveedor_externo='ZADARMA_WEBRTC'
              AND id_externo=? AND canal='LLAMADA_IP' FOR UPDATE");
        $externo='WEBRTC-'.$sdk;
        $stmt->bind_param('iis',$interaccion,$usuario,$externo);$stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$row) return false;
        $stmt=$this->db->prepare("SELECT id FROM telefonia_verificacion_evidencias
            WHERE interaccion_id=? AND usuario_id=? AND sdk_session_id=? AND estado='PENDIENTE' FOR UPDATE");
        $stmt->bind_param('iis',$interaccion,$usuario,$sdk);$stmt->execute();
        $hay=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$hay) return false;
        $notas=str_replace('[VERIFICACION_PENDIENTE_PBX]','[VERIFICACION_EFECTIVA]',(string)$row['notas']);
        $inicio=(string)$call['answer_at'];$fin=(string)$call['end_at'];
        $duracion=max(0,strtotime($fin)-strtotime($inicio));
        $stmt=$this->db->prepare("UPDATE interacciones_vinculacion SET proveedor_externo='ZADARMA',
            id_externo=?,fecha_inicio=?,fecha_fin=?,duracion_segundos=?,notas=? WHERE id=? AND usuario_id=?");
        $stmt->bind_param('sssisii',$pbx,$inicio,$fin,$duracion,$notas,$interaccion,$usuario);
        if (!$stmt->execute()) throw new RuntimeException('No se pudo confirmar la llamada.');$stmt->close();
        $stmt=$this->db->prepare("UPDATE telefonia_verificacion_evidencias SET estado='CONFIRMADA',
            pbx_call_id=?,confirmed_at=NOW() WHERE interaccion_id=? AND usuario_id=? AND estado='PENDIENTE'");
        $stmt->bind_param('sii',$pbx,$interaccion,$usuario);$stmt->execute();$stmt->close();
        return true;
    }

    public function pendientes(int $usuario): array
    {
        try {
            $stmt=$this->db->prepare("SELECT DISTINCT interaccion_id FROM telefonia_verificacion_evidencias
                WHERE usuario_id=? AND estado='PENDIENTE'");
            if (!$stmt) {
                if ((int)$this->db->errno===1146) return [];
                throw new RuntimeException('No se pudo consultar la verificación pendiente.');
            }
            $stmt->bind_param('i',$usuario);$stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            return array_map('intval',array_column($rows,'interaccion_id'));
        } catch (mysqli_sql_exception $e) {if ($e->getCode()===1146) return [];throw $e;}
    }

    public function confirmarSalida(int $usuario,int $interaccion,string $pbx,bool $huboRespuesta): void
    {
        $estado=$huboRespuesta?'CONFIRMADA':'RECHAZADA';
        try {
            $stmt=$this->db->prepare("UPDATE telefonia_verificacion_evidencias SET estado=?,pbx_call_id=?,
                confirmed_at=IF(?='CONFIRMADA',NOW(),NULL) WHERE interaccion_id=? AND usuario_id=? AND estado='PENDIENTE'");
            if (!$stmt) {if ((int)$this->db->errno===1146) return;throw new RuntimeException('No se pudo confirmar la evidencia.');}
            $stmt->bind_param('sssii',$estado,$pbx,$estado,$interaccion,$usuario);$stmt->execute();$stmt->close();
        } catch (mysqli_sql_exception $e) {if ($e->getCode()!==1146) throw $e;}
    }
}
