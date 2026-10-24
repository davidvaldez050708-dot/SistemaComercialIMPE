<?php
// Transporte HTTP de prueba: localhost, entorno testing y esquema sintético exclusivo.
if (getenv('APP_ENV') !== 'testing' || getenv('IMPE_TEST_ALLOW_ROUTER') !== '1' ||
    !preg_match('/^impe_test_handly_aliados_historial_[a-z0-9_]+$/D', getenv('IMPE_DB_NAME') ?: '') ||
    !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
session_start();
require_once __DIR__.'/../config/db_connection.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/_session') {
    $role = $_GET['role'] ?? 'cc';
    $profiles = ['cc'=>[2,4,'Cuenta Clave'], 'marketing'=>[4,5,'Marketing'], 'outside'=>[3,4,'Cuenta Clave']];
    $profile = $profiles[$role] ?? $profiles['cc'];
    $_SESSION = ['usuario_id'=>$profile[0], 'rol_id'=>$profile[1], 'rol'=>$profile[2], 'permisos'=>['aliados.ver','aliados.ver_historial','aliados.seguimiento_convocatorias'],'csrf_token'=>str_repeat('a',64)];
    if ($role === 'readonly') $_SESSION['permisos']=['aliados.ver','aliados.ver_historial'];
    if ($role === 'nohistory') $_SESSION['permisos'] = ['aliados.ver'];
    if ($role === 'guest') $_SESSION = [];
    echo 'fixture'; exit;
}
if ($path === '/_snapshot') {
    $db = (new Database())->connect(false);
    $counts = [];
    foreach (['aliados_manuales_difusiones','aliados_convocatorias_envios','seguimientos_vinculacion'] as $table) {
        $counts[$table] = (int)$db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
    }
    $counts['manual_id'] = (int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_row()[0];
    header('Content-Type: application/json'); echo json_encode($counts); exit;
}
if ($path === '/index.php' && ($_GET['controller'] ?? '') === 'aliado' && in_array($_GET['action'] ?? '',['historial','avanceDifusion','guardarRespuestaDifusion'],true)) {
    require_once __DIR__.'/../app/controllers/AliadoController.php';
    $action=$_GET['action']; (new AliadoController())->$action(); exit;
}
http_response_code(404);
