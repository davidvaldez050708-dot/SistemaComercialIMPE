<?php
if(!in_array(PHP_SAPI,['cli-server','cli'],true)||!preg_match('/^impe_test_formularios_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')){http_response_code(404);exit;}
session_start();
require dirname(__DIR__).'/config/db_connection.php';
require dirname(__DIR__).'/app/models/RolModel.php';
require dirname(__DIR__).'/app/services/FormularioInscripcionProgramas.php';
$m=new RolModel();$role=(int)($_GET['rol']??2);
$_SESSION=['usuario_id'=>$role,'rol_id'=>$role,'rol'=>$role===8?'Marketing':'Rol de prueba','nombre'=>'Persona','apellidos'=>'Prueba','permisos'=>$m->obtenerCodigosPermisosPorRol($role),'csrf_token'=>str_repeat('a',64)];
$vista=$_GET['vista']??'inscripcion';
if($vista==='endpoint'){
 require dirname(__DIR__).'/app/controllers/FormularioController.php';
 $action=$_GET['accion']??'';
 if(!in_array($action,['municipios','generarEnlaceRegistro','generarEnlaceInscripcion','guardarRegistro','guardarInscripcion','guardarInscripcionCompleta','comprobanteInscripcion','validarEjecutivas','acortarRegistro'],true)){http_response_code(404);exit;}
 if($action==='acortarRegistro'){$_POST['csrf_token']=$_SESSION['csrf_token'];$_POST['url']=BASE_URL.'index.php?controller=formularioPublico&action=registro';}
 (new FormularioController())->$action();exit;
}
$estados=[['id'=>1,'nombre'=>'Morelos']];$perfilesInteres=['Bachillerato General'];$nombreCompleto='Persona Prueba';
$programaInscripcion=FormularioInscripcionProgramas::porSlug('titulacion-ejecutivas');
$inscripcionPublica=($_GET['publico']??'')==='1';
$inscripcionRecepcionLista=true;$inscripcionToken=str_repeat('b',64);
$inscripcionAction=BASE_URL.'tests/formularios_permisos_fixture.php?vista=endpoint&accion=guardarInscripcionCompleta&rol='.$role;
$inscripcionMunicipiosUrl=BASE_URL.'tests/formularios_permisos_fixture.php?vista=endpoint&accion=municipios&rol='.$role;
$_SESSION['csrf_inscripcion_completa']=str_repeat('c',64);
?><!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?= BASE_URL ?>public/css/global.css"><link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios.css"></head><body><main style="padding:24px"><?php
switch($vista){
case 'registro':require dirname(__DIR__).'/app/views/formularios/registro.php';break;
case 'catalogo':$programasInscripcion=FormularioInscripcionProgramas::todos();require dirname(__DIR__).'/app/views/formularios/inscripcion_programas.php';break;
case 'menu':require dirname(__DIR__).'/app/views/layout/sidebar.php';break;
default:require dirname(__DIR__).'/app/views/formularios/inscripcion_completa.php';
}
?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script></body></html>
