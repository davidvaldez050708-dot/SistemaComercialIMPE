<?php
// Sólo fixture del servidor local de pruebas; no se incluye en los publicadores.
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/app/controllers/TelefoniaController.php';
$_SESSION=['usuario_id'=>8,'rol_id'=>3,'permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes'],
 'csrf_token'=>str_repeat('b',64),'telefonia_contactos_csrf'=>str_repeat('a',64)];
$case=(string)($_GET['caso']??'');
if($case==='sin_sesion')unset($_SESSION['usuario_id']);
if($case==='otro_rol')$_SESSION['rol_id']=4;
if($case==='sin_permiso')$_SESSION['permisos']=[];
$controller=(new ReflectionClass(TelefoniaController::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(TelefoniaController::class,'validarAtencionVentaJson');$method->setAccessible(true);
$method->invoke($controller,($_GET['lectura']??'')!=='1');
header('Content-Type: application/json');echo '{"ok":true}';
