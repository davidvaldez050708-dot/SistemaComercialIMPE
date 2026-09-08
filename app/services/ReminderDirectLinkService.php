<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderDirectLinkService
{
    private $connection;
    private $cacheEstados = [];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function aplicar($items, $analistaId)
    {
        $items = is_array($items) ? $items : [];
        $analistaId = (int)$analistaId;

        if ($analistaId <= 0 || empty($items)) {
            return array_values($items);
        }

        foreach ($items as &$item) {
            $seguimientoId = (int)($item['seguimiento_id'] ?? $item['id'] ?? 0);

            if ($seguimientoId <= 0) {
                continue;
            }

            $estadoId = $this->resolverEstadoSeguimiento($seguimientoId, $analistaId);
            if ($estadoId <= 0) {
                continue;
            }

            $item['url'] = 'index.php?controller=seguimientoVinculacion&action=estado' .
                '&estado_id=' . $estadoId .
                '&trabajar_id=' . $seguimientoId;
        }
        unset($item);

        return array_values($items);
    }

    private function resolverEstadoSeguimiento($seguimientoId, $analistaId)
    {
        $clave = $analistaId . ':' . $seguimientoId;
        if (array_key_exists($clave, $this->cacheEstados)) {
            return (int)$this->cacheEstados[$clave];
        }

        try {
            $sql = "SELECT estado_id
                    FROM seguimientos_vinculacion
                    WHERE id = ?
                      AND analista_id = ?
                      AND activo = 1
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $seguimientoId, $analistaId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            $estadoId = (int)($fila['estado_id'] ?? 0);
            $this->cacheEstados[$clave] = $estadoId;

            return $estadoId;
        } catch (Throwable $error) {
            error_log('No fue posible resolver el destino de una notificación: ' . $error->getMessage());
            $this->cacheEstados[$clave] = 0;
            return 0;
        }
    }
}
