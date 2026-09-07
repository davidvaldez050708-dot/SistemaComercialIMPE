<?php

require_once __DIR__ . '/../services/InteraccionRutaService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class SeguimientoInteraccionController
{
    private $service;

    public function __construct()
    {
        $this->service = new InteraccionRutaService();
    }

    public function registrarInformativa()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Solo el Analista responsable puede registrar esta interacción.'
            ], 403);
        }

        if (!tienePermiso('seguimientos_vinculacion.ver')) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes permiso para registrar interacciones.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un seguimiento válido.'
            ], 422);
        }

        $resultado = $this->service->registrarInformativa(
            $seguimientoId,
            $usuarioId,
            $_POST
        );
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
