<?php
require_once dirname(__DIR__).'/app/services/SupervisionLlamadasAudioService.php';
require_once dirname(__DIR__).'/app/services/ZadarmaRecordingService.php';
$audioCases=[
 [['WHATSAPP'],true],[['SMS'],true],[['WHATSAPP','SMS'],true],[['BUZON_VOZ'],false],
 [['PROSPECTO_POTENCIAL','WHATSAPP'],true],[['SIN_RESPUESTA','WHATSAPP'],false],
 [['SIN_RESPUESTA'],false],[['BUZON_VOZ','WHATSAPP'],false],[[],false]
];$refs=[];
foreach($audioCases as $i=>[$selection,$allowed]){
 $ref='out_audio_resultado_'.$i;$refs[]=$ref;$event($ref,'NOTIFY_OUT_START');$event($ref,'NOTIFY_ANSWER');$event($ref,'NOTIFY_OUT_END','150',33,['disposition'=>'answered']);
 $event($ref,'NOTIFY_RECORD','150',0,['call_id_with_rec'=>'audio-verificado-'.$i]);
 if($selection)$results->guardar(8,$ref,'',$v3($selection));
}
$snap=[];foreach(['telefonia_ventas_llamadas','telefonia_ventas_resultados','telefonia_ventas_resultado_detalles','telefonia_ventas_resultado_selecciones','telefonia_zadarma_eventos'] as $table)$snap[$table]=$db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
$provider=new ZadarmaRecordingService(false);$audioSvc=new SupervisionLlamadasAudioService($db);$audioRepo=new SupervisionLlamadasRepository($db);
foreach($refs as $i=>$ref){
 $call=$svc->obtener(8,$ref,'150');$key='venta:'.$call['id'];
 $allowed=$audioCases[$i][1];
 vcheck(TelefoniaVentasLlamadasService::audioConsultable($call)===$allowed&&TelefoniaVentasLlamadasService::presentacionAudio($call)['disponible']===$allowed,'Resultado obligatorio y sin contacto prevalece sobre envío '.$i);
 foreach([1,2,20] as $supervisor){
  vcheck($audioRepo->obtener($supervisor,$key,true)['audio_consultable']===$allowed,'Tarjeta de supervisión respeta el resultado '.$i);
  if($allowed){$out=$audioSvc->resolver($supervisor,$key,$provider,true);vcheck($out['disponible']&&$out['segmentos']===1,'Proveedor verifica el archivo en el alcance correcto '.$i);}
  else{$before=count(ZadarmaRecordingService::$consultados);vreject(fn()=>$audioSvc->resolver($supervisor,$key,$provider,true),'Servidor bloquea resultado negativo o pendiente');vcheck(count(ZadarmaRecordingService::$consultados)===$before,'Bloqueo antes de consultar al proveedor');}
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
vcheck($xpath->query('//*[@data-sales-audio]')->length===4,'Historial sólo ofrece audio para resultados admitidos');
foreach($refs as $i=>$ref)vcheck($xpath->query("//*[@data-sales-audio='$ref']")->length===($audioCases[$i][1]?1:0),'Cada tarjeta del asesor respeta su resultado '.$i);
vcheck(str_contains($htmlAudio,'Pendiente de resultado'),'No anuncia ausencia de audio cuando falta registrar resultado');
// Misma política en la vista real de Supervisión, con su diseño actual.
$scope='global';$puedeAudio=true;$f=['area'=>'asesores','persona_id'=>8,'desde'=>substr($call['fecha'],0,10),'hasta'=>substr($call['fecha'],0,10),'direccion'=>'','resultado'=>'','buscar'=>'','pagina'=>1];
$data=(new SupervisionLlamadasRepository($db))->listado(1,$f);
ob_start();require dirname(__DIR__).'/app/views/telefonia/supervision_llamadas.php';$htmlSupervision=ob_get_clean();
$dom->loadHTML('<?xml encoding="UTF-8">'.$htmlSupervision,LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($dom);
foreach($refs as $i=>$ref){$id=$svc->obtener(8,$ref,'150')['id'];vcheck($xpath->query("//*[@data-call-key='venta:$id']//*[@data-supervision-audio]")->length===($audioCases[$i][1]?1:0),'Cada tarjeta de Supervisión respeta su resultado '.$i);}
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
foreach([$refs[0]] as $ref){
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref,'verify'=>true]);$json=json_decode($body,true,512,JSON_THROW_ON_ERROR);vcheck($status===200&&$json['disponible']&&$json['segmentos']===1,'API personal verifica audio de envío o buzón');
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref]);vcheck($status===200&&$body==='AUDIO-SINTETICO','API personal entrega audio verificado');
 [$status,$body]=$api(['uid'=>8,'ref'=>$ref,'range'=>'bytes=0-4']);vcheck($status===206&&$body==='AUDIO','Range conserva reproducción y avance');
}
foreach([3,5,6,7,8] as $i)foreach([true,false] as $verify){[$status]=$api(['uid'=>8,'ref'=>$refs[$i],'verify'=>$verify]);vcheck($status===404,'API bloquea buzón/no contestó con o sin WhatsApp y sin resultado, aun con audio y respuesta PBX');}
foreach([['uid'=>9,'ref'=>$refs[0]],['uid'=>8,'ref'=>$refs[0],'ext'=>'151'],['uid'=>8,'ref'=>$refs[0],'method'=>'POST']] as $case){[$status]=$api($case);vcheck(in_array($status,[403,404,405],true),'API no permite otro propietario, extensión o método');}
$negative=$svc->obtener(8,$refs[0],'150');$negative['registro']['resultados_seleccionados']=['WHATSAPP','SIN_RESPUESTA'];
vcheck(!TelefoniaVentasLlamadasService::audioConsultable($negative),'Un resultado negativo prevalece aunque WhatsApp figure primero');
$results->guardar(8,$refs[8],'',$v3(['WHATSAPP']));vcheck(TelefoniaVentasLlamadasService::audioConsultable($svc->obtener(8,$refs[8],'150')),'Audio se habilita sólo después de guardar un resultado admitido');
echo "Audio: resultado obligatorio, bloqueo de no contestó/buzón incluso con WhatsApp, servidor y tarjetas coherentes; propietario, equipo, métricas y Range conservados.\n";
