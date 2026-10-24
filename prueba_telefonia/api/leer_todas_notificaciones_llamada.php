<?php
/** Marcar avisos telefónicos como leídos; sin cerrar tareas de negocio. */
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once dirname(__DIR__,2).'/app/helpers/PermissionHelper.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaNotificacionService.php';
$uid=(int)($_SESSION['usuario_id']??0);
$allowed=tienePermiso('telefonia.usar') &&
    (tienePermiso('telefonia.recibir') ||
    ((int)($_SESSION['rol_id']??0)===3 && tienePermiso('telefonia.salientes')));
if(!$uid||!$allowed){http_response_code(403);echo '{"ok":false}';exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo '{"ok":false}';exit;}
if(empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))){
    http_response_code(403);echo '{"ok":false}';exit;
}
session_write_close();
try{
    (new TelefoniaNotificacionService())->leerTodas($uid);
    echo '{"ok":true}';
}catch(Throwable $e){
    error_log('[telefono_notificaciones_leer_todas] '.get_class($e));
    http_response_code(500);echo '{"ok":false}';
}
