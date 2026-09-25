<?php

require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Persistencia independiente para el perfil adulto/laboral.
 *
 * No reutiliza indicadores_educativos_oficiales: el perfil adulto describe
 * estructura demográfica/laboral y debe conservar su propia procedencia,
 * cobertura geográfica y metodología.
 */
class PerfilAdultoLaboralModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function tablaDisponible(): bool
    {
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE 'perfil_adulto_laboral_oficial'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    public function obtenerPorEstado(int $estadoId): array
    {
        if ($estadoId <= 0 || !$this->tablaDisponible()) {
            return $this->respuestaVacia();
        }

        $sql = "SELECT
                    estado_id,
                    municipio_id,
                    clave_geografica,
                    anio,
                    poblacion_25_34,
                    poblacion_35_44,
                    poblacion_45_54,
                    poblacion_25_54,
                    poblacion_economicamente_activa,
                    poblacion_ocupada,
                    fuente,
                    archivo_origen,
                    metodologia,
                    fecha_consulta,
                    tipo_actualizacion
                FROM perfil_adulto_laboral_oficial
                WHERE estado_id = ?
                    AND municipio_id IS NULL
                ORDER BY anio DESC, fecha_consulta DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return $fila ? $this->normalizarFila($fila) : $this->respuestaVacia();
    }

    public function obtenerPorMunicipio(int $estadoId, int $municipioId): array
    {
        if ($estadoId <= 0 || $municipioId <= 0 || !$this->tablaDisponible()) {
            return $this->respuestaVacia();
        }

        $sql = "SELECT
                    estado_id,
                    municipio_id,
                    clave_geografica,
                    anio,
                    poblacion_25_34,
                    poblacion_35_44,
                    poblacion_45_54,
                    poblacion_25_54,
                    poblacion_economicamente_activa,
                    poblacion_ocupada,
                    fuente,
                    archivo_origen,
                    metodologia,
                    fecha_consulta,
                    tipo_actualizacion
                FROM perfil_adulto_laboral_oficial
                WHERE estado_id = ?
                    AND municipio_id = ?
                ORDER BY anio DESC, fecha_consulta DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $estadoId, $municipioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return $fila ? $this->normalizarFila($fila) : $this->respuestaVacia();
    }

    private function normalizarFila(array $fila): array
    {
        return [
            'disponible' => true,
            'estado_id' => (int)($fila['estado_id'] ?? 0),
            'municipio_id' => isset($fila['municipio_id']) ? (int)$fila['municipio_id'] : null,
            'clave_geografica' => (string)($fila['clave_geografica'] ?? ''),
            'anio' => (int)($fila['anio'] ?? 0),
            'poblacion_25_34' => $this->enteroNullable($fila['poblacion_25_34'] ?? null),
            'poblacion_35_44' => $this->enteroNullable($fila['poblacion_35_44'] ?? null),
            'poblacion_45_54' => $this->enteroNullable($fila['poblacion_45_54'] ?? null),
            'poblacion_25_54' => $this->enteroNullable($fila['poblacion_25_54'] ?? null),
            'poblacion_economicamente_activa' =>
                $this->enteroNullable($fila['poblacion_economicamente_activa'] ?? null),
            'poblacion_ocupada' => $this->enteroNullable($fila['poblacion_ocupada'] ?? null),
            'fuente' => (string)($fila['fuente'] ?? ''),
            'archivo_origen' => (string)($fila['archivo_origen'] ?? ''),
            'metodologia' => (string)($fila['metodologia'] ?? ''),
            'fecha_consulta' => $fila['fecha_consulta'] ?? null,
            'tipo_actualizacion' => (string)($fila['tipo_actualizacion'] ?? '')
        ];
    }

    private function enteroNullable($valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int)$valor;
    }

    private function respuestaVacia(): array
    {
        return [
            'disponible' => false,
            'poblacion_25_34' => null,
            'poblacion_35_44' => null,
            'poblacion_45_54' => null,
            'poblacion_25_54' => null,
            'poblacion_economicamente_activa' => null,
            'poblacion_ocupada' => null
        ];
    }
}
