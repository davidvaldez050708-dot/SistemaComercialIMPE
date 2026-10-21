<?php
putenv('IMPE_BASE_URL=http://ventas.test/');
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaVentasLlamadasService.php';
$_SESSION=['usuario_id'=>8,'rol_id'=>3,'csrf_token'=>str_repeat('b',64),'telefonia_contactos_csrf'=>str_repeat('a',64)];
$_GET=['controller'=>'telefonia','action'=>'misLlamadas','pbx_call_id'=>$argv[1]??'','extension'=>$argv[2]??''];
$contactosVentas=[['id'=>1,'nombre'=>'María','telefono'=>'+525551112233']];
$ventasBandeja=['filas'=>[
 ['id'=>1,'pbx_call_id'=>'pbx-sales-call-001','usuario_id'=>8,'extension'=>'150','direccion'=>'Entrante','numero'=>'+525551112233','nombre'=>'María','origen_extension'=>'100','fecha'=>'2026-10-19 10:00:00','finalizada'=>true,'conectada'=>true,'segundos'=>18,'estado_pbx'=>'Conectada','segmentos'=>['record-sales-a','record-sales-b'],'registro'=>null],
 ['id'=>2,'pbx_call_id'=>'pbx-sales-call-002','usuario_id'=>8,'extension'=>'150','direccion'=>'Entrante','numero'=>'+525551112233','nombre'=>'Pedro','origen_extension'=>'100','fecha'=>'2026-10-19 10:05:00','finalizada'=>true,'conectada'=>false,'segundos'=>0,'estado_pbx'=>'Sin respuesta','segmentos'=>[],'registro'=>['resultado'=>'SIN_RESPUESTA','observacion'=>'Reintentar','prospecto_nombre'=>'Pedro']]
 ],'pagina'=>1,'paginas'=>1,'total'=>2,'resumen'=>['pbx'=>2,'intentos'=>0,'pendientes'=>1,'perdidas'=>1,'conversaciones'=>0]];
if(($argv[3]??'')==='guardado')$ventasBandeja['filas'][0]['registro']=['resultado'=>'INTERESADO','observacion'=>'Enviar información','prospecto_nombre'=>'María'];
if(($argv[3]??'')==='guardado')$ventasBandeja['filas'][0]['segmentos_info']=[['tramo'=>1,'segundos'=>8],['tramo'=>2,'segundos'=>10]];
if(($argv[3]??'')==='guardado'){$ventasBandeja['resumen']['pendientes']=0;$ventasBandeja['resumen']['conversaciones']=1;}
require ROOT_PATH.'/app/views/telefonia/ventas_llamadas.php';
