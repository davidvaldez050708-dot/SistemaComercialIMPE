<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoVinculacionModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstadosAsignadosAnalista($usuarioId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    MAX(asignaciones_territorio.es_principal) AS es_principal,
                    COUNT(DISTINCT seguimientos_vinculacion.id) AS total_seguimientos
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                LEFT JOIN seguimientos_vinculacion
                    ON seguimientos_vinculacion.estado_id = estados.id
                    AND seguimientos_vinculacion.analista_id = ?
                    AND seguimientos_vinculacion.activo = 1
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1
                    AND (
                        asignaciones_territorio.fecha_inicio IS NULL
                        OR asignaciones_territorio.fecha_inicio <= CURDATE()
                    )
                    AND (
                        asignaciones_territorio.fecha_fin IS NULL
                        OR asignaciones_territorio.fecha_fin >= CURDATE()
                    )
                GROUP BY
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto
                ORDER BY
                    es_principal DESC,
                    estados.nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $usuarioId, $usuarioId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerEstadoAsignadoAnalista($usuarioId, $estadoId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    asignaciones_territorio.es_principal
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.estado_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1
                    AND (
                        asignaciones_territorio.fecha_inicio IS NULL
                        OR asignaciones_territorio.fecha_inicio <= CURDATE()
                    )
                    AND (
                        asignaciones_territorio.fecha_fin IS NULL
                        OR asignaciones_territorio.fecha_fin >= CURDATE()
                    )
                ORDER BY asignaciones_territorio.es_principal DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function puedeAccederEstadoAnalista($usuarioId, $estadoId)
    {
        return $this->obtenerEstadoAsignadoAnalista($usuarioId, $estadoId) !== null;
    }

    public function obtenerResumenSeguimientosAnalistaEstado($usuarioId, $estadoId)
    {
        $sql = "SELECT
                    COUNT(*) AS en_seguimiento,
                    COALESCE(SUM(estado_seguimiento = 'CONTACTANDO'), 0) AS contactando,
                    COALESCE(SUM(estado_seguimiento = 'DATOS_VERIFICADOS'), 0) AS datos_verificados,
                    COALESCE(SUM(estado_seguimiento = 'ESPERANDO_RESPUESTA'), 0) AS esperando_respuesta
                FROM seguimientos_vinculacion
                WHERE analista_id = ?
                    AND estado_id = ?
                    AND activo = 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        $resumen = $stmt->get_result()->fetch_assoc() ?: [];

        return [
            'en_seguimiento' => (int)($resumen['en_seguimiento'] ?? 0),
            'contactando' => (int)($resumen['contactando'] ?? 0),
            'datos_verificados' => (int)($resumen['datos_verificados'] ?? 0),
            'esperando_respuesta' => (int)($resumen['esperando_respuesta'] ?? 0)
        ];
    }

    public function obtenerSeguimientosAnalistaEstado($usuarioId, $estadoId)
    {
        $sql = "SELECT
                    seguimientos_vinculacion.id,
                    seguimientos_vinculacion.nombre_entidad,
                    seguimientos_vinculacion.tipo_entidad,
                    seguimientos_vinculacion.estado_seguimiento,
                    seguimientos_vinculacion.ultima_interaccion_at,
                    seguimientos_vinculacion.fecha_inicio,
                    municipios.nombre AS municipio,
                    oficio_reciente.folio
                FROM seguimientos_vinculacion
                LEFT JOIN municipios
                    ON municipios.id = seguimientos_vinculacion.municipio_id
                LEFT JOIN (
                    SELECT
                        seguimiento_id,
                        MAX(folio) AS folio
                    FROM oficios_vinculacion
                    WHERE folio IS NOT NULL
                        AND folio <> ''
                    GROUP BY seguimiento_id
                ) oficio_reciente
                    ON oficio_reciente.seguimiento_id = seguimientos_vinculacion.id
                WHERE seguimientos_vinculacion.analista_id = ?
                    AND seguimientos_vinculacion.estado_id = ?
                    AND seguimientos_vinculacion.activo = 1
                ORDER BY
                    CASE
                        WHEN seguimientos_vinculacion.proxima_accion_at IS NOT NULL
                            AND seguimientos_vinculacion.proxima_accion_at <= NOW()
                        THEN 0
                        ELSE 1
                    END ASC,
                    seguimientos_vinculacion.proxima_accion_at ASC,
                    seguimientos_vinculacion.ultima_interaccion_at DESC,
                    seguimientos_vinculacion.fecha_inicio DESC";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
