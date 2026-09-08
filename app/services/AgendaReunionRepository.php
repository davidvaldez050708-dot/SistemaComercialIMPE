<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AgendaReunionRepository
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function connection()
    {
        return $this->connection;
    }

    public function tablaDisponible()
    {
        $r = $this->connection->query("SHOW TABLES LIKE 'reuniones_vinculacion'");
        return $r && $r->num_rows > 0;
    }

    public function reunionesMes($usuarioId, $rolId, $inicio, $fin)
    {
        $sql = "SELECT r.*, s.nombre_entidad, s.contacto_nombre, s.contacto_cargo,
                       COALESCE(NULLIF(TRIM(s.correo_verificado),''), NULLIF(TRIM(s.correo_fuente),'')) AS contacto_correo,
                       m.nombre AS municipio_nombre,
                       TRIM(CONCAT(COALESCE(a.nombre,''),' ',COALESCE(a.apellidos,''))) AS analista_nombre,
                       TRIM(CONCAT(COALESCE(k.nombre,''),' ',COALESCE(k.apellidos,''))) AS cuenta_clave_nombre
                FROM reuniones_vinculacion r
                JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id
                LEFT JOIN municipios m ON m.id=s.municipio_id
                LEFT JOIN usuarios a ON a.id=r.analista_id
                LEFT JOIN usuarios k ON k.id=r.cuenta_clave_id
                WHERE r.fecha_propuesta>=? AND r.fecha_propuesta<? AND r.estado<>'CANCELADA'";

        if ((int)$rolId === 4) {
            $sql .= " AND r.analista_id=?";
        } elseif ((int)$rolId === 6) {
            $sql .= " AND r.cuenta_clave_id=?";
        }
        $sql .= " ORDER BY r.fecha_propuesta ASC, r.id ASC";

        $stmt = $this->connection->prepare($sql);
        if (in_array((int)$rolId, [4, 6], true)) {
            $stmt->bind_param('ssi', $inicio, $fin, $usuarioId);
        } else {
            $stmt->bind_param('ss', $inicio, $fin);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function seguimientosElegibles($analistaId)
    {
        $sql = "SELECT s.id, s.nombre_entidad, s.contacto_nombre, s.contacto_cargo,
                       COALESCE(NULLIF(TRIM(s.correo_verificado),''), NULLIF(TRIM(s.correo_fuente),'')) AS contacto_correo,
                       m.nombre AS municipio_nombre
                FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id
                LEFT JOIN municipios m ON m.id=s.municipio_id
                WHERE s.analista_id=? AND s.activo=1 AND s.estado_seguimiento<>'DESCARTADO'
                  AND p.seguimiento_correo_at IS NOT NULL AND p.reunion_agendada_at IS NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM reuniones_vinculacion r
                    WHERE r.seguimiento_id=s.id AND r.estado NOT IN ('CANCELADA','REALIZADA')
                  )
                ORDER BY s.nombre_entidad";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $analistaId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function seguimientoElegible($seguimientoId, $analistaId)
    {
        $sql = "SELECT s.id FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id
                WHERE s.id=? AND s.analista_id=? AND s.activo=1 AND s.estado_seguimiento<>'DESCARTADO'
                  AND p.seguimiento_correo_at IS NOT NULL AND p.reunion_agendada_at IS NULL LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function cuentaClaveParaSeguimiento($seguimientoId, $analistaId)
    {
        $sql = "SELECT cuenta.usuario_id AS cuenta_clave_id
                FROM seguimientos_vinculacion s
                JOIN asignaciones_territorio analista
                    ON analista.estado_id = s.estado_id
                    AND analista.usuario_id = s.analista_id
                    AND analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analista.activo = 1
                    AND (analista.fecha_inicio IS NULL OR analista.fecha_inicio <= CURDATE())
                    AND (analista.fecha_fin IS NULL OR analista.fecha_fin >= CURDATE())
                JOIN asignaciones_territorio cuenta
                    ON cuenta.id = analista.cuenta_clave_asignacion_id
                    AND cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta.activo = 1
                    AND (cuenta.fecha_inicio IS NULL OR cuenta.fecha_inicio <= CURDATE())
                    AND (cuenta.fecha_fin IS NULL OR cuenta.fecha_fin >= CURDATE())
                JOIN usuarios usuario_cuenta
                    ON usuario_cuenta.id = cuenta.usuario_id
                    AND usuario_cuenta.rol_id = 6
                    AND usuario_cuenta.estado = 1
                WHERE s.id = ? AND s.analista_id = ?
                ORDER BY analista.id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return (int)($fila['cuenta_clave_id'] ?? 0);
    }

    public function reunionActivaSeguimiento($seguimientoId)
    {
        $sql = "SELECT id FROM reuniones_vinculacion
                WHERE seguimiento_id=? AND estado NOT IN ('CANCELADA','REALIZADA')
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function reunion($reunionId, $usuarioId, $rolId)
    {
        $sql = "SELECT r.*, s.nombre_entidad, s.contacto_nombre, s.contacto_cargo,
                       COALESCE(NULLIF(TRIM(s.correo_verificado),''), NULLIF(TRIM(s.correo_fuente),'')) AS contacto_correo,
                       m.nombre AS municipio_nombre,
                       TRIM(CONCAT(COALESCE(a.nombre,''),' ',COALESCE(a.apellidos,''))) AS analista_nombre,
                       TRIM(CONCAT(COALESCE(k.nombre,''),' ',COALESCE(k.apellidos,''))) AS cuenta_clave_nombre
                FROM reuniones_vinculacion r
                JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id
                LEFT JOIN municipios m ON m.id=s.municipio_id
                LEFT JOIN usuarios a ON a.id=r.analista_id
                LEFT JOIN usuarios k ON k.id=r.cuenta_clave_id
                WHERE r.id=?";
        if ((int)$rolId === 4) {
            $sql .= " AND r.analista_id=?";
        } elseif ((int)$rolId === 6) {
            $sql .= " AND r.cuenta_clave_id=?";
        }
        $sql .= " LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        if (in_array((int)$rolId, [4, 6], true)) {
            $stmt->bind_param('ii', $reunionId, $usuarioId);
        } else {
            $stmt->bind_param('i', $reunionId);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function ultimaReunionSeguimiento($seguimientoId, $analistaId)
    {
        $sql = "SELECT * FROM reuniones_vinculacion
                WHERE seguimiento_id=? AND analista_id=? AND estado<>'CANCELADA'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function insertarSolicitud($seguimientoId, $analistaId, $fecha, $duracion, $modalidad, $objetivo, $notas)
    {
        $cuentaClaveId = $this->cuentaClaveParaSeguimiento($seguimientoId, $analistaId);
        if ($cuentaClaveId <= 0) {
            throw new RuntimeException('No hay una Cuenta Clave activa vinculada al territorio del Analista.');
        }

        $sql = "INSERT INTO reuniones_vinculacion
                (seguimiento_id,analista_id,cuenta_clave_id,fecha_propuesta,duracion_minutos,modalidad,objetivo,notas_analista,estado)
                VALUES (?,?,?,?,?,?,?,?,'SOLICITADA')";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iiisisss', $seguimientoId, $analistaId, $cuentaClaveId, $fecha, $duracion, $modalidad, $objetivo, $notas);
        $stmt->execute();
        return (int)$this->connection->insert_id;
    }

    public function reprogramar($reunionId, $analistaId, $fecha, $duracion, $modalidad, $objetivo, $notas)
    {
        $sql = "UPDATE reuniones_vinculacion SET fecha_propuesta=?,duracion_minutos=?,modalidad=?,objetivo=?,notas_analista=?,
                    estado='SOLICITADA',cambio_motivo=NULL,cambio_solicitado_at=NULL,cambio_solicitado_por=NULL,
                    notificado_kam_at=NULL,notificado_analista_at=NULL
                WHERE id=? AND analista_id=? AND estado='CAMBIO_SOLICITADO'";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sisssii', $fecha, $duracion, $modalidad, $objetivo, $notas, $reunionId, $analistaId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function confirmar($reunionId, $kamId, $zoomUrl, $ubicacion, $notasKam)
    {
        $sql = "UPDATE reuniones_vinculacion SET cuenta_clave_id=?,zoom_url=?,ubicacion=?,notas_kam=?,estado='CONFIRMADA',
                    confirmada_at=NOW(),confirmada_por=?,notificado_analista_at=NULL
                WHERE id=? AND cuenta_clave_id=? AND estado='SOLICITADA'";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('isssiii', $kamId, $zoomUrl, $ubicacion, $notasKam, $kamId, $reunionId, $kamId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function solicitarCambio($reunionId, $kamId, $motivo)
    {
        $sql = "UPDATE reuniones_vinculacion SET cuenta_clave_id=?,estado='CAMBIO_SOLICITADO',cambio_motivo=?,
                    cambio_solicitado_at=NOW(),cambio_solicitado_por=?,notificado_analista_at=NULL
                WHERE id=? AND cuenta_clave_id=? AND estado='SOLICITADA'";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('isiii', $kamId, $motivo, $kamId, $reunionId, $kamId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function marcarCorreo($reunionId, $analistaId, $asunto, $cuerpo)
    {
        $sql = "UPDATE reuniones_vinculacion SET estado='CORREO_ENVIADO',correo_confirmacion_asunto=?,
                    correo_confirmacion_cuerpo=?,correo_confirmacion_at=NOW(),correo_confirmacion_por=?
                WHERE id=? AND analista_id=? AND estado='CONFIRMADA'";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssiii', $asunto, $cuerpo, $analistaId, $reunionId, $analistaId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function asegurarPostEnvio($seguimientoId)
    {
        $stmt = $this->connection->prepare("INSERT IGNORE INTO seguimientos_vinculacion_post_envio (seguimiento_id) VALUES (?)");
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
    }

    public function sincronizarReunionPostEnvio($seguimientoId, $analistaId, $fecha, $modalidad, $lugar, $notas)
    {
        $sql = "UPDATE seguimientos_vinculacion_post_envio SET reunion_fecha=?,reunion_modalidad=?,reunion_lugar_enlace=?,
                    reunion_notas=?,reunion_agendada_at=NOW(),reunion_agendada_por=? WHERE seguimiento_id=?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssssii', $fecha, $modalidad, $lugar, $notas, $analistaId, $seguimientoId);
        $stmt->execute();
    }

    public function actualizarProximaAccion($seguimientoId, $analistaId, $fecha)
    {
        $sql = "UPDATE seguimientos_vinculacion SET proxima_accion_at=?,ultima_interaccion_at=NOW()
                WHERE id=? AND analista_id=?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $fecha, $seguimientoId, $analistaId);
        $stmt->execute();
    }

    public function registrarInteraccion($seguimientoId, $usuarioId, $notas)
    {
        $sql = "INSERT INTO interacciones_vinculacion (seguimiento_id,usuario_id,canal,fecha_inicio,resultado,notas)
                VALUES (?,?,'SISTEMA',NOW(),'OTRO',?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iis', $seguimientoId, $usuarioId, $notas);
        $stmt->execute();
    }

    public function pendientesKam($limite)
    {
        $kamId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($kamId <= 0) {
            return [];
        }

        $sql = "SELECT r.id,r.seguimiento_id,r.fecha_propuesta,s.nombre_entidad
                FROM reuniones_vinculacion r JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id
                WHERE r.estado='SOLICITADA' AND r.cuenta_clave_id=?
                ORDER BY r.updated_at DESC LIMIT ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $kamId, $limite);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function pendientesAnalista($analistaId, $limite)
    {
        $sql = "SELECT r.id,r.seguimiento_id,r.fecha_propuesta,r.estado,s.nombre_entidad
                FROM reuniones_vinculacion r JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id
                WHERE r.analista_id=? AND (
                    r.estado IN ('CAMBIO_SOLICITADO','CONFIRMADA') OR
                    (r.estado='CORREO_ENVIADO' AND r.fecha_propuesta BETWEEN DATE_SUB(NOW(),INTERVAL 2 HOUR) AND DATE_ADD(NOW(),INTERVAL 24 HOUR))
                ) ORDER BY r.updated_at DESC LIMIT ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $analistaId, $limite);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
