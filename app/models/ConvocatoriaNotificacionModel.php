<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ConvocatoriaNotificacionModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerPorUsuario($usuarioId, $limite = 20)
    {
        $usuarioId = (int)$usuarioId;
        $limite = max(1, min(50, (int)$limite));

        if ($usuarioId <= 0) {
            return [];
        }

        $sql = "SELECT
                    id,
                    convocatoria_id,
                    titulo,
                    mensaje,
                    url,
                    tipo_evento,
                    leida,
                    leida_at,
                    created_at
                FROM notificaciones_convocatorias
                WHERE usuario_id = ?
                  AND NOT (
                      tipo_evento = 'vencimiento_2_dias'
                      AND leida = 1
                  )
                ORDER BY created_at DESC, id DESC
                LIMIT " . $limite;

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $items = [];

        while ($fila = $resultado->fetch_assoc()) {
            $fila['id'] = (int)$fila['id'];
            $fila['convocatoria_id'] = (int)($fila['convocatoria_id'] ?? 0);
            $fila['leida'] = (int)$fila['leida'];
            $items[] = $fila;
        }

        return $items;
    }

    public function contarNoLeidas($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM notificaciones_convocatorias
                WHERE usuario_id = ?
                  AND leida = 0";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return (int)($fila['total'] ?? 0);
    }

    public function marcarLeida($id, $usuarioId)
    {
        $id = (int)$id;
        $usuarioId = (int)$usuarioId;

        if ($id <= 0 || $usuarioId <= 0) {
            return false;
        }

        $sql = "UPDATE notificaciones_convocatorias
                SET leida = 1,
                    leida_at = COALESCE(leida_at, NOW())
                WHERE id = ?
                  AND usuario_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $id, $usuarioId);

        return $stmt->execute();
    }

    public function marcarTodasLeidas($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0) {
            return false;
        }

        $sql = "UPDATE notificaciones_convocatorias
                SET leida = 1,
                    leida_at = COALESCE(leida_at, NOW())
                WHERE usuario_id = ?
                  AND leida = 0";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);

        return $stmt->execute();
    }
}
