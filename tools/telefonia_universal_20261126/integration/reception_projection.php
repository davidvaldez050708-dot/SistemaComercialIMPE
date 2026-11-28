<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Synthetic fixture only');
require_once $lab.'/site/app/services/TelefoniaRecepcionMarketingService.php';
$count=0;$check=static function(bool $ok,string $why)use(&$count){if(!$ok)throw new RuntimeException($why);++$count;};
$e=static function(int $id,string $type,string $ext,array $extra=[]):array{
 return array_replace(['id'=>$id,'evento'=>$type,'internal'=>$ext,'pbx_call_id'=>'out_fixture_reception_projection',
  'caller_id'=>'5550000101','destination'=>'5550000101','call_start'=>'2026-11-25 12:00:00',
  'received_at'=>'2026-11-25 12:01:00','duration'=>0,'payload_json'=>json_encode($extra)],$extra);
};
$project=static fn(array $events):array=>TelefoniaRecepcionMarketingService::agruparEventos($events,'100',true)[0];
$start=$e(1,'NOTIFY_INTERNAL','100');$answer=$e(2,'NOTIFY_ANSWER','100');$end=$e(3,'NOTIFY_END','100',['duration'=>37,'disposition'=>'answered']);
$call=$project([$start,$answer,$end]);$check($call['estado']==='Atendida'&&$call['segundos']===37,'Own incoming answer and known duration');
$check(TelefoniaRecepcionMarketingService::requiereResultado($call),'Own conversation still requires its commercial result');
$foreign=$project([$start,$e(2,'NOTIFY_ANSWER','101'),$e(3,'NOTIFY_END','101',['duration'=>900,'disposition'=>'answered','is_recorded'=>'1','call_id_with_rec'=>'17490000000000001234567890'])]);
$check($foreign['estado']==='Sonando'&&!$foreign['finalizada']&&!$foreign['atendida'],'Foreign answer/end does not finalize or answer Reception');
$check(!$foreign['tiene_grabacion'],'Foreign recording is not advertised as Reception audio');
$call=$project([$start,$e(2,'NOTIFY_ANSWER','',['destination'=>'external-number-ending-100'])]);
$check(!$call['atendida'],'Destination suffix cannot identify an anonymous own answer');
$transfer=$e(3,'NOTIFY_INTERNAL','101',['transfer_from'=>'100']);$return=$e(4,'NOTIFY_INTERNAL','100',['transfer_from'=>'101']);
$call=$project([$start,$answer,$transfer,$return,$e(6,'NOTIFY_END','100',['duration'=>143])]);
$check($call['estado']==='Perdida'&&!$call['atendida'],'Returned leg does not inherit the previous answer');
$check($call['segundos']===null,'Returned parent duration cannot become the own current-leg duration');
$call=$project([$start,$answer,$transfer,$return,$e(5,'NOTIFY_ANSWER','100'),$e(6,'NOTIFY_END','100',['duration'=>143])]);
$check($call['estado']==='Atendida'&&TelefoniaRecepcionMarketingService::requiereResultado($call),'Returned own answer restores native commercial capture');
$call=$project([$start,$answer,$transfer,$e(5,'NOTIFY_END','100',['duration'=>143,'disposition'=>'answered','last_internal'=>'101'])]);
$check($call['estado']==='Transferida'&&$call['destino_transferencia']==='101','Explicit transfer destination preserved');
$check($call['segundos']===null&&$call['fecha_fin']==='','Parent duration/end are not assigned to source own conversation');
$check(!TelefoniaRecepcionMarketingService::requiereResultado($call),'Existing automatic transfer closure remains');
$out=$e(1,'NOTIFY_OUT_START','100');$call=$project([$out,$answer,$e(3,'NOTIFY_OUT_END','100',['duration'=>80,'disposition'=>'answered','last_internal'=>'101'])]);
$check($call['estado']==='Transferida'&&$call['destino_transferencia']==='101'&&$call['segundos']===null,'Signed last participant preserves outgoing handoff without parent duration');
$call=$project([$out,$answer,$e(3,'NOTIFY_OUT_END','100',['duration'=>0,'disposition'=>'answered'])]);
$check($call['estado']==='Completada'&&$call['segundos']===0&&$call['duracion_conocida'],'Own explicit zero remains known zero');
$call=$project([$out,$answer,$e(3,'NOTIFY_OUT_END','100',['disposition'=>'answered'])]);
$check($call['segundos']===null&&!$call['duracion_conocida'],'SQL default zero with absent payload duration remains unknown');
$call=$project([$out,$answer,$e(3,'NOTIFY_OUT_END','',['duration'=>900,'disposition'=>'answered'])]);
$check($call['estado']==='Completada'&&$call['segundos']===null,'Common scoped anonymous outgoing end used by Reception too');
foreach([-1,999999,'invalid'] as $value){$call=$project([$out,$answer,$e(3,'NOTIFY_OUT_END','100',['duration'=>$value,'disposition'=>'answered'])]);$check($call['segundos']===null,'Malformed provider duration cannot become zero or positive evidence');}
echo "PASS $count actual Reception projection checks: shared PBX facts, incoming/outgoing/transfer/return, no phone suffix association, parent/unknown/zero duration, scoped audio and original commercial requirements. No SQL or provider calls.\n";
