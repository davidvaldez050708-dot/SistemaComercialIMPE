<?php
if(getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_horario_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
$root=dirname(__DIR__);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/public/'))return false;
if(preg_match('~^/registro/(obtener-beca|el-primer-paso-hacia-tu-meta-comienza-aqui)/([A-Za-z0-9_-]{12})$~',$path,$match)){
    $_GET=['codigo'=>$match[2]];
    require $root.'/registro/'.$match[1].'/index.php';return true;
}
session_start();require $root.'/config/config.php';
if($path==='/_horario_internal'){
    $_SESSION=['usuario_id'=>4,'rol'=>'Marketing'];
    require $root.'/app/models/FormularioRegistroModel.php';$m=new FormularioRegistroModel();$estados=$m->obtenerEstadosActivos();
    $perfilesInteres=['Bachillerato General','Inglés'];$registroAction=BASE_URL.'index.php?controller=formulario&action=guardarRegistro';$municipiosUrl=BASE_URL.'index.php?controller=formularioPublico&action=municipios';
    require __DIR__.'/horario_contacto_internal.php';return true;
}
if($path==='/_horario_rows'){
    require $root.'/app/models/ProspectoModel.php';header('Content-Type: application/json');echo json_encode((new ProspectoModel())->listar(1,100,['buscar'=>$_GET['buscar']??''])['registros']);return true;
}
$c=$_GET['controller']??'';$a=$_GET['action']??'';
if($path!=='/index.php'||!in_array($c,['formulario','formularioPublico'],true)||!in_array($a,['registro','guardarRegistro','municipios','inscripcion','gracias'],true)){http_response_code(404);return true;}
$class=$c==='formulario'?'FormularioController':'FormularioPublicoController';require $root.'/app/controllers/'.$class.'.php';(new $class())->$a();return true;
