<?php
if(getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_horario_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1')exit(2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/_beca_session'){
    session_start();$_SESSION=['usuario_id'=>4,'rol'=>'Marketing','rol_id'=>5,'permisos'=>['convocatorias.crear','formularios_aliados.gestionar_enlaces'],'csrf_token'=>str_repeat('a',64)];echo 'OK';return true;
}
if($path==='/_beca_links'){
    header('Content-Type: application/json');echo file_get_contents(getenv('IMPE_BECA_SNAPSHOT'));return true;
}
if($path==='/index.php'&&($_GET['controller']??'')==='formulario'&&($_GET['action']??'')==='acortarRegistro'){
    session_start();require __DIR__.'/../config/config.php';require __DIR__.'/../app/controllers/FormularioController.php';(new FormularioController())->acortarRegistro();return true;
}
require __DIR__.'/horario_contacto_router.php';
