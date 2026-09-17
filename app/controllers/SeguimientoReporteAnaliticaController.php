<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/SeguimientoReporteAnaliticaService.php';

class SeguimientoReporteAnaliticaController
{
    public function actividad()
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $ids = $this->obtenerIds($_GET['ids'] ?? '');
        $fechaInicial = (string)($_GET['fecha_inicial'] ?? '');
        $fechaFinal = (string)($_GET['fecha_final'] ?? '');

        try {
            $resumen = (new SeguimientoReporteAnaliticaService())->construir(
                $ids,
                $usuarioId,
                $this->resolverModoAcceso(),
                $fechaInicial,
                $fechaFinal
            );

            $this->responder([
                'ok' => true,
                'resumen' => $resumen
            ]);
        } catch (Throwable $error) {
            error_log('[reporte_seguimiento_analitica] ' . $error->getMessage());
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible calcular la actividad del reporte.'
            ], 500);
        }
    }

    private function obtenerIds($valor)
    {
        $ids = [];

        foreach (explode(',', (string)$valor) as $item) {
            $id = (int)trim($item);
            if ($id > 0) {
                $ids[$id] = $id;
            }

            if (count($ids) >= 200) {
                break;
            }
        }

        return array_values($ids);
    }

    private function resolverModoAcceso()
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return 'administrador';
        }

        if (tienePermiso('seguimientos_vinculacion.supervisar')) {
            return 'supervisor';
        }

        return 'analista';
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso($codigo)) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes permiso para consultar este reporte.'
            ], 403);
        }
    }

    private function responder(array $datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
