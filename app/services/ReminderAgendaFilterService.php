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
        $items = is_array($items) ? $items : [];
        $idsConReunion = $this->obtenerSeguimientosConReunionActiva((int)$analistaId);

        if (empty($idsConReunion)) {
            return array_values($items);
        }

        return array_values(array_filter($items, static function ($item) use ($idsConReunion) {
            $seguimientoId = (int)($item['id'] ?? $item['seguimiento_id'] ?? 0);
            return $seguimientoId <= 0 || !isset($idsConReunion[$seguimientoId]);
        }));
    }

    public function filtrarAvisosSeguimiento($items, $analistaId)
    {
        $items = is_array($items) ? $items : [];
        $idsConReunion = $this->obtenerSeguimientosConReunionActiva((int)$analistaId);

        if (empty($idsConReunion)) {
            return array_values($items);
        }

        return array_values(array_filter($items, static function ($item) use ($idsConReunion) {
            $seguimientoId = (int)($item['seguimiento_id'] ?? $item['id'] ?? 0);
            return $seguimientoId <= 0 || !isset($idsConReunion[$seguimientoId]);
        }));
    }

    private function obtenerSeguimientosConReunionActiva($analistaId)
    {
        if ($analistaId <= 0 || !$this->tablaDisponible()) {
            return [];
        }

        $sql = "SELECT DISTINCT seguimiento_id
                FROM reuniones_vinculacion
                WHERE analista_id = ?
                  AND estado NOT IN ('CANCELADA', 'REALIZADA')";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $analistaId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $ids = [];

        while ($fila = $resultado->fetch_assoc()) {
            $id = (int)($fila['seguimiento_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function tablaDisponible()
    {
        try {
            $resultado = $this->connection->query("SHOW TABLES LIKE 'reuniones_vinculacion'");
            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }
}
