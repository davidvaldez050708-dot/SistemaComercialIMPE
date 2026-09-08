<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderReunionFollowupService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener($analistaId, $limite = 10)
    {
        $analistaId = (int)$analistaId;
        $limite = max(1, min(50, (int)$limite));

        if ($analistaId <= 0) {
            return ['recordatorios' => [], 'avisos' => []];
        }

        try {
            $sql = "SELECT
                        s.id,
                        s.nombre_entidad,
                        s.proxima_accion_at
                    FROM seguimientos_vinculacion s
                    JOIN seguimientos_vinculacion_post_envio p
                        ON p.seguimiento_id = s.id
                    WHERE s.analista_id = ?
                      AND s.activo = 1
                      AND s.estado_seguimiento <> 'DESCARTADO'
                      AND p.reunion_realizada_at IS NOT NULL
                      AND p.reunion_resultado = 'REQUIERE_SEGUIMIENTO'
                      AND p.convenio_formalizado_at IS NULL
                      AND s.proxima_accion_at IS NOT NULL
                      AND s.proxima_accion_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
                    ORDER BY s.proxima_accion_at ASC
                    LIMIT ?";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $analistaId, $limite);
            $stmt->execute();
            $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $recordatorios = [];
            $avisos = [];
            $ahora = new DateTime();

            foreach ($filas as $fila) {
                $seguimientoId = (int)($fila['id'] ?? 0);
                $fecha = trim((string)($fila['proxima_accion_at'] ?? ''));
                $institucion = trim((string)($fila['nombre_entidad'] ?? 'Seguimiento'));

                if ($seguimientoId <= 0 || $fecha === '') {
                    continue;
                }

                try {
                    $momento = new DateTime($fecha);
                } catch (Throwable $error) {
                    continue;
                }

                $accion = 'Dar seguimiento a acuerdos';
                $descripcion = $this->describirFecha($momento, $ahora);
                $url = 'index.php?controller=seguimientoVinculacion&action=detalle&id=' . $seguimientoId;

                $recordatorios[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'nombre_entidad' => $institucion,
                    'accion' => $accion,
                    'fecha' => $fecha,
                    'etiqueta' => $descripcion['etiqueta'],
                    'estado' => $descripcion['estado'],
                    'icono' => 'bi-clipboard-check',
                    'url' => $url
                ];

                $tipo = $this->resolverTipoAviso(
                    $momento->getTimestamp() - $ahora->getTimestamp()
                );

                if ($tipo === '' || !$this->tablaRecordatoriosDisponible()) {
                    continue;
                }

                $this->asegurarCiclo(
                    $seguimientoId,
                    $analistaId,
                    $accion,
                    $fecha
                );

                if (!$this->marcarAviso(
                    $seguimientoId,
                    $analistaId,
                    $accion,
                    $fecha,
                    $tipo
                )) {
                    continue;
                }

                $titulos = [
                    '3H' => 'En aproximadamente 3 horas',
                    '1H' => 'En aproximadamente 1 hora',
                    '10M' => 'En aproximadamente 10 minutos',
                    'VENCIDA' => 'Seguimiento pendiente'
                ];

                $avisos[] = [
                    'seguimiento_id' => $seguimientoId,
                    'id' => $seguimientoId,
                    'institucion' => $institucion,
                    'nombre_entidad' => $institucion,
                    'accion' => $accion,
                    'fecha' => $fecha,
                    'tipo' => $tipo,
                    'titulo' => $titulos[$tipo] ?? 'Recordatorio',
                    'mensaje' => $accion . ' · ' . $institucion,
                    'icono' => 'bi-clipboard-check',
                    'url' => $url
                ];
            }

            return [
                'recordatorios' => $recordatorios,
                'avisos' => $avisos
            ];
        } catch (Throwable $error) {
            error_log('No fue posible cargar recordatorios de seguimiento de reunión: ' . $error->getMessage());
            return ['recordatorios' => [], 'avisos' => []];
        }
    }

    private function describirFecha(DateTime $momento, DateTime $ahora)
    {
        $hoy = (clone $ahora)->setTime(0, 0, 0);
        $manana = (clone $hoy)->modify('+1 day');
        $momentoDia = (clone $momento)->setTime(0, 0, 0);
        $hora = $momento->format('H:i');

        if ($momento < $ahora) {
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

        $segundos = $momento->getTimestamp() - $ahora->getTimestamp();
        if ($segundos <= 3600) {
            $minutos = max(1, (int)ceil($segundos / 60));
            return [
                'etiqueta' => 'En ' . $minutos . ' min · ' . $hora,
                'estado' => 'proxima'
            ];
        }

        if ($momentoDia == $hoy) {
            return [
                'etiqueta' => 'Hoy · ' . $hora,
                'estado' => 'hoy'
            ];
        }

        if ($momentoDia == $manana) {
            return [
                'etiqueta' => 'Mañana · ' . $hora,
                'estado' => 'manana'
            ];
        }

        return [
            'etiqueta' => $momento->format('d/m/Y · H:i'),
            'estado' => 'normal'
        ];
    }

    private function resolverTipoAviso($segundosRestantes)
    {
        $segundosRestantes = (int)$segundosRestantes;

        if ($segundosRestantes <= 0) {
            return 'VENCIDA';
        }
        if ($segundosRestantes <= 10 * 60) {
            return '10M';
        }
        if ($segundosRestantes <= 60 * 60) {
            return '1H';
        }
        if ($segundosRestantes <= 3 * 60 * 60) {
            return '3H';
        }

        return '';
    }

    private function tablaRecordatoriosDisponible()
    {
        $resultado = $this->connection->query("SHOW TABLES LIKE 'recordatorios_vinculacion'");
        return $resultado && $resultado->num_rows > 0;
    }

    private function asegurarCiclo($seguimientoId, $usuarioId, $accion, $fecha)
    {
        $sql = "INSERT IGNORE INTO recordatorios_vinculacion
                (seguimiento_id, usuario_id, accion, proxima_accion_at)
                VALUES (?, ?, ?, ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iiss', $seguimientoId, $usuarioId, $accion, $fecha);
        $stmt->execute();
    }

    private function marcarAviso($seguimientoId, $usuarioId, $accion, $fecha, $tipo)
    {
        $campos = [
            '3H' => 'aviso_3h_at',
            '1H' => 'aviso_1h_at',
            '10M' => 'aviso_10m_at',
            'VENCIDA' => 'aviso_vencida_at'
        ];
        $campoObjetivo = $campos[$tipo] ?? '';

        if ($campoObjetivo === '') {
            return false;
        }

        $asignaciones = [];
        if (in_array($tipo, ['3H', '1H', '10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_3h_at = COALESCE(aviso_3h_at, NOW())';
        }
        if (in_array($tipo, ['1H', '10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_1h_at = COALESCE(aviso_1h_at, NOW())';
        }
        if (in_array($tipo, ['10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_10m_at = COALESCE(aviso_10m_at, NOW())';
        }
        if ($tipo === 'VENCIDA') {
            $asignaciones[] = 'aviso_vencida_at = COALESCE(aviso_vencida_at, NOW())';
        }

        $sql = "UPDATE recordatorios_vinculacion
                SET " . implode(', ', $asignaciones) . "
                WHERE seguimiento_id = ?
                  AND usuario_id = ?
                  AND accion = ?
                  AND proxima_accion_at = ?
                  AND $campoObjetivo IS NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iiss', $seguimientoId, $usuarioId, $accion, $fecha);
        $stmt->execute();

        return $stmt->affected_rows > 0;
    }
}
