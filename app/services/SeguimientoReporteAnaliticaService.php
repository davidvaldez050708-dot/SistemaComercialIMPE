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

    public function construir(array $seguimientoIds, $usuarioId, $modoAcceso, $fechaInicial = '', $fechaFinal = '')
    {
        $seguimientoIds = $this->normalizarIds($seguimientoIds);
        $usuarioId = (int)$usuarioId;
        $modoAcceso = (string)$modoAcceso;

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
        $interacciones = $this->obtenerInteracciones(
            $autorizados,
            $fechaInicial,
            $fechaFinal
        );

        $canales = [
            'llamadas' => 0,
            'correos' => 0,
            'whatsapp' => 0,
            'otros' => 0
        ];
        $llamadas = [
            'total' => 0,
            'contactadas' => 0,
            'sin_respuesta' => 0,
            'numero_incorrecto' => 0,
            'volver_llamar' => 0,
            'otros' => 0,
            'tasa_contacto' => 0.0
        ];
        $seguimientosConActividad = [];

        foreach ($interacciones as $interaccion) {
            $seguimientoId = (int)($interaccion['seguimiento_id'] ?? 0);
            if ($seguimientoId > 0) {
                $seguimientosConActividad[$seguimientoId] = true;
            }

            $canal = strtoupper(trim((string)($interaccion['canal'] ?? '')));
            $resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));

            if (in_array($canal, ['LLAMADA_IP', 'LLAMADA'], true)) {
                $canales['llamadas']++;
                $llamadas['total']++;

                if ($resultado === 'CONTACTADO') {
                    $llamadas['contactadas']++;
                } elseif ($resultado === 'SIN_RESPUESTA') {
                    $llamadas['sin_respuesta']++;
                } elseif ($resultado === 'NUMERO_INCORRECTO') {
                    $llamadas['numero_incorrecto']++;
                } elseif ($resultado === 'SOLICITO_LLAMAR_DESPUES') {
                    $llamadas['volver_llamar']++;
                } else {
                    $llamadas['otros']++;
                }

                continue;
            }

            if ($canal === 'CORREO') {
                $canales['correos']++;
            } elseif ($canal === 'WHATSAPP') {
                $canales['whatsapp']++;
            } else {
                $canales['otros']++;
            }
        }

        if ($llamadas['total'] > 0) {
            $llamadas['tasa_contacto'] = round(
                ($llamadas['contactadas'] / $llamadas['total']) * 100,
                1
            );
        }

        $totalSeguimientos = count($autorizados);
        $totalInteracciones = count($interacciones);
        $totalConActividad = count($seguimientosConActividad);
        $atenciones = (new SeguimientoAtencionOperativaService())->obtenerPorIds($autorizados);
        $totalAtencion = count($atenciones);

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
                'fecha_final' => $fechaFinal
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

    private function obtenerInteracciones(array $ids, $fechaInicial, $fechaFinal)
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT seguimiento_id, canal, resultado, fecha_inicio
                FROM interacciones_vinculacion
                WHERE seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(canal, ''))) <> 'SISTEMA'";
        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

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

        $sql .= " ORDER BY fecha_inicio ASC, id ASC";
        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
                'tasa_contacto' => 0.0
            ],
            'atencion' => [
                'total' => 0,
                'porcentaje' => 0.0,
                'casos' => []
            ],
            'periodo' => [
                'fecha_inicial' => '',
                'fecha_final' => ''
            ]
        ];
    }
}
