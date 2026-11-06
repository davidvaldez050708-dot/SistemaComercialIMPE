<?php
if(PHP_SAPI !== 'cli-server' || !preg_match('/^impe_test_inscripcion_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')){http_response_code(404);exit;}
session_start();
require dirname(__DIR__).'/config/db_connection.php';
require dirname(__DIR__).'/app/services/FormularioInscripcionPantalla.php';
require dirname(__DIR__).'/app/helpers/FormularioPermisos.php';
if(($_GET['accion']??'')==='guardar'){
 FormularioPermisos::exigir(FormularioPermisos::INSCRIPCION,true);
 FormularioInscripcionPantalla::guardar(false);exit;
}
$_SESSION['usuario_id']=999;$_SESSION['rol_id']=5;
$_SESSION['permisos']=[FormularioPermisos::VER,FormularioPermisos::INSCRIPCION];
$programaInscripcion=FormularioInscripcionProgramas::porSlug($_GET['programa']??'titulacion-ejecutivas');
$estados=[['id'=>1,'nombre'=>'Morelos']];
FormularioInscripcionPantalla::preparar($programaInscripcion,false);
extract(FormularioInscripcionPantalla::variables(false));
$inscripcionAction=BASE_URL.'tests/inscripcion_completa_fixture.php?accion=guardar';
?><!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?= BASE_URL ?>public/css/global.css"><link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios.css"></head><body><main style="padding:24px"><?php require dirname(__DIR__).'/app/views/formularios/inscripcion_completa.php'; ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script></body></html>
