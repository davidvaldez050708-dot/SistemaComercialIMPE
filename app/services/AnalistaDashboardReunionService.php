<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AnalistaDashboardReunionService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function ajustar(array $tablero, $usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || !$this->tablaExiste('reuniones_vinculacion')) {
            return $tablero;
        }

        $reuniones = $this->reunionesActivasPorSeguimiento($usuarioId);
        $tablero['resumen'] = $this->ajustarResumen(
            is_array($tablero['resumen'] ?? null) ? $tablero['resumen'] : [],
            $usuarioId
        );
        $tablero['atenciones'] = $this->ajustarAtenciones(
            is_array($tablero['atenciones'] ?? null) ? $tablero['atenciones'] : [],
            $reuniones
        );
        $tablero['proximos'] = $this->obtenerProximos($usuarioId, $reuniones, 5);
        $tablero['reuniones_dashboard'] = $this->mapaReuniones($reuniones);

        return $tablero;
    }

    private function ajustarResumen(array $resumen, $usuarioId)
    {
        $sql = "SELECT
                    COUNT(DISTINCT CASE WHEN (
                        (
                            s.proxima_accion_at IS NOT NULL
                            AND s.proxima_accion_at >= NOW()
                            AND DATE(s.proxima_accion_at) = CURDATE()
                            AND NOT EXISTS (
                                SELECT 1
                                FROM reuniones_vinculacion r_accion
                                WHERE r_accion.seguimiento_id = s.id
                                  AND r_accion.analista_id = s.analista_id
                                  AND r_accion.estado NOT IN ('CANCELADA', 'REALIZADA')
                                  AND ABS(TIMESTAMPDIFF(SECOND, r_accion.fecha_propuesta, s.proxima_accion_at)) <= 60
                            )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM reuniones_vinculacion r_hoy
                            WHERE r_hoy.seguimiento_id = s.id
                              AND r_hoy.analista_id = s.analista_id
                              AND r_hoy.estado IN ('CONFIRMADA', 'CORREO_ENVIADO')
                              AND r_hoy.fecha_propuesta >= NOW()
                              AND DATE(r_hoy.fecha_propuesta) = CURDATE()
                        )
                    ) THEN s.id END) AS para_hoy,
                    COUNT(DISTINCT CASE WHEN (
                        (
                            s.proxima_accion_at IS NOT NULL
                            AND s.proxima_accion_at < NOW()
                            AND NOT EXISTS (
                                SELECT 1
                                FROM reuniones_vinculacion r_accion_vencida
                                WHERE r_accion_vencida.seguimiento_id = s.id
                                  AND r_accion_vencida.analista_id = s.analista_id
                                  AND r_accion_vencida.estado NOT IN ('CANCELADA', 'REALIZADA')
                                  AND ABS(TIMESTAMPDIFF(SECOND, r_accion_vencida.fecha_propuesta, s.proxima_accion_at)) <= 60
                            )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM reuniones_vinculacion r_vencida
                            WHERE r_vencida.seguimiento_id = s.id
                              AND r_vencida.analista_id = s.analista_id
                              AND r_vencida.estado IN ('SOLICITADA', 'CONFIRMADA', 'CORREO_ENVIADO', 'CAMBIO_SOLICITADO')
                              AND r_vencida.fecha_propuesta < NOW()
                        )
                    ) THEN s.id END) AS atrasados
                FROM seguimientos_vinculacion s
                WHERE s.analista_id = ?
                  AND s.activo = 1
                  AND s.estado_seguimiento <> 'DESCARTADO'";
        $fila = $this->obtenerFila($sql, 'i', [$usuarioId]);

        $sqlReuniones = "SELECT COUNT(DISTINCT r.id) AS total
                         FROM reuniones_vinculacion r
                         INNER JOIN seguimientos_vinculacion s ON s.id = r.seguimiento_id
                         WHERE r.analista_id = ?
                           AND s.analista_id = ?
                           AND s.activo = 1
                           AND s.estado_seguimiento <> 'DESCARTADO'
                           AND r.estado IN ('CONFIRMADA', 'CORREO_ENVIADO')
                           AND r.fecha_propuesta >= NOW()
                           AND r.fecha_propuesta < DATE_ADD(NOW(), INTERVAL 7 DAY)";
        $filaReuniones = $this->obtenerFila($sqlReuniones, 'ii', [$usuarioId, $usuarioId]);

        $resumen['para_hoy'] = (int)($fila['para_hoy'] ?? 0);
        $resumen['atrasados'] = (int)($fila['atrasados'] ?? 0);
        $resumen['reuniones_proximas'] = (int)($filaReuniones['total'] ?? 0);

        return $resumen;
    }

    private function reunionesActivasPorSeguimiento($usuarioId)
    {
        $sql = "SELECT
                    r.id,
                    r.seguimiento_id,
                    r.fecha_propuesta,
                    r.estado,
                    r.cambio_solicitado_at,
                    r.updated_at,
                    s.estado_id,
                    s.nombre_entidad
                FROM reuniones_vinculacion r
                INNER JOIN seguimientos_vinculacion s ON s.id = r.seguimiento_id
                WHERE r.analista_id = ?
                  AND s.analista_id = ?
                  AND s.activo = 1
                  AND s.estado_seguimiento <> 'DESCARTADO'
                  AND r.estado NOT IN ('CANCELADA', 'REALIZADA')
                ORDER BY r.seguimiento_id ASC, r.id DESC";
        $filas = $this->obtenerFilas($sql, 'ii', [$usuarioId, $usuarioId]);
        $resultado = [];

        foreach ($filas as $fila) {
            $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);
            if ($seguimientoId <= 0 || isset($resultado[$seguimientoId])) {
                continue;
            }
            $resultado[$seguimientoId] = $fila;
        }

        return $resultado;
    }

    private function ajustarAtenciones(array $atenciones, array $reuniones)
    {
        $porSeguimiento = [];
        foreach ($atenciones as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $porSeguimiento[$id] = $item;
            }
        }

        $ahora = new DateTimeImmutable();
        $limite24h = $ahora->modify('+24 hours');

        foreach ($reuniones as $seguimientoId => $reunion) {
            $estado = strtoupper(trim((string)($reunion['estado'] ?? '')));
            $fecha = $this->fecha($reunion['fecha_propuesta'] ?? null);
            $itemExistente = $porSeguimiento[$seguimientoId] ?? null;

            // La próxima acción del seguimiento usa la misma fecha de la reunión.
            // Si existe una reunión activa, evitamos mostrarla además como acción genérica.
            unset($porSeguimiento[$seguimientoId]);

            $prioridad = 0;
            $tipo = 'reunion';
            $motivo = '';
            $fechaReferencia = $fecha;

            if ($estado === 'CAMBIO_SOLICITADO') {
                $prioridad = 96;
                $motivo = 'Cuenta Clave solicitó reprogramar';
                $fechaReferencia = $this->fecha($reunion['cambio_solicitado_at'] ?? null) ?: $fecha;
            } elseif ($estado === 'SOLICITADA' && $fecha) {
                if ($fecha < $ahora) {
                    $prioridad = 98;
                    $tipo = 'atrasado';
                    $motivo = 'Reunión propuesta vencida sin confirmación';
                } elseif ($fecha <= $limite24h) {
                    $prioridad = 86;
                    $motivo = 'Reunión pendiente de confirmación';
                }
            } elseif (in_array($estado, ['CONFIRMADA', 'CORREO_ENVIADO'], true) && $fecha) {
                if ($fecha < $ahora) {
                    $prioridad = 100;
                    $tipo = 'atrasado';
                    $motivo = 'Reunión pendiente de registrar';
                } elseif ($fecha <= $limite24h) {
                    $prioridad = 55;
                    $motivo = 'Reunión próxima';
                }
            }

            if ($prioridad <= 0) {
                continue;
            }

            $base = is_array($itemExistente) ? $itemExistente : [
                'id' => $seguimientoId,
                'estado_id' => (int)($reunion['estado_id'] ?? 0),
                'nombre_entidad' => (string)($reunion['nombre_entidad'] ?? 'Institución')
            ];
            $base['prioridad'] = $prioridad;
            $base['tipo_atencion'] = $tipo;
            $base['motivo_atencion'] = $motivo;
            $base['fecha_referencia'] = $fechaReferencia
                ? $fechaReferencia->format('Y-m-d H:i:s')
                : null;
            $base['reunion_estado'] = $estado;
            $porSeguimiento[$seguimientoId] = $base;
        }

        $resultado = array_values($porSeguimiento);
        usort($resultado, static function ($a, $b) {
            $prioridad = (int)($b['prioridad'] ?? 0) <=> (int)($a['prioridad'] ?? 0);
            if ($prioridad !== 0) {
                return $prioridad;
            }
            return strcmp(
                (string)($a['fecha_referencia'] ?? ''),
                (string)($b['fecha_referencia'] ?? '')
            );
        });

        return array_slice($resultado, 0, 6);
    }

    private function obtenerProximos($usuarioId, array $reuniones, $limite)
    {
        $limite = max(1, (int)$limite);
        $limiteConsulta = max(10, $limite * 4);
        $eventos = [];

        $sqlAcciones = "SELECT
                            s.id AS seguimiento_id,
                            s.estado_id,
                            s.nombre_entidad,
                            s.proxima_accion_at AS fecha_evento,
                            'ACCION' AS tipo_evento
                        FROM seguimientos_vinculacion s
                        WHERE s.analista_id = ?
                          AND s.activo = 1
                          AND s.estado_seguimiento <> 'DESCARTADO'
                          AND s.proxima_accion_at >= NOW()
                          AND s.proxima_accion_at < DATE_ADD(NOW(), INTERVAL 7 DAY)
                        ORDER BY s.proxima_accion_at ASC
                        LIMIT {$limiteConsulta}";
        $acciones = $this->obtenerFilas($sqlAcciones, 'i', [$usuarioId]);

        foreach ($acciones as $accion) {
            $seguimientoId = (int)($accion['seguimiento_id'] ?? 0);
            $reunion = $reuniones[$seguimientoId] ?? null;
            if ($reunion && $this->mismaFecha(
                $accion['fecha_evento'] ?? null,
                $reunion['fecha_propuesta'] ?? null
            )) {
                continue;
            }
            $eventos[] = $accion;
        }

        foreach ($reuniones as $reunion) {
            $estado = strtoupper(trim((string)($reunion['estado'] ?? '')));
            if (!in_array($estado, ['SOLICITADA', 'CONFIRMADA', 'CORREO_ENVIADO'], true)) {
                continue;
            }

            $fecha = $this->fecha($reunion['fecha_propuesta'] ?? null);
            if (!$fecha) {
                continue;
            }

            $ahora = new DateTimeImmutable();
            if ($fecha < $ahora || $fecha >= $ahora->modify('+7 days')) {
                continue;
            }

            $eventos[] = [
                'seguimiento_id' => (int)$reunion['seguimiento_id'],
                'estado_id' => (int)($reunion['estado_id'] ?? 0),
                'nombre_entidad' => (string)($reunion['nombre_entidad'] ?? 'Institución'),
                'fecha_evento' => $fecha->format('Y-m-d H:i:s'),
                'tipo_evento' => 'REUNION',
                'reunion_estado' => $estado
            ];
        }

        usort($eventos, static function ($a, $b) {
            return strcmp((string)($a['fecha_evento'] ?? ''), (string)($b['fecha_evento'] ?? ''));
        });

        return array_slice($eventos, 0, $limite);
    }

    private function mapaReuniones(array $reuniones)
    {
        $mapa = [];
        foreach ($reuniones as $seguimientoId => $reunion) {
            $mapa[(string)$seguimientoId] = [
                'estado' => strtoupper(trim((string)($reunion['estado'] ?? ''))),
                'fecha' => (string)($reunion['fecha_propuesta'] ?? ''),
                'reunion_id' => (int)($reunion['id'] ?? 0)
            ];
        }
        return $mapa;
    }

    private function mismaFecha($a, $b)
    {
        $fechaA = $this->fecha($a);
        $fechaB = $this->fecha($b);
        if (!$fechaA || !$fechaB) {
            return false;
        }
        return abs($fechaA->getTimestamp() - $fechaB->getTimestamp()) <= 60;
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

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
        if ($tabla === '') {
            return false;
        }
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $this->connection->real_escape_string($tabla) . "'"
        );
        return $resultado && $resultado->num_rows > 0;
    }

    private function obtenerFila($sql, $tipos = '', $parametros = [])
    {
        $filas = $this->obtenerFilas($sql, $tipos, $parametros);
        return $filas[0] ?? [];
    }

    private function obtenerFilas($sql, $tipos = '', $parametros = [])
    {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($tipos !== '' && !empty($parametros)) {
            $referencias = [$tipos];
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
