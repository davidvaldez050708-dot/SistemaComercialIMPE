<?php

require_once __DIR__ . '/../services/SeguimientoFlujoService.php';

class SeguimientoFlujoController
{
    private $service;

    public function __construct()
    {
        $this->service = new SeguimientoFlujoService();
    }

    public function estado()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La ruta de trabajo está disponible para el Analista responsable.'
            ], 403);
        }

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un seguimiento válido.'
            ], 422);
        }

        $resultado = $this->service->obtenerEstado($seguimientoId, $usuarioId);
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
