<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReunionResultadoService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function validarResultadoReunion($datos)
    {
        $resultado = strtoupper(trim((string)($datos['reunion_resultado'] ?? '')));

        if ($resultado !== 'REQUIERE_SEGUIMIENTO') {
            return ['ok' => true];
        }

        $fecha = $this->normalizarFechaHora($datos['reunion_seguimiento_fecha'] ?? '');
        if ($fecha === null) {
            return $this->error(
                'Indica cuándo debe realizarse el seguimiento de los acuerdos.',
                422
            );
        }

        if (strtotime($fecha) <= time()) {
            return $this->error(
                'La fecha del seguimiento debe ser posterior a la fecha y hora actual.',
                422
            );
        }

        return [
            'ok' => true,
            'fecha_seguimiento' => $fecha
        ];
    }

    public function programarSeguimientoTrasReunion($seguimientoId, $analistaId, $datos)
    {
        $resultado = strtoupper(trim((string)($datos['reunion_resultado'] ?? '')));

        if ($resultado !== 'REQUIERE_SEGUIMIENTO') {
            return;
        }

        $fecha = $this->normalizarFechaHora($datos['reunion_seguimiento_fecha'] ?? '');
        if ($fecha === null) {
            return;
        }

        $sql = "UPDATE seguimientos_vinculacion
                SET proxima_accion_at = ?,
                    ultima_interaccion_at = NOW()
                WHERE id = ?
                  AND analista_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $fecha, $seguimientoId, $analistaId);
        $stmt->execute();

        $this->registrarInteraccion(
            $seguimientoId,
            $analistaId,
            'Seguimiento de acuerdos programado para ' . $fecha . '.'
        );
    }

    public function ajustarFlujo($seguimientoId, $analistaId, $flujo)
    {
        if (!is_array($flujo)) {
            return $flujo;
        }

        $estado = $this->obtenerEstado($seguimientoId, $analistaId);
        if (!$estado) {
            return $flujo;
        }

        $resultado = strtoupper(trim((string)($estado['reunion_resultado'] ?? '')));
        $realizadaAt = trim((string)($estado['reunion_realizada_at'] ?? ''));

        if ($realizadaAt === '' || $resultado !== 'REQUIERE_SEGUIMIENTO') {
            return $flujo;
        }

        $fecha = trim((string)($estado['proxima_accion_at'] ?? ''));
        $fechaLegible = $this->fechaLegible($fecha);
        $disponible = $fecha === '' || strtotime($fecha) <= time();

        $flujo['paso_actual'] = 12;
        $flujo['total_pasos'] = 13;
        $flujo['porcentaje'] = (int)round((12 / 13) * 100);
        $flujo['accion_secundaria'] = null;
        $flujo['ventana'] = [
            'anterior' => [
                'numero' => 11,
                'clave' => 'REUNION_AGENDADA',
                'titulo' => 'Reunión agendada'
            ],
            'actual' => [
                'numero' => 12,
                'clave' => 'REUNION_REALIZADA',
                'titulo' => 'Reunión realizada'
            ],
            'siguiente' => [
                'numero' => 13,
                'clave' => 'CONVENIO',
                'titulo' => 'Convenio formalizado'
            ]
        ];
        $flujo['contexto'] = is_array($flujo['contexto'] ?? null)
            ? $flujo['contexto']
            : [];
        $flujo['contexto']['seguimiento_reunion_fecha'] = $fecha;
        $flujo['contexto']['seguimiento_reunion_disponible'] = $disponible;

        if (!$disponible) {
            $flujo['titulo'] = 'Seguimiento de acuerdos programado';
            $flujo['descripcion'] = $fechaLegible !== ''
                ? 'La reunión ya fue realizada y requiere seguimiento. La próxima revisión está programada para ' . $fechaLegible . '.'
                : 'La reunión ya fue realizada y requiere un seguimiento posterior.';
            $flujo['accion_principal'] = [
                'codigo' => 'SEGUIMIENTO_REUNION_AUN_NO_DISPONIBLE',
                'etiqueta' => 'Registrar seguimiento',
                'icono' => 'bi-clock-history',
                'deshabilitada' => true
            ];

            return $flujo;
        }

        $flujo['titulo'] = 'Dar seguimiento a acuerdos';
        $flujo['descripcion'] = 'La reunión ya fue realizada. Registra el seguimiento de los acuerdos para decidir si se avanza al convenio, se programa otro seguimiento o se cierra el caso.';
        $flujo['accion_principal'] = [
            'codigo' => 'REGISTRAR_SEGUIMIENTO_REUNION',
            'etiqueta' => 'Registrar seguimiento',
            'icono' => 'bi-clipboard-check'
        ];

        return $flujo;
    }

    public function registrarSeguimiento($seguimientoId, $analistaId, $datos)
    {
        $estado = $this->obtenerEstado($seguimientoId, $analistaId);
        if (!$estado) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (trim((string)($estado['reunion_realizada_at'] ?? '')) === '' ||
            strtoupper(trim((string)($estado['reunion_resultado'] ?? ''))) !== 'REQUIERE_SEGUIMIENTO') {
            return $this->error(
                'Este seguimiento no está esperando una revisión posterior a la reunión.',
                409
            );
        }

        $fechaActual = trim((string)($estado['proxima_accion_at'] ?? ''));
        if ($fechaActual !== '' && strtotime($fechaActual) > time()) {
            return $this->error(
                'El seguimiento de acuerdos todavía no está disponible. Está programado para ' . $this->fechaLegible($fechaActual) . '.',
                409
            );
        }

        $resultado = strtoupper(trim((string)($datos['seguimiento_reunion_resultado'] ?? '')));
        $notas = trim((string)($datos['seguimiento_reunion_notas'] ?? ''));
        $resultados = ['AVANZAR_CONVENIO', 'REQUIERE_SEGUIMIENTO', 'NO_INTERESADO'];

        if (!in_array($resultado, $resultados, true)) {
            return $this->error('Selecciona el resultado del seguimiento.', 422);
        }
        if ($notas === '') {
            return $this->error('Registra brevemente qué ocurrió durante el seguimiento.', 422);
        }

        $nuevaFecha = null;
        if ($resultado === 'REQUIERE_SEGUIMIENTO') {
            $nuevaFecha = $this->normalizarFechaHora($datos['seguimiento_reunion_fecha'] ?? '');
            if ($nuevaFecha === null || strtotime($nuevaFecha) <= time()) {
                return $this->error(
                    'Indica una nueva fecha futura para continuar el seguimiento.',
                    422
                );
            }
        }

        $this->connection->begin_transaction();

        try {
            $this->registrarInteraccion(
                $seguimientoId,
                $analistaId,
                'Seguimiento posterior a reunión [' . $resultado . ']: ' . $notas
            );

            if ($resultado === 'AVANZAR_CONVENIO') {
                $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                            SET reunion_resultado = 'AVANZAR_CONVENIO'
                            WHERE seguimiento_id = ?";
                $stmtPost = $this->connection->prepare($sqlPost);
                $stmtPost->bind_param('i', $seguimientoId);
                $stmtPost->execute();

                $this->actualizarSeguimientoPrincipal(
                    $seguimientoId,
                    $analistaId,
                    null,
                    null
                );
            } elseif ($resultado === 'NO_INTERESADO') {
                $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                            SET reunion_resultado = 'NO_INTERESADO'
                            WHERE seguimiento_id = ?";
                $stmtPost = $this->connection->prepare($sqlPost);
                $stmtPost->bind_param('i', $seguimientoId);
                $stmtPost->execute();

                $motivo = mb_substr($notas, 0, 255);
                $sqlSeg = "UPDATE seguimientos_vinculacion
                           SET estado_seguimiento = 'DESCARTADO',
                               motivo_descarte = ?,
                               ultima_interaccion_at = NOW(),
                               proxima_accion_at = NULL
                           WHERE id = ? AND analista_id = ?";
                $stmtSeg = $this->connection->prepare($sqlSeg);
                $stmtSeg->bind_param('sii', $motivo, $seguimientoId, $analistaId);
                $stmtSeg->execute();
            } else {
                $this->actualizarSeguimientoPrincipal(
                    $seguimientoId,
                    $analistaId,
                    $nuevaFecha,
                    null
                );
            }

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Seguimiento posterior a reunión: ' . $error->getMessage());
            return $this->error('No fue posible guardar el seguimiento de acuerdos.', 500);
        }

        $mensajes = [
            'AVANZAR_CONVENIO' => 'Seguimiento registrado. El caso puede avanzar al convenio.',
            'REQUIERE_SEGUIMIENTO' => 'Seguimiento registrado. Se programó una nueva revisión de acuerdos.',
            'NO_INTERESADO' => 'Seguimiento cerrado como no interesado.'
        ];

        return [
            'ok' => true,
            'mensaje' => $mensajes[$resultado] ?? 'Seguimiento registrado correctamente.'
        ];
    }

    public function validarFormalizacion($seguimientoId, $analistaId)
    {
        $estado = $this->obtenerEstado($seguimientoId, $analistaId);
        if (!$estado) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (strtoupper(trim((string)($estado['reunion_resultado'] ?? ''))) !== 'AVANZAR_CONVENIO') {
            return $this->error(
                'El resultado de la reunión todavía no permite formalizar un convenio.',
                409
            );
        }

        return ['ok' => true];
    }

    private function obtenerEstado($seguimientoId, $analistaId)
    {
        $sql = "SELECT
                    s.id,
                    s.estado_seguimiento,
                    s.proxima_accion_at,
                    p.reunion_resultado,
                    p.reunion_realizada_at
                FROM seguimientos_vinculacion s
                LEFT JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                WHERE s.id = ?
                  AND s.analista_id = ?
                  AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function registrarInteraccion($seguimientoId, $usuarioId, $notas)
    {
        $sql = "INSERT INTO interacciones_vinculacion (
                    seguimiento_id,
                    usuario_id,
                    canal,
                    fecha_inicio,
                    resultado,
                    notas
                ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iis', $seguimientoId, $usuarioId, $notas);
        $stmt->execute();
    }

    private function actualizarSeguimientoPrincipal($seguimientoId, $analistaId, $proximaAccion, $motivo)
    {
        $sql = "UPDATE seguimientos_vinculacion
                SET ultima_interaccion_at = NOW(),
                    proxima_accion_at = ?,
                    motivo_descarte = ?
                WHERE id = ?
                  AND analista_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssii', $proximaAccion, $motivo, $seguimientoId, $analistaId);
        $stmt->execute();
    }

    private function normalizarFechaHora($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $formato) {
            $fecha = DateTime::createFromFormat($formato, $valor);
            if ($fecha instanceof DateTime) {
                return $fecha->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function fechaLegible($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return $valor;
        }
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
