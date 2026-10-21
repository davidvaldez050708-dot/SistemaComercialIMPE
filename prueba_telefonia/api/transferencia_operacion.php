<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
$root=dirname(__DIR__,2);
require_once $root.'/app/helpers/PermissionHelper.php';
require_once $root.'/app/services/TelefoniaTransferenciaOperacionService.php';
function trResponder(array $data,int $status=200): void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
$user=(int)($_SESSION['usuario_id']??0);
if(!$user)trResponder(['ok'=>false],401);
if(!tienePermiso('telefonia.usar')||!tienePermiso('telefonia.recibir'))trResponder(['ok'=>false],403);
try{
    $svc=new TelefoniaTransferenciaOperacionService();
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $id=(int)($_GET['id']??0);
        $request=(int)($_GET['solicitud_id']??0);
        $op=$id?$svc->obtener($user,$id):($request?$svc->paraDestinoConfirmado($user,$request,trim((string)($_GET['sdk']??''))):$svc->paraDestino($user,trim((string)($_GET['origen']??'')),trim((string)($_GET['sdk']??''))));
        trResponder(['ok'=>true,'operacion'=>$op]);
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')trResponder(['ok'=>false],405);
    if(!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??''))||empty($_SESSION['csrf_token']))trResponder(['ok'=>false],403);
    trResponder(['ok'=>true,'operacion'=>$svc->accion($user,(int)($_POST['id']??0),(string)($_POST['accion']??''))]);
}catch(DomainException $e){trResponder(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
catch(Throwable $e){error_log('[transferencia_operacion] '.get_class($e));trResponder(['ok'=>false,'mensaje'=>'No se pudo actualizar la transferencia.'],500);}
