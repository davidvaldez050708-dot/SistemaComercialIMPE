<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoVinculacionReporteActividadModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerConteosDiarios(array $seguimientoIds, $desde, $hastaExclusivo, $canal = '')
    {
        $ids = [];

        foreach ($seguimientoIds as $seguimientoId) {
            $seguimientoId = (int)$seguimientoId;

            if ($seguimientoId > 0) {
                $ids[$seguimientoId] = $seguimientoId;
            }
        }

        $ids = array_values($ids);
        $desde = trim((string)$desde);
        $hastaExclusivo = trim((string)$hastaExclusivo);
        $canal = strtoupper(trim((string)$canal));

        if (empty($ids) || $desde === '' || $hastaExclusivo === '') {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    DATE(interacciones.fecha_inicio) AS fecha,
                    COUNT(*) AS total
                FROM interacciones_vinculacion interacciones
                WHERE interacciones.seguimiento_id IN ($marcadores)
                    AND interacciones.fecha_inicio >= ?
                    AND interacciones.fecha_inicio < ?";
        $parametros = $ids;
        $tipos = str_repeat('i', count($ids));
        $parametros[] = $desde;
        $parametros[] = $hastaExclusivo;
        $tipos .= 'ss';

        if ($canal !== '') {
            $sql .= " AND interacciones.canal = ?";
            $parametros[] = $canal;
            $tipos .= 's';
        }

        $sql .= " GROUP BY DATE(interacciones.fecha_inicio)
                  ORDER BY fecha ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $conteos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $fecha = (string)($fila['fecha'] ?? '');

            if ($fecha !== '') {
                $conteos[$fecha] = (int)($fila['total'] ?? 0);
            }
        }

        return $conteos;
    }

    private function vincularParametros($stmt, $tipos, array $parametros)
    {
        if ($tipos === '' || empty($parametros)) {
            return;
        }

        $argumentos = [$tipos];

        foreach ($parametros as $indice => $valor) {
            $parametros[$indice] = $valor;
            $argumentos[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $argumentos);
    }
}
