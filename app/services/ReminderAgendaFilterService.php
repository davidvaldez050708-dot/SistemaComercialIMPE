<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderAgendaFilterService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function filtrarRecordatoriosSeguimiento($items, $analistaId)
    {
        return $this->filtrarItems($items, (int)$analistaId, false);
    }

    public function filtrarAvisosSeguimiento($items, $analistaId)
    {
        return $this->filtrarItems($items, (int)$analistaId, true);
    }

    private function filtrarItems($items, $analistaId, $esAviso)
    {
        $items = is_array($items) ? $items : [];
        $idsBloqueados = $this->obtenerSeguimientosAdministradosPorAgenda($analistaId);

        if (empty($idsBloqueados)) {
            return array_values($items);
        }

        return array_values(array_filter(
            $items,
            static function ($item) use ($idsBloqueados, $esAviso) {
                $seguimientoId = $esAviso
                    ? (int)($item['seguimiento_id'] ?? $item['id'] ?? 0)
                    : (int)($item['id'] ?? $item['seguimiento_id'] ?? 0);

                return $seguimientoId <= 0 || !isset($idsBloqueados[$seguimientoId]);
            }
        ));
    }

    private function obtenerSeguimientosAdministradosPorAgenda($analistaId)
    {
        if ($analistaId <= 0) {
            return [];
        }

        $ids = [];

        if ($this->tablaDisponible('reuniones_vinculacion')) {
            $sql = "SELECT DISTINCT seguimiento_id
                    FROM reuniones_vinculacion
                    WHERE analista_id = ?
                      AND estado <> 'CANCELADA'";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $analistaId);
            $stmt->execute();
            $resultado = $stmt->get_result();

            while ($fila = $resultado->fetch_assoc()) {
                $id = (int)($fila['seguimiento_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        if ($this->tablaDisponible('seguimientos_vinculacion_post_envio')) {
            $sql = "SELECT seguimientos.id
                    FROM seguimientos_vinculacion seguimientos
                    JOIN seguimientos_vinculacion_post_envio post
                        ON post.seguimiento_id = seguimientos.id
                    WHERE seguimientos.analista_id = ?
                      AND seguimientos.activo = 1
                      AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                      AND post.reunion_realizada_at IS NOT NULL";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $analistaId);
            $stmt->execute();
            $resultado = $stmt->get_result();

            while ($fila = $resultado->fetch_assoc()) {
                $id = (int)($fila['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    private function tablaDisponible($tabla)
    {
        $tabla = trim((string)$tabla);
        if ($tabla === '') {
            return false;
        }

        try {
            $tablaEscapada = $this->connection->real_escape_string($tabla);
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE '" . $tablaEscapada . "'"
            );
            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }
}
