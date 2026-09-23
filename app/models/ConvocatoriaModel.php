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

    public function obtenerEstados()
    {
        $sql = "SELECT id, nombre
                FROM estados
                WHERE estado = 1
                ORDER BY nombre";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerListado()
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
                GROUP BY convocatorias.id
                ORDER BY convocatorias.created_at DESC, convocatorias.id DESC";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
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

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
