<?php

require_once __DIR__ . '/../models/RolModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class RolController
{
    public function index()
    {
        $this->validarPermiso('roles.ver');

        $modeloRol = new RolModel();
        $modeloRol->inicializarPermisosSistema();

        $roles = $modeloRol->obtenerRoles();
        $rolSeleccionadoId = (int)($_GET['rol_id'] ?? ($roles[0]['id'] ?? 0));
        $rolSeleccionado = $modeloRol->buscarRolPorId($rolSeleccionadoId);

        if (!$rolSeleccionado && !empty($roles)) {
            $rolSeleccionado = $roles[0];
            $rolSeleccionadoId = (int)$rolSeleccionado['id'];
        }

        $permisosAgrupados = $modeloRol->obtenerPermisosAgrupados();
        $permisosRol = $rolSeleccionado
            ? $modeloRol->obtenerPermisosPorRol($rolSeleccionadoId)
            : [];

        $mensajeExito = $_SESSION['mensaje_rol'] ?? '';
        $mensajeError = $_SESSION['error_rol'] ?? '';
        $erroresFormulario = $_SESSION['errores_rol'] ?? [];
        $datosFormulario = $_SESSION['datos_rol'] ?? [];
        $modalAbierto = $_SESSION['modal_rol'] ?? '';

        unset(
            $_SESSION['mensaje_rol'],
            $_SESSION['error_rol'],
            $_SESSION['errores_rol'],
            $_SESSION['datos_rol'],
            $_SESSION['modal_rol']
        );

        $tituloPagina = 'Roles y permisos';
        $subtituloPagina = 'Administra los perfiles y accesos del sistema';
        $opcionActiva = 'roles';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/roles/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function panel()
    {
        $this->validarPermiso('roles.ver');

        $modeloRol = new RolModel();
        $modeloRol->inicializarPermisosSistema();

        $rolSeleccionadoId = (int)($_GET['rol_id'] ?? 0);
        $rolSeleccionado = $modeloRol->buscarRolPorId($rolSeleccionadoId);

        if (!$rolSeleccionado) {
            http_response_code(404);
            echo 'No fue posible completar la operación.';
            return;
        }

        $permisosAgrupados = $modeloRol->obtenerPermisosAgrupados();
        $permisosRol = $modeloRol->obtenerPermisosPorRol($rolSeleccionadoId);

        require_once __DIR__ . '/../views/roles/panel_permisos.php';
    }

    public function guardar()
    {
        $this->validarPermiso('roles.crear');
        $this->validarMetodoPost();

        $modeloRol = new RolModel();
        $datos = $this->limpiarDatosRol($_POST);
        $errores = $this->validarDatosRol($modeloRol, $datos);

        if (!empty($errores)) {
            $this->volverConErrores('crear', $errores, $datos);
        }

        $rolId = $modeloRol->crearRol($datos);

        if ($rolId) {
            $_SESSION['mensaje_rol'] = 'Rol creado correctamente.';
            $this->redirigirARoles((int)$rolId);
        }

        $_SESSION['error_rol'] = 'No fue posible crear el rol.';
        $this->redirigirARoles();
    }

    public function actualizar()
    {
        $this->validarPermiso('roles.editar');
        $this->validarMetodoPost();

        $modeloRol = new RolModel();
        $id = (int)($_POST['id'] ?? 0);
        $datos = $this->limpiarDatosRol($_POST);
        $errores = $this->validarDatosRol($modeloRol, $datos, $id);
        $rol = $modeloRol->buscarRolPorId($id);

        if (!$rol) {
            $errores[] = 'El rol seleccionado no es válido.';
        }

        if ($id === 1) {
            $errores[] = 'El rol Administrador es un rol protegido del sistema.';
        }

        if (
            $rol &&
            (int)$datos['estado'] !== (int)$rol['estado'] &&
            !tienePermiso('roles.cambiar_estado')
        ) {
            $errores[] = 'No tienes permiso para cambiar el estado del rol.';
        }

        if (!empty($errores)) {
            $datos['id'] = $id;
            $this->volverConErrores('editar', $errores, $datos);
        }

        if ($modeloRol->actualizarRol($id, $datos)) {
            $_SESSION['mensaje_rol'] = 'Rol actualizado correctamente.';
        } else {
            $_SESSION['error_rol'] = 'No fue posible actualizar el rol.';
        }

        $this->redirigirARoles($id);
    }

    public function cambiarEstado()
    {
        $this->validarPermiso('roles.cambiar_estado');
        $this->validarMetodoPost();

        $modeloRol = new RolModel();
        $id = (int)($_POST['id'] ?? 0);
        $estado = in_array((string)($_POST['estado'] ?? ''), ['0', '1'], true)
            ? (int)$_POST['estado']
            : null;
        $rol = $modeloRol->buscarRolPorId($id);

        if (!$rol || $estado === null) {
            $_SESSION['error_rol'] = 'La acción seleccionada no es válida.';
            $this->redirigirARoles();
        }

        if ($id === 1) {
            $_SESSION['error_rol'] =
                'El rol Administrador no puede desactivarse.';
            $this->redirigirARoles(1);
        }

        if ($modeloRol->cambiarEstadoRol($id, $estado)) {
            $_SESSION['mensaje_rol'] = $estado === 1
                ? 'Rol activado correctamente.'
                : 'Rol desactivado correctamente.';
        } else {
            $_SESSION['error_rol'] =
                'No fue posible actualizar el estado del rol.';
        }

        $this->redirigirARoles($id);
    }

    public function guardarPermisos()
    {
        $this->validarPermiso('roles.asignar_permisos');
        $this->validarMetodoPost();

        $modeloRol = new RolModel();
        $rolId = (int)($_POST['rol_id'] ?? 0);
        $rol = $modeloRol->buscarRolPorId($rolId);

        if (!$rol) {
            $_SESSION['error_rol'] = 'El rol seleccionado no es válido.';
            $this->redirigirARoles();
        }

        $permisos = $_POST['permisos'] ?? [];

        if (!is_array($permisos)) {
            $permisos = [];
        }

        if ($modeloRol->actualizarPermisosRol($rolId, $permisos)) {
            $_SESSION['mensaje_rol'] = 'Permisos actualizados correctamente.';
        } else {
            $_SESSION['error_rol'] = 'No fue posible actualizar los permisos.';
        }

        if ($rolId === (int)($_SESSION['rol_id'] ?? 0)) {
            $_SESSION['permisos'] =
                $modeloRol->obtenerCodigosPermisosPorRol($rolId);
        }

        $this->redirigirARoles($rolId);
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

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigirARoles();
        }
    }

    private function limpiarDatosRol($origen)
    {
        return [
            'nombre' => trim($origen['nombre'] ?? ''),
            'descripcion' => trim($origen['descripcion'] ?? ''),
            'estado' => in_array((string)($origen['estado'] ?? ''), ['0', '1'], true)
                ? (int)$origen['estado']
                : ''
        ];
    }

    private function validarDatosRol($modeloRol, $datos, $idExcluir = null)
    {
        $errores = [];

        if ($datos['nombre'] === '') {
            $errores[] = 'El nombre del rol es obligatorio.';
        } elseif ($modeloRol->existeNombreRol($datos['nombre'], $idExcluir)) {
            $errores[] = 'El nombre del rol ya está registrado.';
        }

        if (!in_array($datos['estado'], [0, 1], true)) {
            $errores[] = 'El estado seleccionado no es válido.';
        }

        return $errores;
    }

    private function volverConErrores($modal, $errores, $datos)
    {
        $_SESSION['errores_rol'] = $errores;
        $_SESSION['datos_rol'] = $datos;
        $_SESSION['modal_rol'] = $modal;

        $this->redirigirARoles((int)($datos['id'] ?? 0));
    }

    private function redirigirARoles($rolId = 0)
    {
        $url = BASE_URL . 'index.php?controller=rol&action=index';

        if ($rolId > 0) {
            $url .= '&rol_id=' . $rolId;
        }

        header('Location: ' . $url);
        exit;
    }
}
