<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/TelefoniaEntrantesAnalistaService.php';

$pbx = [
  ['fuente'=>'PBX','tipo_llamada'=>'ENTRANTE','estado_pbx'=>'ended',
   'pbx_call_id'=>'in_first','numero'=>'+527777885173',
   'fecha'=>'2026-10-16 09:09:29','duracion'=>24,
   'grabacion_pbx_reportada'=>true,'clasificacion'=>'PENDIENTE'],
  ['fuente'=>'PBX','tipo_llamada'=>'ENTRANTE','estado_pbx'=>'ended',
   'pbx_call_id'=>'in_second','numero'=>'+527777885173',
   'fecha'=>'2026-10-16 09:10:27','duracion'=>40,
   'grabacion_pbx_reportada'=>true,'clasificacion'=>'PENDIENTE']
];
$sdk = [
  ['fuente'=>'SDK_WEBRTC','tipo_llamada'=>'ENTRANTE','estado_pbx'=>'ended',
   'pbx_call_id'=>'sdk-one','numero'=>'7777885173',
   'fecha'=>'2026-10-16 09:09:37','clasificacion'=>'PENDIENTE',
   'seguimiento_id'=>0,'interaccion_id'=>0],
  ['fuente'=>'SDK_WEBRTC','tipo_llamada'=>'ENTRANTE','estado_pbx'=>'ended',
   'pbx_call_id'=>'sdk-two','numero'=>'7777885173',
   'fecha'=>'2026-10-16 09:10:39','clasificacion'=>'PENDIENTE',
   'seguimiento_id'=>0,'interaccion_id'=>0]
];
$result = TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc($pbx,$sdk);
if (count($result)!==2 || $result[0]['pbx_call_id']!=='in_first' ||
    $result[1]['pbx_call_id']!=='in_second' ||
    $result[0]['sesion_webrtc_correlacionada']!=='sdk-one' ||
    !$result[1]['grabacion_pbx_reportada']) {
    throw new RuntimeException('No se conciliaron las sesiones exactas sin perder audio PBX.');
}
$sdk[0]['clasificacion']='EXISTENTE';
$result=TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc($pbx,[$sdk[0]]);
if (count($result)!==3) throw new RuntimeException('Se ocultó una clasificación guardada.');
$sdk[0]['clasificacion']='PENDIENTE';
$ambiguous = $pbx;
$ambiguous[]=$pbx[0];
$ambiguous[2]['pbx_call_id']='in_concurrent';
$result=TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc($ambiguous,[$sdk[0]]);
if (count($result)!==4) throw new RuntimeException('Se ocultó coincidencia ambigua.');
$another=$sdk[0];
$another['pbx_call_id']='sdk-other';
$result=TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc([$pbx[0]],[$sdk[0],$another]);
if (count($result)!==3) throw new RuntimeException('Se ocultaron sesiones competidoras.');
$sdk[0]['numero']='5512345678';
$result=TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc([$pbx[0]],[$sdk[0]]);
if (count($result)!==2) throw new RuntimeException('Se mezclaron teléfonos distintos.');
echo "Conciliación entrantes PBX/WebRTC: OK\n";
