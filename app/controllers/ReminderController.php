<?php

require_once __DIR__ . '/../helpers/ReminderHelper.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';

class ReminderController
{
    private $agendaReunionService;

    public function __construct()
    {
        $this->agendaReunionService = new AgendaReunionService();
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

            $recordatorios = array_slice(
                array_merge($recordatoriosAgenda, $recordatoriosSeguimiento),
                0,
                12
            );
            $avisos = array_values(array_merge(
                $avisosAgenda,
                $resultado['avisos'] ?? []
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
