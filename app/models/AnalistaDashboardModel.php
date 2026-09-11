<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AnalistaDashboardModel
{
    private $connection;
    private $tablas = [];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerTablero($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0) {
            return $this->tableroVacio();
        }

        return [
            'resumen' => $this->obtenerResumen($usuarioId),
            'avance_mes' => $this->obtenerAvanceMes($usuarioId),
            'atenciones' => $this->obtenerAtenciones($usuarioId, 6),
            'proximos' => $this->obtenerProximos($usuarioId, 5),
            'actividad' => $this->obtenerActividadReciente($usuarioId, 6),
            'territorios' => $this->obtenerTerritorios($usuarioId),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }

    private function obtenerResumen($usuarioId)
    {
        $usaReuniones = $this->tablaExiste('reuniones_vinculacion');
        $reunionHoy = $usaReuniones
            ? " OR EXISTS (
                    SELECT 1
                    FROM reuniones_vinculacion reunion_hoy
                    WHERE reunion_hoy.seguimiento_id = seguimientos.id
                      AND reunion_hoy.analista_id = seguimientos.analista_id
                      AND reunion_hoy.estado NOT IN ('CANCELADA', 'REALIZADA')
                      AND DATE(reunion_hoy.fecha_propuesta) = CURDATE()
                )"
            : '';
        $reunionAtrasada = $usaReuniones
            ? " OR EXISTS (
                    SELECT 1
                    FROM reuniones_vinculacion reunion_atrasada
                    WHERE reunion_atrasada.seguimiento_id = seguimientos.id
                      AND reunion_atrasada.analista_id = seguimientos.analista_id
                      AND reunion_atrasada.estado NOT IN ('CANCELADA', 'REALIZADA')
                      AND reunion_atrasada.fecha_propuesta < NOW()
                )"
            : '';

        $sql = "SELECT
                    COUNT(*) AS en_seguimiento,
                    COALESCE(SUM(
                        (
                            (seguimientos.proxima_accion_at IS NOT NULL
                             AND DATE(seguimientos.proxima_accion_at) = CURDATE())
                            {$reunionHoy}
                        )
                    ), 0) AS para_hoy,
                    COALESCE(SUM(
                        (
                            (seguimientos.proxima_accion_at IS NOT NULL
                             AND seguimientos.proxima_accion_at < NOW())
                            {$reunionAtrasada}
                        )
                    ), 0) AS atrasados
                FROM seguimientos_vinculacion seguimientos
                WHERE seguimientos.analista_id = ?
                  AND seguimientos.activo = 1
                  AND seguimientos.estado_seguimiento <> 'DESCARTADO'";

        $resumen = $this->obtenerFila($sql, 'i', [$usuarioId]);
        $resumen = [
            'en_seguimiento' => (int)($resumen['en_seguimiento'] ?? 0),
            'para_hoy' => (int)($resumen['para_hoy'] ?? 0),
            'atrasados' => (int)($resumen['atrasados'] ?? 0),
            'reuniones_proximas' => 0
        ];

        if ($usaReuniones) {
            $sqlReuniones = "SELECT COUNT(*) AS total
                            FROM reuniones_vinculacion reuniones
                            INNER JOIN seguimientos_vinculacion seguimientos
                                ON seguimientos.id = reuniones.seguimiento_id
                            WHERE reuniones.analista_id = ?
                              AND seguimientos.analista_id = ?
                              AND seguimientos.activo = 1
                              AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                              AND reuniones.estado NOT IN ('CANCELADA', 'REALIZADA')
                              AND reuniones.fecha_propuesta >= NOW()
                              AND reuniones.fecha_propuesta < DATE_ADD(NOW(), INTERVAL 7 DAY)";
            $fila = $this->obtenerFila($sqlReuniones, 'ii', [$usuarioId, $usuarioId]);
            $resumen['reuniones_proximas'] = (int)($fila['total'] ?? 0);
        }

        return $resumen;
    }

    private function obtenerAvanceMes($usuarioId)
    {
        $avance = [
            'contactos_efectivos' => 0,
            'datos_verificados' => 0,
            'oficios_enviados' => 0,
            'reuniones_realizadas' => 0,
            'interacciones' => 0
        ];

        if ($this->tablaExiste('interacciones_vinculacion')) {
            $sqlInteracciones = "SELECT
                    COUNT(*) AS interacciones,
                    COUNT(DISTINCT CASE
                        WHEN (
                            interacciones.resultado IN ('CONTACTADO', 'MENSAJE_ENVIADO')
                            OR interacciones.notas LIKE '%[CONTACTO_EFECTIVO]%'
                        ) THEN interacciones.seguimiento_id
                        ELSE NULL
                    END) AS contactos_efectivos
                FROM interacciones_vinculacion interacciones
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = interacciones.seguimiento_id
                WHERE interacciones.usuario_id = ?
                  AND seguimientos.analista_id = ?
                  AND interacciones.fecha_inicio >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  AND interacciones.fecha_inicio < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";
            $fila = $this->obtenerFila($sqlInteracciones, 'ii', [$usuarioId, $usuarioId]);
            $avance['interacciones'] = (int)($fila['interacciones'] ?? 0);
            $avance['contactos_efectivos'] = (int)($fila['contactos_efectivos'] ?? 0);
        }

        $sqlVerificados = "SELECT COUNT(*) AS total
                          FROM seguimientos_vinculacion
                          WHERE analista_id = ?
                            AND datos_verificados = 1
                            AND datos_verificados_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND datos_verificados_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";
        $fila = $this->obtenerFila($sqlVerificados, 'i', [$usuarioId]);
        $avance['datos_verificados'] = (int)($fila['total'] ?? 0);

        if ($this->tablaExiste('oficios_vinculacion')) {
            $sqlOficios = "SELECT COUNT(DISTINCT oficios.id) AS total
                          FROM oficios_vinculacion oficios
                          INNER JOIN seguimientos_vinculacion seguimientos
                              ON seguimientos.id = oficios.seguimiento_id
                          WHERE seguimientos.analista_id = ?
                            AND oficios.estado_oficio = 'ENVIADO'
                            AND oficios.fecha_envio >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                            AND oficios.fecha_envio < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";
            $fila = $this->obtenerFila($sqlOficios, 'i', [$usuarioId]);
            $avance['oficios_enviados'] = (int)($fila['total'] ?? 0);
        }

        if ($this->tablaExiste('reuniones_vinculacion')) {
            $sqlReuniones = "SELECT COUNT(*) AS total
                            FROM reuniones_vinculacion
                            WHERE analista_id = ?
                              AND estado = 'REALIZADA'
                              AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                              AND updated_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)";
            $fila = $this->obtenerFila($sqlReuniones, 'i', [$usuarioId]);
            $avance['reuniones_realizadas'] = (int)($fila['total'] ?? 0);
        }

        return $avance;
    }

    private function obtenerAtenciones($usuarioId, $limite)
    {
        $usaReuniones = $this->tablaExiste('reuniones_vinculacion');
        $camposReunion = $usaReuniones
            ? ",
                (
                    SELECT reuniones.fecha_propuesta
                    FROM reuniones_vinculacion reuniones
                    WHERE reuniones.seguimiento_id = seguimientos.id
                      AND reuniones.analista_id = seguimientos.analista_id
                      AND reuniones.estado <> 'CANCELADA'
                    ORDER BY reuniones.id DESC
                    LIMIT 1
                ) AS reunion_fecha,
                (
                    SELECT reuniones.estado
                    FROM reuniones_vinculacion reuniones
                    WHERE reuniones.seguimiento_id = seguimientos.id
                      AND reuniones.analista_id = seguimientos.analista_id
                      AND reuniones.estado <> 'CANCELADA'
                    ORDER BY reuniones.id DESC
                    LIMIT 1
                ) AS reunion_estado"
            : ", NULL AS reunion_fecha, NULL AS reunion_estado";

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
                  AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                ORDER BY COALESCE(seguimientos.proxima_accion_at, seguimientos.ultima_interaccion_at, seguimientos.fecha_inicio) ASC
                LIMIT 80";

        $filas = $this->obtenerFilas($sql, 'i', [$usuarioId]);
        $ahora = new DateTimeImmutable();
        $hoy = $ahora->format('Y-m-d');
        $atenciones = [];

        foreach ($filas as $fila) {
            $prioridad = 0;
            $tipo = '';
            $motivo = '';
            $fechaReferencia = null;
            $reunionFecha = $this->fecha($fila['reunion_fecha'] ?? null);
            $reunionEstado = strtoupper(trim((string)($fila['reunion_estado'] ?? '')));
            $proximaAccion = $this->fecha($fila['proxima_accion_at'] ?? null);
            $ultimaActividad = $this->fecha(
                $fila['ultima_interaccion_at'] ?? $fila['fecha_inicio'] ?? null
            );

            if (
                $reunionFecha &&
                $reunionFecha < $ahora &&
                !in_array($reunionEstado, ['REALIZADA', 'CANCELADA'], true)
            ) {
                $prioridad = 100;
                $tipo = 'atrasado';
                $motivo = 'Reunión pendiente de registrar';
                $fechaReferencia = $reunionFecha;
            } elseif ($proximaAccion && $proximaAccion < $ahora) {
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
            } elseif (
                $reunionFecha &&
                $reunionFecha >= $ahora &&
                $reunionFecha <= $ahora->modify('+24 hours') &&
                !in_array($reunionEstado, ['REALIZADA', 'CANCELADA'], true)
            ) {
                $prioridad = 55;
                $tipo = 'reunion';
                $motivo = 'Reunión próxima';
                $fechaReferencia = $reunionFecha;
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

        usort($atenciones, function ($a, $b) {
            $prioridad = (int)$b['prioridad'] <=> (int)$a['prioridad'];
            if ($prioridad !== 0) {
                return $prioridad;
            }

            return strcmp(
                (string)($a['fecha_referencia'] ?? ''),
                (string)($b['fecha_referencia'] ?? '')
            );
        });

        return array_slice($atenciones, 0, max(1, (int)$limite));
    }

    private function obtenerProximos($usuarioId, $limite)
    {
        $eventos = [];
        $limiteConsulta = max(8, (int)$limite * 3);

        $sqlAcciones = "SELECT
                            seguimientos.id AS seguimiento_id,
                            seguimientos.estado_id,
                            seguimientos.nombre_entidad,
                            seguimientos.proxima_accion_at AS fecha_evento,
                            'ACCION' AS tipo_evento
                        FROM seguimientos_vinculacion seguimientos
                        WHERE seguimientos.analista_id = ?
                          AND seguimientos.activo = 1
                          AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                          AND seguimientos.proxima_accion_at >= NOW()
                          AND seguimientos.proxima_accion_at < DATE_ADD(NOW(), INTERVAL 7 DAY)
                        ORDER BY seguimientos.proxima_accion_at ASC
                        LIMIT {$limiteConsulta}";
        $eventos = array_merge($eventos, $this->obtenerFilas($sqlAcciones, 'i', [$usuarioId]));

        if ($this->tablaExiste('reuniones_vinculacion')) {
            $sqlReuniones = "SELECT
                                seguimientos.id AS seguimiento_id,
                                seguimientos.estado_id,
                                seguimientos.nombre_entidad,
                                reuniones.fecha_propuesta AS fecha_evento,
                                'REUNION' AS tipo_evento
                            FROM reuniones_vinculacion reuniones
                            INNER JOIN seguimientos_vinculacion seguimientos
                                ON seguimientos.id = reuniones.seguimiento_id
                            WHERE reuniones.analista_id = ?
                              AND seguimientos.analista_id = ?
                              AND seguimientos.activo = 1
                              AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                              AND reuniones.estado NOT IN ('CANCELADA', 'REALIZADA')
                              AND reuniones.fecha_propuesta >= NOW()
                              AND reuniones.fecha_propuesta < DATE_ADD(NOW(), INTERVAL 7 DAY)
                            ORDER BY reuniones.fecha_propuesta ASC
                            LIMIT {$limiteConsulta}";
            $eventos = array_merge(
                $eventos,
                $this->obtenerFilas($sqlReuniones, 'ii', [$usuarioId, $usuarioId])
            );
        }

        usort($eventos, function ($a, $b) {
            return strcmp((string)$a['fecha_evento'], (string)$b['fecha_evento']);
        });

        $unicos = [];
        $resultado = [];

        foreach ($eventos as $evento) {
            $clave = (string)$evento['seguimiento_id'] . '|' .
                (string)$evento['fecha_evento'] . '|' .
                (string)$evento['tipo_evento'];
            if (isset($unicos[$clave])) {
                continue;
            }
            $unicos[$clave] = true;
            $resultado[] = $evento;

            if (count($resultado) >= $limite) {
                break;
            }
        }

        return $resultado;
    }

    private function obtenerActividadReciente($usuarioId, $limite)
    {
        if (!$this->tablaExiste('interacciones_vinculacion')) {
            return [];
        }

        $limite = max(1, (int)$limite);
        $sql = "SELECT
                    interacciones.id,
                    interacciones.seguimiento_id,
                    seguimientos.estado_id,
                    seguimientos.nombre_entidad,
                    interacciones.canal,
                    interacciones.resultado,
                    interacciones.fecha_inicio,
                    interacciones.notas
                FROM interacciones_vinculacion interacciones
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = interacciones.seguimiento_id
                WHERE interacciones.usuario_id = ?
                  AND seguimientos.analista_id = ?
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                LIMIT {$limite}";

        return $this->obtenerFilas($sql, 'ii', [$usuarioId, $usuarioId]);
    }

    private function obtenerTerritorios($usuarioId)
    {
        if (!$this->tablaExiste('asignaciones_territorio')) {
            return [];
        }

        $sql = "SELECT
                    estados.id,
                    estados.nombre,
                    estados.nombre_corto,
                    asignaciones.es_principal,
                    COUNT(DISTINCT CASE
                        WHEN seguimientos.activo = 1
                         AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                        THEN seguimientos.id
                        ELSE NULL
                    END) AS seguimientos_activos
                FROM asignaciones_territorio asignaciones
                INNER JOIN estados ON estados.id = asignaciones.estado_id
                LEFT JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.estado_id = asignaciones.estado_id
                   AND seguimientos.analista_id = asignaciones.usuario_id
                WHERE asignaciones.usuario_id = ?
                  AND asignaciones.tipo_asignacion = 'ANALISTA_DATOS'
                  AND asignaciones.activo = 1
                  AND estados.estado = 1
                  AND (asignaciones.fecha_inicio IS NULL OR asignaciones.fecha_inicio <= CURDATE())
                  AND (asignaciones.fecha_fin IS NULL OR asignaciones.fecha_fin >= CURDATE())
                GROUP BY estados.id, estados.nombre, estados.nombre_corto, asignaciones.es_principal
                ORDER BY asignaciones.es_principal DESC, estados.nombre ASC";

        return $this->obtenerFilas($sql, 'i', [$usuarioId]);
    }

    private function tableroVacio()
    {
        return [
            'resumen' => [
                'en_seguimiento' => 0,
                'para_hoy' => 0,
                'atrasados' => 0,
                'reuniones_proximas' => 0
            ],
            'avance_mes' => [
                'contactos_efectivos' => 0,
                'datos_verificados' => 0,
                'oficios_enviados' => 0,
                'reuniones_realizadas' => 0,
                'interacciones' => 0
            ],
            'atenciones' => [],
            'proximos' => [],
            'actividad' => [],
            'territorios' => [],
            'generado_at' => date('Y-m-d H:i:s')
        ];
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

        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $this->connection->real_escape_string($tabla) . "'");
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
            $referencias = [];
            $referencias[] = $tipos;
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
