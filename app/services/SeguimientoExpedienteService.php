<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoRutaOperativaService.php';

class SeguimientoExpedienteService
{
    private $connection;
    private $rutaService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rutaService = new SeguimientoRutaOperativaService();
    }

    public function obtener($seguimientoId, $usuarioId, $rolId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;

        if ($seguimientoId <= 0 || $usuarioId <= 0) {
            return $this->error('Selecciona un seguimiento válido.', 422);
        }

        if (!in_array($rolId, [4, 6], true)) {
            return $this->error('El expediente operativo está disponible para Analista y Cuenta Clave.', 403);
        }

        $seguimiento = $this->obtenerSeguimientoBase($seguimientoId);

        if (!$seguimiento) {
            return $this->error('No se encontró el seguimiento solicitado.', 404);
        }

        if (!$this->puedeConsultar($seguimiento, $usuarioId, $rolId)) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        $analistaId = (int)($seguimiento['analista_id'] ?? 0);
        $flujo = $this->resolverFlujo($seguimientoId, $analistaId);
        $postEnvio = $this->obtenerPostEnvio($seguimientoId);
        $convenioDocumento = $this->obtenerConvenioAprobado(
            $seguimientoId,
            $postEnvio
        );
        $reuniones = $this->obtenerReuniones($seguimientoId);
        $reprogramaciones = $this->obtenerReprogramaciones($seguimientoId);
        $ultimaInteraccion = $this->obtenerUltimaInteraccion($seguimientoId);

        return [
            'ok' => true,
            'seguimiento_id' => $seguimientoId,
            'flujo' => $flujo,
            'resumen' => [
                'estado_seguimiento' => (string)($seguimiento['estado_seguimiento'] ?? ''),
                'proxima_accion_at' => (string)($seguimiento['proxima_accion_at'] ?? ''),
                'ultima_interaccion_at' => (string)($seguimiento['ultima_interaccion_at'] ?? ''),
                'analista_id' => $analistaId,
                'estado_id' => (int)($seguimiento['estado_id'] ?? 0)
            ],
            'ultima_interaccion' => $ultimaInteraccion,
            'post_envio' => $postEnvio,
            'convenio_documento' => $convenioDocumento,
            'reuniones' => $reuniones,
            'reprogramaciones' => $reprogramaciones
        ];
    }

    private function obtenerSeguimientoBase($seguimientoId)
    {
        $sql = "SELECT id, analista_id, estado_id, estado_seguimiento,
                       datos_verificados, proxima_accion_at,
                       ultima_interaccion_at, created_at, updated_at
                FROM seguimientos_vinculacion
                WHERE id = ? AND activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function puedeConsultar($seguimiento, $usuarioId, $rolId)
    {
        $analistaId = (int)($seguimiento['analista_id'] ?? 0);
        $estadoId = (int)($seguimiento['estado_id'] ?? 0);

        if ($rolId === 4) {
            return $analistaId === $usuarioId;
        }

        if ($rolId !== 6) {
            return false;
        }

        if ($this->tablaDisponible('reuniones_vinculacion')) {
            $sqlReunion = "SELECT id
                           FROM reuniones_vinculacion
                           WHERE seguimiento_id = ? AND cuenta_clave_id = ?
                           LIMIT 1";
            $stmtReunion = $this->connection->prepare($sqlReunion);
            $seguimientoId = (int)($seguimiento['id'] ?? 0);
            $stmtReunion->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtReunion->execute();

            if ($stmtReunion->get_result()->fetch_assoc()) {
                return true;
            }
        }

        $sql = "SELECT cuenta.id
                FROM asignaciones_territorio analista
                JOIN asignaciones_territorio cuenta
                  ON cuenta.id = analista.cuenta_clave_asignacion_id
                 AND cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                 AND cuenta.activo = 1
                WHERE analista.estado_id = ?
                  AND analista.usuario_id = ?
                  AND analista.tipo_asignacion = 'ANALISTA_DATOS'
                  AND analista.activo = 1
                  AND cuenta.usuario_id = ?
                ORDER BY analista.id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iii', $estadoId, $analistaId, $usuarioId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    private function resolverFlujo($seguimientoId, $analistaId)
    {
        if ($analistaId <= 0) {
            return null;
        }

        try {
            $seguimientoBase = $this->obtenerSeguimientoBase($seguimientoId);
            $resultado = $this->rutaService->resolver(
                $seguimientoId,
                $analistaId,
                is_array($seguimientoBase) ? $seguimientoBase : []
            );

            return ($resultado['ok'] ?? false)
                ? ($resultado['flujo'] ?? null)
                : null;
        } catch (Throwable $error) {
            error_log(
                'No fue posible resolver el flujo del expediente: ' .
                $error->getMessage()
            );
            return null;
        }
    }

    private function obtenerPostEnvio($seguimientoId)
    {
        if (!$this->tablaDisponible('seguimientos_vinculacion_post_envio')) {
            return [];
        }

        $stmt = $this->connection->prepare(
            "SELECT *
             FROM seguimientos_vinculacion_post_envio
             WHERE seguimiento_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function obtenerConvenioAprobado($seguimientoId, $postEnvio)
    {
        $seguimientoId = (int)$seguimientoId;
        $postEnvio = is_array($postEnvio) ? $postEnvio : [];

        if (
            $seguimientoId <= 0 ||
            strtoupper(trim((string)($postEnvio['convenio_revision_estado'] ?? ''))) !== 'APROBADO'
        ) {
            return null;
        }

        $versionActual = max(
            0,
            (int)($postEnvio['convenio_version_actual'] ?? 0)
        );

        if ($this->tablaDisponible('seguimientos_vinculacion_convenio_versiones')) {
            if ($versionActual > 0) {
                $sql = "SELECT
                            id,
                            seguimiento_id,
                            version_num,
                            tipo,
                            fecha_recepcion,
                            nombre_original,
                            mime,
                            tamano,
                            recibido_at
                        FROM seguimientos_vinculacion_convenio_versiones
                        WHERE seguimiento_id = ?
                          AND version_num = ?
                        LIMIT 1";
                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param('ii', $seguimientoId, $versionActual);
            } else {
                $sql = "SELECT
                            id,
                            seguimiento_id,
                            version_num,
                            tipo,
                            fecha_recepcion,
                            nombre_original,
                            mime,
                            tamano,
                            recibido_at
                        FROM seguimientos_vinculacion_convenio_versiones
                        WHERE seguimiento_id = ?
                        ORDER BY version_num DESC, id DESC
                        LIMIT 1";
                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param('i', $seguimientoId);
            }

            $stmt->execute();
            $version = $stmt->get_result()->fetch_assoc();

            if ($version) {
                return [
                    'id' => (int)$version['id'],
                    'seguimiento_id' => $seguimientoId,
                    'version' => max(1, (int)$version['version_num']),
                    'tipo' => (string)($version['tipo'] ?? ''),
                    'fecha_recepcion' => (string)($version['fecha_recepcion'] ?? ''),
                    'nombre' => (string)($version['nombre_original'] ?? ''),
                    'mime' => (string)($version['mime'] ?? ''),
                    'tamano' => (int)($version['tamano'] ?? 0),
                    'recibido_at' => (string)($version['recibido_at'] ?? ''),
                    'estado' => 'APROBADO'
                ];
            }
        }

        $archivoActual = trim(
            (string)($postEnvio['convenio_recibido_archivo'] ?? '')
        );

        if ($archivoActual === '') {
            return null;
        }

        return [
            'id' => 0,
            'seguimiento_id' => $seguimientoId,
            'version' => max(1, $versionActual),
            'tipo' => 'REQUISITADO',
            'fecha_recepcion' => (string)($postEnvio['convenio_recibido_fecha'] ?? ''),
            'nombre' => (string)(
                $postEnvio['convenio_recibido_nombre_original'] ??
                'Convenio aprobado'
            ),
            'mime' => (string)($postEnvio['convenio_recibido_mime'] ?? ''),
            'tamano' => (int)($postEnvio['convenio_recibido_tamano'] ?? 0),
            'recibido_at' => (string)($postEnvio['convenio_recibido_at'] ?? ''),
            'estado' => 'APROBADO'
        ];
    }

    private function obtenerReuniones($seguimientoId)
    {
        if (!$this->tablaDisponible('reuniones_vinculacion')) {
            return [];
        }

        $sql = "SELECT r.*,
                       TRIM(CONCAT(COALESCE(k.nombre, ''), ' ', COALESCE(k.apellidos, ''))) AS cuenta_clave_nombre
                FROM reuniones_vinculacion r
                LEFT JOIN usuarios k ON k.id = r.cuenta_clave_id
                WHERE r.seguimiento_id = ?
                ORDER BY r.id DESC
                LIMIT 10";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerReprogramaciones($seguimientoId)
    {
        if (!$this->tablaDisponible('reuniones_vinculacion_reprogramaciones')) {
            return [];
        }

        $stmt = $this->connection->prepare(
            "SELECT *
             FROM reuniones_vinculacion_reprogramaciones
             WHERE seguimiento_id = ?
             ORDER BY id DESC
             LIMIT 10"
        );
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerUltimaInteraccion($seguimientoId)
    {
        $stmt = $this->connection->prepare(
            "SELECT canal, resultado, notas, fecha_inicio
             FROM interacciones_vinculacion
             WHERE seguimiento_id = ?
             ORDER BY fecha_inicio DESC, id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function tablaDisponible($tabla)
    {
        $tablas = [
            'seguimientos_vinculacion_post_envio',
            'seguimientos_vinculacion_convenio_versiones',
            'reuniones_vinculacion',
            'reuniones_vinculacion_reprogramaciones'
        ];

        if (!in_array($tabla, $tablas, true)) {
            return false;
        }

        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
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
