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

    public function aliadoExclusivo($token, string $tipo = "inscripcion")
    {
        require_once __DIR__ . '/../services/FormularioAliadoService.php';
        return (new FormularioAliadoService($this->connection))->resolver($token, false, $tipo);
    }

    public function guardar($datos)
    {
        if (array_key_exists('aliado_token', $datos)) {
            require_once __DIR__ . '/../services/FormularioAliadoService.php';
            $token = $datos['aliado_token'];
            unset($datos['aliado_token']);
            $tipo = ($datos['origen'] ?? '') === 'PUBLICO_INSCRIPCION' ? 'inscripcion' : 'registro';
            return (new FormularioAliadoService($this->connection))->guardar(
                $token, $datos, $tipo, function () use ($datos) { return $this->guardar($datos); }
            );
        }
        $origenSolicitado = strtoupper(
            trim((string)($datos['origen'] ?? 'MARKETING'))
        );

        $esInscripcion = in_array(
            $origenSolicitado,
            ['MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION'],
            true
        );

        $tabla = $esInscripcion
            ? 'formulario_inscripciones'
            : 'formulario_registros';

        /*
         * Al separar físicamente ambos formularios, el campo origen
         * únicamente indica desde dónde se capturó la información.
         */
        if ($esInscripcion) {
            $origen = $origenSolicitado === 'PUBLICO_INSCRIPCION'
                ? 'PUBLICO'
                : 'MARKETING';
        } else {
            $origen = $origenSolicitado === 'PUBLICO'
                ? 'PUBLICO'
                : 'MARKETING';
        }

        $creadoPor = array_key_exists('creado_por', $datos)
            ? $datos['creado_por']
            : null;

        $movilSecundario = trim(
            (string)($datos['movil_secundario'] ?? '')
        );

        $telRecomendado = trim(
            (string)($datos['tel_recomendado'] ?? '')
        );

        $sql = "INSERT INTO {$tabla} (
                    nombre,
                    apellido,
                    fecha_nacimiento,
                    movil,
                    movil_secundario,
                    tel_recomendado,
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
                ) VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                $esInscripcion
                    ? 'No fue posible preparar la inscripción. Verifica que la migración de separación de formularios esté aplicada.'
                    : 'No fue posible preparar el registro. Verifica que la migración del formulario esté aplicada.'
            );
        }

        $stmt->bind_param(
            'ssssssssssiiis',
            $datos['nombre'],
            $datos['apellido'],
            $datos['fecha_nacimiento'],
            $datos['movil'],
            $movilSecundario,
            $telRecomendado,
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
                $esInscripcion
                    ? 'No fue posible guardar la inscripción.'
                    : 'No fue posible guardar el registro.'
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
