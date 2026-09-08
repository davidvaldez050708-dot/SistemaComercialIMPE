<?php

require_once __DIR__ . '/../services/CorreoFirmadoService.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';

class CorreoFirmadoController
{
    private $service;

    public function __construct()
    {
        $this->service = new CorreoFirmadoService();
    }

    public function enviarSeguimiento()
    {
        $this->validarAnalistaPost();

        $resultado = $this->service->enviarSeguimiento(
            (int)($_POST['seguimiento_id'] ?? 0),
            (int)$_SESSION['usuario_id'],
            (string)($_POST['asunto'] ?? ''),
            (string)($_POST['cuerpo'] ?? '')
        );

        $this->responderResultado($resultado);
    }

    public function enviarReunion()
    {
        $this->validarAnalistaPost();

        $resultado = $this->service->enviarReunion(
            (int)($_POST['reunion_id'] ?? 0),
            (int)$_SESSION['usuario_id'],
            (string)($_POST['asunto'] ?? ''),
            (string)($_POST['cuerpo'] ?? ''),
            (string)($_POST['reprogramacion'] ?? '0') === '1'
        );

        $this->responderResultado($resultado);
    }

    private function validarAnalistaPost()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== AgendaReunionService::ROL_ANALISTA) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Solo el Analista puede realizar este envío.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function responderResultado($resultado)
    {
        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);
        $this->responder($resultado, $codigo);
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
