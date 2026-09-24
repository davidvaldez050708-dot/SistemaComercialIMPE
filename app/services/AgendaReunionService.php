<?php

require_once __DIR__ . '/AgendaReunionRepository.php';
require_once __DIR__ . '/EcardReunionService.php';

class AgendaReunionService
{
    public const ROL_ANALISTA = 4;
    public const ROL_CUENTA_CLAVE = 6;

    private $repo;
    private $ecardService;

    public function __construct()
    {
        $this->repo = new AgendaReunionRepository();
        $this->ecardService = new EcardReunionService($this->repo->connection());
    }

    public function puedeAcceder($rolId)
    {
        return in_array((int)$rolId, [self::ROL_ANALISTA, self::ROL_CUENTA_CLAVE], true);
    }

    public function tablaDisponible()
    {
        return $this->repo->tablaDisponible();
    }


    public function validarDisponibilidadHorario(
        $fecha,
        $duracion,
        $analistaId,
        $cuentaClaveId,
        $excluirReunionId = 0
    ) {
        $conflicto = $this->repo->conflictoHorario(
            (string)$fecha,
            (int)$duracion,
            (int)$analistaId,
            (int)$cuentaClaveId,
            (int)$excluirReunionId
        );

        if (!$conflicto) {
            return ['ok' => true];
        }

        $participantes = [];

        if ((int)($conflicto['analista_id'] ?? 0) === (int)$analistaId) {
            $participantes[] = 'el Analista';
        }

        if (
            (int)$cuentaClaveId > 0 &&
            (int)($conflicto['cuenta_clave_id'] ?? 0) === (int)$cuentaClaveId
        ) {
            $participantes[] = 'Cuenta Clave';
        }

        $responsable = !empty($participantes)
            ? implode(' y ', $participantes)
            : 'uno de los responsables';

        $entidad = trim((string)($conflicto['nombre_entidad'] ?? ''));
        $detalleEntidad = $entidad !== ''
            ? ' con "' . $entidad . '"'
            : '';

        return $this->error(
            'Ese horario se cruza con otra reunión activa de ' .
            $responsable .
            $detalleEntidad .
            '. Selecciona otro horario.',
            409
        );
    }

    public function obtenerEcard($usuarioId, $rolId, $reunionId, $template = '')
    {
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;
        $reunionId = (int)$reunionId;

        if (!$this->puedeAcceder($rolId) || $usuarioId <= 0 || $reunionId <= 0) {
            return $this->error('No tienes acceso a la Ecard solicitada.', 403);
        }

        $reunion = $this->repo->reunion($reunionId, $usuarioId, $rolId);
        if (!$reunion) {
            return $this->error('No fue posible localizar la reunión.', 404);
        }

        $resultado = $this->ecardService->generar($reunion, $template);
        if (!($resultado['ok'] ?? false)) {
            return $this->error(
                (string)($resultado['mensaje'] ?? 'No fue posible generar la Ecard.'),
                500
            );
        }

        return $resultado;
    }

    public function resolverMesContexto($usuarioId, $rolId, $reunionId = 0, $seguimientoId = 0)
    {
        if (!$this->puedeAcceder($rolId) || !$this->tablaDisponible()) {
            return '';
        }

        if ((int)$reunionId > 0) {
            $reunion = $this->repo->reunion((int)$reunionId, (int)$usuarioId, (int)$rolId);
        } elseif ((int)$seguimientoId > 0 && (int)$rolId === self::ROL_ANALISTA) {
            $reunion = $this->repo->ultimaReunionSeguimiento((int)$seguimientoId, (int)$usuarioId);
        } else {
            $reunion = null;
        }

        $fecha = trim((string)($reunion['fecha_propuesta'] ?? ''));
        return $fecha !== '' ? substr($fecha, 0, 7) : '';
    }

