<?php
// Datos de interfaz aislados; no conecta al CRM ni a Zadarma.
putenv('IMPE_BASE_URL=http://ventas.test/');
require_once dirname(__DIR__,2).'/app/services/TelefoniaVentasLlamadasService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
$_SESSION=['usuario_id'=>8,'rol_id'=>3,'csrf_token'=>str_repeat('b',64),'telefonia_contactos_csrf'=>str_repeat('a',64)];
$_GET=json_decode($argv[1]??'{}',true)+['controller'=>'telefonia','action'=>'misLlamadas'];
$contactosVentas=[];
$base=['usuario_id'=>8,'extension'=>'150','direccion'=>'Saliente','numero'=>'+527777885173','nombre'=>'','origen_extension'=>'','fecha'=>'2026-10-19 14:40:26','finalizada'=>true,'conectada'=>true,'segundos'=>18,'estado_pbx'=>'Conectada','segmentos'=>[],'registro'=>null];
$result=['resultado'=>'CONVERSACION_PERSONA','prospecto_nombre'=>'David Valdez','observacion'=>'Información comercial'];
$ventasBandeja=['filas'=>[
 ['id'=>1,'pbx_call_id'=>'pbx-pendiente-001']+$base,
 ['id'=>'intento-9','pbx_call_id'=>'','conectada'=>false,'segundos'=>null,'fecha'=>'2026-10-19 14:39:31']+$base,
 ['id'=>3,'pbx_call_id'=>'pbx-audio-003','registro'=>$result,'segmentos'=>['record-sales-a','record-sales-b'],'fecha'=>'2026-10-19 14:37:32']+$base,
 ['id'=>4,'pbx_call_id'=>'pbx-espera-004','registro'=>$result,'audio_motivo'=>'sin_tramo','direccion'=>'Entrante','origen_extension'=>'100']+$base,
 ['id'=>5,'pbx_call_id'=>'pbx-buzon-005','registro'=>['resultado'=>'BUZON_VOZ','prospecto_nombre'=>'','observacion'=>'Contestadora'],'segmentos'=>['record-buzon']] +$base,
 ],'pagina'=>1,'paginas'=>2,'total'=>25,'resumen'=>['pbx'=>4,'intentos'=>1,'pendientes'=>1,'perdidas'=>0,'conversaciones'=>2]];
require ROOT_PATH.'/app/views/telefonia/ventas_llamadas.php';
