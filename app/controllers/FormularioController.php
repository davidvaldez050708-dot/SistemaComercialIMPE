<?php

class FormularioController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Formularios';
        $subtituloPagina = 'Administra los formularios de registro e inscripción.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function registro()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Formulario de Registro';
        $subtituloPagina = 'Gestiona la vista destinada al registro.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/registro.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function inscripcion()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Formulario de Inscripción';
        $subtituloPagina = 'Gestiona la vista destinada a la inscripción.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/inscripcion.php';
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
            die('No tienes permiso para acceder al módulo de Formularios.');
        }
    }
}
