<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$root=dirname(__DIR__,2);
require_once $root.'/config/db_connection.php';
require_once $root.'/app/helpers/PermissionHelper.php';
require_once $root.'/app/models/RolModel.php';
require_once $root.'/app/services/TelefoniaBorradorService.php';
function responderBorrador(array $datos,int $status=200): void {http_response_code($status);echo json_encode($datos,JSON_UNESCAPED_UNICODE);exit;}
$usuario=(int)($_SESSION['usuario_id'] ?? 0);
if ($usuario<1) responderBorrador(['ok'=>false,'mensaje'=>'Sesión no activa.'],401);
$rol=new RolModel();$rol->inicializarPermisosSistema();
$_SESSION['permisos']=$rol->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id'] ?? 0));
$nombreRol=mb_strtoupper(trim((string)($_SESSION['rol'] ?? '')),'UTF-8');
if (!tienePermiso('telefonia.usar') || !in_array($nombreRol,['ANALISTA DE DATOS','ASESOR','ASESOR DE VENTAS'],true)) {
    responderBorrador(['ok'=>false,'mensaje'=>'No tienes acceso a la captura telefónica.'],403);
}
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','POST'],true)) {header('Allow: GET, POST');responderBorrador(['ok'=>false],405);}
try {
    $service=new TelefoniaBorradorService((new Database())->connect());
    if ($method==='GET') {
        $id=$_GET['id'] ?? null;
        if (!is_string($id) || !TelefoniaBorradorService::validarId($id)) throw new InvalidArgumentException('Llamada inválida.');
        responderBorrador(['ok'=>true,'borrador'=>$service->leer($usuario,$id)]);
    }
    $input=json_decode((string)file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Borrador inválido.');
    $token=$input['csrf_token'] ?? null;$expected=(string)($_SESSION['csrf_token'] ?? '');
    if (!is_string($token) || $expected==='' || !hash_equals($expected,$token)) responderBorrador(['ok'=>false,'mensaje'=>'Actualiza la página antes de guardar.'],403);
    $ids=$input['ids'] ?? null;
    if (!is_array($ids) || !$ids || count($ids)>3) throw new InvalidArgumentException('Referencias de llamada inválidas.');
    foreach ($ids as $id) if (!is_string($id) || !TelefoniaBorradorService::validarId($id)) throw new InvalidArgumentException('Llamada inválida.');
    if (($input['accion'] ?? '')==='eliminar') {$service->eliminar($usuario,$ids);responderBorrador(['ok'=>true]);}
    if (!is_array($input['valores'] ?? null)) throw new InvalidArgumentException('Borrador inválido.');
    $revision=$input['capturado_at'] ?? null;
    if (!is_int($revision) || abs($revision-(int)round(microtime(true)*1000))>300000) throw new InvalidArgumentException('Actualiza la página para sincronizar el borrador.');
    responderBorrador(['ok'=>true,'borrador'=>$service->guardar($usuario,array_unique($ids),$input['valores'],$revision)]);
} catch (InvalidArgumentException|JsonException $e) {responderBorrador(['ok'=>false,'mensaje'=>$e->getMessage()],422);}
catch (DomainException $e) {responderBorrador(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
catch (Throwable $e) {error_log('[borrador_llamada] '.get_class($e));responderBorrador(['ok'=>false,'mensaje'=>'El servidor no pudo guardar el borrador. Conserva esta ventana abierta para reintentar.'],500);}
