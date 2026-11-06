<?php
if (PHP_SAPI!=='cli-server'||getenv('APP_ENV')!=='testing') {http_response_code(404);exit;}
define('ROOT_PATH',dirname(__DIR__));define('BASE_URL','http://127.0.0.1:8133/');
require ROOT_PATH.'/app/services/FormularioInscripcionProgramas.php';
$programasInscritos=FormularioInscripcionProgramas::todos();
$puedeExportarInscritos=($_GET['exportar']??'1')==='1';
$puedeVerComprobantesInscritos=true;
?><!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?=BASE_URL?>public/css/global.css"><style>body{margin:0;background:#f5f7fb;font-family:Manrope,sans-serif}.fixture-topbar{padding:18px 30px;background:white;border-bottom:1px solid #e1e8f0}.fixture-topbar h1{font-size:25px;font-weight:800;margin:0 0 5px}.fixture-topbar p{font-size:13px;color:#74849d;margin:0}main{padding:30px}@media(max-width:600px){main{padding:16px}}</style></head><body><header class="fixture-topbar"><h1>Inscritos</h1><p>Consulta las inscripciones recibidas por programa.</p></header><main><?php require ROOT_PATH.'/app/views/inscritos/index.php';?></main></body></html>
