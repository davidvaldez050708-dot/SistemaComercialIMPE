<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';

class CorreoMarketingController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Correos';
        $subtituloPagina = 'Consulta y organiza la comunicación del área de Marketing.';
        $opcionActiva = 'correos_marketing';

        $resumenCorreos = [
            'total' => 0,
            'enviados' => 0,
            'pendientes' => 0,
            'borradores' => 0
        ];

        $correos = [];

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/correos/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
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
}
