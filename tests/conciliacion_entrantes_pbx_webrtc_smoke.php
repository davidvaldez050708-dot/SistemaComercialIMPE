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
$porId=array_column($result,null,'pbx_call_id');
if (count($result)!==2 ||
    $porId['in_first']['sesion_webrtc_correlacionada']!=='sdk-one' ||
    $porId['in_second']['sesion_webrtc_correlacionada']!=='sdk-two' ||
    !$porId['in_second']['grabacion_pbx_reportada']) {
    throw new RuntimeException('No se conciliaron las sesiones exactas sin perder audio PBX.');
}
$sdk[0]['clasificacion']='VINCULADA';$sdk[0]['interaccion_id']=7;$sdk[0]['seguimiento_id']=3;
$result=TelefoniaEntrantesAnalistaService::conciliarHistorialPbxWebrtc($pbx,[$sdk[0]]);
$porId=array_column($result,null,'pbx_call_id');
if (count($result)!==2 || $porId['in_first']['interaccion_id']!==7 || $porId['in_first']['clasificacion']!=='VINCULADA') throw new RuntimeException('Se perdió una clasificación guardada.');
$sdk[0]['interaccion_id']=0;$sdk[0]['seguimiento_id']=0;
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
