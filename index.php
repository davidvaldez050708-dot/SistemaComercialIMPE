<?php

session_start();

$controllerSolicitado = $_GET['controller'] ?? 'login';
$actionSolicitada = $_GET['action'] ?? 'mostrarLogin';

$esConsultaAutomaticaRecordatorios =
    $controllerSolicitado === 'reminder' &&
    $actionSolicitada === 'pendientes';

$esPeticionFetch =
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

$responderSesionJson = static function ($mensaje, $codigoHttp = 401) {
    http_response_code($codigoHttp);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok' => false,
        'mensaje' => $mensaje
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
};


// Tiempo máximo de inactividad: 30 minutos
$tiempoMaximoInactividad = 1800;

if (isset($_SESSION['usuario_id'], $_SESSION['ultima_actividad'])) {

    $tiempoInactivo =
        time() - $_SESSION['ultima_actividad'];

    if ($tiempoInactivo > $tiempoMaximoInactividad) {

        $_SESSION = [];
        session_destroy();

        if ($esPeticionFetch) {
            $responderSesionJson(
                'La sesión expiró. Inicia sesión nuevamente.'
            );
        }

        header(
            'Location: index.php?controller=login&action=mostrarLogin&sesion=expirada'
        );

        exit;
    }

    /*
     * La consulta automática de recordatorios se ejecuta
     * cada minuto, pero no debe contar como actividad
     * real del usuario.
     */
    if (!$esConsultaAutomaticaRecordatorios) {
        $_SESSION['ultima_actividad'] = time();
    }
}


require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/helpers/PermissionHelper.php';


$controller = $controllerSolicitado;
$action = $actionSolicitada;


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

    if ($esPeticionFetch) {
        $responderSesionJson(
            'La sesión no está activa.'
        );
    }

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

    $modeloRolSesion
        ->inicializarPermisosSistema();

    $_SESSION['permisos'] =
        $modeloRolSesion
            ->obtenerCodigosPermisosPorRol(
                (int)($_SESSION['rol_id'] ?? 0)
            );
}


switch ($controller) {

    case 'login':

        require_once __DIR__ .
            '/app/controllers/LoginController.php';

        $controllerInstance =
            new LoginController();

        break;


    case 'home':

        require_once __DIR__ .
            '/app/controllers/HomeController.php';

        $controllerInstance =
            new HomeController();

        break;


    case 'usuario':

        require_once __DIR__ .
            '/app/controllers/UsuarioController.php';

        $controllerInstance =
            new UsuarioController();

        break;


    case 'rol':

        require_once __DIR__ .
            '/app/controllers/RolController.php';

        $controllerInstance =
            new RolController();

        break;


    case 'territorio':

        require_once __DIR__ .
            '/app/controllers/TerritorioController.php';

        $controllerInstance =
            new TerritorioController();

        break;


    case 'dataTerritorial':

        require_once __DIR__ .
            '/app/controllers/DataTerritorialController.php';

        $controllerInstance =
            new DataTerritorialController();

        break;


    case 'seguimientoVinculacion':

        require_once __DIR__ .
            '/app/controllers/SeguimientoVinculacionController.php';

        $controllerInstance =
            new SeguimientoVinculacionController();

        break;


    case 'seguimientoFlujo':

        require_once __DIR__ .
            '/app/controllers/SeguimientoFlujoController.php';

        $controllerInstance =
            new SeguimientoFlujoController();

        break;


    case 'agendaReunion':

        require_once __DIR__ .
            '/app/controllers/AgendaReunionController.php';

        $controllerInstance =
            new AgendaReunionController();

        break;


    case 'oficioVinculacion':

        require_once __DIR__ .
            '/app/controllers/OficioVinculacionController.php';

        $controllerInstance =
            new OficioVinculacionController();

        break;


    case 'oficioCorreo':

        require_once __DIR__ .
            '/app/controllers/OficioCorreoController.php';

        $controllerInstance =
            new OficioCorreoController();

        break;


    case 'reminder':

        require_once __DIR__ .
            '/app/controllers/ReminderController.php';

        $controllerInstance =
            new ReminderController();

        break;


    default:

        die('Controlador no válido.');
}


if (!method_exists($controllerInstance, $action)) {

    die('Acción no válida.');
}


$controllerInstance->$action();