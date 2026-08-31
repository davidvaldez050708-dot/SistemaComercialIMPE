<?php

session_start();

// Tiempo máximo de inactividad: 30 minutos
$tiempoMaximoInactividad = 1800;

if (isset($_SESSION['usuario_id'], $_SESSION['ultima_actividad'])) {

    $tiempoInactivo = time() - $_SESSION['ultima_actividad'];

    if ($tiempoInactivo > $tiempoMaximoInactividad) {

        $_SESSION = [];
        session_destroy();

        header(
            'Location: index.php?controller=login&action=mostrarLogin&sesion=expirada'
        );
        exit;
    }

    $_SESSION['ultima_actividad'] = time();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/helpers/PermissionHelper.php';

$controller = $_GET['controller'] ?? 'login';
$action = $_GET['action'] ?? 'mostrarLogin';

/*
 * Si el usuario inició sesión con una contraseña temporal,
 * solamente puede ver el dashboard o cambiar su contraseña.
 */
if (
    isset($_SESSION['usuario_id']) &&
    !empty($_SESSION['requiere_cambio_password'])
) {

    $rutaPermitida =
        ($controller === 'home' && $action === 'index') ||
        ($controller === 'login' && $action === 'cambiarPassword');

    if (!$rutaPermitida) {

        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=home&action=index'
        );

        exit;
    }
}

$rutasPublicas = [
    'login' => [
        'mostrarLogin',
        'iniciarSesion',
        'mostrarRecuperacion',
        'procesarRecuperacion'
    ]
];

$esRutaPublica =
    isset($rutasPublicas[$controller]) &&
    in_array($action, $rutasPublicas[$controller]);

if (!$esRutaPublica && !isset($_SESSION['usuario_id'])) {

    header(
        'Location: index.php?controller=login&action=mostrarLogin'
    );
    exit;
}

if (
    isset($_SESSION['usuario_id']) &&
    !isset($_SESSION['permisos'])
) {
    require_once __DIR__ . '/app/models/RolModel.php';

    $modeloRolSesion = new RolModel();
    $modeloRolSesion->inicializarPermisosSistema();

    $_SESSION['permisos'] =
        $modeloRolSesion->obtenerCodigosPermisosPorRol(
            (int)($_SESSION['rol_id'] ?? 0)
        );
}

switch ($controller) {

    case 'login':
        require_once __DIR__ . '/app/controllers/LoginController.php';
        $controllerInstance = new LoginController();
        break;

    case 'home':
        require_once __DIR__ . '/app/controllers/HomeController.php';
        $controllerInstance = new HomeController();
        break;

    case 'usuario':
        require_once __DIR__ . '/app/controllers/UsuarioController.php';
        $controllerInstance = new UsuarioController();
        break;

    case 'rol':
        require_once __DIR__ . '/app/controllers/RolController.php';
        $controllerInstance = new RolController();
        break;

    case 'territorio':
        require_once __DIR__ . '/app/controllers/TerritorioController.php';
        $controllerInstance = new TerritorioController();
        break;

    case 'dataTerritorial':
        require_once __DIR__ . '/app/controllers/DataTerritorialController.php';
        $controllerInstance = new DataTerritorialController();
        break;

    default:
        die('Controlador no válido.');
}

if (!method_exists($controllerInstance, $action)) {
    die('Acción no válida.');
}

$controllerInstance->$action();
