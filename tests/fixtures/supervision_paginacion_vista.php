<?php
error_reporting(E_ALL);set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
define('ROOT_PATH',dirname(__DIR__,2));define('BASE_URL','http://supervision.test/');
function renderAvatarUsuario(...$args){return '<span class="system-avatar">SP</span>';}
$total=max(0,(int)($argv[2]??2670));$pages=max(1,(int)ceil($total/30));$page=min($pages,max(1,(int)($argv[1]??1)));
$scope='global';$puedeAudio=true;
$f=['area'=>'asesores','persona_id'=>8,'desde'=>'2026-11-01','hasta'=>'2026-11-05','direccion'=>'Saliente','resultado'=>'SIN_RESPUESTA','buscar'=>'Prueba & "institución"','pagina'=>$page];
$data=['personas'=>[['id'=>8,'nombre'=>'Persona Sintética','activo'=>true]],'llamadas'=>[],'total'=>$total,'paginas'=>$pages,'pagina'=>$page,'resultados'=>['SIN_RESPUESTA'=>'No contestó']];
for($i=($page-1)*30;$i<min($page*30,$total);$i++)$data['llamadas'][]=['key'=>'venta:'.($i+1),'persona'=>'Persona Sintética','nombre'=>'Llamada de prueba','numero'=>'5550000000','extension'=>'150','fecha'=>'2026-11-04 12:00:00','direccion'=>'Saliente','foto_perfil'=>'','segundos'=>0,'observada'=>false,'estado_pbx'=>'Cancelada','conectada'=>false,'resultado_guardado'=>true,'audio_consultable'=>false,'resultado_texto'=>'No contestó','area'=>'asesores'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="public/css/global.css"><link rel="stylesheet" href="public/css/dashboard.css"><style>body{margin:0;background:#f4f7fb;padding:16px}.supervision-calls{margin:0!important;width:100%;max-width:1100px;box-sizing:border-box}main{margin:auto;max-width:1100px}</style></head><body><main><?php require ROOT_PATH.'/app/views/telefonia/supervision_llamadas.php'; ?></main></body></html>
