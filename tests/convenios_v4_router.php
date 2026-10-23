<?php
if(getenv('IMPE_TEST_ALLOW_ROUTER')!=='1'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$appRoot=getenv('IMPE_V4_APP_ROOT')?:__DIR__.'/..';
if($path==='/public/javascript/convocatorias_aliado_modular.js'){header('Content-Type: application/javascript');readfile(is_file($appRoot.$path)?$appRoot.$path:__DIR__.'/fixtures/v3_hostinger/convocatorias_aliado_modular.js');return true;}
if($path==='/public/css/convocatorias_aliado_modular.css'){header('Content-Type: text/css');readfile(is_file($appRoot.$path)?$appRoot.$path:__DIR__.'/fixtures/v3_hostinger/convocatorias_aliado_modular.css');return true;}
if(!in_array($path,['/_v4','/_v4_session','/_v4_stats','/_v4_fail','/_v4_link','/index.php'],true))return false;
session_start();require_once $appRoot.'/config/db_connection.php';require_once $appRoot.'/app/services/AliadoRegistroService.php';
$db=(new Database())->connect(false);
if($path==='/_v4_session'){
    if(($_GET['role']??'')==='logout'){$_SESSION=[];}else{
        $role=$_GET['role']??'marketing';$_SESSION=['usuario_id'=>$role==='marketing'?4:($role==='other'?3:($role==='outside'?21:2)),'rol_id'=>in_array($role,['marketing','other'],true)?5:6,'rol'=>in_array($role,['marketing','other'],true)?'Marketing':'Cuenta Clave','permisos'=>['convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces'],'csrf_token'=>str_repeat('a',64)];
    }header('Content-Type: application/json');echo json_encode(['ok'=>true]);return true;
}
if($path==='/_v4_stats'){
    $drafts=$db->query('SELECT id,version,estado,propietario_id,datos FROM convocatoria_borradores ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $rows=$db->query("SELECT c.id,c.titulo,c.imagen,c.enlace_registro,c.tipo_convocatoria,c.subtipo_convocatoria,o.programa_codigo,o.origen_tipo,o.origen_id FROM convocatorias c LEFT JOIN marketing_aliado_convocatorias_origen o ON o.convocatoria_id=c.id WHERE c.titulo LIKE 'V4 %' ORDER BY c.id")->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');echo json_encode(['drafts'=>$drafts,'convocatorias'=>$rows,'image_count'=>(int)$db->query('SELECT COUNT(*) FROM convocatoria_borrador_imagenes')->fetch_row()[0],'physical_images'=>count(glob(getenv('IMPE_CONVOCATORIA_DRAFT_DIR').'/*/*')?:[])]);return true;
}
if($path==='/_v4_link'){
    (new AliadoRegistroService($db))->enlace('institucional',10,($_GET['active']??'')==='1'?'habilitar':'deshabilitar');header('Content-Type: application/json');echo json_encode(['ok'=>true]);return true;
}
if($path==='/_v4_fail'){
    if(($_GET['active']??'')==='1')$db->query("CREATE TRIGGER v4_fallo BEFORE INSERT ON convocatorias FOR EACH ROW BEGIN IF NEW.subtipo_convocatoria='experiencia-laboral' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='V4 fallo de prueba'; END IF; END");else $db->query('DROP TRIGGER IF EXISTS v4_fallo');
    header('Content-Type: application/json');echo json_encode(['ok'=>true]);return true;
}
if($path==='/index.php'){
    if(($_GET['controller']??'')==='crearConvocatoria'){
        require_once $appRoot.'/app/controllers/CrearConvocatoriaController.php';$controller=new CrearConvocatoriaController();$action=$_GET['action']??'';
        if(!in_array($action,['editorConvenio','borrador','imagenBorrador'],true)){http_response_code(404);return true;}$controller->$action();exit;
    }
    if(($_GET['action']??'')==='guardarProgramasAliado'){require_once $appRoot.'/app/controllers/ConvocatoriaController.php';(new ConvocatoriaController())->guardarProgramasAliado();exit;}
    http_response_code(404);return true;
}
if(!isset($_SESSION['usuario_id'])){header('Location: /_v4_session');return true;}
require_once $appRoot.'/app/models/CrearConvocatoriaModel.php';
$model=new CrearConvocatoriaModel();$estructuraDisponible=$model->estructuraDisponible();$estados=$model->listarEstados();$convenios=$model->listarConveniosRecibidos(in_array($_SESSION['rol'],['Marketing'],true)?null:[1]);$puedeCrearDesdeConvenio=puedeCrearConvocatoriaDesdeConvenio();$_GET['estado_id']=(int)($_GET['estado_id']??1);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><link rel="stylesheet" href="/fixture-bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/public/css/crear_convocatoria.css"><link rel="stylesheet" href="/public/css/aliados_v2_extension.css"></head><body><main class="dashboard-main"><?php require $appRoot.'/app/views/convocatorias/crear_desde_convenio.php'; ?></main><script src="/fixture-bootstrap.js"></script><script src="/public/javascript/crear_convocatoria.js"></script><script src="/public/javascript/crear_convocatoria_v2_extension.js"></script></body></html>
