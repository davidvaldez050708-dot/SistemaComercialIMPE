<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoFlujoService.php';
require_once __DIR__ . '/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReunionFechaGuardService.php';
require_once __DIR__ . '/ReunionResultadoService.php';

class SeguimientoExpedienteService
{
    private $connection;
    private $flujoService;
    private $postEnvioService;
    private $agendaService;
    private $fechaGuardService;
    private $resultadoService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->flujoService = new SeguimientoFlujoService();
        $this->postEnvioService = new SeguimientoPostEnvioService();
        $this->agendaService = new AgendaReunionService();
        $this->fechaGuardService = new ReunionFechaGuardService();
        $this->resultadoService = new ReunionResultadoService();
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
            'reuniones' => $reuniones,
            'reprogramaciones' => $reprogramaciones
        ];
    }

    private function obtenerSeguimientoBase($seguimientoId)
    {
        $sql = "SELECT id, analista_id, estado_id, estado_seguimiento,
                       proxima_accion_at, ultima_interaccion_at
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
            $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
                $seguimientoId,
                $analistaId
            );

            if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
                $flujo = $this->agendaService->ajustarFlujoAnalista(
                    $seguimientoId,
                    $analistaId,
                    $postEnvio['flujo']
                );
                $flujo = $this->fechaGuardService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = $this->resultadoService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                return $flujo;
            }

            $resultado = $this->flujoService->obtenerEstado(
                $seguimientoId,
                $analistaId
            );

            return ($resultado['ok'] ?? false)
                ? ($resultado['flujo'] ?? null)
                : null;
        } catch (Throwable $error) {
            error_log('No fue posible resolver el flujo del expediente: ' . $error->getMessage());
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
