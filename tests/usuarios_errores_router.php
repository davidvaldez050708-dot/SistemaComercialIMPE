<?php
if (getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_usuarios_errores_[a-z0-9]+$/D',getenv('IMPE_DB_NAME')?:'')||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1') {http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(str_starts_with($path,'/public/')) return false;
session_start();
if($path==='/_session'){$_SESSION=['usuario_id'=>1,'nombre'=>'Persona','apellidos'=>'Ficticia','rol_id'=>1,'rol'=>'Administrador','permisos'=>['usuarios.ver','usuarios.crear','usuarios.editar','usuarios.cambiar_estado']];echo 'OK';exit;}
if($path!=='/index.php'||($_GET['controller']??'')!=='usuario'||!in_array($_GET['action']??'',['index','tabla','guardar','actualizar','cambiarEstado','eliminar'],true)){http_response_code(404);exit;}
require __DIR__.'/../app/controllers/UsuarioController.php';$a=$_GET['action'];(new UsuarioController())->$a();
