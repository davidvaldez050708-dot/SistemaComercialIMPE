<?php

require_once __DIR__ . '/../helpers/ReminderHelper.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReminderAgendaFilterService.php';
require_once __DIR__ . '/../services/ReminderReunionFollowupService.php';
require_once __DIR__ . '/../services/ReminderDirectLinkService.php';
require_once __DIR__ . '/../services/ReminderObservacionService.php';
require_once __DIR__ . '/../services/ReminderMeetingConfirmationService.php';

class ReminderController
{
    private $agendaReunionService;
    private $reminderAgendaFilterService;
    private $reminderReunionFollowupService;
    private $reminderDirectLinkService;
    private $reminderObservacionService;
    private $reminderMeetingConfirmationService;

    public function __construct()
    {
        $this->agendaReunionService = new AgendaReunionService();
        $this->reminderAgendaFilterService = new ReminderAgendaFilterService();
        $this->reminderReunionFollowupService = new ReminderReunionFollowupService();
        $this->reminderDirectLinkService = new ReminderDirectLinkService();
        $this->reminderObservacionService = new ReminderObservacionService();
        $this->reminderMeetingConfirmationService = new ReminderMeetingConfirmationService();
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

        // Las reuniones que siguen SOLICITADAS requieren una lectura adicional:
        // el Analista debe enterarse cuando la confirmación se acerca o ya venció,
        // y Cuenta Clave debe ver con prioridad las solicitudes vencidas.
        $confirmaciones = $this->reminderMeetingConfirmationService->obtener(
            $usuarioId,
            $rolId,
            10
        );
        $recordatoriosConfirmacion = array_values(
            $confirmaciones['recordatorios'] ?? []
        );
        $avisosConfirmacion = array_values(
            $confirmaciones['avisos'] ?? []
        );

        // Para Cuenta Clave, Agenda ya devuelve todas las SOLICITADAS. Cuando una
        // de ellas está vencida, sustituimos la versión genérica por la versión
        // urgente del servicio especializado para no duplicarla en la campana.
        if ($rolId === 6 && !empty($recordatoriosConfirmacion)) {
            $reunionesUrgentes = [];
            foreach ($recordatoriosConfirmacion as $recordatorioConfirmacion) {
                $reunionId = (int)($recordatorioConfirmacion['reunion_id'] ?? 0);
                if ($reunionId > 0) {
                    $reunionesUrgentes[$reunionId] = true;
                }
            }

            if (!empty($reunionesUrgentes)) {
                $recordatoriosAgenda = array_values(array_filter(
                    $recordatoriosAgenda,
                    static function ($item) use ($reunionesUrgentes) {
                        $reunionId = (int)($item['reunion_id'] ?? $item['id'] ?? 0);
                        return $reunionId <= 0 || !isset($reunionesUrgentes[$reunionId]);
                    }
                ));
            }
        }

        $requiereMigracion = false;
        $ok = true;
        $recordatorios = array_merge(
            $recordatoriosConfirmacion,
            $recordatoriosAgenda
        );
        $avisos = array_merge(
            $avisosConfirmacion,
            $avisosAgenda
        );

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

            $recordatorios = array_merge(
                $recordatoriosObservaciones,
                $recordatoriosConfirmacion,
                $recordatoriosAgenda,
                $recordatoriosAcuerdos,
                $recordatoriosSeguimiento
            );
            $avisos = array_values(array_merge(
                $avisosObservaciones,
                $avisosConfirmacion,
                $avisosAgenda,
                $avisosAcuerdos,
                $avisosSeguimiento
            ));
            $requiereMigracion = (bool)($resultado['requiere_migracion'] ?? false);
            $ok = (bool)($resultado['ok'] ?? true);
        }

        // La campana debe priorizar obligaciones vencidas y acciones que requieren
        // intervención, sin depender del orden en que cada servicio fue consultado.
        $recordatorios = array_slice(
            $this->ordenarRecordatorios($recordatorios),
            0,
            12
        );

        echo json_encode([
            'ok' => $ok,
            'requiere_migracion' => $requiereMigracion,
            'avisos' => array_values($avisos),
            'recordatorios' => $recordatorios,
            'total' => count($recordatorios)
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function ordenarRecordatorios($items)
    {
        $items = array_values(is_array($items) ? $items : []);

        foreach ($items as $indice => &$item) {
            $item['_orden_original'] = $indice;
        }
        unset($item);

        usort($items, function ($a, $b) {
            $prioridadA = $this->prioridadRecordatorio($a);
            $prioridadB = $this->prioridadRecordatorio($b);

            if ($prioridadA !== $prioridadB) {
                return $prioridadA <=> $prioridadB;
            }

            $fechaA = strtotime((string)($a['fecha'] ?? '')) ?: 0;
            $fechaB = strtotime((string)($b['fecha'] ?? '')) ?: 0;

            if ($fechaA !== $fechaB && $fechaA > 0 && $fechaB > 0) {
                // Para vencidas/próximas, la fecha más inmediata va primero.
                // Para informativas, mostramos primero lo más reciente.
                return $prioridadA <= 2
                    ? ($fechaA <=> $fechaB)
                    : ($fechaB <=> $fechaA);
            }

            return (int)($a['_orden_original'] ?? 0) <=>
                (int)($b['_orden_original'] ?? 0);
        });

        foreach ($items as &$item) {
            unset($item['_orden_original']);
        }
        unset($item);

        return $items;
    }

    private function prioridadRecordatorio($item)
    {
        if (isset($item['prioridad']) && is_numeric($item['prioridad'])) {
            return max(0, min(9, (int)$item['prioridad']));
        }

        $estado = strtolower(trim((string)($item['estado'] ?? '')));
        $etiqueta = strtolower(trim((string)($item['etiqueta'] ?? '')));
        $accion = strtolower(trim((string)($item['accion'] ?? '')));

        if (
            $estado === 'vencida' ||
            strpos($etiqueta, 'vencida') !== false ||
            strpos($etiqueta, 'ayer') !== false
        ) {
            return 0;
        }

        if (
            $etiqueta === 'nueva' ||
            strpos($accion, 'solicita cambiar') !== false ||
            strpos($accion, 'reunión confirmada') !== false ||
            strpos($accion, 'reunion confirmada') !== false
        ) {
            return 1;
        }

        if ($estado === 'proxima' || $estado === 'hoy') {
            return 2;
        }

        if ($estado === 'manana' || $estado === 'mañana') {
            return 3;
        }

        return 4;
    }
}
