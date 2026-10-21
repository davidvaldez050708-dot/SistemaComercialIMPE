<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../models/RolModel.php';
require_once ROOT_PATH . '/app/services/MailService.php';

class LoginController
{
    private $usuarioModel;
    private $rolModel;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
        $this->rolModel = new RolModel();
    }

    public function mostrarLogin()
    {
        require_once __DIR__ . '/../views/auth/login.php';
    }

    public function mostrarRecuperacion()
    {
        require_once ROOT_PATH . '/app/views/auth/recuperar_password.php';
    }

    public function iniciarSesion()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Datos ingresados en el formulario
        */
        $dato = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';


        /*
        * Validar campos vacíos
        */
        if ($dato === '' || $password === '') {

            $_SESSION['error_login'] =
                'Ingrese su usuario o correo y contraseña.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Buscar cuenta por usuario o correo
        */
        $usuario = $this->usuarioModel
            ->buscarPorUsuarioOCorreo($dato);


        /*
        * Cuenta inexistente
        */
        if (!$usuario) {

            $_SESSION['error_login'] =
                'Usuario o contraseña incorrectos.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Cuenta desactivada
        */
        if ((int)$usuario['estado'] !== 1) {

            $_SESSION['error_login'] =
                'La cuenta se encuentra desactivada.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Rol desactivado.
        * Un usuario activo no puede iniciar sesión con un perfil inactivo.
        */
        if ((int)($usuario['rol_estado'] ?? 0) !== 1) {

            $_SESSION['error_login'] =
                'El perfil de acceso asignado a tu cuenta se encuentra desactivado.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Verificar contraseña
        */
        if (!password_verify(
            $password,
            $usuario['password']
        )) {

            $_SESSION['error_login'] =
                'Usuario o contraseña incorrectos.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        /*
        * Si utiliza contraseña temporal,
        * comprobar que no haya expirado.
        */
        if (
            (int)$usuario['requiere_cambio_password'] === 1
        ) {

            $fechaExpiracion =
                strtotime(
                    $usuario['password_temporal_expira']
                );


            if (
                !$fechaExpiracion ||
                time() > $fechaExpiracion
            ) {

                $_SESSION['error_recuperacion'] =
                    'La contraseña temporal ha expirado. Solicite una nueva.';

                header(
                    'Location: ' .
                    BASE_URL .
                    'index.php?controller=login&action=mostrarRecuperacion'
                );

                exit;
            }
        }


        /*
        * Inicio de sesión correcto
        */
        session_regenerate_id(true);


        $_SESSION['usuario_id'] =
            $usuario['id'];

        $_SESSION['nombre'] =
            $usuario['nombre'];

        $_SESSION['apellidos'] =
            $usuario['apellidos'];

        $_SESSION['usuario'] =
            $usuario['usuario'];

        $_SESSION['foto_perfil'] =
            $usuario['foto_perfil'] ?? '';

        $_SESSION['rol_id'] =
            $usuario['rol_id'];

        $_SESSION['rol'] =
            $usuario['rol'];

        $this->rolModel->inicializarPermisosSistema();

        $_SESSION['permisos'] =
            $this->rolModel->obtenerCodigosPermisosPorRol(
                (int)$usuario['rol_id']
            );

        $_SESSION['requiere_cambio_password'] =
            (int)$usuario['requiere_cambio_password'];

        $_SESSION['ultima_actividad'] =
            time();


        /*
        * Registrar último acceso
        */
        $this->usuarioModel
            ->actualizarUltimoAcceso(
                $usuario['id']
            );


        /*
        * Todos ingresan al dashboard.
        *
        * Si requiere cambio de contraseña,
        * el dashboard mostrará el modal obligatorio.
        */
        // Sólo las cuentas con extensión activa y permiso telefónico usan
        // navegación persistente. Las demás conservan el dashboard anterior.
        $destinoInicio = 'index.php?controller=home&action=index';
        if (
            empty($_SESSION['requiere_cambio_password']) &&
            tienePermiso('telefonia.usar')
        ) {
            try {
                require_once __DIR__ . '/../services/TelefoniaExtensionService.php';
                $ext = (new TelefoniaExtensionService())->resolverParaUsuario(
                    (int)$usuario['id']
                );
                if ($ext && (
                    !empty($ext['permite_entrantes']) ||
                    !empty($ext['permite_salientes'])
                )) {
                    $destinoInicio = 'index.php?controller=telefonia&action=shell';
                }
            } catch (Throwable $error) {
                error_log('[Login WebRTC] No se pudo determinar la extensión: ' .
                    $error->getMessage());
                // Si el módulo telefónico falla no impedimos iniciar sesión.
            }
        }

        header('Location: ' . BASE_URL . $destinoInicio);
        exit;
    }

    public function procesarRecuperacion()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $dato = trim((string)($_POST['usuario'] ?? ''));
            if ($dato === '') { $_SESSION['error_recuperacion'] = 'Ingrese su usuario o correo.'; }
            else {
                require_once __DIR__ . '/../services/CorreoRecuperacionService.php';
                (new CorreoRecuperacionService($this->usuarioModel))->solicitar($dato, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
                $_SESSION['mostrar_modal_recuperacion'] = true;
            }
        }
        header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarRecuperacion');
        exit;
    }

    public function mostrarCambioPassword()
    {
        if (
            !isset($_SESSION['usuario_id']) ||
            empty($_SESSION['requiere_cambio_password'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );

            exit;
        }

        require_once ROOT_PATH .
            '/app/views/auth/cambiar_password.php';
    }

    public function cambiarPassword()
    {

        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST' ||
            !isset($_SESSION['usuario_id']) ||
            empty($_SESSION['requiere_cambio_password'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );

            exit;
        }


        $passwordNueva =
            $_POST['password_nueva'] ?? '';

        $confirmarPassword =
            $_POST['confirmar_password'] ?? '';


        if (
            $passwordNueva === '' ||
            $confirmarPassword === ''
        ) {

            $_SESSION['error_cambio_password'] =
                'Complete ambos campos.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );

            exit;
        }


        if (strlen($passwordNueva) < 8) {

            $_SESSION['error_cambio_password'] =
                'La contraseña debe tener al menos 8 caracteres.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );

            exit;
        }


        if ($passwordNueva !== $confirmarPassword) {

            $_SESSION['error_cambio_password'] =
                'Las contraseñas no coinciden.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );

            exit;
        }


        $passwordHash = password_hash(
            $passwordNueva,
            PASSWORD_DEFAULT
        );


        $actualizado =
            $this->usuarioModel
                ->actualizarPasswordDefinitivo(
                    $_SESSION['usuario_id'],
                    $passwordHash
                );


        if (!$actualizado) {

            $_SESSION['error_cambio_password'] =
                'No fue posible actualizar la contraseña.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );

            exit;
        }


        $_SESSION['requiere_cambio_password'] = 0;

        $_SESSION['mensaje_password_actualizado'] = true;


        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=home&action=index'
        );

        exit;
    }
}
