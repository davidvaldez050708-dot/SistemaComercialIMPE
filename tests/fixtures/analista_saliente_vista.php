<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
define('BASE_URL','http://salientes.test/');
$_SESSION=['usuario_id'=>1,'csrf_token'=>'test-csrf'];
$_GET=['saliente_ref'=>'out-fixture-001','pbx_call_id'=>'out-fixture-001'];
$simularSinRespuesta=($argv[1] ?? '') === 'sin-respuesta';
$simularSinCierre=($argv[1] ?? '') === 'sin-cierre';
$bandejaEntrantes=['extension'=>'101','llamadas'=>[
 ['pbx_call_id'=>'out-fixture-001','fuente'=>'PBX','tipo_llamada'=>'SALIENTE','numero'=>'5551234567','fecha'=>'2026-11-04 10:00:00','duracion'=>$simularSinRespuesta||$simularSinCierre?0:7,
  'estado_pbx'=>$simularSinCierre?'ringing':'ended',
  'contestada'=>!$simularSinRespuesta && !$simularSinCierre,
  'pbx_conversacion_confirmada'=>!$simularSinRespuesta && !$simularSinCierre,'clasificacion'=>'PENDIENTE']
]];
$estadosEntrantes=[['id'=>1,'nombre'=>'Morelos']];$errorEntrantes='';
require dirname(__DIR__,2).'/app/views/seguimiento_vinculacion/llamadas_entrantes.php';
