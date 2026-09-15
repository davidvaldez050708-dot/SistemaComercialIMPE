<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderMeetingConfirmationService
{
    private const ROL_ANALISTA = 4;
    private const ROL_CUENTA_CLAVE = 6;

    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener($usuarioId, $rolId, $limite = 10)
    {
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;
        $limite = max(1, min(50, (int)$limite));

        if ($usuarioId <= 0 || !in_array($rolId, [self::ROL_ANALISTA, self::ROL_CUENTA_CLAVE], true)) {
            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        try {
            $filas = $this->consultarPendientes($usuarioId, $rolId, $limite);
            $recordatorios = [];
            $avisos = [];
            $ahora = new DateTime();

            foreach ($filas as $fila) {
                $reunionId = (int)($fila['reunion_id'] ?? 0);
                $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);
                $fecha = trim((string)($fila['fecha_propuesta'] ?? ''));
                $institucion = trim((string)($fila['nombre_entidad'] ?? ''));

                if ($reunionId <= 0 || $seguimientoId <= 0 || $fecha === '') {
                    continue;
                }

                try {
                    $momento = new DateTime($fecha);
                } catch (Throwable $error) {
                    continue;
                }

                if ($institucion === '') {
                    $institucion = 'Seguimiento';
                }

                $vencida = $momento <= $ahora;
                $descripcion = $this->describirFecha($momento, $ahora);
                $url = 'index.php?controller=agendaReunion&action=index&reunion_id=' . $reunionId;

                if ($rolId === self::ROL_ANALISTA) {
                    $accion = $vencida
                        ? 'Reunión no confirmada por Cuenta Clave'
                        : 'Reunión pendiente de confirmación';
                    $icono = $vencida ? 'bi-calendar-x' : 'bi-hourglass-split';
                } else {
                    $accion = 'Confirmar reunión o solicitar nueva fecha';
                    $icono = 'bi-calendar-x';
                }

                $recordatorios[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'reunion_id' => $reunionId,
                    'nombre_entidad' => $institucion,
                    'accion' => $accion,
                    'fecha' => $fecha,
                    'etiqueta' => $descripcion['etiqueta'],
                    'estado' => $descripcion['estado'],
                    'icono' => $icono,
                    'url' => $url,
                    'prioridad' => $vencida ? 0 : 1
                ];

                if (!$vencida || !$this->marcarAvisoVencido($reunionId, $rolId)) {
                    continue;
                }

                $titulo = $rolId === self::ROL_ANALISTA
                    ? 'Reunión sin confirmar vencida'
                    : 'Confirmación de reunión vencida';
                $mensaje = $rolId === self::ROL_ANALISTA
                    ? 'La fecha propuesta para ' . $institucion . ' venció sin confirmación de Cuenta Clave.'
                    : 'La fecha propuesta para ' . $institucion . ' venció. Confirma la reunión o solicita una nueva fecha.';

                $avisos[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'reunion_id' => $reunionId,
                    'institucion' => $institucion,
                    'nombre_entidad' => $institucion,
                    'accion' => $accion,
                    'fecha' => $fecha,
                    'tipo' => 'VENCIDA',
                    'titulo' => $titulo,
                    'mensaje' => $mensaje,
                    'icono' => 'bi-calendar-x',
                    'url' => $url
                ];
            }

            return [
                'recordatorios' => $recordatorios,
                'avisos' => $avisos
            ];
        } catch (Throwable $error) {
            error_log(
                'No fue posible cargar reuniones pendientes de confirmación: ' .
                $error->getMessage()
            );

            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }
    }

    private function consultarPendientes($usuarioId, $rolId, $limite)
    {
        $campoUsuario = $rolId === self::ROL_ANALISTA
            ? 'r.analista_id'
            : 'r.cuenta_clave_id';
        $ventana = $rolId === self::ROL_ANALISTA
            ? "AND r.fecha_propuesta <= DATE_ADD(NOW(), INTERVAL 3 HOUR)"
            : "AND r.fecha_propuesta <= NOW()";

        $sql = "SELECT
                    r.id AS reunion_id,
                    r.seguimiento_id,
                    r.fecha_propuesta,
                    s.nombre_entidad
                FROM reuniones_vinculacion r
                INNER JOIN seguimientos_vinculacion s
                    ON s.id = r.seguimiento_id
                WHERE {$campoUsuario} = ?
                  AND r.estado = 'SOLICITADA'
                  AND s.activo = 1
                  AND s.estado_seguimiento <> 'DESCARTADO'
                  {$ventana}
                ORDER BY
                    CASE WHEN r.fecha_propuesta <= NOW() THEN 0 ELSE 1 END ASC,
                    r.fecha_propuesta ASC,
                    r.id ASC
                LIMIT ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $usuarioId, $limite);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function marcarAvisoVencido($reunionId, $rolId)
    {
        $campo = $rolId === self::ROL_ANALISTA
            ? 'notificado_analista_at'
            : 'notificado_kam_at';

        $sql = "UPDATE reuniones_vinculacion
                SET {$campo} = NOW()
                WHERE id = ?
                  AND estado = 'SOLICITADA'
                  AND fecha_propuesta <= NOW()
                  AND {$campo} IS NULL";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $reunionId);
        $stmt->execute();

        return $stmt->affected_rows > 0;
    }

    private function describirFecha(DateTime $momento, DateTime $ahora)
    {
        $hoy = (clone $ahora)->setTime(0, 0, 0);
        $momentoDia = (clone $momento)->setTime(0, 0, 0);
        $hora = $momento->format('H:i');

        if ($momento <= $ahora) {
            if ($momentoDia == $hoy) {
                return [
                    'etiqueta' => 'Vencida hoy · ' . $hora,
                    'estado' => 'vencida'
                ];
            }

            $ayer = (clone $hoy)->modify('-1 day');
            if ($momentoDia == $ayer) {
                return [
                    'etiqueta' => 'Ayer · ' . $momento->format('d/m') . ' · ' . $hora,
                    'estado' => 'vencida'
                ];
            }

            return [
                'etiqueta' => 'Vencida · ' . $momento->format('d/m') . ' · ' . $hora,
                'estado' => 'vencida'
            ];
        }

        $segundos = max(1, $momento->getTimestamp() - $ahora->getTimestamp());
        if ($segundos <= 3600) {
            $minutos = max(1, (int)ceil($segundos / 60));

            return [
                'etiqueta' => 'En ' . $minutos . ' min · ' . $hora,
                'estado' => 'proxima'
            ];
        }

        $horas = (int)floor($segundos / 3600);
        $minutos = (int)ceil(($segundos % 3600) / 60);
        $partes = ['En ' . max(1, $horas) . ' h'];

        if ($minutos > 0 && $minutos < 60) {
            $partes[] = $minutos . ' min';
        }

        return [
            'etiqueta' => implode(' ', $partes) . ' · ' . $hora,
            'estado' => 'proxima'
        ];
    }
}
