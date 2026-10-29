<?php
require_once dirname(__DIR__).'/app/services/SupervisionLlamadasAudioService.php';
require_once dirname(__DIR__).'/app/services/ZadarmaRecordingService.php';
$audioCases=[['WHATSAPP'],['SMS'],['WHATSAPP','SMS'],['BUZON_VOZ'],['PROSPECTO_POTENCIAL','WHATSAPP']];$refs=[];
foreach($audioCases as $i=>$selection){
 $ref='out_audio_resultado_'.$i;$refs[]=$ref;$event($ref,'NOTIFY_OUT_START');$event($ref,'NOTIFY_ANSWER');$event($ref,'NOTIFY_OUT_END','150',33,['disposition'=>'answered']);
 $event($ref,'NOTIFY_RECORD','150',0,['call_id_with_rec'=>'audio-verificado-'.$i]);
 $results->guardar(8,$ref,'',$v3($selection));
}
$snap=[];foreach(['telefonia_ventas_llamadas','telefonia_ventas_resultados','telefonia_ventas_resultado_detalles','telefonia_ventas_resultado_selecciones','telefonia_zadarma_eventos'] as $table)$snap[$table]=$db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
$provider=new ZadarmaRecordingService(false);$audioSvc=new SupervisionLlamadasAudioService($db);$audioRepo=new SupervisionLlamadasRepository($db);
foreach($refs as $i=>$ref){
 $call=$svc->obtener(8,$ref,'150');$key='venta:'.$call['id'];
 vcheck(TelefoniaVentasLlamadasService::audioConsultable($call)&&TelefoniaVentasLlamadasService::presentacionAudio($call)['disponible'],'Audio propio disponible sin depender del resultado '.$i);
 foreach([1,2,20] as $supervisor){
  vcheck($audioRepo->obtener($supervisor,$key,true)['audio_consultable'],'Tarjeta de supervisión ofrece la consulta '.$i);
  $out=$audioSvc->resolver($supervisor,$key,$provider,true);vcheck($out['disponible']&&$out['segmentos']===1,'Proveedor verifica el archivo en el alcance correcto '.$i);
 }
 $before=count(ZadarmaRecordingService::$consultados);vreject(fn()=>$audioSvc->resolver(21,$key,$provider,true),'Otro equipo no consulta audio');vcheck(count(ZadarmaRecordingService::$consultados)===$before,'Acceso ajeno rechazado antes del proveedor');
 $m=VentasActividadMetricas::calcular([$call],[['id'=>8,'nombre_completo'=>'Asesor']],['fecha_desde'=>substr($call['fecha'],0,10),'fecha_hasta'=>substr($call['fecha'],0,10)]);
 vcheck($m['resumen']['conversaciones_confirmadas']===($i===4?1:0),'El audio no convierte envíos o buzones en contacto humano '.$i);
}
foreach($snap as $table=>$rows)vcheck($db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC)===$rows,'Consultar no cambia '.$table);
// La vista real del asesor ofrece el reproductor con las etiquetas existentes.
if(!defined('ROOT_PATH'))define('ROOT_PATH',dirname(__DIR__));if(!defined('BASE_URL'))define('BASE_URL','http://127.0.0.1/');
$ventasBandeja=['filas'=>array_map(static fn($ref)=>$svc->obtener(8,$ref,'150'),$refs),'total'=>count($refs),'pagina'=>1,'paginas'=>1];$contactosVentas=[];
ob_start();require dirname(__DIR__).'/app/views/telefonia/ventas_llamadas.php';$htmlAudio=ob_get_clean();
$dom=new DOMDocument();$oldErrors=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$htmlAudio);libxml_clear_errors();libxml_use_internal_errors($oldErrors);$xpath=new DOMXPath($dom);
vcheck($xpath->query('//*[@data-sales-audio]')->length===5,'Historial real ofrece consulta para WhatsApp, SMS, ambos canales y buzón');
$call=$svc->obtener(8,$refs[0],'150');$empty=$call;$empty['segmentos']=[];$empty['segmentos_info']=[];
vcheck(!TelefoniaVentasLlamadasService::presentacionAudio($empty)['disponible'],'Duración de 33 s sin referencia no inventa una grabación');
$active=$call;$active['finalizada']=false;vcheck(!TelefoniaVentasLlamadasService::audioConsultable($active),'Saliente en curso no habilita reproducción');
$transfer=$active;$transfer['direccion']='Entrante';$transfer['origen_extension']='100';$transfer['solicitud_id']=1;
vcheck(TelefoniaVentasLlamadasService::audioConsultable($transfer),'Transferencia atendida conserva consulta sin END');
$transfer['origen_extension']='150';vcheck(!TelefoniaVentasLlamadasService::audioConsultable($transfer),'No confunde la extensión propia con origen de transferencia');
// Revocar el permiso de audio sigue teniendo efecto inmediato.
$db->query("UPDATE permisos SET estado=0 WHERE codigo='llamadas.supervision.audio'");vreject(fn()=>$audioSvc->resolver(20,'venta:'.$call['id'],$provider,true),'Audio revocado no se habilita por tener grabación');$db->query("UPDATE permisos SET estado=1 WHERE codigo='llamadas.supervision.audio'");
putenv('IMPE_TEST_DATABASE='.$name);
$api=static function(array $case): array{
 putenv('IMPE_TEST_AUDIO_CASE='.json_encode($case));$cmd=[PHP_BINARY,__DIR__.'/ventas_audio_resultado_api.php'];$p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);$body=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
 if($status!==0||!preg_match('/HTTP:(\d+)/',$err,$m))throw new RuntimeException('Fallo API de prueba: '.$err.' '.$body);
 return [(int)$m[1],$body];
};
foreach([$refs[0],$refs[3]] as $ref){
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref,'verify'=>true]);$json=json_decode($body,true,512,JSON_THROW_ON_ERROR);vcheck($status===200&&$json['disponible']&&$json['segmentos']===1,'API personal verifica audio de envío o buzón');
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref]);vcheck($status===200&&$body==='AUDIO-SINTETICO','API personal entrega audio verificado');
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref,'range'=>'bytes=0-4']);vcheck($status===206&&$body==='AUDIO','Range conserva reproducción y avance');
}
foreach([['uid'=>9,'ref'=>$refs[0]],['uid'=>8,'ref'=>$refs[0],'ext'=>'151'],['uid'=>8,'ref'=>$refs[0],'method'=>'POST']] as $case){[$status]=$api($case);vcheck(in_array($status,[403,404,405],true),'API no permite otro propietario, extensión o método');}
echo "Audio: envíos y buzones consultables con evidencia propia; métricas, propietario, equipo, permisos y Range conservados.\n";
