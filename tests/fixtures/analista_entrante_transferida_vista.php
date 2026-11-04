<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
define('BASE_URL','http://transferidas.test/');
$_SESSION=['usuario_id'=>8,'csrf_token'=>'test-csrf'];
$id='sdk-transfer-abc123xyz456';
$_GET=['sdk_session_id'=>$id];
$desdeMisLlamadas=true;
$bandejaEntrantes=['extension'=>'101','llamadas'=>[[
 'pbx_call_id'=>$id,'sdk_session_id'=>$id,'fuente'=>'SDK_WEBRTC',
 'tipo_llamada'=>'ENTRANTE','numero'=>'5551234567',
 'fecha'=>'2026-11-04 10:00:00','duracion'=>20,'estado_pbx'=>'ended',
 'contestada'=>true,'pbx_conversacion_confirmada'=>false,'clasificacion'=>'PENDIENTE',
 'transferida_desde'=>'100'
]]];
$estadosEntrantes=[['id'=>1,'nombre'=>'Morelos']];$errorEntrantes='';
require dirname(__DIR__,2).'/app/views/seguimiento_vinculacion/llamadas_entrantes.php';