    public function obtenerAgenda($usuarioId, $rolId, $mes = '')
    {
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;
        $mes = $this->normalizarMes($mes);

        if (!$this->puedeAcceder($rolId)) {
            return $this->error('No tienes acceso a la agenda de reuniones.', 403);
        }

        if (!$this->tablaDisponible()) {
            return [
                'ok' => true,
                'requiere_migracion' => true,
                'mes' => $mes,
                'reuniones' => [],
                'seguimientos_elegibles' => []
            ];
        }

        $inicio = $mes . '-01 00:00:00';
        $fin = (new DateTime($inicio))->modify('+1 month')->format('Y-m-d H:i:s');
        $reuniones = array_map(
            fn($fila) => $this->prepararReunion($fila),
            $this->repo->reunionesMes($usuarioId, $rolId, $inicio, $fin)
        );

        return [
            'ok' => true,
            'requiere_migracion' => false,
            'mes' => $mes,
            'reuniones' => $reuniones,
            'seguimientos_elegibles' => $rolId === self::ROL_ANALISTA
                ? $this->repo->seguimientosElegibles($usuarioId)
                : []
        ];
    }

    public function solicitar($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== self::ROL_ANALISTA) {
            return $this->error('Solo el Analista puede solicitar una reunión.', 403);
        }
        if (!$this->tablaDisponible()) {
            return $this->error('Falta aplicar la migración de agenda de reuniones.', 500);
        }

        $seguimientoId = (int)($datos['seguimiento_id'] ?? 0);
        $fecha = $this->normalizarFechaHora($datos['fecha_propuesta'] ?? '');
        $duracion = (int)($datos['duracion_minutos'] ?? 60);
        $modalidad = strtoupper(trim((string)($datos['modalidad'] ?? 'VIRTUAL')));
        $objetivo = trim((string)($datos['objetivo'] ?? ''));
        $notas = trim((string)($datos['notas_analista'] ?? ''));

        $error = $this->validarPropuesta($fecha, $duracion, $modalidad, $objetivo);
        if ($error !== '') {
            return $this->error($error, 422);
        }
        if (!$this->repo->seguimientoElegible($seguimientoId, (int)$usuarioId)) {
            return $this->error('El seguimiento no está listo para solicitar reunión o no te pertenece.', 422);
        }
        if ($this->repo->reunionActivaSeguimiento($seguimientoId)) {
            return $this->error('Este seguimiento ya tiene una reunión activa en la agenda.', 409);
        }

        $cuentaClaveId = $this->repo->cuentaClaveParaSeguimiento(
            $seguimientoId,
            (int)$usuarioId
        );
        if ($cuentaClaveId <= 0) {
            return $this->error(
                'No hay una Cuenta Clave activa vinculada al territorio del Analista.',
                422
            );
        }

        $disponibilidad = $this->validarDisponibilidadHorario(
            $fecha,
            $duracion,
            (int)$usuarioId,
            $cuentaClaveId
        );
        if (!($disponibilidad['ok'] ?? false)) {
            return $disponibilidad;
        }

