<?php

require_once __DIR__ . '/../../config/db_connection.php';

class PoblacionObjetivoEducativaModel
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
            "SHOW TABLES LIKE 'indicadores_educativos_oficiales'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    public function obtenerIndicadorEstado(
        int $estadoId,
        string $codigoIndicador = 'SECUNDARIA_COMPLETA_15_MAS'
    ): array {
        $vacio = [
            'disponible' => false,
            'codigo_indicador' => $codigoIndicador,
            'historico' => []
        ];

        if ($estadoId <= 0 || !$this->tablaDisponible()) {
            return $vacio;
        }

        $sql = "SELECT
                    id,
                    estado_id,
                    clave_geografica,
                    codigo_indicador,
                    nombre_indicador,
                    grupo_edad,
                    anio,
                    cantidad_personas,
                    poblacion_base,
                    porcentaje,
                    fuente,
                    archivo_origen,
                    fecha_consulta,
                    tipo_actualizacion
                FROM indicadores_educativos_oficiales
                WHERE estado_id = ?
                    AND codigo_indicador = ?
                ORDER BY anio DESC, fecha_consulta DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('is', $estadoId, $codigoIndicador);
        $stmt->execute();
        $actual = $stmt->get_result()->fetch_assoc();

        if (!$actual) {
            return $vacio;
        }

        $sqlHistorico = "SELECT
                    anio,
                    cantidad_personas,
                    poblacion_base,
                    porcentaje,
                    fecha_consulta
                FROM indicadores_educativos_oficiales
                WHERE estado_id = ?
                    AND codigo_indicador = ?
                ORDER BY anio ASC";
        $stmtHistorico = $this->connection->prepare($sqlHistorico);
        $stmtHistorico->bind_param('is', $estadoId, $codigoIndicador);
        $stmtHistorico->execute();
        $resultadoHistorico = $stmtHistorico->get_result();
        $historico = [];

        while ($fila = $resultadoHistorico->fetch_assoc()) {
            $historico[] = [
                'anio' => (int)$fila['anio'],
                'cantidad_personas' => isset($fila['cantidad_personas'])
                    ? (int)$fila['cantidad_personas']
                    : null,
                'poblacion_base' => isset($fila['poblacion_base'])
                    ? (int)$fila['poblacion_base']
                    : null,
                'porcentaje' => isset($fila['porcentaje'])
                    ? round((float)$fila['porcentaje'], 2)
                    : null,
                'fecha_consulta' => $fila['fecha_consulta'] ?? null
            ];
        }

        return [
            'disponible' => true,
            'clave_geografica' => (string)($actual['clave_geografica'] ?? ''),
            'codigo_indicador' => (string)($actual['codigo_indicador'] ?? ''),
            'nombre_indicador' => (string)($actual['nombre_indicador'] ?? ''),
            'grupo_edad' => (string)($actual['grupo_edad'] ?? ''),
            'anio' => (int)($actual['anio'] ?? 0),
            'cantidad_personas' => isset($actual['cantidad_personas'])
                ? (int)$actual['cantidad_personas']
                : null,
            'poblacion_base' => isset($actual['poblacion_base'])
                ? (int)$actual['poblacion_base']
                : null,
            'porcentaje' => isset($actual['porcentaje'])
                ? round((float)$actual['porcentaje'], 2)
                : null,
            'fuente' => (string)($actual['fuente'] ?? ''),
            'archivo_origen' => (string)($actual['archivo_origen'] ?? ''),
            'fecha_consulta' => $actual['fecha_consulta'] ?? null,
            'tipo_actualizacion' => (string)($actual['tipo_actualizacion'] ?? ''),
            'historico' => $historico
        ];
    }

    public function guardarIndicadorEstado(
        int $estadoId,
        array $datos,
        int $usuarioId
    ): bool {
        if ($estadoId <= 0 || $usuarioId <= 0 || !$this->tablaDisponible()) {
            return false;
        }

        $clave = trim((string)($datos['clave_geografica'] ?? ''));
        $codigo = trim((string)($datos['codigo_indicador'] ?? ''));
        $nombre = trim((string)($datos['nombre_indicador'] ?? ''));
        $grupoEdad = trim((string)($datos['grupo_edad'] ?? ''));
        $anio = (int)($datos['anio'] ?? 0);
        $cantidad = (int)($datos['cantidad_personas'] ?? -1);
        $poblacionBase = (int)($datos['poblacion_base'] ?? -1);
        $porcentaje = (float)($datos['porcentaje'] ?? -1);
        $fuente = trim((string)($datos['fuente'] ?? ''));
        $archivoOrigen = trim((string)($datos['archivo_origen'] ?? ''));
        $tipoActualizacion = trim((string)($datos['tipo_actualizacion'] ?? 'AUTOMATICA'));

        if (
            !preg_match('/^\d{2}$/', $clave) ||
            $codigo === '' ||
            $nombre === '' ||
            $anio < 2000 ||
            $anio > 2100 ||
            $cantidad < 0 ||
            $poblacionBase <= 0 ||
            $cantidad > $poblacionBase ||
            $porcentaje < 0 ||
            $porcentaje > 100 ||
            $fuente === ''
        ) {
            return false;
        }

        $this->connection->begin_transaction();

        try {
            $sql = "INSERT INTO indicadores_educativos_oficiales (
                        estado_id,
                        clave_geografica,
                        codigo_indicador,
                        nombre_indicador,
                        grupo_edad,
                        anio,
                        cantidad_personas,
                        poblacion_base,
                        porcentaje,
                        fuente,
                        archivo_origen,
                        fecha_consulta,
                        tipo_actualizacion,
                        usuario_importo_id,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        estado_id = VALUES(estado_id),
                        nombre_indicador = VALUES(nombre_indicador),
                        grupo_edad = VALUES(grupo_edad),
                        cantidad_personas = VALUES(cantidad_personas),
                        poblacion_base = VALUES(poblacion_base),
                        porcentaje = VALUES(porcentaje),
                        fuente = VALUES(fuente),
                        archivo_origen = VALUES(archivo_origen),
                        fecha_consulta = NOW(),
                        tipo_actualizacion = VALUES(tipo_actualizacion),
                        usuario_importo_id = VALUES(usuario_importo_id),
                        updated_at = NOW()";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'issssiiidsssi',
                $estadoId,
                $clave,
                $codigo,
                $nombre,
                $grupoEdad,
                $anio,
                $cantidad,
                $poblacionBase,
                $porcentaje,
                $fuente,
                $archivoOrigen,
                $tipoActualizacion,
                $usuarioId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('No fue posible guardar el indicador educativo oficial.');
            }

            $sqlEstado = "UPDATE estados
                    SET fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                        AND estado = 1";
            $stmtEstado = $this->connection->prepare($sqlEstado);
            $stmtEstado->bind_param('i', $estadoId);

            if (!$stmtEstado->execute()) {
                throw new RuntimeException('No fue posible actualizar la fecha territorial.');
            }

            $this->connection->commit();
            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Población objetivo educativa: ' . $error->getMessage());
            return false;
        }
    }
}
