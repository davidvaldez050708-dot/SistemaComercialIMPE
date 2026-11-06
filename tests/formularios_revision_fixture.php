<?php
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing') { http_response_code(404); exit; }
session_start();
define('ROOT_PATH',dirname(__DIR__));
define('BASE_URL','http://127.0.0.1:8131/');
require ROOT_PATH.'/app/helpers/FormularioPermisos.php';
require ROOT_PATH.'/app/services/FormularioInscripcionValidacion.php';
$_SESSION=['usuario_id'=>999,'rol_id'=>5,'rol'=>'Prueba','permisos'=>array_column(FormularioPermisos::catalogo(),'codigo')];
$_SESSION['csrf_inscripcion_completa']=str_repeat('c',64);
$tipo=$_GET['tipo']??'registro';
$estados=[['id'=>1,'nombre'=>'Morelos']];$perfilesInteres=['Bachillerato General'];
$registroAction=BASE_URL.'tests/envio-sintetico';$municipiosUrl=BASE_URL.'tests/municipios-sinteticos';
if($tipo==='publico'){require ROOT_PATH.'/app/views/formularios/publico_registro.php';exit;}
$programaInscripcion=FormularioInscripcionProgramas::porSlug($_GET['programa']??'titulacion-ejecutivas');
$inscripcionPublica=false;$inscripcionRecepcionLista=true;$inscripcionToken=str_repeat('b',64);
$inscripcionAction=$registroAction;$inscripcionMunicipiosUrl=$municipiosUrl;
$vista=$tipo==='inscripcion'?'inscripcion_completa.php':($tipo==='legacy'?'inscripcion.php':'registro.php');
?><!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?=BASE_URL?>public/css/global.css"><link rel="stylesheet" href="<?=BASE_URL?>public/css/formularios.css"></head><body><main style="padding:24px"><?php require ROOT_PATH.'/app/views/formularios/'.$vista; ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><?php if($tipo!=='inscripcion'):?><script src="<?=BASE_URL?>public/javascript/formularios_registro.js"></script><?php endif;?></body></html>
