<?php
putenv('IMPE_BASE_URL=http://ventas.test/');
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaVentasLlamadasService.php';
$_SESSION=['usuario_id'=>8,'rol_id'=>3,'csrf_token'=>str_repeat('b',64)];
$_GET=json_decode($argv[1]??'{}',true)+['controller'=>'telefonia','action'=>'misLlamadas'];$contactosVentas=[];
$all=[];for($i=1;$i<=26;$i++)$all[]=['id'=>$i,'version'=>1,'nombre'=>'Prospecto '.$i,'contacto'=>'Prospecto '.$i,'telefono'=>'+525551112233','nota'=>'Solicita información','origen_extension'=>'100','updated_at'=>'2026-10-19 20:00:00','devolucion'=>'PENDIENTE','intento'=>null,'origen'=>'agenda','fecha'=>$i%2?'2020-01-01 10:00:00':'2099-01-01 10:00:00','detalle'=>'Información comercial','pbx_call_id'=>'','extension'=>'150'];
$vista=$_GET['vista']??'';
if($vista==='devoluciones'){$devolucionesVentas=$all;require ROOT_PATH.'/app/views/telefonia/ventas_devoluciones.php';}
elseif($vista==='agenda'){$programacionesVentas=$all;require ROOT_PATH.'/app/views/telefonia/ventas_programadas.php';}
else{
 $rows=[];foreach($all as $i=>$a)$rows[]=['id'=>$a['id'],'pbx_call_id'=>'pbx-bandeja-'.sprintf('%03d',$a['id']),'usuario_id'=>8,'extension'=>'150','direccion'=>'Saliente','numero'=>$a['telefono'],'nombre'=>$a['nombre'],'origen_extension'=>'','fecha'=>'2026-10-19 14:40:26','finalizada'=>true,'conectada'=>true,'segundos'=>18,'estado_pbx'=>'Conectada','segmentos'=>[],'registro'=>null];
 $q=mb_strtolower($_GET['q']??'');$rows=array_values(array_filter($rows,static fn($a)=>$q===''||str_contains(mb_strtolower($a['nombre'].' '.$a['numero']),$q)));
 $total=count($rows);$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($_GET['pagina']??1)));
 $ventasBandeja=['filas'=>array_slice($rows,($page-1)*20,20),'pagina'=>$page,'paginas'=>$pages,'total'=>$total,'resumen'=>['pbx'=>26,'intentos'=>0,'pendientes'=>26,'perdidas'=>0,'conversaciones'=>0]];
 if(($argv[2]??'')==='hostinger'){
  $snapshot=file_get_contents(ROOT_PATH.'/tools/ventas_pr108_hostinger/before/app/views/telefonia/ventas_llamadas.php');
  // Snapshot PHP confiable del repositorio; resolver su include desde la vista original.
  eval('?>'.str_replace('__DIR__',"ROOT_PATH.'/app/views/telefonia'",$snapshot));
 }
 else require ROOT_PATH.'/app/views/telefonia/ventas_llamadas.php';
}
