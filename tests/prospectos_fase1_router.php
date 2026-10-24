<?php
if(getenv('APP_ENV')!=='testing'||getenv('IMPE_PROSPECTOS_ROUTER')!=='1'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!preg_match('/^impe_test_prospectos_fase1_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(!in_array($path,['/index.php','/_prospectos_session'],true))return false;
session_start();
if($path==='/_prospectos_session'){$role=$_GET['role']??'marketing';$_SESSION=['usuario_id'=>4,'rol_id'=>$role==='marketing'?5:6,'rol'=>$role==='marketing'?'Marketing':'Cuenta Clave','nombre'=>'Prueba','apellidos'=>'Marketing','permisos'=>in_array($role,['marketing','clave'],true)?['prospectos.ver_todos']:[]];header('Content-Type: application/json');echo '{"ok":true}';return true;}
if(($_GET['controller']??'')!=='prospecto'){http_response_code(404);exit;}
$root=getenv('IMPE_PROSPECTOS_ROOT')?:dirname(__DIR__);require $root.'/app/controllers/ProspectoController.php';(new ProspectoController())->index();return true;
