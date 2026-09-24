<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../models/TerritorioModel.php';
require_once __DIR__ . '/../models/AnalistaDashboardModel.php';
require_once __DIR__ . '/../models/ConvocatoriaModel.php';
require_once __DIR__ . '/../services/AnalistaDashboardReunionService.php';
require_once __DIR__ . '/../services/AnalistaDashboardIntegrityService.php';
require_once __DIR__ . '/../services/SeguimientoAtencionOperativaService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

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
                $tituloPagina = 'Inicio';
                $subtituloPagina = 'Resumen operativo de tus seguimientos de vinculación';

                $usuarioId = (int)$_SESSION['usuario_id'];
                $modeloDashboardAnalista = new AnalistaDashboardModel();
                $tableroAnalista = $modeloDashboardAnalista->obtenerTablero($usuarioId);

                $servicioReunionesDashboard = new AnalistaDashboardReunionService();
                $tableroAnalista = $servicioReunionesDashboard->ajustar(
                    $tableroAnalista,
                    $usuarioId
                );

                $servicioIntegridadDashboard = new AnalistaDashboardIntegrityService();
                $tableroAnalista = $servicioIntegridadDashboard->ajustar(
                    $tableroAnalista,
                    $usuarioId
                );

                // Fuente única final para "requieren atención" en Inicio y Reportes.
                $servicioAtencionOperativa = new SeguimientoAtencionOperativaService();
                $atencionesOperativas = $servicioAtencionOperativa->obtenerPorAnalista($usuarioId);
                $totalAtencionesOperativas = count($atencionesOperativas);

                $tableroAnalista['atenciones'] = array_slice($atencionesOperativas, 0, 6);
                $tableroAnalista['resumen']['requieren_atencion'] = $totalAtencionesOperativas;
                $tableroAnalista['integridad_dashboard'] = is_array(
                    $tableroAnalista['integridad_dashboard'] ?? null
                ) ? $tableroAnalista['integridad_dashboard'] : [];
                $tableroAnalista['integridad_dashboard']['requieren_atencion_total'] =
                    $totalAtencionesOperativas;

                $vistaPanel = __DIR__ . '/../views/dashboard/analista.php';
                break;

            case 5:
                $subtituloPagina = 'Panel de Finanzas';
                $vistaPanel = __DIR__ . '/../views/dashboard/finanzas.php';
                break;

            case 6:
                $tituloPagina = 'Panel de Cuenta Clave';
                $subtituloPagina = 'Gestión de vinculación institucional';

                $modeloTerritorio = new TerritorioModel();
                $resumenCuentaClave = $modeloTerritorio->obtenerResumenCuentaClave(
                    (int)$_SESSION['usuario_id']
                );

                $vistaPanel = __DIR__ . '/../views/dashboard/cuenta_clave.php';
                break;

            default:
                if (strcasecmp((string)($_SESSION['rol'] ?? ''), 'Marketing') === 0) {
                    $tituloPagina = 'Panel de Marketing';
                    $subtituloPagina = 'Gestión de convocatorias y publicaciones';

                    $modeloConvocatoria = new ConvocatoriaModel();
                    $modeloConvocatoria->desactivarConvocatoriasVencidas();
                    $resumenMarketing = $modeloConvocatoria->obtenerResumenDashboard();
                    $coberturaMarketing = $modeloConvocatoria->obtenerCoberturaTerritorialDashboard(4);

                    $periodoPublicaciones = (int)($_GET['periodo_publicaciones'] ?? 30);
                    if (!in_array($periodoPublicaciones, [7, 30, 90], true)) {
                        $periodoPublicaciones = 30;
                    }

                    $totalPublicacionesPeriodo = $modeloConvocatoria
                        ->obtenerPublicacionesPorPeriodoDashboard($periodoPublicaciones);

                    $vistaPanel = __DIR__ . '/../views/dashboard/marketing.php';
                    break;
                }

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
