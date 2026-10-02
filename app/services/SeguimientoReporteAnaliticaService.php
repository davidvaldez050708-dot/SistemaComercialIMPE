<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoAtencionOperativaService.php';

class SeguimientoReporteAnaliticaService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function construir(
        array $seguimientoIds,
        $usuarioId,
        $modoAcceso,
        $fechaInicial = '',
        $fechaFinal = '',
        $canal = '',
        $limiteActividadReciente = 60,
        array $actorIds = []
    ) {
        $seguimientoIds = $this->normalizarIds($seguimientoIds);
        $usuarioId = (int)$usuarioId;
        $modoAcceso = (string)$modoAcceso;
        $actorIds = $this->normalizarIds($actorIds);

        if ($modoAcceso === 'analista') {
            $actorIds = [$usuarioId];
        }

        if (empty($seguimientoIds) || $usuarioId <= 0) {
            return $this->estructuraVacia();
        }

        $autorizados = $this->filtrarIdsAutorizados(
            $seguimientoIds,
            $usuarioId,
            $modoAcceso
        );

        if (empty($autorizados)) {
            return $this->estructuraVacia();
        }

        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);
        $canal = strtoupper(trim((string)$canal));
        $resumenInteracciones = $this->obtenerResumenInteracciones(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            $actorIds
        );

        $canales = [
            'llamadas' => (int)($resumenInteracciones['llamadas'] ?? 0),
            'correos' => (int)($resumenInteracciones['correos'] ?? 0),
            'whatsapp' => (int)($resumenInteracciones['whatsapp'] ?? 0),
            'otros' => (int)($resumenInteracciones['otros'] ?? 0)
        ];
        $llamadas = [
            'total' => (int)($resumenInteracciones['llamadas'] ?? 0),
            'contactadas' => (int)($resumenInteracciones['contactadas'] ?? 0),
            'sin_respuesta' => (int)($resumenInteracciones['sin_respuesta'] ?? 0),
            'numero_incorrecto' => (int)($resumenInteracciones['numero_incorrecto'] ?? 0),
            'volver_llamar' => (int)($resumenInteracciones['volver_llamar'] ?? 0),
            'otros' => (int)($resumenInteracciones['llamadas_otros'] ?? 0),
            'verificaciones_efectivas' => (int)($resumenInteracciones['verificaciones_efectivas'] ?? 0),
            'tasa_contacto' => 0.0
        ];

        if ($llamadas['total'] > 0) {
            $llamadas['tasa_contacto'] = round(
                ($llamadas['contactadas'] / $llamadas['total']) * 100,
                1
            );
        }

        $totalSeguimientos = count($autorizados);
        $totalInteracciones = (int)($resumenInteracciones['total_interacciones'] ?? 0);
        $totalConActividad = (int)($resumenInteracciones['seguimientos_con_actividad'] ?? 0);
        $atenciones = (new SeguimientoAtencionOperativaService())->obtenerPorIds($autorizados);
        $totalAtencion = count($atenciones);
        $actividadReciente = $this->obtenerActividadReciente(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            max(1, min(500, (int)$limiteActividadReciente)),
            $actorIds
        );
        $rendimientoTelefonicoDiario = $this->obtenerRendimientoTelefonicoDiario(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            $actorIds
        );
        $cantidadActoresMeta = $modoAcceso === 'supervisor'
            ? max(1, count($actorIds))
            : 1;
        $metaDiariaEquipo = 25 * $cantidadActoresMeta;
        $rendimientoTelefonico = $this->resumirRendimientoTelefonico(
            $rendimientoTelefonicoDiario,
            $fechaInicial,
            $fechaFinal,
            $metaDiariaEquipo
        );
        $cumplimientoEfectivas = $this->calcularCumplimientoEfectivas(
            $rendimientoTelefonicoDiario,
            $fechaInicial,
            $fechaFinal,
            $cantidadActoresMeta
        );
        $rendimientoTelefonicoHoy = $this->rendimientoTelefonicoHoy(
            $rendimientoTelefonicoDiario
        );
        $institucionesActividad = $this->obtenerInstitucionesActividad(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            6,
            $actorIds
        );
        $actividadPorActor = $this->obtenerActividadPorActor(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $canal,
            $actorIds
        );

        return [
            'seguimientos_considerados' => $totalSeguimientos,
            'interacciones' => $totalInteracciones,
            'seguimientos_con_actividad' => $totalConActividad,
            'cobertura_actividad' => $totalSeguimientos > 0
                ? round(($totalConActividad / $totalSeguimientos) * 100, 1)
                : 0.0,
            'promedio_por_seguimiento' => $totalSeguimientos > 0
                ? round($totalInteracciones / $totalSeguimientos, 1)
                : 0.0,
            'canales' => $canales,
            'llamadas' => $llamadas,
            'actividad_reciente' => $actividadReciente,
            'rendimiento_telefonico_diario' => $rendimientoTelefonicoDiario,
            'rendimiento_telefonico' => $rendimientoTelefonico,
            'rendimiento_telefonico_hoy' => $rendimientoTelefonicoHoy,
            'instituciones_actividad' => $institucionesActividad,
            'actividad_por_actor' => $actividadPorActor,
            'cumplimiento_efectivas' => $cumplimientoEfectivas,
            'meta_diaria_efectivas' => 25,
            'atencion' => [
                'total' => $totalAtencion,
                'porcentaje' => $totalSeguimientos > 0
                    ? round(($totalAtencion / $totalSeguimientos) * 100, 1)
                    : 0.0,
                'casos' => array_map(static function ($item) {
                    return [
                        'id' => (int)($item['id'] ?? 0),
                        'nombre_entidad' => (string)($item['nombre_entidad'] ?? 'Institución'),
                        'municipio' => (string)($item['municipio'] ?? ''),
                        'estado_nombre' => (string)($item['estado_nombre'] ?? ''),
                        'estado_seguimiento' => (string)($item['estado_seguimiento'] ?? ''),
                        'tipo' => (string)($item['tipo_atencion'] ?? ''),
                        'motivo' => (string)($item['motivo_atencion'] ?? ''),
                        'prioridad' => (int)($item['prioridad'] ?? 0),
                        'fecha_referencia' => (string)($item['fecha_referencia'] ?? '')
                    ];
                }, $atenciones)
            ],
            'periodo' => [
                'fecha_inicial' => $fechaInicial,
                'fecha_final' => $fechaFinal,
                'canal' => $canal
            ]
        ];
    }

    private function filtrarIdsAutorizados(array $ids, $usuarioId, $modoAcceso)
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        if ($modoAcceso === 'administrador') {
            $sql = "SELECT DISTINCT s.id
                    FROM seguimientos_vinculacion s
                    WHERE s.id IN ($placeholders)
                      AND s.activo = 1";
        } elseif ($modoAcceso === 'supervisor') {
            $sql = "SELECT DISTINCT s.id
                    FROM seguimientos_vinculacion s
                    INNER JOIN asignaciones_territorio analista
                        ON analista.usuario_id = s.analista_id
                        AND analista.estado_id = s.estado_id
                        AND analista.tipo_asignacion = 'ANALISTA_DATOS'
                        AND analista.activo = 1
                        AND (analista.fecha_inicio IS NULL OR analista.fecha_inicio <= CURDATE())
                        AND (analista.fecha_fin IS NULL OR analista.fecha_fin >= CURDATE())
                    INNER JOIN asignaciones_territorio cuenta
                        ON cuenta.id = analista.cuenta_clave_asignacion_id
                        AND cuenta.estado_id = s.estado_id
                        AND cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                        AND cuenta.activo = 1
                        AND (cuenta.fecha_inicio IS NULL OR cuenta.fecha_inicio <= CURDATE())
                        AND (cuenta.fecha_fin IS NULL OR cuenta.fecha_fin >= CURDATE())
                    WHERE s.id IN ($placeholders)
                      AND s.activo = 1
                      AND cuenta.usuario_id = ?";
            $parametros[] = $usuarioId;
            $tipos .= 'i';
        } else {
            $sql = "SELECT DISTINCT s.id
                    FROM seguimientos_vinculacion s
                    WHERE s.id IN ($placeholders)
                      AND s.activo = 1
                      AND s.analista_id = ?";
            $parametros[] = $usuarioId;
            $tipos .= 'i';
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $autorizados = [];

        while ($fila = $resultado->fetch_assoc()) {
            $id = (int)($fila['id'] ?? 0);
            if ($id > 0) {
                $autorizados[$id] = $id;
            }
        }

        return array_values($autorizados);
    }

    private function obtenerResumenInteracciones(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        array $actorIds = []
    ) {
        if (empty($ids)) {
            return [
                'total_interacciones' => 0,
                'seguimientos_con_actividad' => 0,
                'llamadas' => 0,
                'correos' => 0,
                'whatsapp' => 0,
                'otros' => 0,
                'contactadas' => 0,
                'sin_respuesta' => 0,
                'numero_incorrecto' => 0,
                'volver_llamar' => 0,
                'llamadas_otros' => 0,
                'verificaciones_efectivas' => 0
            ];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    COUNT(*) AS total_interacciones,
                    COUNT(DISTINCT seguimiento_id) AS seguimientos_con_actividad,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                        THEN 1 ELSE 0
                    END) AS llamadas,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) = 'CORREO'
                        THEN 1 ELSE 0
                    END) AS correos,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) = 'WHATSAPP'
                        THEN 1 ELSE 0
                    END) AS whatsapp,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) NOT IN ('LLAMADA_IP', 'LLAMADA', 'CORREO', 'WHATSAPP')
                        THEN 1 ELSE 0
                    END) AS otros,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND (
                            notas LIKE '%[CONTACTO_EFECTIVO]%'
                            OR UPPER(TRIM(COALESCE(resultado, ''))) IN (
                                'CONTACTADO',
                                'CONTACTO_CORRECTO',
                                'CONTACTO_REFERIDO',
                                'SOLICITO_INFORMACION',
                                'SOLICITO_LLAMAR_DESPUES',
                                'NO_INTERESADO'
                            )
                         )
                         AND notas NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                        THEN 1 ELSE 0
                    END) AS contactadas,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND UPPER(TRIM(COALESCE(resultado, ''))) = 'SIN_RESPUESTA'
                        THEN 1 ELSE 0
                    END) AS sin_respuesta,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND UPPER(TRIM(COALESCE(resultado, ''))) = 'NUMERO_INCORRECTO'
                        THEN 1 ELSE 0
                    END) AS numero_incorrecto,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND UPPER(TRIM(COALESCE(resultado, ''))) = 'SOLICITO_LLAMAR_DESPUES'
                        THEN 1 ELSE 0
                    END) AS volver_llamar,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND UPPER(TRIM(COALESCE(resultado, ''))) NOT IN (
                            'CONTACTADO',
                            'SIN_RESPUESTA',
                            'NUMERO_INCORRECTO',
                            'SOLICITO_LLAMAR_DESPUES'
                         )
                        THEN 1 ELSE 0
                    END) AS llamadas_otros,
                    COUNT(DISTINCT CASE
                        WHEN UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA_IP', 'LLAMADA')
                         AND notas LIKE '%[VERIFICACION_EFECTIVA]%'
                         AND TRIM(COALESCE(proveedor_externo, '')) <> ''
                         AND TRIM(COALESCE(id_externo, '')) <> ''
                         AND COALESCE(duracion_segundos, 0) > 0
                        THEN CONCAT(usuario_id, '|', seguimiento_id, '|', DATE(fecha_inicio))
                        ELSE NULL
                    END) AS verificaciones_efectivas
                FROM interacciones_vinculacion
                WHERE seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(canal, ''))) <> 'SISTEMA'";

        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        $this->agregarFiltroActores($sql, $tipos, $parametros, $actorIds);

        if ($fechaInicial !== '') {
            $sql .= " AND fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        $this->agregarFiltroCanal($sql, $tipos, $parametros, $canal);

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function obtenerActividadReciente(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        $limite = 60,
        array $actorIds = []
    ) {
        if (empty($ids)) {
            return [];
        }

        $limite = max(1, min(100, (int)$limite));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    i.id,
                    i.seguimiento_id,
                    i.usuario_id,
                    i.canal,
                    i.resultado,
                    i.fecha_inicio,
                    i.notas,
                    i.telefono_destino,
                    i.correo_destino,
                    i.duracion_segundos,
                    i.proveedor_externo,
                    i.id_externo,
                    s.nombre_entidad,
                    TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidos, ''))) AS responsable_nombre
                FROM interacciones_vinculacion i
                INNER JOIN seguimientos_vinculacion s
                    ON s.id = i.seguimiento_id
                LEFT JOIN usuarios u
                    ON u.id = i.usuario_id
                WHERE i.seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(i.canal, ''))) <> 'SISTEMA'";

        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        $this->agregarFiltroActores($sql, $tipos, $parametros, $actorIds, 'i.');

        if ($fechaInicial !== '') {
            $sql .= " AND i.fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND i.fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        $this->agregarFiltroCanal($sql, $tipos, $parametros, $canal, 'i.');

        $sql .= " ORDER BY i.fecha_inicio DESC, i.id DESC LIMIT " . $limite;
        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerRendimientoTelefonicoDiario(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        array $actorIds = []
    ) {
        $canal = strtoupper(trim((string)$canal));
        if (
            empty($ids) ||
            ($canal !== '' && !in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true))
        ) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    DATE(fecha_inicio) AS fecha,
                    COUNT(*) AS llamadas,
                    COALESCE(SUM(
                        CASE
                            WHEN (
                                notas LIKE '%[CONTACTO_EFECTIVO]%'
                                OR UPPER(TRIM(COALESCE(resultado, ''))) IN (
                                    'CONTACTADO',
                                    'CONTACTO_CORRECTO',
                                    'CONTACTO_REFERIDO',
                                    'SOLICITO_INFORMACION',
                                    'SOLICITO_LLAMAR_DESPUES',
                                    'NO_INTERESADO'
                                )
                            )
                            AND notas NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS con_contacto,
                    COUNT(DISTINCT CASE
                        WHEN notas LIKE '%[VERIFICACION_EFECTIVA]%'
                         AND TRIM(COALESCE(proveedor_externo, '')) <> ''
                         AND TRIM(COALESCE(id_externo, '')) <> ''
                         AND COALESCE(duracion_segundos, 0) > 0
                        THEN CONCAT(usuario_id, '|', seguimiento_id)
                        ELSE NULL
                    END) AS efectivas
                FROM interacciones_vinculacion
                WHERE seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')";

        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        $this->agregarFiltroActores($sql, $tipos, $parametros, $actorIds);

        if ($fechaInicial !== '') {
            $sql .= " AND fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        $sql .= " GROUP BY DATE(fecha_inicio) ORDER BY fecha ASC";
        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        $porFecha = [];
        $resultado = $stmt->get_result();
        while ($fila = $resultado->fetch_assoc()) {
            $fecha = (string)($fila['fecha'] ?? '');
            if ($fecha === '') {
                continue;
            }

            $llamadas = max(0, (int)($fila['llamadas'] ?? 0));
            $contacto = max(0, (int)($fila['con_contacto'] ?? 0));
            $efectivas = max(0, (int)($fila['efectivas'] ?? 0));
            $porFecha[$fecha] = [
                'fecha' => $fecha,
                'llamadas' => $llamadas,
                'con_contacto' => $contacto,
                'efectivas' => $efectivas,
                'tasa_contacto' => $llamadas > 0
                    ? round(($contacto / $llamadas) * 100, 1)
                    : 0.0,
                'meta' => 25,
                'cumplimiento_pct' => round(min(100, ($efectivas / 25) * 100), 1)
            ];
        }

        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicio = new DateTimeImmutable($fechaInicial);
                $fin = new DateTimeImmutable($fechaFinal);
                $dias = ((int)$inicio->diff($fin)->days) + 1;

                if ($inicio <= $fin && $dias <= 62) {
                    $completo = [];
                    for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
                        $clave = $fecha->format('Y-m-d');
                        $completo[] = $porFecha[$clave] ?? [
                            'fecha' => $clave,
                            'llamadas' => 0,
                            'con_contacto' => 0,
                            'efectivas' => 0,
                            'tasa_contacto' => 0.0,
                            'meta' => 25,
                            'cumplimiento_pct' => 0.0
                        ];
                    }

                    return $completo;
                }
            } catch (Throwable $error) {
                // Si el periodo no puede expandirse, se conservan únicamente los días con datos.
            }
        }

        return array_values($porFecha);
    }

    private function resumirRendimientoTelefonico(
        array $diasRegistrados,
        $fechaInicial,
        $fechaFinal,
        $metaDiaria = 25
    ) {
        $metaDiaria = max(1, (int)$metaDiaria);
        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);

        if ($fechaInicial === '' || $fechaFinal === '') {
            return [
                'granularidad' => 'dia',
                'periodos' => $diasRegistrados
            ];
        }

        try {
            $inicio = new DateTimeImmutable($fechaInicial);
            $fin = new DateTimeImmutable($fechaFinal);
        } catch (Throwable $error) {
            return [
                'granularidad' => 'dia',
                'periodos' => $diasRegistrados
            ];
        }

        if ($inicio > $fin) {
            return [
                'granularidad' => 'dia',
                'periodos' => []
            ];
        }

        $porFecha = [];
        foreach ($diasRegistrados as $dia) {
            $clave = trim((string)($dia['fecha'] ?? ''));
            if ($clave !== '') {
                $porFecha[$clave] = $dia;
            }
        }

        $diasPeriodo = ((int)$inicio->diff($fin)->days) + 1;
        $granularidad = $diasPeriodo <= 14
            ? 'dia'
            : ($diasPeriodo <= 90 ? 'semana' : 'mes');

        if ($granularidad === 'dia') {
            $periodos = [];
            for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
                $clave = $fecha->format('Y-m-d');
                $base = $porFecha[$clave] ?? [
                    'fecha' => $clave,
                    'llamadas' => 0,
                    'con_contacto' => 0,
                    'efectivas' => 0,
                    'tasa_contacto' => 0.0,
                    'meta' => 25,
                    'cumplimiento_pct' => 0.0
                ];
                $base['meta'] = $metaDiaria;
                $base['cumplimiento_pct'] = round(
                    min(100, ((int)($base['efectivas'] ?? 0) / $metaDiaria) * 100),
                    1
                );
                $base['clave'] = $clave;
                $base['etiqueta'] = $fecha->format('d/m/Y');
                $base['subetiqueta'] = '';
                $base['fecha_inicio'] = $clave;
                $base['fecha_fin'] = $clave;
                $periodos[] = $base;
            }

            return [
                'granularidad' => 'dia',
                'periodos' => $periodos
            ];
        }

        if ($granularidad === 'semana') {
            $periodos = [];
            $inicioBloque = $inicio;
            $numero = 1;

            while ($inicioBloque <= $fin) {
                $finBloque = $inicioBloque->modify('+6 days');
                if ($finBloque > $fin) {
                    $finBloque = $fin;
                }

                $totales = $this->sumarRendimientoEntre(
                    $porFecha,
                    $inicioBloque,
                    $finBloque
                );
                $periodos[] = array_merge($totales, [
                    'clave' => 'semana_' . $numero,
                    'etiqueta' => 'Semana ' . $numero,
                    'subetiqueta' =>
                        $inicioBloque->format('d/m') . ' - ' . $finBloque->format('d/m'),
                    'fecha_inicio' => $inicioBloque->format('Y-m-d'),
                    'fecha_fin' => $finBloque->format('Y-m-d'),
                    'meta' => null,
                    'cumplimiento_pct' => null
                ]);

                $numero++;
                $inicioBloque = $finBloque->modify('+1 day');
            }

            return [
                'granularidad' => 'semana',
                'periodos' => $periodos
            ];
        }

        $periodos = [];
        $mes = $inicio->modify('first day of this month');
        $ultimoMes = $fin->modify('first day of this month');

        while ($mes <= $ultimoMes) {
            $inicioBloque = $mes < $inicio ? $inicio : $mes;
            $finBloque = $mes->modify('last day of this month');
            if ($finBloque > $fin) {
                $finBloque = $fin;
            }

            $totales = $this->sumarRendimientoEntre(
                $porFecha,
                $inicioBloque,
                $finBloque
            );
            $periodos[] = array_merge($totales, [
                'clave' => $mes->format('Y-m'),
                'etiqueta' => $this->mesCortoReporte((int)$mes->format('n')) . ' ' . $mes->format('Y'),
                'subetiqueta' => '',
                'fecha_inicio' => $inicioBloque->format('Y-m-d'),
                'fecha_fin' => $finBloque->format('Y-m-d'),
                'meta' => null,
                'cumplimiento_pct' => null
            ]);

            $mes = $mes->modify('first day of next month');
        }

        return [
            'granularidad' => 'mes',
            'periodos' => $periodos
        ];
    }

    private function sumarRendimientoEntre(
        array $porFecha,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin
    ) {
        $llamadas = 0;
        $contacto = 0;
        $efectivas = 0;

        for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
            $fila = $porFecha[$fecha->format('Y-m-d')] ?? [];
            $llamadas += (int)($fila['llamadas'] ?? 0);
            $contacto += (int)($fila['con_contacto'] ?? 0);
            $efectivas += (int)($fila['efectivas'] ?? 0);
        }

        return [
            'llamadas' => $llamadas,
            'con_contacto' => $contacto,
            'efectivas' => $efectivas,
            'tasa_contacto' => $llamadas > 0
                ? round(($contacto / $llamadas) * 100, 1)
                : 0.0
        ];
    }

    private function rendimientoTelefonicoHoy(array $diasRegistrados)
    {
        $hoy = date('Y-m-d');

        foreach ($diasRegistrados as $dia) {
            if ((string)($dia['fecha'] ?? '') === $hoy) {
                return [
                    'fecha' => $hoy,
                    'llamadas' => (int)($dia['llamadas'] ?? 0),
                    'con_contacto' => (int)($dia['con_contacto'] ?? 0),
                    'efectivas' => (int)($dia['efectivas'] ?? 0)
                ];
            }
        }

        return [
            'fecha' => $hoy,
            'llamadas' => 0,
            'con_contacto' => 0,
            'efectivas' => 0
        ];
    }

    private function mesCortoReporte($mes)
    {
        $meses = [
            1 => 'Ene',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Abr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dic'
        ];

        return $meses[(int)$mes] ?? '';
    }

    private function obtenerInstitucionesActividad(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        $limite = 6,
        array $actorIds = []
    ) {
        if (empty($ids)) {
            return [];
        }

        $limite = max(1, min(12, (int)$limite));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    i.seguimiento_id,
                    s.nombre_entidad,
                    COALESCE(m.nombre, '') AS municipio,
                    COUNT(*) AS interacciones,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                        THEN 1 ELSE 0
                    END) AS llamadas,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) = 'CORREO'
                        THEN 1 ELSE 0
                    END) AS correos,
                    SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                         AND (
                            i.notas LIKE '%[CONTACTO_EFECTIVO]%'
                            OR UPPER(TRIM(COALESCE(i.resultado, ''))) IN (
                                'CONTACTADO',
                                'CONTACTO_CORRECTO',
                                'CONTACTO_REFERIDO',
                                'SOLICITO_INFORMACION',
                                'SOLICITO_LLAMAR_DESPUES',
                                'NO_INTERESADO'
                            )
                         )
                         AND i.notas NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                        THEN 1 ELSE 0
                    END) AS con_contacto,
                    COUNT(DISTINCT CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                         AND i.notas LIKE '%[VERIFICACION_EFECTIVA]%'
                         AND TRIM(COALESCE(i.proveedor_externo, '')) <> ''
                         AND TRIM(COALESCE(i.id_externo, '')) <> ''
                         AND COALESCE(i.duracion_segundos, 0) > 0
                        THEN DATE(i.fecha_inicio)
                        ELSE NULL
                    END) AS efectivas,
                    MAX(i.fecha_inicio) AS ultima_actividad
                FROM interacciones_vinculacion i
                INNER JOIN seguimientos_vinculacion s
                    ON s.id = i.seguimiento_id
                LEFT JOIN municipios m
                    ON m.id = s.municipio_id
                WHERE i.seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(i.canal, ''))) <> 'SISTEMA'";

        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        $this->agregarFiltroActores($sql, $tipos, $parametros, $actorIds, 'i.');

        if ($fechaInicial !== '') {
            $sql .= " AND i.fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND i.fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        $this->agregarFiltroCanal($sql, $tipos, $parametros, $canal, 'i.');
        $sql .= " GROUP BY i.seguimiento_id, s.nombre_entidad, m.nombre
                  ORDER BY interacciones DESC, ultima_actividad DESC
                  LIMIT " . $limite;

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcularCumplimientoEfectivas(
        array $diasRegistrados,
        $fechaInicial,
        $fechaFinal,
        $cantidadActores
    ) {
        $metaPorAnalista = 25;
        $cantidadActores = max(1, (int)$cantidadActores);
        $metaDiariaEquipo = $metaPorAnalista * $cantidadActores;
        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);
        $diasEvaluados = 0;

        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicio = new DateTimeImmutable($fechaInicial);
                $fin = new DateTimeImmutable($fechaFinal);
                if ($inicio <= $fin) {
                    $diasEvaluados = ((int)$inicio->diff($fin)->days) + 1;
                }
            } catch (Throwable $error) {
                $diasEvaluados = 0;
            }
        }

        if ($diasEvaluados <= 0) {
            $diasEvaluados = max(1, count($diasRegistrados));
        }

        $efectivas = 0;
        $diasCumplidos = 0;
        foreach ($diasRegistrados as $dia) {
            $efectivasDia = max(0, (int)($dia['efectivas'] ?? 0));
            $efectivas += $efectivasDia;
            if ($efectivasDia >= $metaDiariaEquipo) {
                $diasCumplidos++;
            }
        }

        $metaPeriodo = $metaDiariaEquipo * $diasEvaluados;
        $cumplimiento = $metaPeriodo > 0
            ? round(($efectivas / $metaPeriodo) * 100, 1)
            : 0.0;
        $promedioPorAnalistaDia = ($diasEvaluados * $cantidadActores) > 0
            ? round($efectivas / ($diasEvaluados * $cantidadActores), 1)
            : 0.0;

        return [
            'meta_diaria_por_analista' => $metaPorAnalista,
            'analistas_evaluados' => $cantidadActores,
            'meta_diaria_equipo' => $metaDiariaEquipo,
            'dias_evaluados' => $diasEvaluados,
            'meta_periodo' => $metaPeriodo,
            'efectivas' => $efectivas,
            'cumplimiento_pct' => $cumplimiento,
            'promedio_diario_por_analista' => $promedioPorAnalistaDia,
            'dias_cumplidos' => $diasCumplidos
        ];
    }

    private function obtenerActividadPorActor(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $canal,
        array $actorIds
    ) {
        $ids = $this->normalizarIds($ids);
        $actorIds = $this->normalizarIds($actorIds);

        if (empty($ids) || empty($actorIds)) {
            return [];
        }

        $seguimientoPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $actorPlaceholders = implode(',', array_fill(0, count($actorIds), '?'));

        $sql = "SELECT
                    u.id AS usuario_id,
                    TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidos, ''))) AS analista_nombre,
                    COUNT(i.id) AS interacciones,
                    COUNT(DISTINCT i.seguimiento_id) AS instituciones,
                    COALESCE(SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                        THEN 1 ELSE 0
                    END), 0) AS llamadas,
                    COALESCE(SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) = 'CORREO'
                        THEN 1 ELSE 0
                    END), 0) AS correos,
                    COALESCE(SUM(CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                         AND (
                            i.notas LIKE '%[CONTACTO_EFECTIVO]%'
                            OR UPPER(TRIM(COALESCE(i.resultado, ''))) IN (
                                'CONTACTADO',
                                'CONTACTO_CORRECTO',
                                'CONTACTO_REFERIDO',
                                'SOLICITO_INFORMACION',
                                'SOLICITO_LLAMAR_DESPUES',
                                'NO_INTERESADO'
                            )
                         )
                         AND i.notas NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                        THEN 1 ELSE 0
                    END), 0) AS con_contacto,
                    COUNT(DISTINCT CASE
                        WHEN UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')
                         AND i.notas LIKE '%[VERIFICACION_EFECTIVA]%'
                         AND TRIM(COALESCE(i.proveedor_externo, '')) <> ''
                         AND TRIM(COALESCE(i.id_externo, '')) <> ''
                         AND COALESCE(i.duracion_segundos, 0) > 0
                        THEN CONCAT(i.seguimiento_id, '|', DATE(i.fecha_inicio))
                        ELSE NULL
                    END) AS efectivas
                FROM usuarios u
                LEFT JOIN interacciones_vinculacion i
                    ON i.usuario_id = u.id
                    AND i.seguimiento_id IN (" . $seguimientoPlaceholders . ")
                    AND UPPER(TRIM(COALESCE(i.canal, ''))) <> 'SISTEMA'";

        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        if ($fechaInicial !== '') {
            $sql .= " AND i.fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND i.fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        $canal = strtoupper(trim((string)$canal));
        if ($canal !== '') {
            if (in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true)) {
                $sql .= " AND UPPER(TRIM(COALESCE(i.canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')";
            } elseif ($canal === 'NOTA') {
                $sql .= " AND UPPER(TRIM(COALESCE(i.canal, ''))) NOT IN ('SISTEMA', 'LLAMADA', 'LLAMADA_IP', 'CORREO', 'WHATSAPP')";
            } else {
                $sql .= " AND UPPER(TRIM(COALESCE(i.canal, ''))) = ?";
                $parametros[] = $canal;
                $tipos .= 's';
            }
        }

        $sql .= " WHERE u.id IN (" . $actorPlaceholders . ")
                  GROUP BY u.id, u.nombre, u.apellidos
                  ORDER BY u.nombre, u.apellidos";

        foreach ($actorIds as $actorId) {
            $parametros[] = (int)$actorId;
            $tipos .= 'i';
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $diasEvaluados = 1;
        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicioMeta = new DateTimeImmutable($fechaInicial);
                $finMeta = new DateTimeImmutable($fechaFinal);
                if ($inicioMeta <= $finMeta) {
                    $diasEvaluados = ((int)$inicioMeta->diff($finMeta)->days) + 1;
                }
            } catch (Throwable $error) {
                $diasEvaluados = 1;
            }
        }
        $metaPeriodoAnalista = 25 * max(1, $diasEvaluados);

        foreach ($filas as &$fila) {
            $llamadas = max(0, (int)($fila['llamadas'] ?? 0));
            $contactos = max(0, (int)($fila['con_contacto'] ?? 0));
            $fila['usuario_id'] = (int)($fila['usuario_id'] ?? 0);
            $fila['interacciones'] = max(0, (int)($fila['interacciones'] ?? 0));
            $fila['instituciones'] = max(0, (int)($fila['instituciones'] ?? 0));
            $fila['llamadas'] = $llamadas;
            $fila['correos'] = max(0, (int)($fila['correos'] ?? 0));
            $fila['con_contacto'] = $contactos;
            $fila['efectivas'] = max(0, (int)($fila['efectivas'] ?? 0));
            $fila['meta_efectivas_periodo'] = $metaPeriodoAnalista;
            $fila['cumplimiento_efectivas_pct'] = $metaPeriodoAnalista > 0
                ? round(($fila['efectivas'] / $metaPeriodoAnalista) * 100, 1)
                : 0.0;
            $fila['tasa_contacto'] = $llamadas > 0
                ? round(($contactos / $llamadas) * 100, 1)
                : 0.0;
        }
        unset($fila);

        return $filas;
    }

    private function agregarFiltroActores(
        string &$sql,
        string &$tipos,
        array &$parametros,
        array $actorIds,
        $prefijo = ''
    ) {
        $actorIds = $this->normalizarIds($actorIds);
        if (empty($actorIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
        $sql .= " AND " . $prefijo . "usuario_id IN (" . $placeholders . ")";

        foreach ($actorIds as $actorId) {
            $parametros[] = (int)$actorId;
            $tipos .= 'i';
        }
    }

    private function agregarFiltroCanal(
        string &$sql,
        string &$tipos,
        array &$parametros,
        $canal,
        $prefijo = ''
    ) {
        $canal = strtoupper(trim((string)$canal));
        if ($canal === '') {
            return;
        }

        $campo = $prefijo . 'canal';

        if (in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true)) {
            $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) IN ('LLAMADA', 'LLAMADA_IP')";
            return;
        }

        if ($canal === 'NOTA') {
            $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) NOT IN ('SISTEMA', 'LLAMADA', 'LLAMADA_IP', 'CORREO', 'WHATSAPP')";
            return;
        }

        $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) = ?";
        $parametros[] = $canal;
        $tipos .= 's';
    }

    private function normalizarIds(array $ids)
    {
        $normalizados = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalizados[$id] = $id;
            }

            if (count($normalizados) >= 200) {
                break;
            }
        }

        return array_values($normalizados);
    }

    private function normalizarFecha($valor)
    {
        $valor = trim((string)$valor);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return '';
        }

        try {
            $fecha = new DateTimeImmutable($valor);
        } catch (Throwable $error) {
            return '';
        }

        return $fecha->format('Y-m-d') === $valor ? $valor : '';
    }

    private function vincularParametros($stmt, $tipos, array $parametros)
    {
        if ($tipos === '') {
            return;
        }

        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
    }

    private function estructuraVacia()
    {
        return [
            'seguimientos_considerados' => 0,
            'interacciones' => 0,
            'seguimientos_con_actividad' => 0,
            'cobertura_actividad' => 0.0,
            'promedio_por_seguimiento' => 0.0,
            'canales' => [
                'llamadas' => 0,
                'correos' => 0,
                'whatsapp' => 0,
                'otros' => 0
            ],
            'llamadas' => [
                'total' => 0,
                'contactadas' => 0,
                'sin_respuesta' => 0,
                'numero_incorrecto' => 0,
                'volver_llamar' => 0,
                'otros' => 0,
                'verificaciones_efectivas' => 0,
                'tasa_contacto' => 0.0
            ],
            'actividad_reciente' => [],
            'rendimiento_telefonico_diario' => [],
            'rendimiento_telefonico' => [
                'granularidad' => 'dia',
                'periodos' => []
            ],
            'rendimiento_telefonico_hoy' => [
                'fecha' => date('Y-m-d'),
                'llamadas' => 0,
                'con_contacto' => 0,
                'efectivas' => 0
            ],
            'instituciones_actividad' => [],
            'actividad_por_actor' => [],
            'cumplimiento_efectivas' => [
                'meta_diaria_por_analista' => 25,
                'analistas_evaluados' => 1,
                'meta_diaria_equipo' => 25,
                'dias_evaluados' => 0,
                'meta_periodo' => 0,
                'efectivas' => 0,
                'cumplimiento_pct' => 0.0,
                'promedio_diario_por_analista' => 0.0,
                'dias_cumplidos' => 0
            ],
            'meta_diaria_efectivas' => 25,
            'atencion' => [
                'total' => 0,
                'porcentaje' => 0.0,
                'casos' => []
            ],
            'periodo' => [
                'fecha_inicial' => '',
                'fecha_final' => '',
                'canal' => ''
            ]
        ];
    }
}
