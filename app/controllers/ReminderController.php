<?php

require_once __DIR__ . '/../helpers/ReminderHelper.php';

class ReminderController
{
    public function pendientes()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No tienes acceso a estos recordatorios.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $resultado = obtenerAvisosPendientesRecordatoriosAnalista($usuarioId);
        $recordatorios = serializarRecordatoriosSeguimiento(
            $resultado['recordatorios'] ?? []
        );

        echo json_encode([
            'ok' => (bool)($resultado['ok'] ?? false),
            'requiere_migracion' => (bool)($resultado['requiere_migracion'] ?? false),
            'avisos' => array_values($resultado['avisos'] ?? []),
            'recordatorios' => $recordatorios,
            'total' => count($recordatorios)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
