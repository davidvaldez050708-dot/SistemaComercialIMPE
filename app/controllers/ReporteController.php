<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ReporteController
{
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
