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
        $resultado = strtoupper(
            trim((string)($datos['reunion_resultado'] ?? ''))
        );

        if ($resultado !== 'REQUIERE_SEGUIMIENTO') {
            return ['ok' => true];
        }

        $fecha = $this->normalizarFechaHora(
            $datos['reunion_seguimiento_fecha'] ?? ''
        );

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

        $contexto = $this->validarContextoSeguimiento(
            $datos,
            'reunion'
        );

        if (!($contexto['ok'] ?? false)) {
            return $contexto;
        }

        $contexto['fecha_seguimiento'] = $fecha;

        return $contexto;
    }

    public function programarSeguimientoTrasReunion(
        $seguimientoId,
        $analistaId,
        $datos
    ) {
        $resultado = strtoupper(
            trim((string)($datos['reunion_resultado'] ?? ''))
        );

        if ($resultado !== 'REQUIERE_SEGUIMIENTO') {
            return;
        }

        $fecha = $this->normalizarFechaHora(
            $datos['reunion_seguimiento_fecha'] ?? ''
        );
        $contexto = $this->extraerContextoSeguimiento(
            $datos,
            'reunion'
        );

        if ($fecha === null || $contexto['objetivo'] === '') {
            return;
        }

        $sql = "UPDATE seguimientos_vinculacion
                SET proxima_accion_at = ?,
                    ultima_interaccion_at = NOW()
                WHERE id = ?
                  AND analista_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'sii',
            $fecha,
            $seguimientoId,
            $analistaId
        );
        $stmt->execute();

        $this->actualizarContextoSeguimiento(
            $seguimientoId,
            $contexto
        );

        $this->registrarInteraccion(
            $seguimientoId,
            $analistaId,
            'Seguimiento de acuerdos programado para ' . $fecha .
            '. Pendiente: ' . $contexto['objetivo'] .
            '. Acción prevista: ' .
            $this->etiquetaAccionSeguimiento($contexto['accion']) .
            '. Pendiente de: ' .
            $this->etiquetaPendienteDe($contexto['pendiente_de']) . '.'
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
        $objetivo = trim((string)(
            $estado['reunion_seguimiento_objetivo'] ?? ''
        ));
        $pendienteDe = strtoupper(trim((string)(
            $estado['reunion_seguimiento_pendiente_de'] ?? ''
        )));
        $accionSeguimiento = strtoupper(trim((string)(
            $estado['reunion_seguimiento_accion'] ?? ''
        )));
        $pendienteEtiqueta = $this->etiquetaPendienteDe($pendienteDe);
        $accionEtiqueta = $this->etiquetaAccionSeguimiento(
            $accionSeguimiento
        );

        $flujo['contexto']['seguimiento_reunion_fecha'] = $fecha;
        $flujo['contexto']['seguimiento_reunion_disponible'] = $disponible;
        $flujo['contexto']['seguimiento_reunion_objetivo'] = $objetivo;
        $flujo['contexto']['seguimiento_reunion_pendiente_de'] =
            $pendienteDe;
        $flujo['contexto']['seguimiento_reunion_pendiente_de_label'] =
            $pendienteEtiqueta;
        $flujo['contexto']['seguimiento_reunion_accion'] =
            $accionSeguimiento;
        $flujo['contexto']['seguimiento_reunion_accion_label'] =
            $accionEtiqueta;

        $detalleContexto = $objetivo !== ''
            ? ' Pendiente: ' . $objetivo . '.'
            : '';
        if ($accionEtiqueta !== '') {
            $detalleContexto .= ' Acción prevista: ' .
                $accionEtiqueta . '.';
        }
        if ($pendienteEtiqueta !== '') {
            $detalleContexto .= ' Pendiente de: ' .
                $pendienteEtiqueta . '.';
        }

        if (!$disponible) {
            $flujo['titulo'] = $accionEtiqueta !== ''
                ? 'Seguimiento programado · ' . $accionEtiqueta
                : 'Seguimiento de acuerdos programado';
            $flujo['descripcion'] = $fechaLegible !== ''
                ? 'La reunión ya fue realizada y requiere seguimiento.' .
                    $detalleContexto .
                    ' Próxima revisión: ' . $fechaLegible . '.'
                : 'La reunión ya fue realizada y requiere seguimiento.' .
                    $detalleContexto;
            $flujo['accion_principal'] = [
                'codigo' => 'SEGUIMIENTO_REUNION_AUN_NO_DISPONIBLE',
                'etiqueta' => 'Registrar seguimiento',
                'icono' => 'bi-clock-history',
                'deshabilitada' => true
            ];

            return $flujo;
        }

        $flujo['titulo'] = $accionEtiqueta !== ''
            ? 'Dar seguimiento · ' . $accionEtiqueta
            : 'Dar seguimiento a acuerdos';
        $flujo['descripcion'] =
            'Ya corresponde revisar el pendiente acordado en la reunión.' .
            $detalleContexto;
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
        $nuevoContexto = null;

        if ($resultado === 'REQUIERE_SEGUIMIENTO') {
            $nuevaFecha = $this->normalizarFechaHora(
                $datos['seguimiento_reunion_fecha'] ?? ''
            );
            if (
                $nuevaFecha === null ||
                strtotime($nuevaFecha) <= time()
            ) {
                return $this->error(
                    'Indica una nueva fecha futura para continuar el seguimiento.',
                    422
                );
            }

            $validacionContexto = $this->validarContextoSeguimiento(
                $datos,
                'seguimiento'
            );
            if (!($validacionContexto['ok'] ?? false)) {
                return $validacionContexto;
            }

            $nuevoContexto = [
                'objetivo' => $validacionContexto['objetivo'],
                'pendiente_de' => $validacionContexto['pendiente_de'],
                'accion' => $validacionContexto['accion']
            ];
        }

        $this->connection->begin_transaction();

        try {
            $pendienteAtendido = trim((string)(
                $estado['reunion_seguimiento_objetivo'] ?? ''
            ));
            $detalleAtendido = $pendienteAtendido !== ''
                ? ' Pendiente atendido: ' . $pendienteAtendido . '.'
                : '';

            $this->registrarInteraccion(
                $seguimientoId,
                $analistaId,
                'Seguimiento posterior a reunión [' . $resultado . ']:' .
                $detalleAtendido . ' Resultado: ' . $notas
            );

            $this->actualizarResultadoAgenda(
                $seguimientoId,
                $analistaId,
                $resultado,
                $notas
            );

            if ($resultado === 'AVANZAR_CONVENIO') {
                $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                            SET reunion_resultado = 'AVANZAR_CONVENIO',
                                reunion_seguimiento_objetivo = NULL,
                                reunion_seguimiento_pendiente_de = NULL,
                                reunion_seguimiento_accion = NULL
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
                            SET reunion_resultado = 'NO_INTERESADO',
                                reunion_seguimiento_objetivo = NULL,
                                reunion_seguimiento_pendiente_de = NULL,
                                reunion_seguimiento_accion = NULL
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
                $this->actualizarContextoSeguimiento(
                    $seguimientoId,
                    $nuevoContexto
                );

                $this->registrarInteraccion(
                    $seguimientoId,
                    $analistaId,
                    'Nueva revisión programada para ' . $nuevaFecha .
                    '. Pendiente: ' . $nuevoContexto['objetivo'] .
                    '. Acción prevista: ' .
                    $this->etiquetaAccionSeguimiento(
                        $nuevoContexto['accion']
                    ) .
                    '. Pendiente de: ' .
                    $this->etiquetaPendienteDe(
                        $nuevoContexto['pendiente_de']
                    ) . '.'
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
        $agendaDisponible = $this->resultadoAgendaDisponible();
        $reactivacionDisponible = $this->reactivacionRutaDisponible();

        if ($agendaDisponible) {
            $joinAgenda = "LEFT JOIN reuniones_vinculacion reunion
                    ON reunion.id = (
                        SELECT reciente.id
                        FROM reuniones_vinculacion reciente
                        WHERE reciente.seguimiento_id = s.id
                          AND reciente.estado <> 'CANCELADA'
                        ORDER BY reciente.id DESC
                        LIMIT 1
                    )";

            if ($reactivacionDisponible) {
                $joinAgenda .= "
                    AND (
                        p.reactivacion_ruta_at IS NULL
                        OR reunion.created_at >= p.reactivacion_ruta_at
                    )";
            }

            $sql = "SELECT
                        s.id,
                        s.estado_seguimiento,
                        s.proxima_accion_at,
                        CASE
                            WHEN reunion.id IS NOT NULL
                            THEN reunion.reunion_resultado
                            ELSE p.reunion_resultado
                        END AS reunion_resultado,
                        CASE
                            WHEN reunion.id IS NOT NULL
                            THEN reunion.realizada_at
                            ELSE p.reunion_realizada_at
                        END AS reunion_realizada_at,
                        p.reunion_seguimiento_objetivo,
                        p.reunion_seguimiento_pendiente_de,
                        p.reunion_seguimiento_accion
                    FROM seguimientos_vinculacion s
                    LEFT JOIN seguimientos_vinculacion_post_envio p
                        ON p.seguimiento_id = s.id
                    " . $joinAgenda . "
                    WHERE s.id = ?
                      AND s.analista_id = ?
                      AND s.activo = 1
                    LIMIT 1";
        } else {
            $sql = "SELECT
                        s.id,
                        s.estado_seguimiento,
                        s.proxima_accion_at,
                        p.reunion_resultado,
                        p.reunion_realizada_at,
                        p.reunion_seguimiento_objetivo,
                        p.reunion_seguimiento_pendiente_de,
                        p.reunion_seguimiento_accion
                    FROM seguimientos_vinculacion s
                    LEFT JOIN seguimientos_vinculacion_post_envio p
                        ON p.seguimiento_id = s.id
                    WHERE s.id = ?
                      AND s.analista_id = ?
                      AND s.activo = 1
                    LIMIT 1";
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function actualizarResultadoAgenda(
        $seguimientoId,
        $analistaId,
        $resultado,
        $notas
    ) {
        if (!$this->resultadoAgendaDisponible()) {
            return;
        }

        $sql = "UPDATE reuniones_vinculacion
                SET reunion_resultado = ?,
                    reunion_resultado_notas = ?
                WHERE seguimiento_id = ?
                  AND analista_id = ?
                  AND estado = 'REALIZADA'
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'ssii',
            $resultado,
            $notas,
            $seguimientoId,
            $analistaId
        );
        $stmt->execute();
    }

    private function reactivacionRutaDisponible()
    {
        $tabla = $this->connection->query(
            "SHOW TABLES LIKE 'seguimientos_vinculacion_post_envio'"
        );
        if (!$tabla || $tabla->num_rows === 0) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'reactivacion_ruta_at'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function resultadoAgendaDisponible()
    {
        $tabla = $this->connection->query(
            "SHOW TABLES LIKE 'reuniones_vinculacion'"
        );
        if (!$tabla || $tabla->num_rows === 0) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM reuniones_vinculacion LIKE 'reunion_resultado'"
        );

        return $resultado && $resultado->num_rows > 0;
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

    private function validarContextoSeguimiento($datos, $modo)
    {
        $contexto = $this->extraerContextoSeguimiento($datos, $modo);

        if ($contexto['objetivo'] === '') {
            return $this->error(
                'Describe brevemente qué pendiente deberá revisarse en el próximo seguimiento.',
                422
            );
        }

        $pendientesValidos = ['INSTITUCION', 'FUNDACION', 'AMBOS'];
        if (!in_array($contexto['pendiente_de'], $pendientesValidos, true)) {
            return $this->error(
                'Selecciona de quién depende el pendiente.',
                422
            );
        }

        $accionesValidas = [
            'LLAMAR',
            'ENVIAR_CORREO',
            'ESPERAR_RESPUESTA',
            'REVISAR_DOCUMENTACION',
            'CONFIRMAR_AUTORIZACION',
            'OTRO'
        ];
        if (!in_array($contexto['accion'], $accionesValidas, true)) {
            return $this->error(
                'Selecciona la acción prevista para el seguimiento.',
                422
            );
        }

        return [
            'ok' => true,
            'objetivo' => $contexto['objetivo'],
            'pendiente_de' => $contexto['pendiente_de'],
            'accion' => $contexto['accion']
        ];
    }

    private function extraerContextoSeguimiento($datos, $modo)
    {
        if ($modo === 'seguimiento') {
            $prefijo = 'seguimiento_reunion_';
        } else {
            $prefijo = 'reunion_seguimiento_';
        }

        return [
            'objetivo' => trim((string)(
                $datos[$prefijo . 'objetivo'] ?? ''
            )),
            'pendiente_de' => strtoupper(trim((string)(
                $datos[$prefijo . 'pendiente_de'] ?? ''
            ))),
            'accion' => strtoupper(trim((string)(
                $datos[$prefijo . 'accion'] ?? ''
            )))
        ];
    }

    private function actualizarContextoSeguimiento(
        $seguimientoId,
        $contexto
    ) {
        if (!is_array($contexto)) {
            return;
        }

        $objetivo = trim((string)($contexto['objetivo'] ?? ''));
        $pendienteDe = strtoupper(trim((string)(
            $contexto['pendiente_de'] ?? ''
        )));
        $accion = strtoupper(trim((string)(
            $contexto['accion'] ?? ''
        )));

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET reunion_seguimiento_objetivo = ?,
                    reunion_seguimiento_pendiente_de = ?,
                    reunion_seguimiento_accion = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'sssi',
            $objetivo,
            $pendienteDe,
            $accion,
            $seguimientoId
        );
        $stmt->execute();
    }

    private function etiquetaPendienteDe($valor)
    {
        $mapa = [
            'INSTITUCION' => 'Institución',
            'FUNDACION' => 'Fundación Red',
            'AMBOS' => 'Ambos'
        ];

        return $mapa[strtoupper(trim((string)$valor))] ?? '';
    }

    private function etiquetaAccionSeguimiento($valor)
    {
        $mapa = [
            'LLAMAR' => 'Llamar',
            'ENVIAR_CORREO' => 'Enviar correo',
            'ESPERAR_RESPUESTA' => 'Esperar respuesta',
            'REVISAR_DOCUMENTACION' => 'Revisar documentación',
            'CONFIRMAR_AUTORIZACION' => 'Confirmar autorización',
            'REVISAR_ACUERDOS' => 'Revisar acuerdos',
            'OTRO' => 'Otra acción'
        ];

        return $mapa[strtoupper(trim((string)$valor))] ?? '';
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
