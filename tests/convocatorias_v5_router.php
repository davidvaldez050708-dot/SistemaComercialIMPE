<?php
if(getenv('IMPE_TEST_ALLOW_ROUTER')!=='1'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
// El componente productivo conserva la ruta actual (index.php). La página
// sintética /_v4 debe dirigir esa misma llamada al controlador real.
if($path==='/_v4'&&($_GET['controller']??'')==='aliado'&&($_GET['action']??'')==='registroGestion')$path='/index.php';
$appRoot=getenv('IMPE_V5_APP_ROOT')?:__DIR__.'/..';
if(!in_array($path,['/_v5','/_v5_session','/_v5_stats','/_v5_failure','/index.php'],true)){
    if(str_starts_with($path,'/_v4') || str_contains($path,'convocatorias_aliado_modular')){putenv('IMPE_V4_APP_ROOT='.$appRoot);require __DIR__.'/convenios_v4_router.php';return true;}
    return false;
}
if($path==='/index.php' && (($_GET['controller']??'')==='crearConvocatoria'||($_GET['action']??'')==='guardarProgramasAliado')){
    if(($_GET['controller']??'')==='crearConvocatoria'&&($_GET['action']??'')==='index')$_SERVER['REQUEST_URI']='/_v4?'.http_build_query($_GET);
    putenv('IMPE_V4_APP_ROOT='.$appRoot);require __DIR__.'/convenios_v4_router.php';return true;
}
session_start();require_once $appRoot.'/app/controllers/ConvocatoriaController.php';
$db=(new Database())->connect(false);$controller=new ConvocatoriaController();
if($path==='/index.php'&&($_GET['controller']??'')==='aliado'&&($_GET['action']??'')==='registroGestion'){
    require_once $appRoot.'/app/controllers/AliadoRegistroActions.php';
    $actions=new class {use AliadoRegistroActions;};$actions->registroGestion();exit;
}
if($path==='/_v5_session'){
    $role=$_GET['role']??'marketing';
    $_SESSION=['usuario_id'=>$role==='marketing'?4:2,'rol_id'=>$role==='marketing'?5:6,'rol'=>$role==='marketing'?'Marketing':'Cuenta Clave','permisos'=>['convocatorias.ver','convocatorias.editar','convocatorias.cambiar_estado','convocatorias.descargar','convenios.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces'],'csrf_token'=>str_repeat('a',64)];
    if($role==='reader')$_SESSION['permisos']=['convocatorias.ver'];
    if($role==='cc-no-links')$_SESSION['permisos']=array_values(array_diff($_SESSION['permisos'],['formularios_aliados.gestionar_enlaces']));
    if($role==='analyst'){$_SESSION['rol_id']=4;$_SESSION['rol']='Analista de Datos';}
    if($role==='admin'){$_SESSION['rol_id']=1;$_SESSION['rol']='Administrador';}
    header('Content-Type: application/json');echo '{"ok":true}';return true;
}
if($path==='/_v5_stats'){
    $rows=$db->query('SELECT id,titulo,imagen,enlace_registro,tipo_convocatoria,subtipo_convocatoria FROM convocatorias ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');echo json_encode(['rows'=>$rows,'drafts'=>(int)$db->query('SELECT COUNT(*) FROM convocatoria_borradores WHERE estado="borrador"')->fetch_row()[0],'links'=>$db->query('SELECT * FROM marketing_aliado_convocatorias_origen ORDER BY id')->fetch_all(MYSQLI_ASSOC)]);return true;
}
if($path==='/_v5_failure'){
    $db->query('DROP TRIGGER IF EXISTS v5_update_failure');
    if(($_GET['active']??'')==='1')$db->query("CREATE TRIGGER v5_update_failure BEFORE UPDATE ON convocatorias FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo sintético V5'");
    echo 'OK';return true;
}
if($path==='/index.php' && ($_GET['action']??'')!=='index'){
    $action=$_GET['action']??'';
    if(!in_array($action,['listadoFiltrado','detalle','guardar','actualizar','cambiarEstado','descargarImagen'],true)){http_response_code(404);return true;}
    $controller->$action();exit;
}
if(!tienePermiso('convocatorias.ver')){http_response_code(403);exit;}
$modelo=new ConvocatoriaModel();$estados=$modelo->obtenerEstados();$territorioId=(int)($_GET['territorio_id']??1);
$allowed=new ReflectionMethod(ConvocatoriaController::class,'usuarioPuedeConsultarTerritorio');if(!$allowed->invoke($controller,$territorioId)){http_response_code(403);exit;}
$territorioSeleccionado=array_values(array_filter($estados,fn($s)=>(int)$s['id']===$territorioId))[0]??null;
$tipoConvocatoria=$_GET['tipo']??'bachillerato';$clasificacion=$_GET['clasificacion']??'';$subtipoConvocatoria=explode('|',$clasificacion,2)[1]??'';
$buscar=$_GET['buscar']??'';$fechaFiltro=$_GET['fecha']??'';$estadoFiltro=$territorioId;
$presentar=new ReflectionMethod(ConvocatoriaController::class,'presentarOrigenes');$contar=new ReflectionMethod(ConvocatoriaController::class,'contarModalidades');
$convocatorias=$presentar->invoke($controller,$modelo->obtenerListado($buscar,$territorioId,'','',$tipoConvocatoria,$subtipoConvocatoria,0,0,$fechaFiltro));
$resumenModalidades=$contar->invoke($controller,$modelo->obtenerListado($buscar,$territorioId,'','',$tipoConvocatoria,'',0,0,$fechaFiltro));
$puedeCrear=tienePermiso('convocatorias.crear');$puedeEditar=tienePermiso('convocatorias.editar');$puedeDescargar=tienePermiso('convocatorias.descargar');$puedeCambiarEstado=tienePermiso('convocatorias.cambiar_estado');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/fixture-bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><script src="/fixture-bootstrap.js"></script></head><body><main class="dashboard-main"><h1>Gestión de Convocatorias</h1><?php require $appRoot.'/app/views/convocatorias/index.php'; ?></main></body></html>
