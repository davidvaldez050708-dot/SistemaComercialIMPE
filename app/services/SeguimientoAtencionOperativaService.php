<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoAtencionOperativaService
{
    private $connection;
    private $tablas = [];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerPorAnalista($usuarioId)
    {
        $usuarioId = (int)$usuarioId;
        if ($usuarioId <= 0) {
            return [];
        }

        return $this->obtener(
            'seguimientos.analista_id = ?',
            'i',
            [$usuarioId]
        );
    }

    public function obtenerPorIds(array $seguimientoIds)
    {
        $ids = [];
        foreach ($seguimientoIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
            if (count($ids) >= 200) {
                break;
            }
        }

        $ids = array_values($ids);
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->obtener(
            "seguimientos.id IN ($placeholders)",
            str_repeat('i', count($ids)),
            $ids
        );
    }

    private function obtener($condicion, $tipos, array $parametros)
    {
        $usaReuniones = $this->tablaExiste('reuniones_vinculacion');
        $camposReunion = $usaReuniones
            ? ",
                (
                    SELECT reunion.fecha_propuesta
                    FROM reuniones_vinculacion reunion
                    WHERE reunion.seguimiento_id = seguimientos.id
                      AND reunion.analista_id = seguimientos.analista_id
                      AND reunion.estado NOT IN ('CANCELADA', 'REALIZADA')
                    ORDER BY reunion.id DESC
                    LIMIT 1
                ) AS reunion_fecha,
                (
                    SELECT reunion.estado
                    FROM reuniones_vinculacion reunion
                    WHERE reunion.seguimiento_id = seguimientos.id
                      AND reunion.analista_id = seguimientos.analista_id
                      AND reunion.estado NOT IN ('CANCELADA', 'REALIZADA')
                    ORDER BY reunion.id DESC
                    LIMIT 1
                ) AS reunion_estado,
                (
                    SELECT reunion.cambio_solicitado_at
                    FROM reuniones_vinculacion reunion
                    WHERE reunion.seguimiento_id = seguimientos.id
                      AND reunion.analista_id = seguimientos.analista_id
                      AND reunion.estado NOT IN ('CANCELADA', 'REALIZADA')
                    ORDER BY reunion.id DESC
                    LIMIT 1
                ) AS reunion_cambio_solicitado_at"
            : ", NULL AS reunion_fecha, NULL AS reunion_estado, NULL AS reunion_cambio_solicitado_at";

        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.estado_id,
                    seguimientos.analista_id,
                    seguimientos.nombre_entidad,
                    seguimientos.tipo_entidad,
                    seguimientos.estado_seguimiento,
                    seguimientos.proxima_accion_at,
                    seguimientos.ultima_interaccion_at,
                    seguimientos.fecha_inicio,
                    municipios.nombre AS municipio,
                    estados.nombre_corto AS estado_nombre
                    {$camposReunion}
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN municipios ON municipios.id = seguimientos.municipio_id
                LEFT JOIN estados ON estados.id = seguimientos.estado_id
                WHERE {$condicion}
                  AND seguimientos.activo = 1
                  AND seguimientos.estado_seguimiento <> 'DESCARTADO'";

        $filas = $this->obtenerFilas($sql, $tipos, $parametros);
        $ahora = new DateTimeImmutable();
        $hoy = $ahora->format('Y-m-d');
        $limite24h = $ahora->modify('+24 hours');
        $atenciones = [];

        foreach ($filas as $fila) {
            $prioridad = 0;
            $tipo = '';
            $motivo = '';
            $fechaReferencia = null;

            $reunionEstado = strtoupper(trim((string)($fila['reunion_estado'] ?? '')));
            $reunionFecha = $this->fecha($fila['reunion_fecha'] ?? null);
            $reunionCambio = $this->fecha($fila['reunion_cambio_solicitado_at'] ?? null);
            $tieneReunionActiva = $reunionEstado !== '';

            if ($tieneReunionActiva) {
                if ($reunionEstado === 'CAMBIO_SOLICITADO') {
                    $prioridad = 96;
                    $tipo = 'reunion';
                    $motivo = 'Cuenta Clave solicitó reprogramar';
                    $fechaReferencia = $reunionCambio ?: $reunionFecha;
                } elseif ($reunionEstado === 'SOLICITADA' && $reunionFecha) {
                    if ($reunionFecha < $ahora) {
                        $prioridad = 98;
                        $tipo = 'atrasado';
                        $motivo = 'Reunión propuesta vencida sin confirmación';
                    } elseif ($reunionFecha <= $limite24h) {
                        $prioridad = 86;
                        $tipo = 'reunion';
                        $motivo = 'Reunión pendiente de confirmación';
                    }
                    $fechaReferencia = $reunionFecha;
                } elseif (in_array($reunionEstado, ['CONFIRMADA', 'CORREO_ENVIADO'], true) && $reunionFecha) {
                    if ($reunionFecha < $ahora) {
                        $prioridad = 100;
                        $tipo = 'atrasado';
                        $motivo = 'Reunión pendiente de registrar';
                    } elseif ($reunionFecha <= $limite24h) {
                        $prioridad = 55;
                        $tipo = 'reunion';
                        $motivo = 'Reunión próxima';
                    }
                    $fechaReferencia = $reunionFecha;
                }
            } else {
                $proximaAccion = $this->fecha($fila['proxima_accion_at'] ?? null);
                $ultimaActividad = $this->fecha($fila['ultima_interaccion_at'] ?? null)
                    ?: $this->fecha($fila['fecha_inicio'] ?? null);

                if ($proximaAccion && $proximaAccion < $ahora) {
                    $prioridad = 90;
                    $tipo = 'atrasado';
                    $motivo = 'Acción programada vencida';
                    $fechaReferencia = $proximaAccion;
                } elseif ($proximaAccion && $proximaAccion->format('Y-m-d') === $hoy) {
                    $prioridad = 80;
                    $tipo = 'hoy';
                    $motivo = 'Acción programada para hoy';
                    $fechaReferencia = $proximaAccion;
                } elseif (
                    strtoupper((string)($fila['estado_seguimiento'] ?? '')) === 'ESPERANDO_RESPUESTA' &&
                    $ultimaActividad &&
                    $ultimaActividad <= $ahora->modify('-3 days')
                ) {
                    $dias = max(3, (int)$ultimaActividad->diff($ahora)->days);
                    $prioridad = 70 + min(10, $dias);
                    $tipo = 'espera';
                    $motivo = 'Esperando respuesta · ' . $dias . ' días sin actividad';
                    $fechaReferencia = $ultimaActividad;
                } elseif ($ultimaActividad && $ultimaActividad <= $ahora->modify('-7 days')) {
                    $dias = max(7, (int)$ultimaActividad->diff($ahora)->days);
                    $prioridad = 60 + min(9, $dias);
                    $tipo = 'seguimiento';
                    $motivo = $dias . ' días sin actividad registrada';
                    $fechaReferencia = $ultimaActividad;
                }
            }

            if ($prioridad <= 0) {
                continue;
            }

            $fila['prioridad'] = $prioridad;
            $fila['tipo_atencion'] = $tipo;
            $fila['motivo_atencion'] = $motivo;
            $fila['fecha_referencia'] = $fechaReferencia
                ? $fechaReferencia->format('Y-m-d H:i:s')
                : null;
            $atenciones[] = $fila;
        }

        usort($atenciones, static function ($a, $b) {
            $prioridad = (int)($b['prioridad'] ?? 0) <=> (int)($a['prioridad'] ?? 0);
            if ($prioridad !== 0) {
                return $prioridad;
            }

            return strcmp(
                (string)($a['fecha_referencia'] ?? ''),
                (string)($b['fecha_referencia'] ?? '')
            );
        });

        return $atenciones;
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
        if ($tabla === '') {
            return false;
        }

        if (array_key_exists($tabla, $this->tablas)) {
            return $this->tablas[$tabla];
        }

        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $this->connection->real_escape_string($tabla) . "'"
        );
        $existe = $resultado && $resultado->num_rows > 0;
        $this->tablas[$tabla] = $existe;
        return $existe;
    }

    private function fecha($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable $error) {
            return null;
        }
    }

    private function obtenerFilas($sql, $tipos = '', array $parametros = [])
    {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($tipos !== '' && !empty($parametros)) {
            $referencias = [];
            $referencias[] = &$tipos;
            foreach ($parametros as $indice => $valor) {
                $referencias[] = &$parametros[$indice];
            }
            call_user_func_array([$stmt, 'bind_param'], $referencias);
        }

        if (!$stmt->execute()) {
            return [];
        }

        $resultado = $stmt->get_result();
        if (!$resultado) {
            return [];
        }

        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
        return $filas;
    }
}
