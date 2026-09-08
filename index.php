<?php

// Cargar configuraciones
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/models/UsuarioModel.php';
require_once __DIR__ . '/app/helpers/PermissionHelper.php';

// Iniciar sesión
session_start();

// Si no hay sesión y no es login -> mandar al login
$controller = $_GET['controller'] ?? 'login';
$action = $_GET['action'] ?? 'index';

if (!isset($_SESSION['usuario_id']) && $controller != 'login') {
    header("Location: " . BASE_URL . "index.php?controller=login&action=index");
    exit;
}

if (
    isset($_SESSION['usuario_id']) &&
    (int)($_SESSION['requiere_cambio_password'] ?? 0) === 1 &&
    !(
        $controller === 'usuario' &&
        in_array($action, ['actualizarMiPassword', 'obtenerMiPerfil'], true)
    ) &&
    !($controller === 'login' && $action === 'logout')
) {
    header("Location: " . BASE_URL . "index.php?controller=home&action=index");
    exit;
}

switch ($controller) {

    case 'login':
        require_once __DIR__ . '/app/controllers/LoginController.php';
        $controllerObject = new LoginController();
        break;

    case 'home':
        require_once __DIR__ . '/app/controllers/HomeController.php';
        $controllerObject = new HomeController();
        break;

    case 'usuario':
        require_once __DIR__ . '/app/controllers/UsuarioController.php';
        $controllerObject = new UsuarioController();
        break;

    case 'rol':
        require_once __DIR__ . '/app/controllers/RolController.php';
        $controllerObject = new RolController();
        break;

    case 'territorio':
        require_once __DIR__ . '/app/controllers/TerritorioController.php';
        $controllerObject = new TerritorioController();
        break;

    case 'dataTerritorial':
        require_once __DIR__ . '/app/controllers/DataTerritorialController.php';
        $controllerObject = new DataTerritorialController();
        break;

    case 'poblacionObjetivoEducativa':
        require_once __DIR__ . '/app/controllers/PoblacionObjetivoEducativaController.php';
        $controllerObject = new PoblacionObjetivoEducativaController();
        break;

    case 'seguimientoVinculacion':
        require_once __DIR__ . '/app/controllers/SeguimientoVinculacionController.php';
        $controllerObject = new SeguimientoVinculacionController();
        break;

    case 'reminder':
        require_once __DIR__ . '/app/controllers/ReminderController.php';
        $controllerObject = new ReminderController();
        break;
    default:
        echo "Controlador no encontrado";
        exit;
}

if (method_exists($controllerObject, $action)) {
    $controllerObject->$action();
} else {
    echo "Acción no encontrada";
}
