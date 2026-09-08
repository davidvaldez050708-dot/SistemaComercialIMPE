<?php

require_once __DIR__ . '/../helpers/ReminderHelper.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReminderAgendaFilterService.php';
require_once __DIR__ . '/../services/ReminderReunionFollowupService.php';
require_once __DIR__ . '/../services/ReminderDirectLinkService.php';
require_once __DIR__ . '/../services/ReminderObservacionService.php';
require_once __DIR__ . '/../services/ReminderCorreoEntranteService.php';
require_once __DIR__ . '/../services/HostingerInboundMailService.php';
require_once __DIR__ . '/../services/SeguimientoPostEnvioService.php';

class ReminderController
{
    private $agendaReunionService;
    private $reminderAgendaFilterService;
    private $reminderReunionFollowupService;
    private $reminderDirectLinkService;
    private $reminderObservacionService;
    private $reminderCorreoEntranteService;
    private $hostingerInboundMailService;

    public function __construct()
    {
        $this->agendaReunionService = new AgendaReunionService();
        $this->reminderAgendaFilterService = new ReminderAgendaFilterService();
        $this->reminderReunionFollowupService = new ReminderReunionFollowupService();
        $this->reminderDirectLinkService = new ReminderDirectLinkService();
        $this->reminderObservacionService = new ReminderObservacionService();
        $this->reminderCorreoEntranteService = new ReminderCorreoEntranteService();
        $this->hostingerInboundMailService = new HostingerInboundMailService();
    }

