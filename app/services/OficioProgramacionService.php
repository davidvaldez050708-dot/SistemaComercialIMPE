<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioProgramacionService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstado($seguimientoId, $usuarioId, $modoAcceso)
    {
        $seguimiento = $this->obtenerSeguimientoAutorizado(
            (int)$seguimientoId,
            (int)$usuarioId,
            $modoAcceso
        );

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        return [
            'ok' => true,
            'programacion' => $this->construirEstado($seguimiento)
        ];
    }

    public function programar($seguimientoId, $usuarioId, $fechaFormulario)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $fechaFormulario = trim((string)$fechaFormulario);
        $fecha = $this->normalizarFecha($fechaFormulario);

        if ($fecha === null) {
            return $this->error('Selecciona una fecha y hora válidas.', 422);
        }

        $momento = new DateTime($fecha);
        $ahora = new DateTime();

        if ($momento <= $ahora) {
            return $this->error('La fecha de envío debe ser posterior a la hora actual.', 422);
        }

        $this->connection->begin_transaction();

        try {
            $seguimiento = $this->obtenerSeguimientoAnalistaBloqueado(
                $seguimientoId,
                $usuarioId
            );

            if (!$seguimiento) {
                $this->connection->rollback();
                return $this->error(
                    'La programación solo puede realizarla el Analista responsable.',
                    403
                );
            }

            $validacion = $this->validarRequisitos($seguimiento);

            if (!($validacion['ok'] ?? false)) {
                $this->connection->rollback();
                return $validacion;
            }

            $fechaAnterior = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
            $esReprogramacion = $fechaAnterior !== '';
            $folio = trim((string)($seguimiento['folio'] ?? ''));
            $fechaLabel = $momento->format('d/m/Y H:i');
            $notas = ($esReprogramacion
                    ? 'Envío de oficio/correo reprogramado'
                    : 'Envío de oficio/correo programado') .
                "\nFolio: {$folio}" .
                "\nPróxima acción: Enviar oficio/correo" .
                "\nFecha programada: {$fechaLabel}";

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                        seguimiento_id,
                        usuario_id,
                        canal,
                        fecha_inicio,
                        resultado,
                        notas
                    ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notas);
            $stmtInteraccion->execute();

            $sqlActualizar = "UPDATE seguimientos_vinculacion
                    SET proxima_accion_at = ?,
                        ultima_interaccion_at = NOW()
                    WHERE id = ?
                        AND analista_id = ?
                        AND activo = 1
                        AND estado_seguimiento = 'OFICIO_PREPARADO'";
            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            $stmtActualizar->bind_param('sii', $fecha, $seguimientoId, $usuarioId);
            $stmtActualizar->execute();

            if ($stmtActualizar->affected_rows <= 0) {
                throw new RuntimeException('No fue posible actualizar la programación.');
            }

            $this->connection->commit();

            $seguimientoActualizado = $this->obtenerSeguimientoAutorizado(
                $seguimientoId,
                $usuarioId,
                'analista'
            );

            return [
                'ok' => true,
                'mensaje' => $esReprogramacion
                    ? 'Envío reprogramado correctamente.'
                    : 'Envío programado correctamente.',
                'programacion' => $this->construirEstado($seguimientoActualizado ?: $seguimiento)
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('No fue posible programar el envío del oficio: ' . $error->getMessage());

            return $this->error('No fue posible guardar la programación del envío.', 500);
        }
    }

    private function obtenerSeguimientoAutorizado($seguimientoId, $usuarioId, $modoAcceso)
    {
        $sql = $this->consultaBase();

        if ($modoAcceso === 'administrador') {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
        } elseif ($modoAcceso === 'supervisor') {
            $sql .= "
                INNER JOIN asignaciones_territorio asignacion_analista
                    ON asignacion_analista.usuario_id = seguimientos.analista_id
                    AND asignacion_analista.estado_id = seguimientos.estado_id
                    AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignacion_analista.activo = 1
                    AND " . $this->condicionAsignacionVigente('asignacion_analista') . "
                INNER JOIN asignaciones_territorio cuenta_clave
                    ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                    AND cuenta_clave.estado_id = seguimientos.estado_id
                    AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta_clave.activo = 1
                    AND cuenta_clave.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuenta_clave') . "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $usuarioId, $seguimientoId);
        } else {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        }

        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function obtenerSeguimientoAnalistaBloqueado($seguimientoId, $usuarioId)
    {
        $sql = $this->consultaBase() . "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1
                FOR UPDATE";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function consultaBase()
    {
        return "SELECT DISTINCT
                    seguimientos.id,
                    seguimientos.estado_id,
                    seguimientos.analista_id,
                    seguimientos.estado_seguimiento,
                    seguimientos.proxima_accion_at,
                    seguimientos.nombre_entidad,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    oficio.estado_oficio,
                    oficio.archivo_pdf,
                    oficio.asunto_correo,
                    oficio.cuerpo_correo
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT oficio_reciente.id
                        FROM oficios_vinculacion oficio_reciente
                        WHERE oficio_reciente.seguimiento_id = seguimientos.id
                        ORDER BY oficio_reciente.id DESC
                        LIMIT 1
                    )";
    }

    private function construirEstado($seguimiento)
    {
        $fecha = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
        $requisitos = $this->validarRequisitos($seguimiento);

        return [
            'seguimiento_id' => (int)($seguimiento['id'] ?? 0),
            'analista_id' => (int)($seguimiento['analista_id'] ?? 0),
            'folio' => (string)($seguimiento['folio'] ?? ''),
            'estado_seguimiento' => (string)($seguimiento['estado_seguimiento'] ?? ''),
            'programado' => $fecha !== '',
            'proxima_accion_at' => $fecha,
            'proxima_accion_input' => $this->fechaParaInput($fecha),
            'proxima_accion_label' => $this->fechaLabel($fecha),
            'cumple_requisitos' => (bool)($requisitos['ok'] ?? false),
            'motivo_bloqueo' => ($requisitos['ok'] ?? false)
                ? ''
                : (string)($requisitos['mensaje'] ?? 'La programación aún no está disponible.')
        ];
    }

    private function validarRequisitos($seguimiento)
    {
        if ((string)($seguimiento['estado_seguimiento'] ?? '') !== 'OFICIO_PREPARADO') {
            return $this->error(
                'La programación solo está disponible mientras el seguimiento está en Oficio preparado.',
                422
            );
        }

        $oficioId = (int)($seguimiento['oficio_id'] ?? 0);
        $folio = trim((string)($seguimiento['folio'] ?? ''));
        $estadoOficio = strtoupper(trim((string)($seguimiento['estado_oficio'] ?? '')));
        $rutaPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));
        $asunto = trim((string)($seguimiento['asunto_correo'] ?? ''));
        $cuerpo = trim((string)($seguimiento['cuerpo_correo'] ?? ''));

        if ($oficioId <= 0 || $folio === '') {
            return $this->error('Primero genera el oficio.', 422);
        }

        if ($estadoOficio !== 'GENERADO' || $rutaPdf === '') {
            return $this->error('Primero genera el PDF del oficio.', 422);
        }

        $rutaAbsoluta = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rutaPdf, '/\\'));

        if (!is_file($rutaAbsoluta)) {
            return $this->error('El PDF del oficio no está disponible.', 422);
        }

        if ($asunto === '' || $cuerpo === '') {
            return $this->error('Primero guarda el borrador del correo.', 422);
        }

        return ['ok' => true];
    }

    private function normalizarFecha($valor)
    {
        if ($valor === '') {
            return null;
        }

        $formatos = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'];

        foreach ($formatos as $formato) {
            $fecha = DateTime::createFromFormat($formato, $valor);

            if ($fecha instanceof DateTime) {
                return $fecha->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function fechaParaInput($fecha)
    {
        if (trim((string)$fecha) === '') {
            return '';
        }

        try {
            return (new DateTime($fecha))->format('Y-m-d\\TH:i');
        } catch (Throwable $error) {
            return '';
        }
    }

    private function fechaLabel($fecha)
    {
        if (trim((string)$fecha) === '') {
            return 'Sin programar';
        }

        try {
            return (new DateTime($fecha))->format('d/m/Y · H:i');
        } catch (Throwable $error) {
            return 'Fecha pendiente';
        }
    }

    private function condicionAsignacionVigente($alias)
    {
        return "(
            ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())
        )";
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
