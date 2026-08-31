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
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=home&action=index'
        );

        exit;
    }

    public function procesarRecuperacion()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarRecuperacion'
            );

            exit;
        }


        $dato = trim($_POST['usuario'] ?? '');


        if ($dato === '') {

            $_SESSION['error_recuperacion'] =
                'Ingrese su usuario o correo.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarRecuperacion'
            );

            exit;
        }


        $usuario = $this->usuarioModel
            ->buscarPorUsuarioOCorreo($dato);


        /*
        * Nunca indicamos al visitante si la cuenta
        * existe o no.
        */
        if ($usuario && (int)$usuario['estado'] === 1) {

            // Generar contraseña temporal
            $passwordTemporal =
                $this->generarPasswordTemporal();


            // Preparar hash
            $passwordHash = password_hash(
                $passwordTemporal,
                PASSWORD_DEFAULT
            );


            // Vigencia de 30 minutos
            $fechaExpiracion = date(
                'Y-m-d H:i:s',
                time() + (30 * 60)
            );


            // Nombre que aparecerá en el correo
            $nombreDestino = trim(
                $usuario['nombre'] . ' ' .
                $usuario['apellidos']
            );


            // Enviar correo
            $mailService = new MailService();

            $correoEnviado =
                $mailService->enviarPasswordTemporal(
                    $usuario['correo'],
                    $nombreDestino,
                    $passwordTemporal
                );


            /*
            * La contraseña solo se modifica
            * si el correo logró enviarse.
            */
            if ($correoEnviado) {

                $actualizado =
                    $this->usuarioModel
                        ->establecerPasswordTemporal(
                            $usuario['id'],
                            $passwordHash,
                            $fechaExpiracion
                        );


                if (!$actualizado) {

                    error_log(
                        'No se pudo guardar la contraseña temporal ' .
                        'del usuario ID: ' .
                        $usuario['id']
                    );
                }

            } else {

                error_log(
                    'No se pudo enviar la contraseña temporal ' .
                    'al usuario ID: ' .
                    $usuario['id']
                );
            }
        }


        /*
        * El modal aparece tanto si la cuenta existe
        * como si no existe.
        */
        $_SESSION['mostrar_modal_recuperacion'] = true;


        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=login&action=mostrarRecuperacion'
        );

        exit;
    }

    private function generarPasswordTemporal()
    {
        $mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minusculas = 'abcdefghijkmnopqrstuvwxyz';
        $numeros = '23456789';

        $password = '';

        // Garantizamos al menos uno de cada tipo
        $password .= $mayusculas[random_int(0, strlen($mayusculas) - 1)];
        $password .= $minusculas[random_int(0, strlen($minusculas) - 1)];
        $password .= $numeros[random_int(0, strlen($numeros) - 1)];

        $todos = $mayusculas . $minusculas . $numeros;

        // Completar hasta 10 caracteres
        while (strlen($password) < 10) {

            $password .= $todos[
                random_int(0, strlen($todos) - 1)
            ];
        }

        // Mezclar de manera segura
        $caracteres = str_split($password);

        for ($i = count($caracteres) - 1; $i > 0; $i--) {

            $j = random_int(0, $i);

            $temporal = $caracteres[$i];
            $caracteres[$i] = $caracteres[$j];
            $caracteres[$j] = $temporal;
        }

        return implode('', $caracteres);
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
