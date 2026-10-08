<?php

require_once __DIR__ . '/../../config/db_connection.php';

class EscolaridadAdultaModel
{
    public const SIN_SUPERIOR_25 = 'SIN_EDUCACION_SUPERIOR_25_MAS';
    public const SIN_MEDIA_CONCLUIDA_18 = 'SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS';
    public const SIN_MEDIA_CONCLUIDA_15_17 = 'SIN_MEDIA_SUPERIOR_CONCLUIDA_15_17';

    private mysqli $connection;

    public function __construct()
    {
        $this->connection = (new Database())->connect();
    }

    public function tablaDisponible(): bool
    {
        try {
            return $this->connection->query("SHOW TABLES LIKE 'indicadores_escolaridad_adulta'")->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function obtenerPorEstado(int $estadoId): array
    {
        $salida = [
            self::SIN_SUPERIOR_25 => ['disponible' => false, 'grupo_edad' => '25 años y más'],
            self::SIN_MEDIA_CONCLUIDA_18 => ['disponible' => false, 'grupo_edad' => '18 años y más'],
            self::SIN_MEDIA_CONCLUIDA_15_17 => ['disponible' => false, 'grupo_edad' => '15 a 17 años']
        ];

        if ($estadoId <= 0 || !$this->tablaDisponible()) {
            return $salida;
        }

        try {
            $sql = 'SELECT codigo_indicador, anio, poblacion_base, cantidad_personas,
                           fuente, referencia_url, metodologia, fecha_consulta
                    FROM indicadores_escolaridad_adulta WHERE estado_id = ?
                    ORDER BY anio DESC, fecha_consulta DESC';
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $estadoId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($fila = $res->fetch_assoc()) {
                $codigo = (string)$fila['codigo_indicador'];
                if (!isset($salida[$codigo]) || ($salida[$codigo]['disponible'] ?? false)) {
                    continue;
                }
                $base = (int)$fila['poblacion_base'];
                $cantidad = (int)$fila['cantidad_personas'];
                if ($base <= 0 || $cantidad < 0 || $cantidad > $base) {
                    continue;
                }
                $salida[$codigo] = array_merge($salida[$codigo], [
                    'disponible' => true,
                    'anio' => (int)$fila['anio'],
                    'poblacion_base' => $base,
                    'cantidad_personas' => $cantidad,
                    'porcentaje' => round(($cantidad / $base) * 100, 2),
                    'fuente' => (string)$fila['fuente'],
                    'referencia_url' => (string)$fila['referencia_url'],
                    'metodologia' => (string)$fila['metodologia'],
                    'fecha_consulta' => (string)$fila['fecha_consulta']
                ]);
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Escolaridad adulta: ' . $e->getMessage());
        }
        return $salida;
    }

    public function importarLote(array $filas, string $archivo): void
    {
        if (!$this->tablaDisponible()) {
            throw new RuntimeException('Falta aplicar la migración de escolaridad adulta.');
        }
        $sql = 'INSERT INTO indicadores_escolaridad_adulta
                (estado_id, codigo_indicador, anio, poblacion_base,
                 cantidad_personas, fuente, referencia_url, metodologia, archivo_origen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                 poblacion_base=VALUES(poblacion_base),
                 cantidad_personas=VALUES(cantidad_personas),
                 fuente=VALUES(fuente), referencia_url=VALUES(referencia_url),
                 metodologia=VALUES(metodologia), archivo_origen=VALUES(archivo_origen),
                 fecha_consulta=NOW()';
        $this->connection->begin_transaction();
        try {
            $stmt = $this->connection->prepare($sql);
            foreach ($filas as $fila) {
                $estado = (int)$fila['estado_id'];
                $codigo = $fila['codigo_indicador'];
                $anio = (int)$fila['anio'];
                $base = (int)$fila['poblacion_base'];
                $cantidad = (int)$fila['cantidad_personas'];
                $fuente = $fila['fuente'];
                $url = $fila['referencia_url'];
                $metodo = $fila['metodologia'];
                $stmt->bind_param('isiiissss', $estado, $codigo, $anio, $base, $cantidad, $fuente, $url, $metodo, $archivo);
                if (!$stmt->execute()) {
                    throw new RuntimeException('No se pudo guardar una fila de escolaridad adulta.');
                }
            }
            $stmt->close();
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }
}
