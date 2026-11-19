<?php

/** Lectura opcional de manuales para reportes; no crea tablas ni expedientes. */
class ReporteAliadosManualService
{
    private $connection;

    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function obtener(array $estadosAutorizados, int $estadoId, int $municipioId): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $estadosAutorizados), static fn($id) => $id > 0
        )));
        if (!$ids || ($estadoId > 0 && !in_array($estadoId, $ids, true))) {
            return [];
        }
        if (!$this->existe('aliados_manuales')) {
            return [];
        }
        $ids = $estadoId > 0 ? [$estadoId] : $ids;
        $sql = "SELECT a.*, 0 AS seguimiento_id, 'manual' AS origen_tipo,
                    a.id AS origen_id, e.nombre AS estado_nombre,
                    COALESCE(m.nombre, '') AS municipio_nombre,
                    NULL AS convenio_formalizado_at
                FROM aliados_manuales a
                INNER JOIN estados e ON e.id = a.estado_id
                LEFT JOIN municipios m ON m.id = a.municipio_id
                WHERE a.activo = 1 AND a.estado_id IN (" .
                    implode(',', array_fill(0, count($ids), '?')) . ')';
        if ($municipioId > 0) {
            $sql .= ' AND a.municipio_id = ?';
            $ids[] = $municipioId;
        }
        $stmt = $this->connection->prepare($sql . ' ORDER BY a.nombre_entidad');
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        // Conservar la fotografía del último envío manual, si esa extensión existe.
        if ($rows && $this->existe('aliados_manuales_difusiones')) {
            $manualIds = array_map(static fn($row) => (int)$row['origen_id'], $rows);
            $sql = 'SELECT d.* FROM aliados_manuales_difusiones d WHERE d.aliado_id IN (' .
                implode(',', array_fill(0, count($manualIds), '?')) . ') AND NOT EXISTS (
                    SELECT 1 FROM aliados_manuales_difusiones reciente
                    WHERE reciente.aliado_id = d.aliado_id AND
                        (reciente.realizado_at > d.realizado_at OR
                        (reciente.realizado_at = d.realizado_at AND reciente.id > d.id)))';
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(str_repeat('i', count($manualIds)), ...$manualIds);
            $stmt->execute();
            $latest = [];
            $result = $stmt->get_result();
            while ($event = $result->fetch_assoc()) {
                $latest[(int)$event['aliado_id']] = $event;
            }
            foreach ($rows as &$row) {
                $event = $latest[(int)$row['origen_id']] ?? null;
                if ($event) {
                    $row['ultimo_envio_id'] = (int)$event['id'];
                    $row['ultimo_envio_at'] = $event['realizado_at'];
                    $row['ultimo_envio_canal'] = $event['canal'];
                }
            }
            unset($row);
        }
        return $rows;
    }

    private function existe(string $table): bool
    {
        // Nombres constantes internos; no se interpolan entradas HTTP.
        $result = $this->connection->query("SHOW TABLES LIKE '" . $table . "'");
        return $result && $result->num_rows > 0;
    }
}
