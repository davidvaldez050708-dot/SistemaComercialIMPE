<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoPostEnvioService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerFlujoSiAplica($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        if (!$this->tablaDisponible()) {
            return ['ok' => true, 'aplica' => false];
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);

        if (!$seguimiento || (string)$seguimiento['estado_seguimiento'] === 'DESCARTADO') {
            return ['ok' => true, 'aplica' => false];
        }

        $correoEnviado = trim((string)($seguimiento['fecha_envio'] ?? '')) !== '' ||
            strtoupper(trim((string)($seguimiento['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            trim((string)($seguimiento['respuesta_at'] ?? '')) !== '';

        if (!$correoEnviado) {
            return ['ok' => true, 'aplica' => false];
        }

        return [
            'ok' => true,
            'aplica' => true,
            'flujo' => $this->construirFlujo($seguimiento)
        ];
    }

    public function registrarAccion($seguimientoId, $usuarioId, $accion, $datos)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $accion = strtoupper(trim((string)$accion));

        if (!$this->tablaDisponible()) {
            return $this->error(
                'Falta aplicar la migración del flujo posterior al envío.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if ((string)$seguimiento['estado_seguimiento'] === 'DESCARTADO') {
            return $this->error('Este seguimiento ya fue descartado.', 409);
        }

        $correoEnviado = trim((string)($seguimiento['fecha_envio'] ?? '')) !== '' ||
            strtoupper(trim((string)($seguimiento['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            trim((string)($seguimiento['respuesta_at'] ?? '')) !== '';

        if (!$correoEnviado) {
            return $this->error(
                'Primero debe enviarse el oficio/correo antes de continuar.',
                422
            );
        }

        $accionesValidas = [
            'REGISTRAR_RESPUESTA',
            'REGISTRAR_SEGUIMIENTO_CORREO',
            'AGENDAR_REUNION',
            'REGISTRAR_REUNION_REALIZADA',
            'FORMALIZAR_CONVENIO'
        ];

        if (!in_array($accion, $accionesValidas, true)) {
            return $this->error('La acción solicitada no es válida.', 422);
        }

        $this->connection->begin_transaction();

        try {
            $this->asegurarRegistro($seguimientoId);

            if ($accion === 'REGISTRAR_RESPUESTA') {
                $this->registrarRespuesta($seguimientoId, $usuarioId, $datos);
            } elseif ($accion === 'REGISTRAR_SEGUIMIENTO_CORREO') {
                $this->registrarSeguimientoCorreo($seguimientoId, $usuarioId, $datos);
            } elseif ($accion === 'AGENDAR_REUNION') {
                $this->agendarReunion($seguimientoId, $usuarioId, $datos);
            } elseif ($accion === 'REGISTRAR_REUNION_REALIZADA') {
                $this->registrarReunionRealizada($seguimientoId, $usuarioId, $datos);
            } elseif ($accion === 'FORMALIZAR_CONVENIO') {
                $this->formalizarConvenio($seguimientoId, $usuarioId, $datos);
            }

            $this->connection->commit();
        } catch (InvalidArgumentException $error) {
            $this->connection->rollback();
            return $this->error($error->getMessage(), 422);
        } catch (RuntimeException $error) {
            $this->connection->rollback();
            return $this->error($error->getMessage(), 409);
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Error en flujo post-envío: ' . $error->getMessage());
            return $this->error(
                'No fue posible guardar el avance del seguimiento.',
                500
            );
        }

        $actualizado = $this->obtenerSeguimiento($seguimientoId, $usuarioId);

        return [
            'ok' => true,
            'mensaje' => $this->mensajeAccion($accion),
            'flujo' => $actualizado ? $this->construirFlujo($actualizado) : null
        ];
    }

    private function construirFlujo($seguimiento)
    {
        $pasos = [
            ['numero' => 1, 'clave' => 'INICIO', 'titulo' => 'Seguimiento iniciado'],
            ['numero' => 2, 'clave' => 'INVESTIGACION', 'titulo' => 'Investigación de datos'],
            ['numero' => 3, 'clave' => 'CONTACTO', 'titulo' => 'Contacto y validación'],
            ['numero' => 4, 'clave' => 'VERIFICACION', 'titulo' => 'Datos verificados'],
            ['numero' => 5, 'clave' => 'OFICIO', 'titulo' => 'Oficio preparado'],
            ['numero' => 6, 'clave' => 'PDF', 'titulo' => 'PDF generado'],
            ['numero' => 7, 'clave' => 'ENVIO', 'titulo' => 'Oficio / correo enviado'],
            ['numero' => 8, 'clave' => 'ESPERA', 'titulo' => 'Esperando respuesta'],
            ['numero' => 9, 'clave' => 'RESPUESTA', 'titulo' => 'Respuesta recibida'],
            ['numero' => 10, 'clave' => 'SEGUIMIENTO_CORREO', 'titulo' => 'Seguimiento por correo'],
            ['numero' => 11, 'clave' => 'REUNION_AGENDADA', 'titulo' => 'Reunión agendada'],
            ['numero' => 12, 'clave' => 'REUNION_REALIZADA', 'titulo' => 'Reunión realizada'],
            ['numero' => 13, 'clave' => 'CONVENIO', 'titulo' => 'Convenio formalizado']
        ];

        $respuestaAt = trim((string)($seguimiento['respuesta_at'] ?? ''));
        $seguimientoCorreoAt = trim((string)($seguimiento['seguimiento_correo_at'] ?? ''));
        $reunionAgendadaAt = trim((string)($seguimiento['reunion_agendada_at'] ?? ''));
        $reunionRealizadaAt = trim((string)($seguimiento['reunion_realizada_at'] ?? ''));
        $convenioAt = trim((string)($seguimiento['convenio_formalizado_at'] ?? ''));

        if ($convenioAt !== '') {
            return $this->respuestaFlujo(
                $pasos,
                13,
                'Convenio formalizado',
                'La ruta del Analista quedó concluida. El expediente está listo para que Cuenta Clave continúe con la relación institucional.',
                null,
                null,
                $seguimiento
            );
        }

        if ($reunionRealizadaAt !== '') {
            return $this->respuestaFlujo(
                $pasos,
                13,
                'Formalizar convenio',
                'La reunión ya fue registrada. Si existe acuerdo para continuar, captura los datos del convenio para concluir la ruta del Analista.',
                [
                    'codigo' => 'FORMALIZAR_CONVENIO',
                    'etiqueta' => 'Formalizar convenio',
                    'icono' => 'bi-file-earmark-check'
                ],
                null,
                $seguimiento
            );
        }

        if ($reunionAgendadaAt !== '') {
            $fecha = $this->fechaLegible($seguimiento['reunion_fecha'] ?? '');
            return $this->respuestaFlujo(
                $pasos,
                12,
                'Registrar reunión realizada',
                $fecha !== ''
                    ? 'La reunión está agendada para ' . $fecha . '. Al finalizar, registra el resultado para continuar.'
                    : 'La reunión está agendada. Al finalizar, registra el resultado para continuar.',
                [
                    'codigo' => 'REGISTRAR_REUNION_REALIZADA',
                    'etiqueta' => 'Registrar reunión',
                    'icono' => 'bi-people'
                ],
                null,
                $seguimiento
            );
        }

        if ($seguimientoCorreoAt !== '') {
            return $this->respuestaFlujo(
                $pasos,
                11,
                'Agendar reunión',
                'El seguimiento por correo ya quedó registrado. El siguiente paso es definir fecha, modalidad y lugar o enlace de la reunión.',
                [
                    'codigo' => 'AGENDAR_REUNION',
                    'etiqueta' => 'Agendar reunión',
                    'icono' => 'bi-calendar-event'
                ],
                null,
                $seguimiento
            );
        }

        if ($respuestaAt !== '') {
            $tipo = $this->etiquetaRespuesta($seguimiento['respuesta_tipo'] ?? '');
            return $this->respuestaFlujo(
                $pasos,
                10,
                'Dar seguimiento por correo',
                $tipo !== ''
                    ? 'Respuesta registrada: ' . $tipo . '. Registra el correo de seguimiento antes de pasar a la reunión.'
                    : 'La respuesta ya fue registrada. Registra el correo de seguimiento antes de pasar a la reunión.',
                [
                    'codigo' => 'REGISTRAR_SEGUIMIENTO_CORREO',
                    'etiqueta' => 'Registrar seguimiento',
                    'icono' => 'bi-envelope-check'
                ],
                null,
                $seguimiento
            );
        }

        return $this->respuestaFlujo(
            $pasos,
            8,
            'Esperar y registrar respuesta',
            'El oficio y el correo ya fueron enviados. Cuando la institución responda, registra qué contestó para continuar con la reunión y el convenio.',
            [
                'codigo' => 'REGISTRAR_RESPUESTA',
                'etiqueta' => 'Registrar respuesta',
                'icono' => 'bi-chat-left-text'
            ],
            null,
            $seguimiento
        );
    }

    private function registrarRespuesta($seguimientoId, $usuarioId, $datos)
    {
        $actual = $this->obtenerPostEnvio($seguimientoId);

        if (trim((string)($actual['respuesta_at'] ?? '')) !== '') {
            throw new RuntimeException('La respuesta de esta institución ya fue registrada.');
        }

        $tipo = strtoupper(trim((string)($datos['respuesta_tipo'] ?? '')));
        $canal = strtoupper(trim((string)($datos['respuesta_canal'] ?? 'CORREO')));
        $texto = trim((string)($datos['respuesta_texto'] ?? ''));
        $contactarDespues = $this->normalizarFechaHora($datos['contactar_despues_at'] ?? '');
        $tipos = ['INTERESADO', 'MAS_INFORMACION', 'QUIERE_REUNION', 'CONTACTAR_DESPUES', 'NO_INTERESADO'];
        $canales = ['CORREO', 'LLAMADA', 'WHATSAPP'];

        if (!in_array($tipo, $tipos, true)) {
            throw new InvalidArgumentException('Selecciona el tipo de respuesta recibida.');
        }
        if (!in_array($canal, $canales, true)) {
            throw new InvalidArgumentException('Selecciona un canal válido para la respuesta.');
        }
        if ($texto === '') {
            throw new InvalidArgumentException('Escribe brevemente qué respondió la institución.');
        }
        if ($tipo === 'CONTACTAR_DESPUES' && $contactarDespues === null) {
            throw new InvalidArgumentException('Indica cuándo debe retomarse el contacto.');
        }

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET respuesta_tipo = ?,
                    respuesta_canal = ?,
                    respuesta_texto = ?,
                    respuesta_at = NOW(),
                    respuesta_por = ?,
                    contactar_despues_at = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sssisi', $tipo, $canal, $texto, $usuarioId, $contactarDespues, $seguimientoId);
        $stmt->execute();

        $notas = 'Respuesta recibida [' . $this->etiquetaRespuesta($tipo) . ']: ' . $texto;
        $canalDb = $canal === 'LLAMADA' ? 'LLAMADA_IP' : $canal;
        $this->registrarInteraccion($seguimientoId, $usuarioId, $canalDb, 'OTRO', $notas);

        if ($tipo === 'NO_INTERESADO') {
            $motivo = mb_substr($texto, 0, 255);
            $sqlSeg = "UPDATE seguimientos_vinculacion
                    SET estado_seguimiento = 'DESCARTADO',
                        motivo_descarte = ?,
                        ultima_interaccion_at = NOW(),
                        proxima_accion_at = NULL
                    WHERE id = ? AND analista_id = ?";
            $stmtSeg = $this->connection->prepare($sqlSeg);
            $stmtSeg->bind_param('sii', $motivo, $seguimientoId, $usuarioId);
            $stmtSeg->execute();
        } else {
            $sqlSeg = "UPDATE seguimientos_vinculacion
                    SET ultima_interaccion_at = NOW(),
                        proxima_accion_at = ?
                    WHERE id = ? AND analista_id = ?";
            $stmtSeg = $this->connection->prepare($sqlSeg);
            $stmtSeg->bind_param('sii', $contactarDespues, $seguimientoId, $usuarioId);
            $stmtSeg->execute();
        }
    }

    private function registrarSeguimientoCorreo($seguimientoId, $usuarioId, $datos)
    {
        $actual = $this->obtenerPostEnvio($seguimientoId);
        if (trim((string)($actual['respuesta_at'] ?? '')) === '') {
            throw new RuntimeException('Primero registra la respuesta de la institución.');
        }
        if (trim((string)($actual['seguimiento_correo_at'] ?? '')) !== '') {
            throw new RuntimeException('El seguimiento por correo ya fue registrado.');
        }

        $notas = trim((string)($datos['seguimiento_correo_notas'] ?? ''));
        if ($notas === '') {
            throw new InvalidArgumentException('Describe brevemente el correo de seguimiento enviado.');
        }

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET seguimiento_correo_notas = ?,
                    seguimiento_correo_at = NOW(),
                    seguimiento_correo_por = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $notas, $usuarioId, $seguimientoId);
        $stmt->execute();

        $this->registrarInteraccion(
            $seguimientoId,
            $usuarioId,
            'CORREO',
            'CORREO_ENVIADO',
            'Seguimiento por correo: ' . $notas
        );

        $this->actualizarUltimaInteraccion($seguimientoId, $usuarioId, null);
    }

    private function agendarReunion($seguimientoId, $usuarioId, $datos)
    {
        $actual = $this->obtenerPostEnvio($seguimientoId);
        if (trim((string)($actual['seguimiento_correo_at'] ?? '')) === '') {
            throw new RuntimeException('Primero registra el seguimiento por correo.');
        }
        if (trim((string)($actual['reunion_agendada_at'] ?? '')) !== '') {
            throw new RuntimeException('La reunión ya fue agendada.');
        }

        $fecha = $this->normalizarFechaHora($datos['reunion_fecha'] ?? '');
        $modalidad = strtoupper(trim((string)($datos['reunion_modalidad'] ?? '')));
        $lugar = trim((string)($datos['reunion_lugar_enlace'] ?? ''));
        $notas = trim((string)($datos['reunion_notas'] ?? ''));

        if ($fecha === null) {
            throw new InvalidArgumentException('Indica la fecha y hora de la reunión.');
        }
        if (!in_array($modalidad, ['VIRTUAL', 'PRESENCIAL', 'HIBRIDA'], true)) {
            throw new InvalidArgumentException('Selecciona la modalidad de la reunión.');
        }
        if ($lugar === '') {
            throw new InvalidArgumentException('Indica el enlace o lugar de la reunión.');
        }

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET reunion_fecha = ?,
                    reunion_modalidad = ?,
                    reunion_lugar_enlace = ?,
                    reunion_notas = ?,
                    reunion_agendada_at = NOW(),
                    reunion_agendada_por = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssssii', $fecha, $modalidad, $lugar, $notas, $usuarioId, $seguimientoId);
        $stmt->execute();

        $this->registrarInteraccion(
            $seguimientoId,
            $usuarioId,
            'SISTEMA',
            'OTRO',
            'Reunión agendada: ' . $fecha . ' | ' . $modalidad . ' | ' . $lugar . ($notas !== '' ? ' | ' . $notas : '')
        );
        $this->actualizarUltimaInteraccion($seguimientoId, $usuarioId, $fecha);
    }

    private function registrarReunionRealizada($seguimientoId, $usuarioId, $datos)
    {
        $actual = $this->obtenerPostEnvio($seguimientoId);
        if (trim((string)($actual['reunion_agendada_at'] ?? '')) === '') {
            throw new RuntimeException('Primero agenda la reunión.');
        }
        if (trim((string)($actual['reunion_realizada_at'] ?? '')) !== '') {
            throw new RuntimeException('La reunión ya fue registrada como realizada.');
        }

        $resultado = strtoupper(trim((string)($datos['reunion_resultado'] ?? '')));
        $notas = trim((string)($datos['reunion_resultado_notas'] ?? ''));
        $resultados = ['AVANZAR_CONVENIO', 'REQUIERE_SEGUIMIENTO', 'NO_INTERESADO'];

        if (!in_array($resultado, $resultados, true)) {
            throw new InvalidArgumentException('Selecciona el resultado de la reunión.');
        }
        if ($notas === '') {
            throw new InvalidArgumentException('Registra brevemente los acuerdos o resultado de la reunión.');
        }

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET reunion_resultado = ?,
                    reunion_resultado_notas = ?,
                    reunion_realizada_at = NOW(),
                    reunion_realizada_por = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssii', $resultado, $notas, $usuarioId, $seguimientoId);
        $stmt->execute();

        $this->registrarInteraccion(
            $seguimientoId,
            $usuarioId,
            'SISTEMA',
            'OTRO',
            'Reunión realizada [' . $resultado . ']: ' . $notas
        );

        if ($resultado === 'NO_INTERESADO') {
            $motivo = mb_substr($notas, 0, 255);
            $sqlSeg = "UPDATE seguimientos_vinculacion
                    SET estado_seguimiento = 'DESCARTADO',
                        motivo_descarte = ?,
                        ultima_interaccion_at = NOW(),
                        proxima_accion_at = NULL
                    WHERE id = ? AND analista_id = ?";
            $stmtSeg = $this->connection->prepare($sqlSeg);
            $stmtSeg->bind_param('sii', $motivo, $seguimientoId, $usuarioId);
            $stmtSeg->execute();
        } else {
            $this->actualizarUltimaInteraccion($seguimientoId, $usuarioId, null);
        }
    }

    private function formalizarConvenio($seguimientoId, $usuarioId, $datos)
    {
        $actual = $this->obtenerPostEnvio($seguimientoId);
        if (trim((string)($actual['reunion_realizada_at'] ?? '')) === '') {
            throw new RuntimeException('Primero registra la reunión realizada.');
        }
        if (trim((string)($actual['convenio_formalizado_at'] ?? '')) !== '') {
            throw new RuntimeException('El convenio ya fue formalizado.');
        }

        $fecha = trim((string)($datos['convenio_fecha'] ?? ''));
        $referencia = trim((string)($datos['convenio_referencia'] ?? ''));
        $notas = trim((string)($datos['convenio_notas'] ?? ''));

        if (!$this->fechaValida($fecha)) {
            throw new InvalidArgumentException('Indica una fecha válida para el convenio.');
        }
        if ($referencia === '') {
            throw new InvalidArgumentException('Indica el folio, referencia o nombre del convenio.');
        }

        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET convenio_fecha = ?,
                    convenio_referencia = ?,
                    convenio_notas = ?,
                    convenio_formalizado_at = NOW(),
                    convenio_formalizado_por = ?
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sssii', $fecha, $referencia, $notas, $usuarioId, $seguimientoId);
        $stmt->execute();

        $this->registrarInteraccion(
            $seguimientoId,
            $usuarioId,
            'SISTEMA',
            'OTRO',
            'Convenio formalizado: ' . $referencia . ' | Fecha: ' . $fecha . ($notas !== '' ? ' | ' . $notas : '')
        );
        $this->actualizarUltimaInteraccion($seguimientoId, $usuarioId, null);
    }

    private function obtenerSeguimiento($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.analista_id,
                    seguimientos.estado_seguimiento,
                    seguimientos.proxima_accion_at,
                    oficio.estado_oficio,
                    oficio.fecha_envio,
                    post.respuesta_tipo,
                    post.respuesta_canal,
                    post.respuesta_texto,
                    post.respuesta_at,
                    post.contactar_despues_at,
                    post.seguimiento_correo_notas,
                    post.seguimiento_correo_at,
                    post.reunion_fecha,
                    post.reunion_modalidad,
                    post.reunion_lugar_enlace,
                    post.reunion_notas,
                    post.reunion_agendada_at,
                    post.reunion_resultado,
                    post.reunion_resultado_notas,
                    post.reunion_realizada_at,
                    post.convenio_fecha,
                    post.convenio_referencia,
                    post.convenio_notas,
                    post.convenio_formalizado_at
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT reciente.id
                        FROM oficios_vinculacion reciente
                        WHERE reciente.seguimiento_id = seguimientos.id
                        ORDER BY reciente.id DESC
                        LIMIT 1
                    )
                LEFT JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = seguimientos.id
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function obtenerPostEnvio($seguimientoId)
    {
        $sql = "SELECT * FROM seguimientos_vinculacion_post_envio WHERE seguimiento_id = ? LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: [];
    }

    private function asegurarRegistro($seguimientoId)
    {
        $sql = "INSERT IGNORE INTO seguimientos_vinculacion_post_envio (seguimiento_id) VALUES (?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
    }

    private function registrarInteraccion($seguimientoId, $usuarioId, $canal, $resultado, $notas)
    {
        $sql = "INSERT INTO interacciones_vinculacion (
                    seguimiento_id,
                    usuario_id,
                    canal,
                    fecha_inicio,
                    resultado,
                    notas
                ) VALUES (?, ?, ?, NOW(), ?, ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iisss', $seguimientoId, $usuarioId, $canal, $resultado, $notas);
        $stmt->execute();
    }

    private function actualizarUltimaInteraccion($seguimientoId, $usuarioId, $proximaAccion)
    {
        $sql = "UPDATE seguimientos_vinculacion
                SET ultima_interaccion_at = NOW(),
                    proxima_accion_at = ?
                WHERE id = ? AND analista_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $proximaAccion, $seguimientoId, $usuarioId);
        $stmt->execute();
    }

    private function tablaDisponible()
    {
        $resultado = $this->connection->query("SHOW TABLES LIKE 'seguimientos_vinculacion_post_envio'");
        return $resultado && $resultado->num_rows > 0;
    }

    private function respuestaFlujo($pasos, $pasoActual, $titulo, $descripcion, $principal, $secundaria, $seguimiento)
    {
        $pasoActual = max(1, min(count($pasos), (int)$pasoActual));
        $indice = $pasoActual - 1;

        return [
            'seguimiento_id' => (int)($seguimiento['id'] ?? 0),
            'paso_actual' => $pasoActual,
            'total_pasos' => count($pasos),
            'porcentaje' => (int)round(($pasoActual / count($pasos)) * 100),
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'faltantes' => [],
            'accion_principal' => $principal,
            'accion_secundaria' => $secundaria,
            'ventana' => [
                'anterior' => $indice > 0 ? $pasos[$indice - 1] : null,
                'actual' => $pasos[$indice] ?? null,
                'siguiente' => isset($pasos[$indice + 1]) ? $pasos[$indice + 1] : null
            ],
            'contexto' => [
                'estado_seguimiento' => (string)($seguimiento['estado_seguimiento'] ?? ''),
                'proxima_accion_at' => trim((string)($seguimiento['proxima_accion_at'] ?? '')),
                'respuesta_tipo' => (string)($seguimiento['respuesta_tipo'] ?? ''),
                'reunion_fecha' => (string)($seguimiento['reunion_fecha'] ?? ''),
                'convenio_referencia' => (string)($seguimiento['convenio_referencia'] ?? '')
            ]
        ];
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

    private function fechaValida($valor)
    {
        $fecha = DateTime::createFromFormat('Y-m-d', (string)$valor);
        return $fecha instanceof DateTime && $fecha->format('Y-m-d') === (string)$valor;
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
            return '';
        }
    }

    private function etiquetaRespuesta($tipo)
    {
        $mapa = [
            'INTERESADO' => 'Interesado / respuesta positiva',
            'MAS_INFORMACION' => 'Solicita más información',
            'QUIERE_REUNION' => 'Quiere agendar una reunión',
            'CONTACTAR_DESPUES' => 'Solicita contactar más adelante',
            'NO_INTERESADO' => 'No interesado'
        ];
        return $mapa[strtoupper(trim((string)$tipo))] ?? '';
    }

    private function mensajeAccion($accion)
    {
        $mapa = [
            'REGISTRAR_RESPUESTA' => 'Respuesta registrada correctamente.',
            'REGISTRAR_SEGUIMIENTO_CORREO' => 'Seguimiento por correo registrado.',
            'AGENDAR_REUNION' => 'Reunión agendada correctamente.',
            'REGISTRAR_REUNION_REALIZADA' => 'Resultado de la reunión registrado.',
            'FORMALIZAR_CONVENIO' => 'Convenio formalizado. La ruta del Analista quedó concluida.'
        ];
        return $mapa[$accion] ?? 'Avance guardado correctamente.';
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
