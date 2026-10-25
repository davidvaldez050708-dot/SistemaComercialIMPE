<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaGrabacionesTransferenciaVentasService.php';
function ok(bool $actual,string $what): void {if(!$actual)throw new RuntimeException('FALLO: '.$what);}
function denied(callable $fn,string $what): void {
    try{$fn();}catch(DomainException $e){return;}
    throw new RuntimeException('Se autorizó indebidamente: '.$what);
}
$pbx='out_104f634c799a7fd246dfe5bad6e572b0fe32415e';
$e=static fn(string $type,string $internal='',string $id=''): array =>
    ['evento'=>$type,'internal'=>$internal,'call_id_with_rec'=>$id];
$events=[
    $e('NOTIFY_OUT_START','100'),
    $e('NOTIFY_ANSWER','100'),
    $e('NOTIFY_OUT_END','100'), // Anterior a ANSWER 150: NO finaliza asesor.
    $e('NOTIFY_RECORD','','1439981389.2702773'),
    $e('NOTIFY_ANSWER','150'),
    $e('NOTIFY_RECORD','','1439981389.2702884')
];
$refs=TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($events,'100','150');
ok(count($refs)===2,'dos tramos del mismo PBX 100 -> 150');
ok(array_keys($refs)===['1439981389.2702773','1439981389.2702884'],'cada call_id opaco conserva su precisión y no se pisa');
ok(!array_filter($refs,static fn($x)=>!empty($x['extension'])),'el webhook RECORD no identifica a qué extensión corresponde');
$events[]=$e('NOTIFY_RECORD','','1439981389.2702773');
ok(count(TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($events,'100','150'))===2,'repetición idempotente de RECORD');
$third=$events;$third[]=$e('NOTIFY_ANSWER','151');
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($third,'100','150'),'tercero en la conferencia');
$last=$events;$last[]=$e('NOTIFY_END','100')+['last_internal'=>'151'];
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($last,'100','150'),'last_internal tercer participante');
$recordThird=$events;$recordThird[]=$e('NOTIFY_RECORD','151','record-third-151');
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($recordThird,'100','150'),'RECORD de tercer participante');
$missing=$events;foreach($missing as $k=>$v)if($v['evento']==='NOTIFY_ANSWER'&&$v['internal']==='150')unset($missing[$k]);
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($missing,'100','150'),'sin respuesta PBX de asesor');
$source=$events;foreach($source as $k=>$v)if($v['evento']==='NOTIFY_OUT_START')unset($source[$k]);
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($source,'100','150'),'sin inicio propio de Recepción');
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($events,'150','150'),'mismo origen y destino');
$foreign=$events;$foreign[]=$e('NOTIFY_RECORD','','evil?url');
ok(count(TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($foreign,'100','150'))===2,'ID manipulado descartado');
$full=$events;for($i=3;$i<=9;$i++)$full[]=$e('NOTIFY_RECORD','','1439981389.2702'.str_pad((string)$i,3,'0',STR_PAD_LEFT));
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($full,'100','150'),'más de ocho tramos');
// La llamada externa entra a Recepción antes de transferirse a Ventas.
$incoming=[
 $e('NOTIFY_INTERNAL','100'),$e('NOTIFY_ANSWER','100'),
 $e('NOTIFY_INTERNAL','150')+['payload_json'=>'{"transfer_from":"100"}'],
 $e('NOTIFY_ANSWER','150'),$e('NOTIFY_END','100')+['last_internal'=>'150'],
 $e('NOTIFY_RECORD','','incoming-record-100'),$e('NOTIFY_RECORD','','incoming-record-150')
];
ok(count(TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($incoming,'100','150'))===2,'entrante transferida conserva ambos audios');
ok(count(TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas(array_reverse($incoming),'100','150'))===2,'avisos tardíos no cambian la propiedad del grupo');
$noAnswer=$incoming;unset($noAnswer[1]);
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($noAnswer,'100','150'),'timbre de Recepción sin atención no acredita transferencia');
$noDest=$incoming;unset($noDest[3]);
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($noDest,'100','150'),'entrante sin respuesta del asesor');
$thirdIn=$incoming;$thirdIn[]=$e('NOTIFY_ANSWER','151');
denied(static fn()=>TelefoniaGrabacionesTransferenciaVentasService::referenciasVerificadas($thirdIn,'100','150'),'tercer participante en entrante');
$durations=[['extension'=>'100','segundos'=>44],['extension'=>'150','segundos'=>18]];
ok(TelefoniaGrabacionesTransferenciaVentasService::duracionPropia($durations,'150')===18,'duración propia sin copiar los 44 segundos de Recepción');
ok(TelefoniaGrabacionesTransferenciaVentasService::duracionPropia([$durations[0]],'150')===null,'sin duración propia no hereda la del origen');
ok(TelefoniaGrabacionesTransferenciaVentasService::duracionPropia(array_merge($durations,[$durations[1]]),'150')===null,'no sumar tramos potencialmente solapados');
ok(TelefoniaGrabacionesTransferenciaVentasService::duracionPropia([['extension'=>'150','segundos'=>null]],'150')===null,'duración desconocida no es cero');
echo "OK: grabaciones entrantes y salientes, eventos tardíos, terceros rechazados y duración propia sin suposiciones\n";
