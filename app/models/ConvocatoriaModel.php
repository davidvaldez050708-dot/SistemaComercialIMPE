<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ConvocatoriaModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function desactivarConvocatoriasVencidas()
    {
        $sql = "UPDATE convocatorias
                SET estado = 0,
                    updated_at = NOW()
                WHERE estado = 1
                  AND fecha_termino < CURDATE()";

        return $this->connection->query($sql);
    }

    public function obtenerEstados()
    {
        $sql = "SELECT id, nombre
                FROM estados
                WHERE estado = 1
                ORDER BY nombre";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerListado($buscar = '', $estadoId = 0, $estatus = '')
    {
        $sql = "SELECT
                    convocatorias.id,
                    convocatorias.titulo,
                    convocatorias.imagen,
                    convocatorias.fecha_inicio,
                    convocatorias.fecha_termino,
                    convocatorias.estado,
                    convocatorias.created_at,
                    convocatorias.updated_at,
                    GROUP_CONCAT(
                        DISTINCT estados.nombre
                        ORDER BY estados.nombre
                        SEPARATOR ', '
                    ) AS estados
                FROM convocatorias
                LEFT JOIN convocatoria_estados
                    ON convocatoria_estados.convocatoria_id = convocatorias.id
                LEFT JOIN estados
                    ON estados.id = convocatoria_estados.estado_id
                WHERE 1 = 1";

        $tipos = '';
        $parametros = [];

        if ($buscar !== '') {
            $sql .= " AND convocatorias.titulo LIKE ?";
            $tipos .= 's';
            $parametros[] = '%' . $buscar . '%';
        }

        if ($estadoId > 0) {
            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM convocatoria_estados filtro_estado
                        WHERE filtro_estado.convocatoria_id = convocatorias.id
                          AND filtro_estado.estado_id = ?
                    )";
            $tipos .= 'i';
            $parametros[] = $estadoId;
        }

        if ($estatus === '0' || $estatus === '1') {
            $sql .= " AND convocatorias.estado = ?";
            $tipos .= 'i';
            $parametros[] = (int)$estatus;
        }

        $sql .= " GROUP BY convocatorias.id
                  ORDER BY convocatorias.created_at DESC, convocatorias.id DESC";

        if ($tipos === '') {
            $resultado = $this->connection->query($sql);
            return $this->convertirResultadoEnArreglo($resultado);
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function buscarPorId($id)
    {
        $sql = "SELECT
                    convocatorias.*,
                    creador.nombre AS creador_nombre,
                    creador.apellidos AS creador_apellidos,
                    editor.nombre AS editor_nombre,
                    editor.apellidos AS editor_apellidos
                FROM convocatorias
                LEFT JOIN usuarios creador
                    ON creador.id = convocatorias.creado_por
                LEFT JOIN usuarios editor
                    ON editor.id = convocatorias.actualizado_por
                WHERE convocatorias.id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $convocatoria = $stmt->get_result()->fetch_assoc();

        if (!$convocatoria) {
            return null;
        }

        $convocatoria['estados_ids'] = $this->obtenerEstadosIds($id);
        $convocatoria['estados'] = $this->obtenerNombresEstados($id);

        return $convocatoria;
    }

    public function crear($datos, $estadosIds)
    {
        $this->connection->begin_transaction();

        try {
            $sql = "INSERT INTO convocatorias (
                        titulo,
                        imagen,
                        fecha_inicio,
                        fecha_termino,
                        estado,
                        creado_por,
                        actualizado_por
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'ssssiii',
                $datos['titulo'],
                $datos['imagen'],
                $datos['fecha_inicio'],
                $datos['fecha_termino'],
                $datos['estado'],
                $datos['usuario_id'],
                $datos['usuario_id']
            );

            if (!$stmt->execute()) {
                throw new Exception('No fue posible registrar la convocatoria.');
            }

            $convocatoriaId = (int)$this->connection->insert_id;
            $this->guardarEstados($convocatoriaId, $estadosIds);

            $this->connection->commit();

            return $convocatoriaId;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function actualizar($id, $datos, $estadosIds)
    {
        $this->connection->begin_transaction();

        try {
            $sql = "UPDATE convocatorias
                    SET titulo = ?,
                        imagen = ?,
                        fecha_inicio = ?,
                        fecha_termino = ?,
                        estado = ?,
                        actualizado_por = ?
                    WHERE id = ?";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'ssssiii',
                $datos['titulo'],
                $datos['imagen'],
                $datos['fecha_inicio'],
                $datos['fecha_termino'],
                $datos['estado'],
                $datos['usuario_id'],
                $id
            );

            if (!$stmt->execute()) {
                throw new Exception('No fue posible actualizar la convocatoria.');
            }

            $sqlEliminar = "DELETE FROM convocatoria_estados
                            WHERE convocatoria_id = ?";
            $stmtEliminar = $this->connection->prepare($sqlEliminar);
            $stmtEliminar->bind_param('i', $id);
            $stmtEliminar->execute();

            $this->guardarEstados($id, $estadosIds);

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function cambiarEstado($id, $estado, $usuarioId)
    {
        $sql = "UPDATE convocatorias
                SET estado = ?,
                    actualizado_por = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iii', $estado, $usuarioId, $id);

        return $stmt->execute();
    }

    public function obtenerResumenDashboard()
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) AS activas,
                    SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) AS inactivas,
                    SUM(
                        CASE
                            WHEN estado = 1
                                AND CURDATE() BETWEEN fecha_inicio AND fecha_termino
                            THEN 1 ELSE 0
                        END
                    ) AS vigentes,
                    SUM(
                        CASE
                            WHEN estado = 1
                                AND fecha_termino BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                            THEN 1 ELSE 0
                        END
                    ) AS proximas_finalizar
                FROM convocatorias";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return [
            'total' => (int)($fila['total'] ?? 0),
            'activas' => (int)($fila['activas'] ?? 0),
            'inactivas' => (int)($fila['inactivas'] ?? 0),
            'vigentes' => (int)($fila['vigentes'] ?? 0),
            'proximas_finalizar' => (int)($fila['proximas_finalizar'] ?? 0)
        ];
    }

    public function obtenerCoberturaTerritorialDashboard($limite = 4)
    {
        $sqlTotalEstados = "SELECT COUNT(*) AS total_estados
                            FROM estados
                            WHERE estado = 1";

        $resultadoTotalEstados = $this->connection->query($sqlTotalEstados);
        $filaTotalEstados = $resultadoTotalEstados->fetch_assoc();
        $totalEstados = (int)($filaTotalEstados['total_estados'] ?? 0);

        $sqlCobertura = "SELECT
                            COUNT(DISTINCT asociaciones.estado_id) AS estados_cubiertos
                         FROM (
                            SELECT DISTINCT
                                convocatoria_estados.convocatoria_id,
                                convocatoria_estados.estado_id
                            FROM convocatoria_estados
                            INNER JOIN convocatorias
                                ON convocatorias.id = convocatoria_estados.convocatoria_id
                            WHERE convocatorias.estado = 1
                         ) asociaciones";

        $resultadoCobertura = $this->connection->query($sqlCobertura);
        $filaCobertura = $resultadoCobertura->fetch_assoc();
        $estadosCubiertos = (int)($filaCobertura['estados_cubiertos'] ?? 0);

        $limite = max(1, min(5, (int)$limite));

        $sqlTop = "SELECT
                        estados.id,
                        estados.nombre,
                        COUNT(*) AS convocatorias_activas
                   FROM (
                        SELECT DISTINCT
                            convocatoria_estados.convocatoria_id,
                            convocatoria_estados.estado_id
                        FROM convocatoria_estados
                        INNER JOIN convocatorias
                            ON convocatorias.id = convocatoria_estados.convocatoria_id
                        WHERE convocatorias.estado = 1
                   ) asociaciones
                   INNER JOIN estados
                       ON estados.id = asociaciones.estado_id
                   WHERE estados.estado = 1
                   GROUP BY estados.id, estados.nombre
                   ORDER BY convocatorias_activas DESC, estados.nombre ASC
                   LIMIT " . $limite;

        $resultadoTop = $this->connection->query($sqlTop);
        $territorios = $this->convertirResultadoEnArreglo($resultadoTop);

        return [
            'total_estados' => $totalEstados,
            'estados_cubiertos' => $estadosCubiertos,
            'territorios' => array_map(
                static function ($fila) {
                    return [
                        'id' => (int)($fila['id'] ?? 0),
                        'nombre' => (string)($fila['nombre'] ?? ''),
                        'convocatorias_activas' => (int)($fila['convocatorias_activas'] ?? 0)
                    ];
                },
                $territorios
            )
        ];
    }

    public function obtenerEstadosSinConvocatoriaActivaDashboard()
    {
        $sql = "SELECT
                    estados.id,
                    estados.nombre
                FROM estados
                WHERE estados.estado = 1
                  AND NOT EXISTS (
                      SELECT 1
                      FROM convocatoria_estados
                      INNER JOIN convocatorias
                          ON convocatorias.id = convocatoria_estados.convocatoria_id
                      WHERE convocatoria_estados.estado_id = estados.id
                        AND convocatorias.estado = 1
                  )
                ORDER BY estados.nombre ASC";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerPublicacionesPorPeriodoDashboard($dias = 30)
    {
        $diasPermitidos = [7, 30, 90];
        $dias = in_array((int)$dias, $diasPermitidos, true) ? (int)$dias : 30;

        $sql = "SELECT
                    COUNT(*) AS publicaciones
                FROM convocatorias
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . $dias . " DAY)";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return (int)($fila['publicaciones'] ?? 0);
    }

    private function obtenerEstadosIds($convocatoriaId)
    {
        $sql = "SELECT estado_id
                FROM convocatoria_estados
                WHERE convocatoria_id = ?
                ORDER BY estado_id";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $convocatoriaId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $ids = [];

        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['estado_id'];
        }

        return $ids;
    }

    private function obtenerNombresEstados($convocatoriaId)
    {
        $sql = "SELECT estados.nombre
                FROM convocatoria_estados
                INNER JOIN estados
                    ON estados.id = convocatoria_estados.estado_id
                WHERE convocatoria_estados.convocatoria_id = ?
                ORDER BY estados.nombre";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $convocatoriaId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $nombres = [];

        while ($fila = $resultado->fetch_assoc()) {
            $nombres[] = $fila['nombre'];
        }

        return $nombres;
    }

    private function guardarEstados($convocatoriaId, $estadosIds)
    {
        $sql = "INSERT INTO convocatoria_estados (
                    convocatoria_id,
                    estado_id
                ) VALUES (?, ?)";

        $stmt = $this->connection->prepare($sql);

        foreach ($estadosIds as $estadoId) {
            $estadoId = (int)$estadoId;
            $stmt->bind_param('ii', $convocatoriaId, $estadoId);

            if (!$stmt->execute()) {
                throw new Exception('No fue posible asociar los estados.');
            }
        }
    }

    private function vincularParametros($stmt, $tipos, $parametros)
    {
        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
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
