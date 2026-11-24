<?php
session_start();
require_once dirname(__DIR__,2).'/app/helpers/PermissionHelper.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoAnalistaService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$usuario = (int)($_SESSION['usuario_id'] ?? 0);
if (!$usuario || (int)($_SESSION['rol_id'] ?? 0) !== 4 || !tienePermiso('seguimientos_vinculacion.operar_propios')) {
    http_response_code(403); echo json_encode(['ok'=>false,'mensaje'=>'No tienes permiso para operar este seguimiento.']); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); echo json_encode(['ok'=>false,'mensaje'=>'Método no permitido.']); exit;
}
session_write_close();
try {
    $reglas = TelefoniaResultadoAnalistaService::consultar($usuario, (int)($_GET['seguimiento_id'] ?? 0), trim((string)($_GET['pbx_call_id'] ?? '')));
    echo json_encode(['ok'=>true,'reglas'=>$reglas], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $e) {
    http_response_code(409); echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[analista_reglas_resultado] '.get_class($e));
    http_response_code(503); echo json_encode(['ok'=>false,'mensaje'=>'No se pudo comprobar la llamada. Intenta nuevamente.']);
}
