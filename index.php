<?php

session_start();

if (isset($_SESSION['usuario_id'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$controllerSolicitado = $_GET['controller'] ?? 'login';
$actionSolicitada = $_GET['action'] ?? 'mostrarLogin';

$esConsultaAutomaticaRecordatorios =
    (
        $controllerSolicitado === 'reminder' &&
        $actionSolicitada === 'pendientes'
    ) ||
    (
        $controllerSolicitado === 'convocatoriaNotificacion' &&
        $actionSolicitada === 'pendientes'
    );

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
    ],
    'whatsappWebhook' => [
        'webhook'
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


/*
 * La autorización se sincroniza contra la base en cada petición autenticada.
 * De esta manera, cambios de rol, estado de usuario/rol o permisos aplican
 * inmediatamente sin exigir cerrar sesión.
 */
if (isset($_SESSION['usuario_id'])) {
    require_once __DIR__ . '/app/models/UsuarioModel.php';
    require_once __DIR__ . '/app/models/RolModel.php';

    $modeloUsuarioSesion = new UsuarioModel();
    $usuarioSesion = $modeloUsuarioSesion->buscarPorId(
        (int)$_SESSION['usuario_id']
    );

    $sesionVigente =
        is_array($usuarioSesion) &&
        (int)($usuarioSesion['estado'] ?? 0) === 1 &&
        (int)($usuarioSesion['rol_estado'] ?? 0) === 1;

    if (!$sesionVigente) {
        $_SESSION = [];
        session_destroy();

        if ($esPeticionFetch) {
            $responderSesionJson(
                'Tu cuenta o perfil de acceso ya no se encuentra activo.',
                403
            );
        }

        header(
            'Location: index.php?controller=login&action=mostrarLogin&acceso=revocado'
        );

        exit;
    }

    $_SESSION['nombre'] = (string)($usuarioSesion['nombre'] ?? '');
    $_SESSION['apellidos'] = (string)($usuarioSesion['apellidos'] ?? '');
    $_SESSION['usuario'] = (string)($usuarioSesion['usuario'] ?? '');
    $_SESSION['foto_perfil'] = (string)($usuarioSesion['foto_perfil'] ?? '');
    $_SESSION['rol_id'] = (int)($usuarioSesion['rol_id'] ?? 0);
    $_SESSION['rol'] = (string)($usuarioSesion['rol'] ?? '');
    $_SESSION['requiere_cambio_password'] =
        (int)($usuarioSesion['requiere_cambio_password'] ?? 0);

    $modeloRolSesion = new RolModel();
    $_SESSION['permisos'] =
        $modeloRolSesion->obtenerCodigosPermisosPorRol(
            (int)$_SESSION['rol_id']
        );
}


if (isset($_SESSION['usuario_id']) && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/*
 * Los permisos ya fueron refrescados desde la base en esta misma petición.
 */


/*
 * Protección CSRF de la ruta operativa de Vinculación.
 * Los formularios/fetch del dashboard adjuntan el token de sesión
 * automáticamente desde dashboard_head.php.
 */
$controladoresProtegidosCsrf = [
    'seguimientoVinculacion',
    'seguimientoFlujo',
    'seguimientoInteraccion',
    'seguimientoObservacion',
    'agendaReunion',
    'correoFirmado',
    'oficioVinculacion',
    'oficioCorreo',
    'convocatoria',
    'convocatoriaNotificacion',
    'formulario',
    'aliado',
    'whatsapp',
    'rol',
    'usuario',
    'telefonia'
];

if (
    isset($_SESSION['usuario_id']) &&
    strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' &&
    in_array($controller, $controladoresProtegidosCsrf, true)
) {
    $tokenSesion = (string)($_SESSION['csrf_token'] ?? '');
    $tokenPeticion = trim((string)(
        $_SERVER['HTTP_X_CSRF_TOKEN'] ??
        $_POST['csrf_token'] ??
        ''
    ));

    if (
        $tokenSesion === '' ||
        $tokenPeticion === '' ||
        !hash_equals($tokenSesion, $tokenPeticion)
    ) {
        http_response_code(419);

        if ($esPeticionFetch) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'mensaje' => 'La sesión de seguridad cambió. Recarga la página e intenta nuevamente.'
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'La sesión de seguridad cambió. Recarga la página e intenta nuevamente.';
        }

        exit;
    }
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


    case 'firmaCorreo':

        require_once __DIR__ .
            '/app/controllers/FirmaCorreoController.php';

        $controllerInstance =
            new FirmaCorreoController();

        break;


    case 'telefonia':

        require_once __DIR__ .
            '/app/controllers/TelefoniaController.php';

        $controllerInstance =
            new TelefoniaController();

        break;


    case 'correoFirmado':

        require_once __DIR__ .
            '/app/controllers/CorreoFirmadoController.php';

        $controllerInstance =
            new CorreoFirmadoController();

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


    case 'reporte':

        require_once __DIR__ .
            '/app/controllers/ReporteController.php';

        $controllerInstance =
            new ReporteController();

        break;


    case 'desempeno':

        require_once __DIR__ .
            '/app/controllers/DesempenoController.php';

        $controllerInstance =
            new DesempenoController();

        break;


    case 'reporteAdministrador':

        require_once __DIR__ .
            '/app/controllers/ReporteAdministradorController.php';

        $controllerInstance =
            new ReporteAdministradorController();

        break;


    case 'dataTerritorialReporte':

        require_once __DIR__ .
            '/app/controllers/DataTerritorialReporteController.php';

        $controllerInstance =
            new DataTerritorialReporteController();

        break;


    case 'fuenteOficial':

        require_once __DIR__ .
            '/app/controllers/FuenteOficialController.php';

        $controllerInstance =
            new FuenteOficialController();

        break;


    case 'poblacionObjetivoEducativa':

        require_once __DIR__ .
            '/app/controllers/PoblacionObjetivoEducativaController.php';

        $controllerInstance =
            new PoblacionObjetivoEducativaController();

        break;


    case 'seguimientoVinculacion':

        require_once __DIR__ .
            '/app/controllers/SeguimientoVinculacionController.php';

        $controllerInstance =
            new SeguimientoVinculacionController();

        break;


    case 'seguimientoVinculacionReporte':

        require_once __DIR__ .
            '/app/controllers/SeguimientoVinculacionReporteController.php';

        $controllerInstance =
            new SeguimientoVinculacionReporteController();

        break;


    case 'seguimientoReporteAnalitica':

        require_once __DIR__ .
            '/app/controllers/SeguimientoReporteAnaliticaController.php';

        $controllerInstance =
            new SeguimientoReporteAnaliticaController();

        break;


    case 'seguimientoFlujo':

        require_once __DIR__ .
            '/app/controllers/SeguimientoFlujoController.php';

        $controllerInstance =
            new SeguimientoFlujoController();

        break;


    case 'seguimientoInteraccion':

        require_once __DIR__ .
            '/app/controllers/SeguimientoInteraccionController.php';

        $controllerInstance =
            new SeguimientoInteraccionController();

        break;


    case 'seguimientoObservacion':

        require_once __DIR__ .
            '/app/controllers/SeguimientoObservacionController.php';

        $controllerInstance =
            new SeguimientoObservacionController();

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


    case 'convocatoria':

        require_once __DIR__ .
            '/app/controllers/ConvocatoriaController.php';

        $controllerInstance =
            new ConvocatoriaController();

        break;


    case 'correoMarketing':

        require_once __DIR__ .
            '/app/controllers/CorreoMarketingController.php';

        $controllerInstance =
            new CorreoMarketingController();

        break;


    case 'formulario':

        require_once __DIR__ .
            '/app/controllers/FormularioController.php';

        $controllerInstance =
            new FormularioController();

        break;


    case 'aliado':

        require_once __DIR__ .
            '/app/controllers/AliadoController.php';

        $controllerInstance =
            new AliadoController();

        break;


    case 'aliadoReporte':

        require_once __DIR__ .
            '/app/controllers/AliadoReporteController.php';

        $controllerInstance =
            new AliadoReporteController();

        break;


    case 'whatsapp':

        require_once __DIR__ .
            '/app/controllers/WhatsAppController.php';

        $controllerInstance =
            new WhatsAppController();

        break;


    case 'whatsappWebhook':

        require_once __DIR__ .
            '/app/controllers/WhatsAppWebhookController.php';

        $controllerInstance =
            new WhatsAppWebhookController();

        break;


    case 'convocatoriaReporte':

        require_once __DIR__ .
            '/app/controllers/ConvocatoriaReporteController.php';

        $controllerInstance =
            new ConvocatoriaReporteController();

        break;


    case 'convocatoriaNotificacion':

        require_once __DIR__ .
            '/app/controllers/ConvocatoriaNotificacionController.php';

        $controllerInstance =
            new ConvocatoriaNotificacionController();

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