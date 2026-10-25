<?php
// Render real de la vista, sin sesión externa, proveedor ni base de datos.
putenv('IMPE_BASE_URL=http://ventas.test/');
require_once dirname(__DIR__,2).'/config/config.php';
require_once ROOT_PATH.'/app/services/TelefoniaVentasLlamadasService.php';
require_once ROOT_PATH.'/app/services/TelefoniaResultadoVentasService.php';
$_SESSION=['csrf_token'=>'fixture-csrf'];$pending=($argv[1]??'')==='pending';
$ventasBandeja=['filas'=>[['id'=>1,'pbx_call_id'=>'pbx-inbound-recording-001','extension'=>'150',
 'direccion'=>'Entrante','nombre'=>'Prospecto de prueba','numero'=>'5550000000','origen_extension'=>'100',
 'solicitud_id'=>10,'fecha'=>'2026-10-24 21:35:00','finalizada'=>!$pending,'conectada'=>true,
 'estado_pbx'=>'Conectada','segundos'=>null,'registro'=>['resultado'=>'CONVERSACION_PERSONA'],
 'segmentos'=>$pending?[]:['record-150'],'segmentos_info'=>[]]],'resumen'=>[]];
require ROOT_PATH.'/app/views/telefonia/ventas_llamadas.php';
