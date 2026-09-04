<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/AgendaReunionService.php';

class ReprogramacionReunionService
{
    private $connection;
    private $agendaService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->agendaService = new AgendaReunionService();
    }

    public function solicitarAnalista($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== AgendaReunionService::ROL_ANALISTA) {
            return $this->error('Solo el Analista puede proponer esta reprogramación.', 403);
        }
        if (!$this->estructuraDisponible()) {
            return $this->error('Falta aplicar la migración de reprogramación de reuniones.', 500);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $motivo = trim((string)($datos['motivo'] ?? ''));
        $fecha = $this->normalizarFechaHora($datos['fecha_propuesta'] ?? '');
        $duracion = (int)($datos['duracion_minutos'] ?? 60);
        $modalidad = strtoupper(trim((string)($datos['modalidad'] ?? 'VIRTUAL')));

        if ($motivo === '') {
            return $this->error('Indica el motivo de la reprogramación.', 422);
        }
        $error = $this->validarPropuesta($fecha, $duracion, $modalidad);
        if ($error !== '') {
            return $this->error($error, 422);
        }

        $reunion = $this->obtenerReunion($reunionId, (int)$usuarioId, 4);
        if (!$reunion || !in_array((string)$reunion['estado'], ['CONFIRMADA', 'CORREO_ENVIADO'], true)) {
            return $this->error('La reunión no está disponible para reprogramarse.', 409);
        }

        $this->connection->begin_transaction();
        try {
            $historialId = $this->insertarHistorial(
                $reunion,
                $fecha,
                $duracion,
                $modalidad,
                $motivo,
                (int)$usuarioId,
                'ANALISTA',
                'PENDIENTE_KAM'
            );

            $sql = "UPDATE reuniones_vinculacion
                    SET fecha_propuesta=?, duracion_minutos=?, modalidad=?, estado='SOLICITADA',
                        es_reprogramacion=1, reprogramacion_motivo=?, reprogramacion_solicitada_at=NOW(),
                        reprogramacion_solicitada_por=?, confirmada_at=NULL, confirmada_por=NULL,
                        correo_confirmacion_asunto=NULL, correo_confirmacion_cuerpo=NULL,
                        correo_confirmacion_at=NULL, correo_confirmacion_por=NULL,
                        notificado_kam_at=NULL, notificado_analista_at=NULL
                    WHERE id=? AND analista_id=? AND estado IN ('CONFIRMADA','CORREO_ENVIADO')";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'sissiii',
                $fecha,
                $duracion,
                $modalidad,
                $motivo,
                $usuarioId,
                $reunionId,
                $usuarioId
            );
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                throw new RuntimeException('La reunión cambió de estado. Actualiza la agenda.');
            }

            $this->regresarPasoOnce((int)$reunion['seguimiento_id'], (int)$usuarioId, $fecha);
            $this->registrarInteraccion(
                (int)$reunion['seguimiento_id'],
                (int)$usuarioId,
                'Reprogramación solicitada. Fecha anterior: ' . $reunion['fecha_propuesta'] .
                ' | Nueva fecha: ' . $fecha . ' | Motivo: ' . $motivo
            );

            $this->connection->commit();

            return [
                'ok' => true,
                'mensaje' => 'La nueva fecha fue enviada a Cuenta Clave para confirmación.',
                'reunion_id' => $reunionId,
                'historial_id' => $historialId
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            return $this->error($error->getMessage(), $error instanceof RuntimeException ? 409 : 500);
        }
    }

    public function solicitarKam($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== AgendaReunionService::ROL_CUENTA_CLAVE) {
            return $this->error('Solo Cuenta Clave puede solicitar este cambio.', 403);
        }
        if (!$this->estructuraDisponible()) {
            return $this->error('Falta aplicar la migración de reprogramación de reuniones.', 500);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $motivo = trim((string)($datos['motivo'] ?? ''));
        if ($motivo === '') {
            return $this->error('Indica el motivo de la reprogramación.', 422);
        }

        $reunion = $this->obtenerReunion($reunionId, (int)$usuarioId, 6);
        if (!$reunion || !in_array((string)$reunion['estado'], ['CONFIRMADA', 'CORREO_ENVIADO'], true)) {
            return $this->error('La reunión no está disponible para solicitar un cambio.', 409);
        }

        $this->connection->begin_transaction();
        try {
            $this->insertarHistorial(
                $reunion,
                null,
                null,
                null,
                $motivo,
                (int)$usuarioId,
                'CUENTA_CLAVE',
                'ESPERANDO_PROPUESTA'
            );

            $sql = "UPDATE reuniones_vinculacion
                    SET estado='CAMBIO_SOLICITADO', es_reprogramacion=1,
                        reprogramacion_motivo=?, reprogramacion_solicitada_at=NOW(),
                        reprogramacion_solicitada_por=?, cambio_motivo=?,
                        cambio_solicitado_at=NOW(), cambio_solicitado_por=?,
                        notificado_analista_at=NULL
                    WHERE id=? AND cuenta_clave_id=? AND estado IN ('CONFIRMADA','CORREO_ENVIADO')";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('sisiii', $motivo, $usuarioId, $motivo, $usuarioId, $reunionId, $usuarioId);
            $stmt->execute();

            if ($stmt->affected_rows <= 0) {
                throw new RuntimeException('La reunión cambió de estado. Actualiza la agenda.');
            }

            $this->regresarPasoOnce((int)$reunion['seguimiento_id'], (int)$reunion['analista_id'], null);
            $this->registrarInteraccion(
                (int)$reunion['seguimiento_id'],
                (int)$usuarioId,
                'Cuenta Clave solicitó reprogramar la reunión del ' . $reunion['fecha_propuesta'] .
                '. Motivo: ' . $motivo
            );

            $this->connection->commit();

            return [
                'ok' => true,
                'mensaje' => 'Se notificó al Analista para que proponga una nueva fecha.',
                'reunion_id' => $reunionId
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            return $this->error($error->getMessage(), $error instanceof RuntimeException ? 409 : 500);
        }
    }

    public function completarAnalista($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== AgendaReunionService::ROL_ANALISTA) {
            return $this->error('Solo el Analista puede proponer la nueva fecha.', 403);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $reunion = $this->obtenerReunion($reunionId, (int)$usuarioId, 4);
        if (!$reunion || (int)($reunion['es_reprogramacion'] ?? 0) !== 1 || (string)$reunion['estado'] !== 'CAMBIO_SOLICITADO') {
            return $this->error('La reunión no está esperando una nueva propuesta.', 409);
        }

        $resultado = $this->agendaService->reprogramar($usuarioId, $rolId, $datos);
        if (!($resultado['ok'] ?? false)) {
            return $resultado;
        }

        $fecha = $this->normalizarFechaHora($datos['fecha_propuesta'] ?? '');
        $duracion = (int)($datos['duracion_minutos'] ?? 60);
        $modalidad = strtoupper(trim((string)($datos['modalidad'] ?? 'VIRTUAL')));

        $sql = "UPDATE reuniones_vinculacion_reprogramaciones
                SET fecha_nueva=?, duracion_nueva=?, modalidad_nueva=?, estado='PENDIENTE_KAM'
                WHERE reunion_id=? AND estado='ESPERANDO_PROPUESTA'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sis i', $fecha, $duracion, $modalidad, $reunionId);
        // bind_param no permite espacios en el tipo; se prepara nuevamente abajo.
        $stmt->close();
        $stmt = $this->connection->prepare(
            "UPDATE reuniones_vinculacion_reprogramaciones
             SET fecha_nueva=?, duracion_nueva=?, modalidad_nueva=?, estado='PENDIENTE_KAM'
             WHERE reunion_id=? AND estado='ESPERANDO_PROPUESTA'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('sisi', $fecha, $duracion, $modalidad, $reunionId);
        $stmt->execute();

        return $resultado;
    }

    public function confirmarKam($usuarioId, $rolId, $datos)
    {
        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $reunion = $this->obtenerReunion($reunionId, (int)$usuarioId, 6);
        if (!$reunion || (int)($reunion['es_reprogramacion'] ?? 0) !== 1) {
            return $this->error('Esta reunión no corresponde a una reprogramación pendiente.', 409);
        }

        $resultado = $this->agendaService->confirmar($usuarioId, $rolId, $datos);
        if (!($resultado['ok'] ?? false)) {
            return $resultado;
        }

        $zoom = trim((string)($datos['zoom_url'] ?? ''));
        $ubicacion = trim((string)($datos['ubicacion'] ?? ''));
        $sql = "UPDATE reuniones_vinculacion_reprogramaciones
                SET estado='CONFIRMADA', zoom_nuevo=?, ubicacion_nueva=?, confirmada_at=NOW(), confirmada_por=?
                WHERE reunion_id=? AND estado='PENDIENTE_KAM'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssii', $zoom, $ubicacion, $usuarioId, $reunionId);
        $stmt->execute();

        $resultado['mensaje'] = 'Nueva fecha confirmada. El Analista ya puede enviar el correo de reprogramación.';
        return $resultado;
    }

    public function marcarCorreoAnalista($usuarioId, $rolId, $datos)
    {
        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $reunion = $this->obtenerReunion($reunionId, (int)$usuarioId, 4);
        if (!$reunion || (int)($reunion['es_reprogramacion'] ?? 0) !== 1) {
            return $this->error('Esta reunión no corresponde a una reprogramación confirmada.', 409);
        }

        $resultado = $this->agendaService->marcarCorreoEnviado($usuarioId, $rolId, $datos);
        if (!($resultado['ok'] ?? false)) {
            return $resultado;
        }

        $sql = "UPDATE reuniones_vinculacion_reprogramaciones
                SET estado='CORREO_ENVIADO', correo_enviado_at=NOW()
                WHERE reunion_id=? AND estado='CONFIRMADA'
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $reunionId);
        $stmt->execute();

        $resultado['mensaje'] = 'Correo de reprogramación registrado. La nueva fecha quedó formalmente agendada.';
        return $resultado;
    }

    private function insertarHistorial($reunion, $fechaNueva, $duracionNueva, $modalidadNueva, $motivo, $usuarioId, $rol, $estado)
    {
        $sql = "INSERT INTO reuniones_vinculacion_reprogramaciones
                (reunion_id,seguimiento_id,fecha_anterior,fecha_nueva,duracion_anterior,duracion_nueva,
                 modalidad_anterior,modalidad_nueva,zoom_anterior,ubicacion_anterior,motivo,solicitado_por,
                 solicitado_por_rol,estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $this->connection->prepare($sql);

        $reunionId = (int)$reunion['id'];
        $seguimientoId = (int)$reunion['seguimiento_id'];
        $fechaAnterior = (string)$reunion['fecha_propuesta'];
        $duracionAnterior = (int)($reunion['duracion_minutos'] ?? 60);
        $modalidadAnterior = (string)($reunion['modalidad'] ?? 'VIRTUAL');
        $zoomAnterior = trim((string)($reunion['zoom_url'] ?? ''));
        $ubicacionAnterior = trim((string)($reunion['ubicacion'] ?? ''));
        $fechaNuevaDb = $fechaNueva ?: null;
        $duracionNuevaDb = $duracionNueva ?: null;
        $modalidadNuevaDb = $modalidadNueva ?: null;

        $stmt->bind_param(
            'iissiiissssiss',
            $reunionId,
            $seguimientoId,
            $fechaAnterior,
            $fechaNuevaDb,
            $duracionAnterior,
            $duracionNuevaDb,
            $modalidadAnterior,
            $modalidadNuevaDb,
            $zoomAnterior,
            $ubicacionAnterior,
            $motivo,
            $usuarioId,
            $rol,
            $estado
        );
        $stmt->execute();
        return (int)$this->connection->insert_id;
    }

    private function obtenerReunion($reunionId, $usuarioId, $rolId)
    {
        $sql = "SELECT r.* FROM reuniones_vinculacion r WHERE r.id=?";
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

    private function regresarPasoOnce($seguimientoId, $analistaId, $fecha)
    {
        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET reunion_fecha=NULL, reunion_modalidad=NULL, reunion_lugar_enlace=NULL, reunion_notas=NULL,
                    reunion_agendada_at=NULL, reunion_agendada_por=NULL,
                    reunion_resultado=NULL, reunion_resultado_notas=NULL,
                    reunion_realizada_at=NULL, reunion_realizada_por=NULL
                WHERE seguimiento_id=?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        $sql = "UPDATE seguimientos_vinculacion
                SET proxima_accion_at=?, ultima_interaccion_at=NOW()
                WHERE id=? AND analista_id=?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $fecha, $seguimientoId, $analistaId);
        $stmt->execute();
    }

    private function registrarInteraccion($seguimientoId, $usuarioId, $notas)
    {
        $sql = "INSERT INTO interacciones_vinculacion
                (seguimiento_id,usuario_id,canal,fecha_inicio,resultado,notas)
                VALUES (?,?,'SISTEMA',NOW(),'OTRO',?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iis', $seguimientoId, $usuarioId, $notas);
        $stmt->execute();
    }

    private function estructuraDisponible()
    {
        $tabla = $this->connection->query("SHOW TABLES LIKE 'reuniones_vinculacion_reprogramaciones'");
        if (!$tabla || $tabla->num_rows <= 0) {
            return false;
        }
        $columna = $this->connection->query("SHOW COLUMNS FROM reuniones_vinculacion LIKE 'es_reprogramacion'");
        return $columna && $columna->num_rows > 0;
    }

    private function normalizarFechaHora($valor)
    {
        $valor = str_replace('T', ' ', trim((string)$valor));
        if ($valor === '') {
            return null;
        }
        $fecha = DateTime::createFromFormat('Y-m-d H:i', $valor)
            ?: DateTime::createFromFormat('Y-m-d H:i:s', $valor);
        return $fecha ? $fecha->format('Y-m-d H:i:s') : null;
    }

    private function validarPropuesta($fecha, $duracion, $modalidad)
    {
        if ($fecha === null || strtotime($fecha) <= time()) {
            return 'Selecciona una fecha y hora futura para la reunión.';
        }
        if (!in_array((int)$duracion, [30,45,60,90,120], true)) {
            return 'Selecciona una duración válida.';
        }
        if (!in_array($modalidad, ['VIRTUAL','PRESENCIAL','HIBRIDA'], true)) {
            return 'Selecciona una modalidad válida.';
        }
        return '';
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
