<?php
// Se incluye desde la fixture comercial real, antes de eliminar su esquema aislado.
require_once dirname(__DIR__).'/app/services/TelefoniaVentasEvidenciaService.php';
require_once dirname(__DIR__).'/app/services/TelefoniaProspectoLlamadaService.php';
$migration=function(string $mode)use($name,$port):int{
 if(!preg_match('/^impe_ventas_test_[0-9]+$/D',$name))throw new RuntimeException('Sólo fixture aislada.');
 $env=array_merge(getenv(),['IMPE_RUNTIME_ROOT'=>dirname(__DIR__),'IMPE_DB_HOST'=>'127.0.0.1:'.$port,'IMPE_DB_USER'=>getenv('IMPE_TEST_MYSQL_USER')?:'root','IMPE_DB_PASSWORD'=>getenv('IMPE_TEST_MYSQL_PASSWORD')?:'','IMPE_DB_NAME'=>$name,'APP_ENV'=>'testing']);
 $process=proc_open([PHP_BINARY,dirname(__DIR__).'/tools/migrar_ventas_etapa2.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);
 if(!is_resource($process))throw new RuntimeException('No se pudo ejecutar la migración aislada.');
 stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($process);
};
vcheck($migration('--check')!==0,'Check de migración detecta estructura ausente sin crearla');
vcheck(!$db->query("SHOW TABLES LIKE 'telefonia_ventas_evidencia'")->num_rows,'Check intacto');
vcheck($migration('--apply')===0&&$migration('--apply')===0,'Migración CLI explícita idempotente');
vcheck($migration('--check')===0,'Check valida claves y estructuras después de migrar');
$db->query("UPDATE telefonia_extensiones SET usuario_id=8,created_at=DATE_SUB(NOW(),INTERVAL 20 DAY),updated_at=DATE_SUB(NOW(),INTERVAL 20 DAY) WHERE extension='150'");
// Los audios entrantes se autorizaron antes de instalar la caché; ahora probar
// el guardado de su duración con la migración real explícita ya aplicada.
foreach(['pbx-inbound-transfer-one','pbx-inbound-transfer-two'] as $incomingRef){
 $svc->sincronizar(8);$incomingCall=$svc->obtener(8,$incomingRef,'150');
 vcheck($incomingCall['finalizada']&&$incomingCall['segundos']===null,'Cierre global no inventa duración del asesor');
 $incomingStats=static fn()=>[
  ['pbx_call_id'=>$incomingRef,'call_id'=>$incomingRef.'-reception','sip'=>'100','seconds'=>25,'disposition'=>'answered','is_recorded'=>true],
  ['pbx_call_id'=>$incomingRef,'call_id'=>$incomingRef.'-advisor','sip'=>'150','seconds'=>37,'disposition'=>'answered','is_recorded'=>true]
 ];
 $evidenceIncoming=new TelefoniaVentasEvidenciaService($db);
 $confirmedIncoming=$evidenceIncoming->consultar($incomingCall,$incomingStats,static fn($id)=>['link'=>'https://provider.test/'.$id]);
 vcheck($confirmedIncoming['segundos']===37,'POST concilia la duración propia sin copiar la de Recepción');
 vcheck($svc->obtener(8,$incomingRef,'150')['segundos']===37,'Duración confirmada persiste al recargar');
}

$refApi='pbx-api-evidencia-01';$event($refApi,'NOTIFY_INTERNAL');$event($refApi,'NOTIFY_ANSWER');$event($refApi,'NOTIFY_END','100',200,['last_internal'=>'150','disposition'=>'answered']);
$callApi=$svc->obtener(8,$refApi);vcheck($callApi['segundos']===null&&$callApi['segmentos']===[],'Antes de API no inventa duración ni audio');
$evidence=new TelefoniaVentasEvidenciaService($db);$apiCount=0;$downloads=[];
$statsRows=[['pbx_call_id'=>$refApi,'sip'=>150,'call_id'=>'1234567890.123456789','seconds'=>45,'disposition'=>'answered','is_recorded'=>true],['pbx_call_id'=>$refApi,'sip'=>100,'call_id'=>'source-part-100','seconds'=>200,'disposition'=>'answered','is_recorded'=>true]];
$stats=function()use(&$apiCount,&$statsRows){$apiCount++;return $statsRows;};$record=function($id)use(&$downloads){$downloads[]=$id;return ['link'=>'https://provider.test/exact'];};
$confirmed=$evidence->consultar($callApi,$stats,$record);
vcheck($confirmed['segundos']===45&&$confirmed['segmentos']===['1234567890.123456789'],'API por PBX exacto y sip 150 recupera duración propia y decimal sin pérdida');
vcheck($downloads===['1234567890.123456789'],'No consulta audio del participante 100');
$evidence->consultar($callApi,$stats,$record);vcheck($apiCount===1,'Cooldown durable evita consultas repetidas por dos instancias');
$persisted=(new TelefoniaVentasLlamadasService($db))->obtener(8,$refApi,'150');vcheck($persisted['segmentos']===$confirmed['segmentos'],'El vínculo persiste tras recrear la sesión de servicio');
vreject(fn()=>$svc->obtener(9,$refApi),'Otra cuenta no puede recuperar evidencia ajena');
$statsRows[]=$statsRows[0];$statsRows[]=['pbx_call_id'=>'otro-pbx-mismo-numero','sip'=>150,'call_id'=>'audio-ajeno','seconds'=>99,'disposition'=>'answered','is_recorded'=>true];
$parts=TelefoniaPbxSegmentosService::propios($statsRows,$refApi,'150');vcheck(count($parts['tramos'])===1,'Deduplica segmentos y rechaza otro PBX');
$statsRows[]=['pbx_call_id'=>$refApi,'sip'=>100,'call_id'=>'1234567890.123456789','seconds'=>45,'disposition'=>'answered','is_recorded'=>true];
vcheck(TelefoniaPbxSegmentosService::propios($statsRows,$refApi,'150')['tramos']===[],'ID compartido por otra extensión es ambiguo');
$statsRows=[['pbx_call_id'=>$refApi,'sip'=>150,'call_id'=>'part-sales-a','seconds'=>20,'disposition'=>'answered','is_recorded'=>true],['pbx_call_id'=>$refApi,'sip'=>150,'call_id'=>'part-sales-b','seconds'=>25,'disposition'=>'answered','is_recorded'=>true]];
$db->query('UPDATE telefonia_ventas_evidencia SET consultado_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)');
$multi=$evidence->consultar($callApi,$stats,$record);vcheck(count($multi['segmentos'])===2&&$multi['segundos']===null,'Multipartes conserva cada duración sin sumar posibles solapamientos');
$db->query('UPDATE telefonia_ventas_evidencia SET consultado_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)');
$late=$evidence->consultar($callApi,$stats,static fn()=>null);vcheck($late['segmentos']===[]&&count($late['tramos_verificados'])===2,'Audio tardío conserva evidencia de tramos sin inventar enlaces');
$db->query('UPDATE telefonia_ventas_evidencia SET consultado_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)');
vcheck(count($evidence->consultar($callApi,$stats,$record)['segmentos'])===2,'Reconsulta recupera segmentos tardíos sin nuevas llamadas');
$activeRef='pbx-captura-activa';$event($activeRef,'NOTIFY_INTERNAL');$event($activeRef,'NOTIFY_ANSWER');
$capture=new TelefoniaProspectoLlamadaService($db);$values=['nombre'=>'Diana Estrada','telefono'=>'+527771234567','correo'=>'diana@example.test','programa'=>'Bachillerato 286','notas'=>'Solicita información durante la llamada'];
$saved=$capture->guardar(8,$activeRef,'150',$values,0);vcheck($saved['version']===1&&$saved['valores']['nombre']==='Diana Estrada','Alta de datos durante llamada activa');
vcheck(!$svc->obtener(8,$activeRef)['finalizada']&&$svc->obtener(8,$activeRef)['registro']===null,'Guardar notas no finaliza ni crea resultado');
vcheck($capture->guardar(8,$activeRef,'150',$values,0)['version']===1,'Reenvío idéntico no duplica prospecto ni versión');
$values['notas']='Notas actualizadas';vreject(fn()=>$capture->guardar(8,$activeRef,'150',$values,0),'Captura antigua no sobrescribe una edición nueva');
vcheck($capture->guardar(8,$activeRef,'150',$values,1)['version']===2,'Edición con versión vigente');
vreject(fn()=>$capture->leer(9,$activeRef,'150'),'Aislamiento entre asesores');
$bad=$values;$bad['correo']='correo incorrecto';vreject(fn()=>$capture->guardar(8,$activeRef,'150',$bad,2),'Validación backend del correo');
$bad=$values;$bad['telefono']='1';vreject(fn()=>$capture->guardar(8,$activeRef,'150',$bad,2),'Validación backend del teléfono');
vcheck((new TelefoniaProspectoLlamadaService($db))->leer(8,$activeRef,'150')['valores']===$values,'Notas persistentes recuperables tras nueva sesión');
