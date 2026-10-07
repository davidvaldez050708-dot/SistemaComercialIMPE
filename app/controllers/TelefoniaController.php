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

    public function estadoUsuario()
    {
        $this->validarUsuarioTelefoniaJson();

        try {
            $asignacion = $this->service->resolverParaUsuario(
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            if (!$asignacion) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Tu usuario no tiene una extensión Zadarma activa asignada.'
                ], 422);
            }

            if (empty($asignacion['permite_salientes'])) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Tu extensión no tiene habilitadas llamadas salientes.'
                ], 403);
            }

            $this->responderJson([
                'ok' => true,
                'extension' => (string)($asignacion['extension'] ?? ''),
                'caller_id' => (string)($asignacion['caller_id'] ?? ''),
                'permite_salientes' => !empty($asignacion['permite_salientes']),
                'permite_entrantes' => !empty($asignacion['permite_entrantes']),
                'origen' => (string)($asignacion['origen'] ?? 'USUARIO')
            ]);
        } catch (Throwable $e) {
            error_log(
                'No fue posible consultar estado de telefonía: ' .
                $e->getMessage()
            );

            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible consultar tu configuración de telefonía.'
            ], 500);
        }
    }

    public function host()
    {
        $this->validarUsuarioTelefoniaHtml();

        try {
            $asignacionTelefonica =
                $this->service->resolverParaUsuario(
                    (int)($_SESSION['usuario_id'] ?? 0)
                );

            if (
                !$asignacionTelefonica ||
                empty($asignacionTelefonica['permite_salientes'])
            ) {
                http_response_code(403);
                die(
                    'Tu usuario no tiene una extensión activa para llamadas salientes.'
                );
            }
        } catch (Throwable $e) {
            http_response_code(500);
            die('No fue posible preparar el motor de telefonía.');
        }

        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );
        header('Pragma: no-cache');

        require_once __DIR__ . '/../views/telefonia/host.php';
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

    private function validarUsuarioTelefoniaJson()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rol = trim((string)($_SESSION['rol'] ?? ''));

        if (
            $usuarioId <= 0 ||
            !in_array(
                $rol,
                ['Analista de Datos', 'Asesor de Ventas'],
                true
            )
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Tu perfil no tiene acceso al motor de telefonía.'
            ], 403);
        }
    }

    private function validarUsuarioTelefoniaHtml()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rol = trim((string)($_SESSION['rol'] ?? ''));

        if (
            $usuarioId <= 0 ||
            !in_array(
                $rol,
                ['Analista de Datos', 'Asesor de Ventas'],
                true
            )
        ) {
            http_response_code(403);
            die('Tu perfil no tiene acceso al motor de telefonía.');
        }
    }

    private function responderJson(array $datos, $status = 200)
    {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=utf-8');
        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );

        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
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
