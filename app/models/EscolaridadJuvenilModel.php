<?php

require_once __DIR__ . '/../../config/db_connection.php';

class EscolaridadJuvenilModel
{
    public const SIN_MEDIA_CONCLUIDA_15_17 = 'SIN_MEDIA_SUPERIOR_CONCLUIDA_15_17';

    private mysqli $connection;

    public function __construct()
    {
        $this->connection = (new Database())->connect();
    }

    public function tablaDisponible(): bool
    {
        try {
            return $this->connection->query("SHOW TABLES LIKE 'indicadores_escolaridad_juvenil'")->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function obtenerPorEstado(int $estadoId): array
    {
        $pendiente = [
            'disponible' => false,
            'codigo_indicador' => self::SIN_MEDIA_CONCLUIDA_15_17,
            'grupo_edad' => '15 a 17 años',
            'es_minimo' => true
        ];
        if ($estadoId <= 0 || !$this->tablaDisponible()) {
            return $pendiente;
        }
        try {
            $sql = 'SELECT anio, poblacion_base, cantidad_personas, fuente,
                           referencia_url, metodologia, archivo_origen, fecha_consulta
                    FROM indicadores_escolaridad_juvenil
                    WHERE estado_id = ? AND codigo_indicador = ?
                    ORDER BY anio DESC, fecha_consulta DESC LIMIT 1';
            $stmt = $this->connection->prepare($sql);
            $codigo = self::SIN_MEDIA_CONCLUIDA_15_17;
            $stmt->bind_param('is', $estadoId, $codigo);
            $stmt->execute();
            $dato = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$dato) {
                return $pendiente;
            }
            $base = (int)$dato['poblacion_base'];
            $cantidad = (int)$dato['cantidad_personas'];
            if ($base <= 0 || $cantidad < 0 || $cantidad > $base) {
                return $pendiente;
            }
            return array_merge($pendiente, [
                'disponible' => true,
                'anio' => (int)$dato['anio'],
                'poblacion_base' => $base,
                'cantidad_personas' => $cantidad,
                'porcentaje' => round(100 * $cantidad / $base, 2),
                'fuente' => (string)$dato['fuente'],
                'referencia_url' => (string)$dato['referencia_url'],
                'metodologia' => (string)$dato['metodologia'],
                'archivo_origen' => (string)$dato['archivo_origen'],
                'fecha_consulta' => (string)$dato['fecha_consulta']
            ]);
        } catch (Throwable $e) {
            error_log('Indicador juvenil: ' . $e->getMessage());
            return $pendiente;
        }
    }

    public function importarDesdeInegi(
        int $estadoId,
        int $anio,
        int $base,
        int $cantidad,
        string $fuente,
        string $url,
        string $metodologia,
        string $archivo
    ): void {
        if (!$this->tablaDisponible()) {
            throw new RuntimeException('Falta ejecutar migración de escolaridad juvenil.');
        }
        if ($estadoId <= 0 || $base <= 0 || $cantidad < 0 || $cantidad > $base ||
            $anio < 2020 || strlen($metodologia) < 35) {
            throw new InvalidArgumentException('Indicador juvenil incompleto o no consistente.');
        }
        $sql = 'INSERT INTO indicadores_escolaridad_juvenil
                (estado_id, codigo_indicador, anio, poblacion_base, cantidad_personas,
                 fuente, referencia_url, metodologia, archivo_origen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                   poblacion_base=VALUES(poblacion_base),
                   cantidad_personas=VALUES(cantidad_personas),
                   fuente=VALUES(fuente), referencia_url=VALUES(referencia_url),
                   metodologia=VALUES(metodologia), archivo_origen=VALUES(archivo_origen),
                   fecha_consulta=NOW()';
        $stmt = $this->connection->prepare($sql);
        $codigo = self::SIN_MEDIA_CONCLUIDA_15_17;
        $stmt->bind_param(
            'isiiissss',
            $estadoId, $codigo, $anio, $base, $cantidad,
            $fuente, $url, $metodologia, $archivo
        );
        try {
            if (!$stmt->execute()) {
                throw new RuntimeException('No fue posible guardar escolaridad juvenil.');
            }
        } finally {
            $stmt->close();
        }
    }
}
