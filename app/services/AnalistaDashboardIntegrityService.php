<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AnalistaDashboardIntegrityService
{
    private $connection;
    private $tablas = [];
    private $columnas = [];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function ajustar(array $tablero, $usuarioId)
    {
        $usuarioId = (int)$usuarioId;
        if ($usuarioId <= 0) {
            return $tablero;
        }

        $tablero['resumen'] = is_array($tablero['resumen'] ?? null)
            ? $tablero['resumen']
            : [];
        $tablero['avance_mes'] = is_array($tablero['avance_mes'] ?? null)
            ? $tablero['avance_mes']
            : [];

        $tablero['avance_mes']['contactos_efectivos'] = $this->contarContactosEfectivosMes($usuarioId);

        $reunionesRealizadas = $this->contarReunionesRealizadasMes($usuarioId);
        if ($reunionesRealizadas !== null) {
            $tablero['avance_mes']['reuniones_realizadas'] = $reunionesRealizadas;
        }

        $atenciones = $this->obtenerAtenciones($usuarioId);
        $tablero['atenciones'] = array_slice($atenciones, 0, 6);
        $tablero['resumen']['requieren_atencion'] = count($atenciones);

        $tablero['actividad'] = $this->obtenerActividadReciente($usuarioId, 10);

        $tablero['integridad_dashboard'] = [
            'requieren_atencion_total' => (int)$tablero['resumen']['requieren_atencion'],
            'actividad_actores' => array_map(static function ($registro) {
                return [
                    'id' => (int)($registro['id'] ?? 0),
                    'actor_id' => (int)($registro['actor_id'] ?? 0),
                    'actor_nombre' => trim((string)($registro['actor_nombre'] ?? '')),
                    'actor_rol' => trim((string)($registro['actor_rol'] ?? '')),
                    'canal' => (string)($registro['canal'] ?? ''),
                    'resultado' => (string)($registro['resultado'] ?? ''),
                    'notas' => (string)($registro['notas'] ?? '')
                ];
            }, $tablero['actividad'])
        ];

        return $tablero;
    }

    private function contarContactosEfectivosMes($usuarioId)
    {
        if (!$this->tablaExiste('interacciones_vinculacion')) {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT CASE
                    WHEN interacciones.notas LIKE '%[SIN_CONTACTO_EFECTIVO]%' THEN NULL
                    WHEN interacciones.resultado = 'CONTACTADO'
                         OR interacciones.notas LIKE '%[CONTACTO_EFECTIVO]%'
                    THEN interacciones.seguimiento_id
                    ELSE NULL
                END) AS total
                FROM interacciones_vinculacion interacciones
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = interacciones.seguimiento_id
                WHERE interacciones.usuario_id = ?
                  AND seguimientos.analista_id = ?
                  AND interacciones.fecha_inicio >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  AND interacciones.fecha_inicio < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";

        $fila = $this->obtenerFila($sql, 'ii', [$usuarioId, $usuarioId]);
        return (int)($fila['total'] ?? 0);
    }

    private function contarReunionesRealizadasMes($usuarioId)
    {
        if (
            !$this->tablaExiste('seguimientos_vinculacion_post_envio') ||
            !$this->columnaExiste('seguimientos_vinculacion_post_envio', 'reunion_realizada_at')
        ) {
            return null;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM seguimientos_vinculacion_post_envio post_envio
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = post_envio.seguimiento_id
                WHERE seguimientos.analista_id = ?
                  AND post_envio.reunion_realizada_at IS NOT NULL
                  AND post_envio.reunion_realizada_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  AND post_envio.reunion_realizada_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";

        $fila = $this->obtenerFila($sql, 'i', [$usuarioId]);
        return (int)($fila['total'] ?? 0);
    }

    private function obtenerAtenciones($usuarioId)
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
                WHERE seguimientos.analista_id = ?
                  AND seguimientos.activo = 1
                  AND seguimientos.estado_seguimiento <> 'DESCARTADO'";

        $filas = $this->obtenerFilas($sql, 'i', [$usuarioId]);
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

    private function obtenerActividadReciente($usuarioId, $limite)
    {
        if (!$this->tablaExiste('interacciones_vinculacion')) {
            return [];
        }

        $limite = max(5, (int)$limite);
        $limiteConsulta = max(30, $limite * 4);

        $sql = "SELECT
                    interacciones.id,
                    interacciones.seguimiento_id,
                    seguimientos.estado_id,
                    seguimientos.nombre_entidad,
                    interacciones.canal,
                    interacciones.resultado,
                    interacciones.fecha_inicio,
                    interacciones.notas,
                    usuarios.id AS actor_id,
                    TRIM(CONCAT(COALESCE(usuarios.nombre, ''), ' ', COALESCE(usuarios.apellidos, ''))) AS actor_nombre,
                    roles.nombre AS actor_rol
                FROM interacciones_vinculacion interacciones
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = interacciones.seguimiento_id
                LEFT JOIN usuarios ON usuarios.id = interacciones.usuario_id
                LEFT JOIN roles ON roles.id = usuarios.rol_id
                WHERE seguimientos.analista_id = ?
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                LIMIT {$limiteConsulta}";

        $filas = $this->obtenerFilas($sql, 'i', [$usuarioId]);
        $resultado = [];
        $vistos = [];

        foreach ($filas as $fila) {
            $minuto = '';
            $fecha = $this->fecha($fila['fecha_inicio'] ?? null);
            if ($fecha) {
                $minuto = $fecha->format('Y-m-d H:i');
            }

            $notas = preg_replace('/\s+/', ' ', trim((string)($fila['notas'] ?? '')));
            $firma = implode('|', [
                (string)($fila['seguimiento_id'] ?? ''),
                strtoupper((string)($fila['canal'] ?? '')),
                strtoupper((string)($fila['resultado'] ?? '')),
                mb_strtolower((string)$notas, 'UTF-8'),
                (string)($fila['actor_id'] ?? ''),
                $minuto
            ]);

            if (isset($vistos[$firma])) {
                continue;
            }

            $vistos[$firma] = true;
            $resultado[] = $fila;

            if (count($resultado) >= $limite) {
                break;
            }
        }

        return $resultado;
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

    private function columnaExiste($tabla, $columna)
    {
        $clave = $tabla . '.' . $columna;
        if (array_key_exists($clave, $this->columnas)) {
            return $this->columnas[$clave];
        }

        $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
        $columna = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$columna);
        if ($tabla === '' || $columna === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM `{$tabla}` LIKE '" . $this->connection->real_escape_string($columna) . "'"
        );
        $existe = $resultado && $resultado->num_rows > 0;
        $this->columnas[$clave] = $existe;
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
