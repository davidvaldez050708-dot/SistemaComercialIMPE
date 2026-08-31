<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class UsuarioController
{
    public function index()
    {
        $this->validarPermisoUsuario('usuarios.ver');

        $modeloUsuario = new UsuarioModel();

        $filtros = $this->obtenerFiltros();
        $usuarios = $modeloUsuario->listarUsuariosConFiltros($filtros);
        $roles = $modeloUsuario->obtenerRoles();
        $administradoresActivos = $modeloUsuario->contarAdministradoresActivos();

        $mensajeExito = $_SESSION['mensaje_usuario'] ?? '';
        $mensajeError = $_SESSION['error_usuario'] ?? '';
        $erroresFormulario = $_SESSION['errores_usuario'] ?? [];
        $datosFormulario = $_SESSION['datos_usuario'] ?? [];
        $modalAbierto = $_SESSION['modal_usuario'] ?? '';

        unset(
            $_SESSION['mensaje_usuario'],
            $_SESSION['error_usuario'],
            $_SESSION['errores_usuario'],
            $_SESSION['datos_usuario'],
            $_SESSION['modal_usuario']
        );

        $tituloPagina = 'Gestión de usuarios';
        $subtituloPagina = 'Administra las cuentas y accesos al sistema';
        $opcionActiva = 'usuarios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/usuarios/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function tabla()
    {
        $this->validarPermisoUsuario('usuarios.ver');

        $modeloUsuario = new UsuarioModel();

        $filtros = $this->obtenerFiltros();
        $usuarios = $modeloUsuario->listarUsuariosConFiltros($filtros);
        $usuarioActualId = (int)($_SESSION['usuario_id'] ?? 0);
        $administradoresActivos = $modeloUsuario->contarAdministradoresActivos();

        require_once __DIR__ . '/../views/usuarios/tabla.php';
    }

    public function guardar()
    {
        $this->validarPermisoUsuario('usuarios.crear');
        $this->validarMetodoPost();

        $modeloUsuario = new UsuarioModel();

        $datos = $this->limpiarDatosUsuario($_POST);
        $errores = $this->validarDatosCreacion($modeloUsuario, $datos);
        $foto = $this->procesarFotoPerfil();

        if ($foto['error'] !== '') {
            $errores[] = $foto['error'];
        }

        if (!empty($errores)) {
            if (!empty($foto['nueva'])) {
                $this->eliminarFotoPerfil($foto['ruta']);
            }

            $this->volverConErrores('crear', $errores, $datos);
        }

        $datos['foto_perfil'] = $foto['ruta'];

        $datos['password_hash'] = password_hash(
            $datos['password'],
            PASSWORD_DEFAULT
        );

        if ($modeloUsuario->crearUsuario($datos)) {
            $_SESSION['mensaje_usuario'] = 'Usuario creado correctamente.';
        } else {
            if (!empty($foto['nueva'])) {
                $this->eliminarFotoPerfil($foto['ruta']);
            }

            $_SESSION['error_usuario'] = 'No fue posible crear el usuario.';
        }

        $this->redirigirAUsuarios();
    }

    public function actualizar()
    {
        $this->validarPermisoUsuario('usuarios.editar');
        $this->validarMetodoPost();

        $modeloUsuario = new UsuarioModel();

        $datos = $this->limpiarDatosUsuario($_POST, true);
        $errores = $this->validarDatosEdicion($modeloUsuario, $datos);
        $usuarioOriginal = $modeloUsuario->buscarPorId((int)$datos['id']);

        if (!empty($errores)) {
            if ($usuarioOriginal) {
                $datos['ultimo_acceso'] = $usuarioOriginal['ultimo_acceso'];
                $datos['foto_perfil'] = $usuarioOriginal['foto_perfil'];
            }

            $this->volverConErrores('editar', $errores, $datos);
        }

        $fotoActual = $usuarioOriginal['foto_perfil'] ?? '';
        $quitarFoto = isset($_POST['quitar_foto_perfil']) &&
            (string)$_POST['quitar_foto_perfil'] === '1';
        $hayFotoNueva =
            isset($_FILES['foto_perfil']) &&
            $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($quitarFoto && $hayFotoNueva) {
            $datos['ultimo_acceso'] = $usuarioOriginal['ultimo_acceso'] ?? null;
            $datos['foto_perfil'] = $fotoActual;
            $this->volverConErrores(
                'editar',
                ['No puedes quitar y reemplazar la fotografía al mismo tiempo.'],
                $datos
            );
        }

        $foto = $quitarFoto
            ? [
                'ruta' => null,
                'error' => '',
                'nueva' => false
            ]
            : $this->procesarFotoPerfil($fotoActual);

        if ($foto['error'] !== '') {
            $datos['ultimo_acceso'] = $usuarioOriginal['ultimo_acceso'] ?? null;
            $datos['foto_perfil'] = $fotoActual;
            $this->volverConErrores('editar', [$foto['error']], $datos);
        }

        $datos['foto_perfil'] = $foto['ruta'];

        if ($modeloUsuario->actualizarUsuario((int)$datos['id'], $datos)) {
            $_SESSION['mensaje_usuario'] = 'Usuario actualizado correctamente.';

            if (
                ($quitarFoto || !empty($foto['nueva'])) &&
                $fotoActual !== '' &&
                $fotoActual !== (string)($datos['foto_perfil'] ?? '')
            ) {
                $this->eliminarFotoPerfilSiNoCompartida(
                    $modeloUsuario,
                    $fotoActual
                );
            }

            if ((int)$datos['id'] === (int)$_SESSION['usuario_id']) {
                $_SESSION['nombre'] = $datos['nombre'];
                $_SESSION['apellidos'] = $datos['apellidos'];
                $_SESSION['usuario'] = $datos['usuario'];
                $_SESSION['foto_perfil'] = $datos['foto_perfil'];
            }
        } else {
            if (!empty($foto['nueva'])) {
                $this->eliminarFotoPerfil($foto['ruta']);
            }

            $_SESSION['error_usuario'] = 'No fue posible actualizar el usuario.';
        }

        $this->redirigirAUsuarios();
    }

    public function cambiarEstado()
    {
        $this->validarPermisoUsuario('usuarios.cambiar_estado');
        $this->validarMetodoPost();

        $modeloUsuario = new UsuarioModel();

        $id = (int)($_POST['id'] ?? 0);
        $estadoNuevo = in_array((string)($_POST['estado'] ?? ''), ['0', '1'], true)
            ? (int)$_POST['estado']
            : null;

        if ($id <= 0 || $estadoNuevo === null) {
            $_SESSION['error_usuario'] = 'La acción seleccionada no es válida.';
            $this->redirigirAUsuarios();
        }

        if ($id === (int)$_SESSION['usuario_id'] && $estadoNuevo === 0) {
            $_SESSION['error_usuario'] = 'No puedes desactivar tu propia cuenta.';
            $this->redirigirAUsuarios();
        }

        $usuario = $modeloUsuario->buscarPorId($id);

        if (!$usuario) {
            $_SESSION['error_usuario'] = 'El usuario seleccionado no existe.';
            $this->redirigirAUsuarios();
        }

        if (
            $estadoNuevo === 0 &&
            (int)$usuario['rol_id'] === 1 &&
            (int)$usuario['estado'] === 1 &&
            $modeloUsuario->contarAdministradoresActivos() <= 1
        ) {
            $_SESSION['error_usuario'] =
                'No puede quedar el sistema sin al menos un Administrador activo.';
            $this->redirigirAUsuarios();
        }

        if ($modeloUsuario->actualizarEstadoUsuario($id, $estadoNuevo)) {
            $_SESSION['mensaje_usuario'] = $estadoNuevo === 1
                ? 'Usuario activado correctamente.'
                : 'Usuario desactivado correctamente.';
        } else {
            $_SESSION['error_usuario'] = 'No fue posible actualizar el estado.';
        }

        $this->redirigirAUsuarios();
    }

    public function eliminar()
    {
        $this->validarPermisoUsuario('usuarios.cambiar_estado');
        $this->validarMetodoPost();

        $modeloUsuario = new UsuarioModel();
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error_usuario'] = 'El usuario seleccionado no es válido.';
            $this->redirigirAUsuarios();
        }

        if ($id === (int)$_SESSION['usuario_id']) {
            $_SESSION['error_usuario'] = 'No puedes eliminar tu propia cuenta.';
            $this->redirigirAUsuarios();
        }

        $usuario = $modeloUsuario->buscarPorId($id);

        if (!$usuario) {
            $_SESSION['error_usuario'] = 'El usuario seleccionado no existe.';
            $this->redirigirAUsuarios();
        }

        if (
            (int)$usuario['rol_id'] === 1 &&
            (int)$usuario['estado'] === 1 &&
            $modeloUsuario->contarAdministradoresActivos() <= 1
        ) {
            $_SESSION['error_usuario'] =
                'No puede quedar el sistema sin al menos un Administrador activo.';
            $this->redirigirAUsuarios();
        }

        if ($modeloUsuario->actualizarEstadoUsuario($id, 0)) {
            $_SESSION['mensaje_usuario'] = 'Usuario desactivado correctamente.';
        } else {
            $_SESSION['error_usuario'] = 'No fue posible desactivar el usuario.';
        }

        $this->redirigirAUsuarios();
    }

    private function validarPermisoUsuario($codigo)
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

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigirAUsuarios();
        }
    }

    private function obtenerFiltros()
    {
        $rol = $_GET['rol'] ?? '';
        $estado = $_GET['estado'] ?? '';

        return [
            'buscar' => trim($_GET['buscar'] ?? ''),
            'rol' => ctype_digit((string)$rol) ? (int)$rol : '',
            'estado' => in_array((string)$estado, ['0', '1'], true)
                ? (int)$estado
                : ''
        ];
    }

    private function limpiarDatosUsuario($origen, $incluyeEstado = false)
    {
        $datos = [
            'nombre' => trim($origen['nombre'] ?? ''),
            'apellidos' => trim($origen['apellidos'] ?? ''),
            'telefono' => trim($origen['telefono'] ?? ''),
            'foto_perfil' => trim($origen['foto_perfil'] ?? ''),
            'correo' => trim($origen['correo'] ?? ''),
            'usuario' => trim($origen['usuario'] ?? ''),
            'rol_id' => (int)($origen['rol_id'] ?? 0),
            'password' => $origen['password'] ?? '',
            'confirmar_password' => $origen['confirmar_password'] ?? ''
        ];

        if ($incluyeEstado) {
            $datos['id'] = (int)($origen['id'] ?? 0);
            $datos['estado'] = in_array((string)($origen['estado'] ?? ''), ['0', '1'], true)
                ? (int)$origen['estado']
                : '';
        }

        return $datos;
    }

    private function validarDatosCreacion($modeloUsuario, $datos)
    {
        $errores = $this->validarDatosBase(
            $modeloUsuario,
            $datos,
            null,
            true
        );

        if (strlen($datos['password']) < 8) {
            $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
        }

        if ($datos['password'] !== $datos['confirmar_password']) {
            $errores[] = 'La contraseña y la confirmación no coinciden.';
        }

        return $errores;
    }

    private function validarDatosEdicion($modeloUsuario, $datos)
    {
        $errores = [];
        $usuarioOriginal = null;

        if ((int)$datos['id'] <= 0) {
            $errores[] = 'El usuario seleccionado no es válido.';
        } else {
            $usuarioOriginal = $modeloUsuario->buscarPorId((int)$datos['id']);

            if (!$usuarioOriginal) {
                $errores[] = 'El usuario seleccionado no es válido.';
            }
        }

        if (!in_array($datos['estado'], [0, 1], true)) {
            $errores[] = 'El estado seleccionado no es válido.';
        }

        if ($usuarioOriginal) {
            $esCuentaActual =
                (int)$usuarioOriginal['id'] === (int)$_SESSION['usuario_id'];

            if (
                $esCuentaActual &&
                (int)$datos['rol_id'] !== (int)$usuarioOriginal['rol_id']
            ) {
                $errores[] = 'Por seguridad, no puedes modificar tu propio rol.';
            }

            if ($esCuentaActual && (int)$datos['estado'] === 0) {
                $errores[] = 'Por seguridad, no puedes desactivar tu cuenta.';
            }

            $desactivaUltimoAdministrador =
                (int)$usuarioOriginal['rol_id'] === 1 &&
                (int)$usuarioOriginal['estado'] === 1 &&
                (
                    (int)$datos['rol_id'] !== 1 ||
                    (int)$datos['estado'] !== 1
                ) &&
                $modeloUsuario->contarAdministradoresActivos() <= 1;

            if ($desactivaUltimoAdministrador) {
                $errores[] =
                    'No puede quedar el sistema sin al menos un Administrador activo.';
            }
        }

        return array_merge(
            $errores,
            $this->validarDatosBase($modeloUsuario, $datos, (int)$datos['id'])
        );
    }

    private function validarDatosBase(
        $modeloUsuario,
        $datos,
        $idExcluir = null,
        $requiereRolActivo = false
    )
    {
        $errores = [];

        if ($datos['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        }

        if ($datos['apellidos'] === '') {
            $errores[] = 'Los apellidos son obligatorios.';
        }

        if ($datos['correo'] === '') {
            $errores[] = 'El correo electrónico es obligatorio.';
        } elseif (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El formato del correo electrónico no es válido.';
        } elseif ($modeloUsuario->existeCorreo($datos['correo'], $idExcluir)) {
            $errores[] = 'El correo electrónico ya está registrado.';
        }

        if ($datos['usuario'] === '') {
            $errores[] = 'El usuario es obligatorio.';
        } elseif ($modeloUsuario->existeUsuario($datos['usuario'], $idExcluir)) {
            $errores[] = 'El usuario ya está registrado.';
        }

        $rolValido = $requiereRolActivo
            ? $modeloUsuario->existeRolActivo((int)$datos['rol_id'])
            : $modeloUsuario->existeRol((int)$datos['rol_id']);

        if ((int)$datos['rol_id'] <= 0 || !$rolValido) {
            $errores[] = 'El rol seleccionado no es válido.';
        }

        return $errores;
    }

    private function volverConErrores($modal, $errores, $datos)
    {
        unset($datos['password'], $datos['confirmar_password']);

        $_SESSION['errores_usuario'] = $errores;
        $_SESSION['datos_usuario'] = $datos;
        $_SESSION['modal_usuario'] = $modal;

        $this->redirigirAUsuarios();
    }

    private function procesarFotoPerfil($fotoActual = '')
    {
        if (
            !isset($_FILES['foto_perfil']) ||
            $_FILES['foto_perfil']['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return [
                'ruta' => $fotoActual,
                'error' => '',
                'nueva' => false
            ];
        }

        if ($_FILES['foto_perfil']['error'] !== UPLOAD_ERR_OK) {
            return [
                'ruta' => $fotoActual,
                'error' => 'No fue posible cargar la foto de perfil.',
                'nueva' => false
            ];
        }

        if ($_FILES['foto_perfil']['size'] > 2 * 1024 * 1024) {
            return [
                'ruta' => $fotoActual,
                'error' => 'La foto de perfil no debe superar 2 MB.',
                'nueva' => false
            ];
        }

        $rutaTemporal = $_FILES['foto_perfil']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipoImagen = $finfo->file($rutaTemporal);
        $extensiones = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($extensiones[$tipoImagen])) {
            return [
                'ruta' => $fotoActual,
                'error' => 'La foto de perfil debe ser JPG, PNG o WEBP.',
                'nueva' => false
            ];
        }

        $carpetaDestino = ROOT_PATH . '/public/uploads/usuarios';

        if (!is_dir($carpetaDestino)) {
            mkdir($carpetaDestino, 0775, true);
        }

        $nombreArchivo =
            'usuario_' .
            date('YmdHis') .
            '_' .
            bin2hex(random_bytes(6)) .
            '.' .
            $extensiones[$tipoImagen];

        $rutaDestino = $carpetaDestino . '/' . $nombreArchivo;
        $rutaPublica = 'public/uploads/usuarios/' . $nombreArchivo;

        if (!move_uploaded_file($rutaTemporal, $rutaDestino)) {
            return [
                'ruta' => $fotoActual,
                'error' => 'No fue posible guardar la foto de perfil.',
                'nueva' => false
            ];
        }

        return [
            'ruta' => $rutaPublica,
            'error' => '',
            'nueva' => true
        ];
    }

    private function eliminarFotoPerfil($ruta)
    {
        $ruta = trim(str_replace('\\', '/', (string)$ruta));

        if ($ruta === '' || strpos($ruta, '..') !== false) {
            return false;
        }

        $ruta = ltrim($ruta, '/');
        $directorioRelativo = 'public/uploads/usuarios/';

        if (strpos($ruta, $directorioRelativo) !== 0) {
            return false;
        }

        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $directorioBase = realpath(ROOT_PATH . '/public/uploads/usuarios');

        if ($directorioBase === false) {
            return false;
        }

        $rutaArchivo = realpath(ROOT_PATH . '/' . $ruta);

        if ($rutaArchivo === false || !is_file($rutaArchivo)) {
            return false;
        }

        $directorioBase = rtrim(str_replace('\\', '/', $directorioBase), '/') . '/';
        $rutaArchivoNormalizada = str_replace('\\', '/', $rutaArchivo);

        if (strpos($rutaArchivoNormalizada, $directorioBase) !== 0) {
            return false;
        }

        return @unlink($rutaArchivo);
    }

    private function eliminarFotoPerfilSiNoCompartida($modeloUsuario, $ruta)
    {
        if (
            $ruta === '' ||
            $modeloUsuario->contarUsuariosConFotoPerfil($ruta) > 0
        ) {
            return false;
        }

        return $this->eliminarFotoPerfil($ruta);
    }

    private function redirigirAUsuarios()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=usuario&action=index'
        );
        exit;
    }
}
