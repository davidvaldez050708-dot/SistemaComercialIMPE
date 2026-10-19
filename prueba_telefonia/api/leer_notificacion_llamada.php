<?php
declare(strict_types=1);
session_start();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
require_once dirname(__DIR__,2).'/app/helpers/PermissionHelper.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaNotificacionService.php';
$id=(int)($_SESSION['usuario_id']??0);
if(!$id||!tienePermiso('telefonia.usar')||(!tienePermiso('telefonia.recibir')&&!((int)($_SESSION['rol_id']??0)===3&&tienePermiso('telefonia.salientes')))){http_response_code(403);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
if(empty($_SESSION['csrf_token'])||!hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))){http_response_code(403);exit;}
try{$svc=new TelefoniaNotificacionService();echo json_encode(['ok'=>$svc->leer($id,(int)($_POST['id']??0))]);}
catch(Throwable $e){error_log('[telefono_notificacion_leer] '.get_class($e));http_response_code(500);echo '{"ok":false}';}
