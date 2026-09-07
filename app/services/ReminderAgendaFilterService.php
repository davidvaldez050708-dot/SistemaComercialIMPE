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
        $analistaId = (int)$analistaId;
        $idsConReunion = $this->obtenerSeguimientosConReunionActiva($analistaId);
        $idsSeguimientoAcuerdos = $this->obtenerSeguimientosConAcuerdosPendientes($analistaId);
        $salida = [];

        foreach ($items as $item) {
            $seguimientoId = (int)($item['id'] ?? $item['seguimiento_id'] ?? 0);

            if ($seguimientoId > 0 && isset($idsConReunion[$seguimientoId])) {
                continue;
            }

            if ($seguimientoId > 0 && isset($idsSeguimientoAcuerdos[$seguimientoId])) {
                $item['accion'] = 'Dar seguimiento a acuerdos';
                $item['icono'] = 'bi-clipboard-check';
            }

            $salida[] = $item;
        }

        return array_values($salida);
    }

    public function filtrarAvisosSeguimiento($items, $analistaId)
    {
        $items = is_array($items) ? $items : [];
        $analistaId = (int)$analistaId;
        $idsConReunion = $this->obtenerSeguimientosConReunionActiva($analistaId);
        $idsSeguimientoAcuerdos = $this->obtenerSeguimientosConAcuerdosPendientes($analistaId);
        $salida = [];

        foreach ($items as $item) {
            $seguimientoId = (int)($item['seguimiento_id'] ?? $item['id'] ?? 0);

            if ($seguimientoId > 0 && isset($idsConReunion[$seguimientoId])) {
                continue;
            }

            if ($seguimientoId > 0 && isset($idsSeguimientoAcuerdos[$seguimientoId])) {
                $institucion = trim((string)($item['institucion'] ?? ''));
                $item['accion'] = 'Dar seguimiento a acuerdos';
                $item['mensaje'] = 'Dar seguimiento a acuerdos' .
                    ($institucion !== '' ? ' · ' . $institucion : '');
                $item['icono'] = 'bi-clipboard-check';
            }

            $salida[] = $item;
        }

        return array_values($salida);
    }

    private function obtenerSeguimientosConReunionActiva($analistaId)
    {
        if ($analistaId <= 0 || !$this->tablaDisponible('reuniones_vinculacion')) {
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

    private function obtenerSeguimientosConAcuerdosPendientes($analistaId)
    {
        if (
            $analistaId <= 0 ||
            !$this->tablaDisponible('seguimientos_vinculacion_post_envio')
        ) {
            return [];
        }

        $sql = "SELECT seguimientos.id
                FROM seguimientos_vinculacion seguimientos
                JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = seguimientos.id
                WHERE seguimientos.analista_id = ?
                  AND seguimientos.activo = 1
                  AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                  AND seguimientos.proxima_accion_at IS NOT NULL
                  AND post.reunion_realizada_at IS NOT NULL
                  AND post.reunion_resultado = 'REQUIERE_SEGUIMIENTO'
                  AND post.convenio_formalizado_at IS NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $analistaId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $ids = [];

        while ($fila = $resultado->fetch_assoc()) {
            $id = (int)($fila['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
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
