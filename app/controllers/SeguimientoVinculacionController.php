<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class SeguimientoVinculacionController
{
    public function index()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $territorios = $modelo->obtenerEstadosAsignadosAnalista($usuarioId);
        $mensajeError = $_SESSION['error_seguimiento_vinculacion'] ?? '';

        unset($_SESSION['error_seguimiento_vinculacion']);

        $tituloPagina = 'Seguimiento de vinculación';
        $subtituloPagina = 'Selecciona uno de tus territorios asignados';
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function estado()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $estado = $modelo->obtenerEstadoAsignadoAnalista($usuarioId, $estadoId);

        if (!$estado) {
            $_SESSION['error_seguimiento_vinculacion'] =
                'No tienes acceso a este territorio.';
            $this->redirigirASeguimiento();
        }

        $resumen = $modelo->obtenerResumenSeguimientosAnalistaEstado(
            $usuarioId,
            $estadoId
        );
        $seguimientos = $modelo->obtenerSeguimientosAnalistaEstado(
            $usuarioId,
            $estadoId
        );

        $tituloPagina = 'Seguimiento de vinculación';
        $subtituloPagina = (string)$estado['nombre'];
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/estado.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function obtenerUsuarioActualId()
    {
        return (int)($_SESSION['usuario_id'] ?? 0);
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (!tienePermiso($codigo)) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }

    private function redirigirASeguimiento()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=seguimientoVinculacion&action=index'
        );
        exit;
    }
}
