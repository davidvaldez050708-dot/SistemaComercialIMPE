<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();
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

$nombreRol=mb_strtoupper(trim((string)($_SESSION['rol'] ?? '')),'UTF-8');
if (!tienePermiso('telefonia.usar') ||
    (!tienePermiso('telefonia.recibir') && !tienePermiso('telefonia.salientes'))) {
    responderBorrador(['ok'=>false,'mensaje'=>'No tienes acceso a la captura telefónica.'],403);
}
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','POST'],true)) {header('Allow: GET, POST');responderBorrador(['ok'=>false],405);}
try {
    $service=new TelefoniaBorradorService(Database::compartida());
    if ($method==='GET') {
        if (($_GET['pendientes'] ?? '')==='1') responderBorrador(['ok'=>true,'capturas'=>$service->pendientes($usuario)]);
        $id=$_GET['id'] ?? null;
        if (!is_string($id) || !TelefoniaBorradorService::validarId($id)) throw new InvalidArgumentException('Llamada inválida.');
        responderBorrador(['ok'=>true,'borrador'=>$service->leer($usuario,$id)]);
    }
    $input=json_decode((string)file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Borrador inválido.');
    $token=$input['csrf_token'] ?? null;$expected=(string)($_SESSION['csrf_token'] ?? '');
    if (!is_string($token) || $expected==='' || !hash_equals($expected,$token)) responderBorrador(['ok'=>false,'mensaje'=>'Actualiza la página antes de guardar.'],403);
    $ids=$input['ids'] ?? null;
    if (!is_array($ids) || !$ids || count($ids)>32) throw new InvalidArgumentException('Referencias de llamada inválidas.');
    foreach ($ids as $id) if (!is_string($id) || !TelefoniaBorradorService::validarId($id)) throw new InvalidArgumentException('Llamada inválida.');
    if (($input['accion'] ?? '')==='eliminar') {$closeRevision=$input['revision']??null;if($closeRevision!==null&&(!is_int($closeRevision)||$closeRevision<1))throw new InvalidArgumentException('Revisión inválida.');$service->eliminar($usuario,$ids,$closeRevision);responderBorrador(['ok'=>true]);}
    if (!is_array($input['valores'] ?? null)) throw new InvalidArgumentException('Borrador inválido.');
    $revision=$input['capturado_at'] ?? null;
    if (!is_int($revision) || $revision<1 || $revision>(int)round(microtime(true)*1000)+300000) throw new InvalidArgumentException('La revisión del borrador no es válida.');
    $contexto=$input['contexto'] ?? [];
    if (!is_array($contexto)) throw new InvalidArgumentException('El contexto de la captura no es válido.');
    responderBorrador(['ok'=>true,'borrador'=>$service->guardar($usuario,array_unique($ids),$input['valores'],$revision,$contexto)]);
} catch (InvalidArgumentException|JsonException $e) {responderBorrador(['ok'=>false,'mensaje'=>$e->getMessage()],422);}
catch (DomainException $e) {responderBorrador(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
catch (Throwable $e) {
    if (Database::responderFalloTemporal($e)) exit;error_log('[borrador_llamada] '.get_class($e));responderBorrador(['ok'=>false,'mensaje'=>'El servidor no pudo guardar el borrador. Conserva esta ventana abierta para reintentar.'],500);}
