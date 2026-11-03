<?php
if (getenv('APP_ENV')!=='testing'||getenv('IMPE_CENTRAL_BROWSER')!=='1'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!preg_match('/^impe_test_prospectos_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')) {http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/public/')) return false;
session_start();
if ($path==='/_session') {
    $role=$_GET['rol']??'marketing';$roles=['marketing'=>[5,'Marketing'],'admin'=>[1,'Administrador'],'asesor'=>[3,'Asesor de Ventas'],'sin_permiso'=>[6,'Cuenta Clave']];
    [$id,$nombre]=$roles[$role]??$roles['sin_permiso'];$_SESSION=['usuario_id'=>1,'rol_id'=>$id,'rol'=>$nombre,'nombre'=>'Usuario','apellidos'=>'Ficticio','permisos'=>$role==='sin_permiso'?[]:['prospectos.ver_todos']];header('Content-Type: application/json');echo '{"ok":true}';exit;
}
if ($path==='/index.php'&&in_array($_GET['controller']??'',['formularioPublico','formulario'],true)) {
    $class=$_GET['controller']==='formulario'?'FormularioController':'FormularioPublicoController';$action=$_GET['action']??'';
    if (!in_array($action,['registro','inscripcion','guardarRegistro','guardarInscripcion','municipios','gracias'],true)) {http_response_code(404);exit;}
    require __DIR__.'/../app/controllers/'.$class.'.php';(new $class())->$action();exit;
}
if ($path!=='/index.php'||($_GET['controller']??'')!=='prospecto') {http_response_code(404);exit;}
require __DIR__.'/../app/controllers/ProspectoController.php';
$action=$_GET['action']??'index';if (!in_array($action,['index','detalle','detalleRegistro','metaPrevisualizar','metaConfirmar','metaCancelar'],true)) {http_response_code(404);exit;}
(new ProspectoController())->$action();