        try {
            $id = $this->repo->insertarSolicitud(
                $seguimientoId,
                (int)$usuarioId,
                $fecha,
                $duracion,
                $modalidad,
                $objetivo,
                $notas
            );
            $this->repo->actualizarProximaAccion($seguimientoId, (int)$usuarioId, $fecha);
            $this->repo->registrarInteraccion(
                $seguimientoId,
                (int)$usuarioId,
                'Solicitud de reunión enviada a Cuenta Clave para ' . $fecha . ' (' . $modalidad . ').'
            );

            return [
                'ok' => true,
                'mensaje' => 'Solicitud enviada a Cuenta Clave. La reunión quedó pendiente de confirmación.',
                'reunion_id' => $id
            ];
        } catch (Throwable $error) {
            error_log('Agenda reunión solicitar: ' . $error->getMessage());
            return $this->error('No fue posible guardar la solicitud de reunión.', 500);
        }
    }

    public function reprogramar($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== self::ROL_ANALISTA) {
            return $this->error('Solo el Analista puede proponer una nueva fecha.', 403);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $reunion = $this->repo->reunion($reunionId, (int)$usuarioId, (int)$rolId);
        if (!$reunion || (string)$reunion['estado'] !== 'CAMBIO_SOLICITADO') {
            return $this->error('Esta reunión no está esperando una nueva propuesta.', 409);
        }

        $fecha = $this->normalizarFechaHora($datos['fecha_propuesta'] ?? '');
        $duracion = (int)($datos['duracion_minutos'] ?? 60);
        $modalidad = strtoupper(trim((string)($datos['modalidad'] ?? 'VIRTUAL')));
        $objetivo = trim((string)($datos['objetivo'] ?? ''));
        $notas = trim((string)($datos['notas_analista'] ?? ''));
        $error = $this->validarPropuesta($fecha, $duracion, $modalidad, $objetivo);
        if ($error !== '') {
            return $this->error($error, 422);
        }

        $disponibilidad = $this->validarDisponibilidadHorario(
            $fecha,
            $duracion,
            (int)$reunion['analista_id'],
            (int)$reunion['cuenta_clave_id'],
            $reunionId
        );
        if (!($disponibilidad['ok'] ?? false)) {
            return $disponibilidad;
        }

        if (!$this->repo->reprogramar($reunionId, (int)$usuarioId, $fecha, $duracion, $modalidad, $objetivo, $notas)) {
            return $this->error('La reunión cambió de estado. Actualiza la agenda.', 409);
        }

        $this->repo->actualizarProximaAccion((int)$reunion['seguimiento_id'], (int)$usuarioId, $fecha);
        $this->repo->registrarInteraccion(
            (int)$reunion['seguimiento_id'],
            (int)$usuarioId,
            'Nueva propuesta de reunión enviada a Cuenta Clave para ' . $fecha . '.'
        );

        return [
            'ok' => true,
            'mensaje' => 'La nueva fecha fue enviada a Cuenta Clave para confirmación.',
            'reunion_id' => $reunionId
        ];
    }

    public function confirmar($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== self::ROL_CUENTA_CLAVE) {
            return $this->error('Solo Cuenta Clave puede confirmar la reunión.', 403);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $reunion = $this->repo->reunion($reunionId, (int)$usuarioId, (int)$rolId);
        if (!$reunion || (string)$reunion['estado'] !== 'SOLICITADA') {
            return $this->error('La solicitud ya no está pendiente de confirmación.', 409);
        }

        $fechaPropuesta = trim((string)($reunion['fecha_propuesta'] ?? ''));
        if ($fechaPropuesta === '' || strtotime($fechaPropuesta) <= time()) {
            return $this->error(
                'La fecha propuesta ya venció. Solicita al Analista una nueva fecha antes de confirmar.',
                409
            );
        }

        $disponibilidad = $this->validarDisponibilidadHorario(
            $fechaPropuesta,
            (int)($reunion['duracion_minutos'] ?? 60),
            (int)$reunion['analista_id'],
            (int)$reunion['cuenta_clave_id'],
            $reunionId
        );
        if (!($disponibilidad['ok'] ?? false)) {
            return $disponibilidad;
        }

        $modalidad = strtoupper((string)($reunion['modalidad'] ?? 'VIRTUAL'));
        $zoomUrl = trim((string)($datos['zoom_url'] ?? ''));
        $ubicacion = trim((string)($datos['ubicacion'] ?? ''));
        $notas = trim((string)($datos['notas_kam'] ?? ''));

        if (in_array($modalidad, ['VIRTUAL', 'HIBRIDA'], true) &&
            ($zoomUrl === '' || !filter_var($zoomUrl, FILTER_VALIDATE_URL))) {
            return $this->error('Genera en Zoom y pega un enlace válido antes de confirmar.', 422);
        }
        if (in_array($modalidad, ['PRESENCIAL', 'HIBRIDA'], true) && $ubicacion === '') {
            return $this->error('Indica el lugar de la reunión presencial.', 422);
        }

        if (!$this->repo->confirmar($reunionId, (int)$usuarioId, $zoomUrl, $ubicacion, $notas)) {
            return $this->error('La solicitud fue atendida por otro usuario.', 409);
        }

        $this->repo->registrarInteraccion(
            (int)$reunion['seguimiento_id'],
            (int)$usuarioId,
            'Cuenta Clave confirmó la reunión para ' . $reunion['fecha_propuesta'] . '.'
        );

        return [
            'ok' => true,
            'mensaje' => 'Reunión confirmada. El Analista ya puede enviar el correo de confirmación.',
            'reunion_id' => $reunionId
        ];
    }

    public function solicitarCambio($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== self::ROL_CUENTA_CLAVE) {
            return $this->error('Solo Cuenta Clave puede solicitar un cambio.', 403);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $motivo = trim((string)($datos['motivo'] ?? ''));
        if ($motivo === '') {
            return $this->error('Indica qué debe cambiar el Analista.', 422);
        }

        $reunion = $this->repo->reunion($reunionId, (int)$usuarioId, (int)$rolId);
        if (!$reunion || (string)$reunion['estado'] !== 'SOLICITADA') {
            return $this->error('La solicitud ya no está pendiente.', 409);
        }

        if (!$this->repo->solicitarCambio($reunionId, (int)$usuarioId, $motivo)) {
            return $this->error('La solicitud fue atendida por otro usuario.', 409);
        }

        $this->repo->registrarInteraccion(
            (int)$reunion['seguimiento_id'],
            (int)$usuarioId,
            'Cuenta Clave solicitó modificar la propuesta de reunión. Motivo: ' . $motivo
        );

        return [
            'ok' => true,
            'mensaje' => 'Se notificó al Analista para que proponga una nueva fecha.',
            'reunion_id' => $reunionId
        ];
    }

    public function cancelar($usuarioId, $rolId, $datos)
    {
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;

        if (!$this->puedeAcceder($rolId)) {
            return $this->error('No tienes acceso a cancelar esta reunión.', 403);
        }

        if (!$this->repo->cancelacionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de integridad de agenda y cancelaciones.',
                500
            );
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $motivo = trim((string)($datos['motivo_cancelacion'] ?? ''));

        if ($motivo === '') {
            return $this->error('Indica el motivo de la cancelación.', 422);
        }
        if (mb_strlen($motivo) > 2000) {
            return $this->error('El motivo de cancelación es demasiado largo.', 422);
        }

        $reunion = $this->repo->reunion($reunionId, $usuarioId, $rolId);
        if (
            !$reunion ||
            !in_array(
                strtoupper((string)($reunion['estado'] ?? '')),
                ['SOLICITADA', 'CAMBIO_SOLICITADO', 'CONFIRMADA', 'CORREO_ENVIADO'],
                true
            )
        ) {
            return $this->error(
                'La reunión ya no está disponible para cancelarse.',
                409
            );
        }

        $db = $this->repo->connection();
        $db->begin_transaction();

        try {
            if (!$this->repo->cancelar($reunionId, $usuarioId, $rolId, $motivo)) {
                throw new RuntimeException(
                    'La reunión cambió de estado. Actualiza la agenda.'
                );
            }

            $seguimientoId = (int)$reunion['seguimiento_id'];
            $analistaId = (int)$reunion['analista_id'];

            $this->repo->cancelarReprogramacionesPendientes($reunionId);
            $this->repo->limpiarReunionPostEnvio($seguimientoId);
            $this->repo->actualizarProximaAccion(
                $seguimientoId,
                $analistaId,
                null
            );
            $this->repo->registrarInteraccion(
                $seguimientoId,
                $usuarioId,
                'Reunión cancelada. Motivo: ' . $motivo
            );

            $db->commit();

            return [
                'ok' => true,
                'mensaje' =>
                    'Reunión cancelada. El seguimiento vuelve a coordinación de reunión.',
                'reunion_id' => $reunionId,
                'seguimiento_id' => $seguimientoId
            ];
        } catch (Throwable $error) {
            $db->rollback();

            return $this->error(
                $error->getMessage(),
                $error instanceof RuntimeException ? 409 : 500
            );
        }
    }

    public function marcarCorreoEnviado($usuarioId, $rolId, $datos)
    {
        if ((int)$rolId !== self::ROL_ANALISTA) {
            return $this->error('Solo el Analista puede registrar el correo de confirmación.', 403);
        }

        $reunionId = (int)($datos['reunion_id'] ?? 0);
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = trim((string)($datos['cuerpo'] ?? ''));
        $ecardTemplate = strtoupper(trim((string)($datos['ecard_template'] ?? '')));
        $reunion = $this->repo->reunion($reunionId, (int)$usuarioId, (int)$rolId);

        if (!$reunion || (string)$reunion['estado'] !== 'CONFIRMADA') {
            return $this->error('La reunión todavía no está lista para enviar la confirmación.', 409);
        }
        $correo = trim((string)($reunion['contacto_correo'] ?? ''));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El seguimiento no tiene un correo de contacto válido.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            return $this->error('Revisa el asunto y el mensaje antes de registrar el envío.', 422);
        }

        $db = $this->repo->connection();
        $db->begin_transaction();
        try {
            if (!$this->repo->marcarCorreo($reunionId, (int)$usuarioId, $asunto, $cuerpo)) {
                throw new RuntimeException('La reunión cambió de estado. Actualiza la agenda.');
            }

            $seguimientoId = (int)$reunion['seguimiento_id'];
            $lugar = trim((string)($reunion['zoom_url'] ?? '')) ?: trim((string)($reunion['ubicacion'] ?? ''));
            $this->repo->asegurarPostEnvio($seguimientoId);
            $this->repo->sincronizarReunionPostEnvio(
                $seguimientoId,
                (int)$usuarioId,
                (string)$reunion['fecha_propuesta'],
                (string)$reunion['modalidad'],
                $lugar,
                trim((string)($reunion['objetivo'] ?? ''))
            );
            $this->repo->actualizarProximaAccion($seguimientoId, (int)$usuarioId, (string)$reunion['fecha_propuesta']);
            $etiquetaEcard = $ecardTemplate === 'SERGIO'
                ? 'Sergio López Porcayo'
                : ($ecardTemplate === 'MANUEL' ? 'Mtro. Manuel Porcayo' : '');

            $notaInteraccion =
                'Correo de confirmación de reunión registrado como enviado a ' .
                $correo . '. Asunto: ' . $asunto;

            if ($etiquetaEcard !== '') {
                $notaInteraccion .= '. Ecard: ' . $etiquetaEcard;
            }

            $this->repo->registrarInteraccion(
                $seguimientoId,
                (int)$usuarioId,
                $notaInteraccion
            );
            $db->commit();

            return [
                'ok' => true,
                'mensaje' => 'Correo registrado. La reunión quedó formalmente agendada y el flujo avanzó al paso 12.',
                'reunion_id' => $reunionId
            ];
        } catch (Throwable $error) {
            $db->rollback();
            return $this->error($error->getMessage(), $error instanceof RuntimeException ? 409 : 500);
        }
    }

    public function ajustarFlujoAnalista($seguimientoId, $usuarioId, $flujo)
    {
        if (!is_array($flujo) || !$this->tablaDisponible() || (int)($flujo['paso_actual'] ?? 0) !== 11) {
            return $flujo;
        }

        $reunion = $this->repo->ultimaReunionSeguimiento((int)$seguimientoId, (int)$usuarioId);
        $flujo['accion_principal'] = [
            'codigo' => 'AGENDAR_REUNION',
            'etiqueta' => 'Abrir agenda',
            'icono' => 'bi-calendar3'
        ];
        $flujo['accion_secundaria'] = null;

        if (!$reunion) {
            $flujo['titulo'] = 'Coordinar reunión';
            $flujo['descripcion'] = 'Abre la agenda, selecciona una fecha y envía la propuesta a Cuenta Clave para confirmación.';
            return $flujo;
        }

        $estado = (string)$reunion['estado'];
        $flujo['contexto']['reunion_estado'] = $estado;
        $flujo['contexto']['reunion_id'] = (int)$reunion['id'];
        $mapa = [
            'SOLICITADA' => [
                'Esperando confirmación de Cuenta Clave',
                'La fecha propuesta ya fue enviada. Cuenta Clave debe revisarla y confirmar la reunión antes de generar el enlace de Zoom.',
                'Ver solicitud en agenda'
            ],
            'CAMBIO_SOLICITADO' => [
                'Cuenta Clave solicitó un cambio',
                'Revisa la observación de Cuenta Clave y propone una nueva fecha desde la agenda.',
                'Reprogramar en agenda'
            ],
            'CONFIRMADA' => [
                'Reunión confirmada · enviar correo',
                'Cuenta Clave confirmó la fecha y agregó los datos de la reunión. Abre la agenda para preparar y enviar la confirmación a la institución.',
                'Preparar correo de reunión'
            ]
        ];

        if (isset($mapa[$estado])) {
            [$flujo['titulo'], $flujo['descripcion'], $flujo['accion_principal']['etiqueta']] = $mapa[$estado];
        }
        return $flujo;
    }

    public function obtenerNotificacionesCampana($usuarioId, $rolId, $limite = 10)
    {
        if (!$this->puedeAcceder($rolId) || !$this->tablaDisponible()) {
            return ['recordatorios' => [], 'avisos' => []];
        }

        $filas = (int)$rolId === self::ROL_CUENTA_CLAVE
            ? $this->repo->pendientesKam((int)$limite)
            : $this->repo->pendientesAnalista((int)$usuarioId, (int)$limite);
        $salida = [];

        foreach ($filas as $fila) {
            $estado = (string)($fila['estado'] ?? 'SOLICITADA');
            if ((int)$rolId === self::ROL_CUENTA_CLAVE) {
                $accion = 'Confirmar solicitud de reunión';
                $etiqueta = 'Pendiente de Cuenta Clave';
                $icono = 'bi-calendar-check';
                $estadoUi = 'proxima';
            } elseif ($estado === 'CAMBIO_SOLICITADO') {
                $accion = 'Cuenta Clave solicita cambiar la reunión';
                $etiqueta = 'Requiere ajuste';
                $icono = 'bi-calendar-x';
                $estadoUi = 'vencida';
            } elseif ($estado === 'CONFIRMADA') {
                $accion = 'Reunión confirmada · enviar correo';
                $etiqueta = 'Lista para enviar';
                $icono = 'bi-envelope-check';
                $estadoUi = 'proxima';
            } else {
                $accion = 'Reunión próxima';
                $etiqueta = $this->fechaLegible((string)$fila['fecha_propuesta']);
                $icono = 'bi-camera-video';
                $estadoUi = 'proxima';
            }

            $id = (int)$fila['id'];
            $salida[] = [
                'id' => $id,
                'nombre_entidad' => (string)$fila['nombre_entidad'],
                'accion' => $accion,
                'fecha' => (string)$fila['fecha_propuesta'],
                'etiqueta' => $etiqueta,
                'estado' => $estadoUi,
                'icono' => $icono,
                'url' => 'index.php?controller=agendaReunion&action=index&reunion_id=' . $id
            ];
        }

        return ['recordatorios' => $salida, 'avisos' => []];
    }

    private function prepararReunion($fila)
    {
        $fila['id'] = (int)($fila['id'] ?? 0);
        $fila['seguimiento_id'] = (int)($fila['seguimiento_id'] ?? 0);
        $fila['analista_id'] = (int)($fila['analista_id'] ?? 0);
        $fila['cuenta_clave_id'] = (int)($fila['cuenta_clave_id'] ?? 0);
        $fila['duracion_minutos'] = (int)($fila['duracion_minutos'] ?? 60);

        $estado = strtoupper(trim((string)($fila['estado'] ?? 'SOLICITADA')));
        $estadoVisual = $this->resolverEstadoVisual(
            $estado,
            (string)($fila['fecha_propuesta'] ?? '')
        );

        $fila['esta_vencida'] = (bool)$estadoVisual['vencida'];
        $fila['estado_visual'] = (string)$estadoVisual['clase'];
        $fila['estado_etiqueta'] = (string)$estadoVisual['etiqueta'];
        $fila['fecha_legible'] = $this->fechaLegible((string)($fila['fecha_propuesta'] ?? ''));

        $correo = $this->correoSugerido($fila);
        $fila['correo_sugerido_asunto'] = $correo['asunto'];
        $fila['correo_sugerido_cuerpo'] = $correo['cuerpo'];

        $ecard = $this->ecardService->metadatos($fila);
        $fila['ecard_template'] = (string)($ecard['template'] ?? 'manuel');
        $fila['ecard_template_predeterminado'] =
            (string)($ecard['template_predeterminado'] ?? $fila['ecard_template']);
        $fila['ecard_ponente'] = (string)($ecard['ponente'] ?? 'Mtro. Manuel Porcayo');
        $fila['ecard_cargo'] = (string)($ecard['cargo'] ?? 'Presidente');
        $fila['ecard_evento'] = (string)($ecard['evento'] ?? '');
        $fila['ecard_sede'] = (string)($ecard['sede'] ?? '');

        $previewBase =
            'index.php?controller=agendaReunion&action=ecardPreview&reunion_id=' .
            (int)$fila['id'] .
            '&v=' . rawurlencode(EcardReunionService::VERSION);

        $fila['ecard_preview_url'] =
            $previewBase .
            '&template=' . rawurlencode($fila['ecard_template']);
        $fila['ecard_preview_url_manuel'] =
            $previewBase . '&template=manuel';
        $fila['ecard_preview_url_sergio'] =
            $previewBase . '&template=sergio';
        $fila['ecard_ponente_manuel'] = 'Mtro. Manuel Porcayo';
        $fila['ecard_ponente_sergio'] = 'Sergio López Porcayo';

        return $fila;
    }

    private function resolverEstadoVisual($estado, $fechaPropuesta)
    {
        $estado = strtoupper(trim((string)$estado));
        $vencida = false;

        try {
            $fecha = trim((string)$fechaPropuesta) !== ''
                ? new DateTime((string)$fechaPropuesta)
                : null;
            $vencida = $fecha instanceof DateTime &&
                $fecha <= new DateTime() &&
                in_array($estado, ['SOLICITADA', 'CONFIRMADA', 'CORREO_ENVIADO'], true);
        } catch (Throwable $error) {
            $vencida = false;
        }

        if ($vencida) {
            $etiquetas = [
                'SOLICITADA' => 'Vencida · pendiente de Cuenta Clave',
                'CONFIRMADA' => 'Vencida · confirmación pendiente',
                'CORREO_ENVIADO' => 'Vencida · pendiente de registrar'
            ];

            return [
                'vencida' => true,
                'clase' => 'vencida',
                'etiqueta' => $etiquetas[$estado] ?? 'Vencida'
            ];
        }

        return [
            'vencida' => false,
            'clase' => strtolower(str_replace('_', '-', $estado !== '' ? $estado : 'SOLICITADA')),
            'etiqueta' => $this->etiquetaEstado($estado)
        ];
    }

    private function correoSugerido($r)
    {
        $institucion = trim((string)($r['nombre_entidad'] ?? 'la institución'));
        $contacto = trim((string)($r['contacto_nombre'] ?? ''));
        $objetivo = trim((string)($r['objetivo'] ?? ''));

        $lineas = [
            $contacto !== '' ? 'Buen día, ' . $contacto . ':' : 'Buen día:',
            '',
            'Por este medio confirmamos la reunión de vinculación con ' .
                $institucion . '.',
            '',
            'Fecha y hora: ' .
                $this->fechaLegible((string)($r['fecha_propuesta'] ?? '')),
            'Modalidad: ' .
                $this->etiquetaModalidad((string)($r['modalidad'] ?? ''))
        ];

        if (trim((string)($r['zoom_url'] ?? '')) !== '') {
            $lineas[] = 'Enlace de Zoom: ' . trim((string)$r['zoom_url']);
        }

        if (trim((string)($r['ubicacion'] ?? '')) !== '') {
            $lineas[] = 'Lugar: ' . trim((string)$r['ubicacion']);
        }

        if ($objetivo !== '') {
            $lineas[] = '';
            $lineas[] = 'Tema: ' . rtrim($objetivo, '. ') . '.';
        }

        $lineas[] = '';
        $lineas[] = 'Quedamos atentos a cualquier comentario y agradecemos su tiempo.';
        $lineas[] = '';
        $lineas[] = 'Saludos cordiales.';

        return [
            'asunto' => 'Confirmación de reunión de vinculación - ' . $institucion,
            'cuerpo' => implode("\n", $lineas)
        ];
    }

    private function validarPropuesta($fecha, $duracion, $modalidad, $objetivo)
    {
        if ($fecha === null || strtotime($fecha) <= time()) {
            return 'Selecciona una fecha y hora futura para la reunión.';
        }
        if (!in_array((int)$duracion, [30, 45, 60, 90, 120], true)) {
            return 'Selecciona una duración válida.';
        }
        if (!in_array($modalidad, ['VIRTUAL', 'PRESENCIAL', 'HIBRIDA'], true)) {
            return 'Selecciona una modalidad válida.';
        }
        return trim((string)$objetivo) === '' ? 'Indica brevemente el objetivo de la reunión.' : '';
    }

    private function normalizarMes($mes)
    {
        $mes = trim((string)$mes);
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return date('Y-m');
        }
        $fecha = DateTime::createFromFormat('!Y-m', $mes);
        return $fecha && $fecha->format('Y-m') === $mes ? $mes : date('Y-m');
    }

    private function normalizarFechaHora($valor)
    {
        $valor = str_replace('T', ' ', trim((string)$valor));
        if ($valor === '') {
            return null;
        }
        $fecha = DateTime::createFromFormat('Y-m-d H:i', $valor) ?: DateTime::createFromFormat('Y-m-d H:i:s', $valor);
        return $fecha ? $fecha->format('Y-m-d H:i:s') : null;
    }

    private function fechaLegible($valor)
    {
        try {
            return trim((string)$valor) !== '' ? (new DateTime((string)$valor))->format('d/m/Y · H:i') : '';
        } catch (Throwable $error) {
            return (string)$valor;
        }
    }

    private function etiquetaEstado($estado)
    {
        return [
            'SOLICITADA' => 'Pendiente de Cuenta Clave',
            'CAMBIO_SOLICITADO' => 'Cambio solicitado',
            'CONFIRMADA' => 'Confirmada',
            'CORREO_ENVIADO' => 'Confirmación enviada',
            'REALIZADA' => 'Realizada',
            'CANCELADA' => 'Cancelada'
        ][strtoupper(trim((string)$estado))] ?? 'Pendiente';
    }

    private function etiquetaModalidad($modalidad)
    {
        return [
            'VIRTUAL' => 'Virtual',
            'PRESENCIAL' => 'Presencial',
            'HIBRIDA' => 'Híbrida'
        ][strtoupper(trim((string)$modalidad))] ?? 'Por definir';
    }

    private function error($mensaje, $codigoHttp)
    {
        return ['ok' => false, 'mensaje' => $mensaje, 'codigo_http' => (int)$codigoHttp];
    }
}
