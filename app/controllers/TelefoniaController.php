<?php

require_once __DIR__ . '/../services/TelefoniaExtensionService.php';
require_once __DIR__ . '/../models/RolModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class TelefoniaController
{
    private $service;

    public function __construct()
    {
        $modeloRol = new RolModel();
        $modeloRol->inicializarPermisosSistema();

        if (isset($_SESSION['rol_id'])) {
            $_SESSION['permisos'] =
                $modeloRol->obtenerCodigosPermisosPorRol(
                    (int)$_SESSION['rol_id']
                );
        }

        $this->service = new TelefoniaExtensionService();
    }

    public function index()
    {
        $this->validarAdministrador();

        $usuariosTelefonia = [];
        $resumenTelefonia = [
            'usuarios' => 0,
            'configurados' => 0,
            'activos' => 0,
            'pendientes' => 0,
        ];

        $mensajeExito = $_SESSION['mensaje_telefonia'] ?? '';
        $mensajeError = $_SESSION['error_telefonia'] ?? '';
        $datosFormulario = $_SESSION['datos_telefonia'] ?? [];

        unset(
            $_SESSION['mensaje_telefonia'],
            $_SESSION['error_telefonia'],
            $_SESSION['datos_telefonia']
        );

        try {
            $usuariosTelefonia =
                $this->service->listarUsuariosConfigurables();

            $resumenTelefonia =
                $this->service->resumenConfiguracion(
                    $usuariosTelefonia
                );
        } catch (Throwable $e) {
            $mensajeError = $mensajeError !== ''
                ? $mensajeError
                : 'No fue posible preparar la configuración de telefonía.';
        }

        $tituloPagina = 'Telefonía';
        $subtituloPagina =
            'Asigna extensiones Zadarma a los usuarios que utilizarán llamadas';
        $opcionActiva = 'telefonia';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/telefonia/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function guardarExtension()
    {
        $this->validarAdministrador();
        $this->validarPost();

        $datos = [
            'usuario_id' =>
                (int)($_POST['usuario_id'] ?? 0),
            'extension' =>
                trim((string)($_POST['extension'] ?? '')),
            'caller_id' =>
                trim((string)($_POST['caller_id'] ?? '')),
            'permite_salientes' =>
                isset($_POST['permite_salientes']) ? 1 : 0,
            'permite_entrantes' =>
                isset($_POST['permite_entrantes']) ? 1 : 0,
            'activo' =>
                isset($_POST['activo']) ? 1 : 0,
        ];

        try {
            $this->service->guardarAsignacion(
                $datos['usuario_id'],
                $datos['extension'],
                $datos['caller_id'],
                $datos['permite_salientes'] === 1,
                $datos['permite_entrantes'] === 1,
                $datos['activo'] === 1,
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            $_SESSION['mensaje_telefonia'] =
                'Extensión telefónica guardada correctamente.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_telefonia'] = $e->getMessage();
            $_SESSION['datos_telefonia'] = $datos;
        } catch (Throwable $e) {
            error_log(
                'No fue posible guardar telefonía: ' .
                $e->getMessage()
            );
            $_SESSION['error_telefonia'] =
                'No fue posible guardar la extensión. Revisa los datos e intenta nuevamente.';
            $_SESSION['datos_telefonia'] = $datos;
        }

        $this->volver();
    }

    public function liberarExtension()
    {
        $this->validarAdministrador();
        $this->validarPost();

        $usuarioId = (int)($_POST['usuario_id'] ?? 0);

        try {
            $this->service->liberarAsignacion(
                $usuarioId,
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            $_SESSION['mensaje_telefonia'] =
                'La extensión quedó libre para poder asignarla a otro usuario.';
        } catch (Throwable $e) {
            error_log(
                'No fue posible liberar telefonía: ' .
                $e->getMessage()
            );
            $_SESSION['error_telefonia'] =
                'No fue posible liberar la extensión seleccionada.';
        }

        $this->volver();
    }

    private function validarAdministrador()
    {
        if (
            (int)($_SESSION['rol_id'] ?? 0) !== 1 ||
            !tienePermiso('telefonia.configurar')
        ) {
            http_response_code(403);
            die('No tienes permiso para configurar la telefonía.');
        }
    }

    private function validarPost()
    {
        if (
            strtoupper(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
            ) !== 'POST'
        ) {
            http_response_code(405);
            die('Método no permitido.');
        }
    }

    private function volver()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=telefonia&action=index'
        );
        exit;
    }
}
