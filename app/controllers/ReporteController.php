<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/UsuarioModel.php';

class ReporteController
{
    public function usuarios()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarLogin');
            exit;
        }

        if ((int)($_SESSION['rol_id'] ?? 0) !== 1) {
            http_response_code(403);
            die('No tienes permiso para generar este reporte.');
        }

        $rolesReporteAdministrador = (new UsuarioModel())->obtenerRolesActivos();

        $tituloPagina = 'Reportes';
        $subtituloPagina = 'Selecciona los roles que deseas incluir en el reporte administrativo.';
        $opcionActiva = 'reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/administrador_usuarios.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function index()
    {
        $puedeReporteTerritorial = tienePermiso('data_territorial.ver');
        $puedeReporteSeguimiento = tienePermiso('seguimientos_vinculacion.ver');
        $puedeReporteAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;

        if (!$puedeReporteTerritorial && !$puedeReporteSeguimiento && !$puedeReporteAdministrador) {
            http_response_code(403);
            die('No tienes permiso para consultar reportes.');
        }

        $tituloPagina = 'Reportes';
        $subtituloPagina = 'Consulta, analiza y exporta información de los módulos disponibles.';
        $opcionActiva = 'reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }
}
