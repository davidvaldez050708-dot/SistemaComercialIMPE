<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/CorreoMarketingService.php';

class CorreoMarketingController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $service = new CorreoMarketingService();

        $tituloPagina = 'Correos';
        $subtituloPagina = 'Consulta y organiza la comunicación del área de Marketing.';
        $opcionActiva = 'correos_marketing';

        $resumenCorreos = $service->resumen($usuarioId);
        $correos = $service->listar($usuarioId);

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/correos/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function enviar()
    {
        $this->validarAccesoMarketing();

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $service = new CorreoMarketingService();
        $resultado = $service->enviar(
            (int)($_SESSION['usuario_id'] ?? 0),
            $_POST,
            $_FILES['adjuntos'] ?? null
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function validarAccesoMarketing()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        $rol = trim((string)($_SESSION['rol'] ?? ''));

        if (strcasecmp($rol, 'Marketing') !== 0) {
            http_response_code(403);
            die('No tienes permiso para acceder al módulo de Correos.');
        }
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
