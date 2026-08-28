<?php

require_once __DIR__ . '/../models/UsuarioModel.php';

class HomeController
{
    public function index()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: index.php');
            exit;
        }

        $idRol = (int)($_SESSION['rol_id'] ?? 0);

        $tituloPagina = 'Panel administrativo';
        $subtituloPagina = 'Gestión general del sistema';
        $opcionActiva = 'inicio';
        $vistaPanel = __DIR__ . '/../views/dashboard/en_desarrollo.php';

        switch ($idRol) {
            case 1:
                $modeloUsuario = new UsuarioModel();

                $conteoUsuarios = $modeloUsuario->contarUsuarios();
                $totalRoles = $modeloUsuario->contarRoles();
                $usuariosPorRol = $modeloUsuario->obtenerUsuariosPorRol();
                $usuariosRecientes = $modeloUsuario->obtenerUsuariosRecientes(6);

                $vistaPanel = __DIR__ . '/../views/dashboard/administrador.php';
                break;

            case 2:
                $subtituloPagina = 'Panel de Coordinador Comercial';
                $vistaPanel = __DIR__ . '/../views/dashboard/coordinador.php';
                break;

            case 3:
                $subtituloPagina = 'Panel de Asesor de Ventas';
                $vistaPanel = __DIR__ . '/../views/dashboard/asesor.php';
                break;

            case 4:
                $subtituloPagina = 'Panel de Analista de Datos';
                $vistaPanel = __DIR__ . '/../views/dashboard/analista.php';
                break;

            case 5:
                $subtituloPagina = 'Panel de Finanzas';
                $vistaPanel = __DIR__ . '/../views/dashboard/finanzas.php';
                break;

            default:
                $subtituloPagina = 'Rol no reconocido';
                break;
        }

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once $vistaPanel;
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }
}
