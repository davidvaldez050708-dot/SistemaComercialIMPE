<?php
define('BASE_URL','http://atencion.test/');
$_SESSION=['usuario_id'=>1,'csrf_token'=>'test-csrf'];
$bandejaEntrantes=['extension'=>'101','llamadas'=>[
    ['pbx_call_id'=>'pbx-confirmed-001','sdk_session_id'=>'sdk-confirmed-12345678','fuente'=>'PBX','tipo_llamada'=>'ENTRANTE','numero'=>'5551234567','fecha'=>'2026-10-17 09:00:00','duracion'=>30,'estado_pbx'=>'ended','contestada'=>true,'pbx_conversacion_confirmada'=>true,'clasificacion'=>'PENDIENTE'],
    ['pbx_call_id'=>'sdk-pending-12345678','fuente'=>'SDK_WEBRTC','tipo_llamada'=>'ENTRANTE','numero'=>'5559876543','fecha'=>'2026-10-17 08:00:00','duracion'=>30,'estado_pbx'=>'ended','contestada'=>true,'clasificacion'=>'PENDIENTE'],
    ['pbx_call_id'=>'pbx-late-001','sdk_session_id'=>'sdk-late-12345678','fuente'=>'PBX','tipo_llamada'=>'ENTRANTE','numero'=>'5557778888','fecha'=>'2026-10-17 07:00:00','duracion'=>30,'estado_pbx'=>'ended','contestada'=>true,'pbx_conversacion_confirmada'=>true,'clasificacion'=>'VINCULADA','seguimiento_id'=>1,'interaccion_id'=>4,'verificacion_pendiente'=>true],
    ['pbx_call_id'=>'transferencia-1-1','fuente'=>'OPERACION','tipo_llamada'=>'ENTRANTE','numero'=>'5551112233','transferida_desde'=>'100','fecha'=>'2026-10-17 06:00:00','duracion'=>0,'estado_pbx'=>'ended','contestada'=>false,'clasificacion'=>'PENDIENTE','grabacion_pbx_reportada'=>false]
]];
$estadosEntrantes=[['id'=>1,'nombre'=>'Morelos']];$errorEntrantes='';
if(($argv[1]??'')==='audio')$bandejaEntrantes['llamadas'][0]['grabacion_pbx_reportada']=true;
require dirname(__DIR__,2).'/app/views/seguimiento_vinculacion/llamadas_entrantes.php';
