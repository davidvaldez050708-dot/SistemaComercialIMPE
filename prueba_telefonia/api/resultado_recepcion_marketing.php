<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';
function responderResultadoRecepcion(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>'Método no permitido.'],405);
}
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId < 1) responderResultadoRecepcion(['ok'=>false,'mensaje'=>'Sesión no activa.'],401);
$rol = new RolModel();
$rol->inicializarPermisosSistema();
$_SESSION['permisos'] = $rol->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id'] ?? 0));
if (!TelefoniaRecepcionMarketingService::tienePermisos()) {
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>'No tienes acceso a recepción.'],403);
}
$csrf = (string)($_SESSION['csrf_token'] ?? '');
$recibido = $_POST['csrf_token'] ?? null;
if ($csrf === '' || !is_string($recibido) || !hash_equals($csrf,$recibido)) {
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>'Actualiza la página antes de guardar.'],403);
}
foreach (['pbx_call_id','resultado','nota'] as $campo) {
    if (!isset($_POST[$campo]) || !is_string($_POST[$campo])) {
        responderResultadoRecepcion(['ok'=>false,'mensaje'=>'Datos de resultado inválidos.'],422);
    }
}
try {
    $registro = (new TelefoniaRecepcionMarketingService())->guardarResultado(
        $usuarioId,trim($_POST['pbx_call_id']),trim($_POST['resultado']),trim($_POST['nota'])
    );
    responderResultadoRecepcion(['ok'=>true,'registro'=>$registro]);
} catch (InvalidArgumentException $e) {
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>$e->getMessage()],422);
} catch (DomainException $e) {
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>$e->getMessage()],$e->getCode() === 409 ? 409 : 403);
} catch (Throwable $e) {
    error_log('[recepcion_resultado] '.get_class($e));
    responderResultadoRecepcion(['ok'=>false,'mensaje'=>'No se pudo guardar. Inténtalo nuevamente.'],500);
}
