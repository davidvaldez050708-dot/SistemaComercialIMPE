<?php

require_once __DIR__ . '/../helpers/ReminderHelper.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReminderAgendaFilterService.php';
require_once __DIR__ . '/../services/ReminderReunionFollowupService.php';
require_once __DIR__ . '/../services/ReminderDirectLinkService.php';

class ReminderController
{
    private $agendaReunionService;
    private $reminderAgendaFilterService;
    private $reminderReunionFollowupService;
    private $reminderDirectLinkService;

    public function __construct()
    {
        $this->agendaReunionService = new AgendaReunionService();
        $this->reminderAgendaFilterService = new ReminderAgendaFilterService();
        $this->reminderReunionFollowupService = new ReminderReunionFollowupService();
        $this->reminderDirectLinkService = new ReminderDirectLinkService();
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
        $requiereMigracion = false;
        $ok = true;
        $recordatorios = $recordatoriosAgenda;
        $avisos = $avisosAgenda;

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
            // conservan su URL propia hacia la reunión correspondiente.
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
                    $recordatoriosAgenda,
                    $recordatoriosAcuerdos,
                    $recordatoriosSeguimiento
                ),
                0,
                12
            );
            $avisos = array_values(array_merge(
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
}
