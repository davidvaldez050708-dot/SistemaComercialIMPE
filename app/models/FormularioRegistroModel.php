<?php

require_once __DIR__ . '/../../config/db_connection.php';

class FormularioRegistroModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstadosActivos()
    {
        $sql = "SELECT id, nombre
                FROM estados
                WHERE estado = 1
                ORDER BY nombre ASC";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerMunicipiosActivos($estadoId)
    {
        $estadoId = (int)$estadoId;

        if ($estadoId <= 0) {
            return [];
        }

        $sql = "SELECT id, nombre
                FROM municipios
                WHERE estado_id = ?
                  AND estado = 1
                ORDER BY nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function municipioPerteneceAEstado($municipioId, $estadoId)
    {
        $municipioId = (int)$municipioId;
        $estadoId = (int)$estadoId;

        if ($municipioId <= 0 || $estadoId <= 0) {
            return false;
        }

        $sql = "SELECT municipios.id
                FROM municipios
                INNER JOIN estados
                    ON estados.id = municipios.estado_id
                WHERE municipios.id = ?
                  AND municipios.estado_id = ?
                  AND municipios.estado = 1
                  AND estados.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $municipioId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function guardar($datos)
    {
        $origen = strtoupper(trim((string)($datos['origen'] ?? 'MARKETING')));
        $origen = in_array($origen, ['MARKETING', 'PUBLICO'], true)
            ? $origen
            : 'MARKETING';
        $creadoPor = array_key_exists('creado_por', $datos)
            ? $datos['creado_por']
            : null;

        $sql = "INSERT INTO formulario_registros (
                    nombre,
                    apellido,
                    fecha_nacimiento,
                    movil,
                    correo,
                    perfil_interes,
                    lugar_laboras,
                    cargo_puesto,
                    estado_id,
                    municipio_id,
                    creado_por,
                    origen,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible preparar el registro. Verifica que la migración del formulario esté aplicada.'
            );
        }

        $stmt->bind_param(
            'ssssssssiiis',
            $datos['nombre'],
            $datos['apellido'],
            $datos['fecha_nacimiento'],
            $datos['movil'],
            $datos['correo'],
            $datos['perfil_interes'],
            $datos['lugar_laboras'],
            $datos['cargo_puesto'],
            $datos['estado_id'],
            $datos['municipio_id'],
            $creadoPor,
            $origen
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'No fue posible guardar el registro.'
            );
        }

        return (int)$this->connection->insert_id;
    }

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];

        if (!$resultado) {
            return $filas;
        }

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