    public function pendientes()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || !in_array($rolId, [4, 6], true)) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No tienes acceso a estas notificaciones.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $agenda = $this->agendaReunionService->obtenerNotificacionesCampana(
            $usuarioId,
            $rolId,
            10
        );
        $recordatoriosAgenda = array_values($agenda['recordatorios'] ?? []);
        $avisosAgenda = array_values($agenda['avisos'] ?? []);

        $correoEntrante = $this->reminderCorreoEntranteService->obtener(
            $usuarioId,
            $rolId,
            10
        );
        $recordatoriosCorreo = array_values($correoEntrante['recordatorios'] ?? []);
        $avisosCorreo = array_values($correoEntrante['avisos'] ?? []);

        $requiereMigracion = false;
        $ok = true;
        $recordatorios = array_slice(
            array_merge($recordatoriosCorreo, $recordatoriosAgenda),
            0,
            12
        );
        $avisos = array_values(array_merge($avisosCorreo, $avisosAgenda));

        if ($rolId === 4) {
            $resultado = obtenerAvisosPendientesRecordatoriosAnalista($usuarioId);
            $recordatoriosSeguimiento = serializarRecordatoriosSeguimiento(
                $resultado['recordatorios'] ?? []
            );

            // Los pasos administrados por la agenda/reunión no deben conservar
            // recordatorios antiguos como "Enviar oficio/correo".
            $recordatoriosSeguimiento = $this->reminderAgendaFilterService
                ->filtrarRecordatoriosSeguimiento($recordatoriosSeguimiento, $usuarioId);
            $avisosSeguimiento = $this->reminderAgendaFilterService
                ->filtrarAvisosSeguimiento($resultado['avisos'] ?? [], $usuarioId);

            // Después de una reunión realizada con acuerdos pendientes usamos un
            // recordatorio propio: "Dar seguimiento a acuerdos".
            $seguimientoReunion = $this->reminderReunionFollowupService->obtener(
                $usuarioId,
                10
            );
            $recordatoriosAcuerdos = array_values(
                $seguimientoReunion['recordatorios'] ?? []
            );
            $avisosAcuerdos = array_values(
                $seguimientoReunion['avisos'] ?? []
            );

            // Las observaciones de Cuenta Clave se muestran como notificaciones
            // persistentes hasta que el Analista abra ese seguimiento.
            $observaciones = $this->reminderObservacionService->obtener(
                $usuarioId,
                10
            );
            $recordatoriosObservaciones = array_values(
                $observaciones['recordatorios'] ?? []
            );
            $avisosObservaciones = array_values(
                $observaciones['avisos'] ?? []
            );

            // Si el servicio especializado ya reconoce un seguimiento como
            // "Dar seguimiento a acuerdos", eliminamos cualquier recordatorio o
            // aviso genérico del mismo seguimiento. Esto evita que sobrevivan
            // mensajes de etapas anteriores como "Enviar oficio/correo".
            $idsAcuerdos = [];
            foreach ($recordatoriosAcuerdos as $itemAcuerdos) {
                $idAcuerdos = (int)($itemAcuerdos['seguimiento_id'] ?? $itemAcuerdos['id'] ?? 0);
                if ($idAcuerdos > 0) {
                    $idsAcuerdos[$idAcuerdos] = true;
                }
            }

            if (!empty($idsAcuerdos)) {
                $recordatoriosSeguimiento = array_values(array_filter(
                    $recordatoriosSeguimiento,
                    static function ($item) use ($idsAcuerdos) {
                        $id = (int)($item['seguimiento_id'] ?? $item['id'] ?? 0);
                        return $id <= 0 || !isset($idsAcuerdos[$id]);
                    }
                ));

                $avisosSeguimiento = array_values(array_filter(
                    $avisosSeguimiento,
                    static function ($item) use ($idsAcuerdos) {
                        $id = (int)($item['seguimiento_id'] ?? $item['id'] ?? 0);
                        return $id <= 0 || !isset($idsAcuerdos[$id]);
                    }
                ));
            }

            // Las notificaciones operativas del Analista deben abrir directamente
            // el panel "Trabajar" del seguimiento. Las notificaciones de agenda
            // y de correo conservan sus URL especializadas.
            $recordatoriosSeguimiento = $this->reminderDirectLinkService->aplicar(
                $recordatoriosSeguimiento,
                $usuarioId
            );
            $avisosSeguimiento = $this->reminderDirectLinkService->aplicar(
                $avisosSeguimiento,
                $usuarioId
            );
            $recordatoriosAcuerdos = $this->reminderDirectLinkService->aplicar(
                $recordatoriosAcuerdos,
                $usuarioId
            );
            $avisosAcuerdos = $this->reminderDirectLinkService->aplicar(
                $avisosAcuerdos,
                $usuarioId
            );

            $recordatorios = array_slice(
                array_merge(
                    $recordatoriosCorreo,
                    $recordatoriosObservaciones,
                    $recordatoriosAgenda,
                    $recordatoriosAcuerdos,
                    $recordatoriosSeguimiento
                ),
                0,
                12
            );
            $avisos = array_values(array_merge(
                $avisosCorreo,
                $avisosObservaciones,
                $avisosAgenda,
                $avisosAcuerdos,
                $avisosSeguimiento
            ));
            $requiereMigracion = (bool)($resultado['requiere_migracion'] ?? false);
            $ok = (bool)($resultado['ok'] ?? true);
        }

        echo json_encode([
            'ok' => $ok,
            'requiere_migracion' => $requiereMigracion,
            'avisos' => $avisos,
            'recordatorios' => $recordatorios,
            'total' => count($recordatorios)
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function correoEntrante()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $respuestaId = (int)($_GET['id'] ?? 0);

        if ($usuarioId <= 0 || !in_array($rolId, [4, 6], true)) {
            http_response_code(403);
            die('No tienes acceso a esta respuesta de correo.');
        }

        $correoEntrante = $this->hostingerInboundMailService->obtenerParaUsuario(
            $respuestaId,
            $usuarioId,
            $rolId
        );

        if (!$correoEntrante) {
            http_response_code(404);
            die('La respuesta de correo no existe o no está asignada a tu usuario.');
        }

        $this->hostingerInboundMailService->marcarLeido($respuestaId, $usuarioId);

        $mensajeExito = isset($_GET['registrada'])
            ? 'La respuesta quedó registrada en la ruta de vinculación.'
            : '';
        $mensajeError = $_SESSION['error_correo_entrante'] ?? '';
        unset($_SESSION['error_correo_entrante']);

        $tituloPagina = 'Respuesta de correo';
        $subtituloPagina = (string)($correoEntrante['nombre_entidad'] ?? 'Seguimiento de vinculación');
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reminders/correo_entrante.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function registrarCorreoEntranteRespuesta()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            die('Método no permitido.');
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $respuestaId = (int)($_POST['respuesta_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            http_response_code(403);
            die('Solo el Analista responsable puede registrar esta respuesta.');
        }

        $correoEntrante = $this->hostingerInboundMailService->obtenerParaUsuario(
            $respuestaId,
            $usuarioId,
            $rolId
        );

        if (!$correoEntrante || !($correoEntrante['puede_registrar_respuesta'] ?? false)) {
            $_SESSION['error_correo_entrante'] =
                'Esta respuesta ya no puede registrarse automáticamente en la ruta.';
            $this->redirigirCorreoEntrante($respuestaId);
        }

        $seguimientoId = (int)($correoEntrante['seguimiento_id'] ?? 0);
        $tipo = strtoupper(trim((string)($_POST['respuesta_tipo'] ?? '')));
        $texto = trim((string)($_POST['respuesta_texto'] ?? ''));
        $contactarDespues = trim((string)($_POST['contactar_despues_at'] ?? ''));

        $servicioPostEnvio = new SeguimientoPostEnvioService();
        $resultado = $servicioPostEnvio->registrarAccion(
            $seguimientoId,
            $usuarioId,
            'REGISTRAR_RESPUESTA',
            [
                'respuesta_tipo' => $tipo,
                'respuesta_canal' => 'CORREO',
                'respuesta_texto' => $texto,
                'contactar_despues_at' => $contactarDespues
            ]
        );

        if (!($resultado['ok'] ?? false)) {
            $_SESSION['error_correo_entrante'] =
                (string)($resultado['mensaje'] ?? 'No fue posible registrar la respuesta.');
            $this->redirigirCorreoEntrante($respuestaId);
        }

        $this->hostingerInboundMailService->marcarProcesada($respuestaId, $usuarioId);

        header(
            'Location: ' . BASE_URL .
            'index.php?controller=reminder&action=correoEntrante&id=' .
            $respuestaId . '&registrada=1'
        );
        exit;
    }

    private function redirigirCorreoEntrante($respuestaId)
    {
        header(
            'Location: ' . BASE_URL .
            'index.php?controller=reminder&action=correoEntrante&id=' .
            (int)$respuestaId
        );
        exit;
    }
}
