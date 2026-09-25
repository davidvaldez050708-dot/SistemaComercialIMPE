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

    public function guardarPerfil(
        int $estadoId,
        ?int $municipioId,
        array $datos,
        int $usuarioId
    ): bool {
        if ($estadoId <= 0 || $usuarioId <= 0 || !$this->tablaDisponible()) {
            return false;
        }

        $clave = trim((string)($datos['clave_geografica'] ?? ''));
        $anio = (int)($datos['anio'] ?? 0);
        $p25a34 = $datos['poblacion_25_34'] ?? null;
        $p35a44 = $datos['poblacion_35_44'] ?? null;
        $p45a54 = $datos['poblacion_45_54'] ?? null;
        $p25a54 = $datos['poblacion_25_54'] ?? null;
        $pea = $datos['poblacion_economicamente_activa'] ?? null;
        $ocupada = $datos['poblacion_ocupada'] ?? null;
        $fuente = trim((string)($datos['fuente'] ?? ''));
        $archivo = trim((string)($datos['archivo_origen'] ?? ''));
        $metodologia = trim((string)($datos['metodologia'] ?? ''));
        $tipo = trim((string)($datos['tipo_actualizacion'] ?? 'AUTOMATICA'));

        foreach ([$p25a34, $p35a44, $p45a54, $p25a54] as $valor) {
            if (!is_int($valor) || $valor < 0) {
                return false;
            }
        }

        if (
            $clave === '' ||
            $anio < 2000 ||
            $anio > 2100 ||
            $p25a54 !== ($p25a34 + $p35a44 + $p45a54) ||
            ($pea !== null && (!is_int($pea) || $pea < 0)) ||
            ($ocupada !== null && (!is_int($ocupada) || $ocupada < 0)) ||
            $fuente === ''
        ) {
            return false;
        }

        // MySQL permite múltiples NULL dentro de una llave UNIQUE. Por eso el
        // registro estatal (municipio_id IS NULL) se actualiza explícitamente,
        // evitando duplicados cada vez que se sincroniza la misma entidad/año.
        if ($municipioId === null) {
            $sqlActualizar = "UPDATE perfil_adulto_laboral_oficial
                              SET poblacion_25_34 = ?,
                                  poblacion_35_44 = ?,
                                  poblacion_45_54 = ?,
                                  poblacion_25_54 = ?,
                                  poblacion_economicamente_activa = ?,
                                  poblacion_ocupada = ?,
                                  fuente = ?,
                                  archivo_origen = ?,
                                  metodologia = ?,
                                  fecha_consulta = NOW(),
                                  tipo_actualizacion = ?,
                                  usuario_importo_id = ?,
                                  updated_at = NOW()
                              WHERE estado_id = ?
                                  AND municipio_id IS NULL
                                  AND clave_geografica = ?
                                  AND anio = ?";

            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            if (!$stmtActualizar) {
                return false;
            }

            $stmtActualizar->bind_param(
                'iiiiiissssiisi',
                $p25a34,
                $p35a44,
                $p45a54,
                $p25a54,
                $pea,
                $ocupada,
                $fuente,
                $archivo,
                $metodologia,
                $tipo,
                $usuarioId,
                $estadoId,
                $clave,
                $anio
            );

            if (!$stmtActualizar->execute()) {
                return false;
            }

            if ($stmtActualizar->affected_rows > 0) {
                return true;
            }
        }

        $sql = "INSERT INTO perfil_adulto_laboral_oficial (
                    estado_id, municipio_id, clave_geografica, anio,
                    poblacion_25_34, poblacion_35_44, poblacion_45_54, poblacion_25_54,
                    poblacion_economicamente_activa, poblacion_ocupada,
                    fuente, archivo_origen, metodologia, fecha_consulta,
                    tipo_actualizacion, usuario_importo_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    poblacion_25_34 = VALUES(poblacion_25_34),
                    poblacion_35_44 = VALUES(poblacion_35_44),
                    poblacion_45_54 = VALUES(poblacion_45_54),
                    poblacion_25_54 = VALUES(poblacion_25_54),
                    poblacion_economicamente_activa = VALUES(poblacion_economicamente_activa),
                    poblacion_ocupada = VALUES(poblacion_ocupada),
                    fuente = VALUES(fuente),
                    archivo_origen = VALUES(archivo_origen),
                    metodologia = VALUES(metodologia),
                    fecha_consulta = NOW(),
                    tipo_actualizacion = VALUES(tipo_actualizacion),
                    usuario_importo_id = VALUES(usuario_importo_id),
                    updated_at = NOW()";

        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'iisiiiiiiissssi',
            $estadoId,
            $municipioId,
            $clave,
            $anio,
            $p25a34,
            $p35a44,
            $p45a54,
            $p25a54,
            $pea,
            $ocupada,
            $fuente,
            $archivo,
            $metodologia,
            $tipo,
            $usuarioId
        );

        return $stmt->execute();
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
