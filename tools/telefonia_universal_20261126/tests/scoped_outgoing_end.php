<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaPbxSegmentosService.php';
$count=0;$check=static function(bool $ok,string $why)use(&$count){if(!$ok)throw new RuntimeException($why);++$count;};
$ref='out_'.str_repeat('a',32);$start='2026-11-25 12:00:00';
$event=static fn(int $id,string $type,string $ext,array $extra=[]):array=>array_replace([
 'id'=>$id,'evento'=>$type,'pbx_call_id'=>$ref,'internal'=>$ext,'received_at'=>'2026-11-25 12:01:00','call_start'=>$start,'payload_json'=>'{}'
],$extra);
$base=[$event(1,'NOTIFY_OUT_START','102'),$event(2,'NOTIFY_ANSWER','102'),$event(3,'NOTIFY_OUT_END','',['disposition'=>'answered','duration'=>900,'has_duration'=>true,'call_id_with_rec'=>'17490000000000001234567890','is_recorded'=>'1'])];
$facts=TelefoniaPbxSegmentosService::hechos($base,'102');
$check($facts['finalizada']&&$facts['conectada'],'Exact PBX/start with one participant supports optional outgoing END internal');
$check($facts['segundos']===null,'Anonymous duration is not assigned to the own leg');
$check($facts['segmentos']===[],'Anonymous recording alone does not establish ownership');
$check($facts['end_at']==='2026-11-25 12:01:00'&&$facts['evento_fin_id']===3,'End timestamp belongs to the scoped terminal event');
$changed=$base;$changed[]=$event(4,'NOTIFY_RECORD','100',['call_id_with_rec'=>'17490000000000001234567890']);$facts=TelefoniaPbxSegmentosService::hechos($changed,'102');
$check($facts['finalizada']&&$facts['segmentos']===[],'Foreign recording metadata blocks audio without inventing a new live participant');
$changed=$base;$changed[2]['call_start']='';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'No anonymous terminal inference without provider start identity');
$changed=$base;$changed[2]['call_start']='2026-11-25 11:00:00';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Different attempt start cannot close this call');
$changed=$base;$changed[]=$event(4,'NOTIFY_OUT_START','102');$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Multiple own starts remain ambiguous');
$changed=$base;array_splice($changed,2,0,[$event(4,'NOTIFY_INTERNAL','100')]);$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Another participant prevents anonymous terminal association');
$changed=$base;$changed[1]['payload_json']='{"transfer_from":"100"}';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Transfer edge prevents single-participant inference');
$changed=$base;$changed[2]['payload_json']='{"last_internal":"100"}';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Foreign last participant is never own terminal');
$changed=$base;$changed[2]['evento']='NOTIFY_END';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Unscoped incoming leg end is not a global outgoing end');
$changed=array_slice($base,1);$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Own outgoing start required');
$changed=$base;$changed[2]['internal']='102';$changed[2]['duration']=0;$facts=TelefoniaPbxSegmentosService::hechos($changed,'102');
$check($facts['segundos']===0&&$facts['conectada'],'Explicit own zero remains zero despite elapsed wall time');
$changed[2]['has_duration']=false;$check(TelefoniaPbxSegmentosService::hechos($changed,'102')['segundos']===null,'Missing duration cannot be estimated from ANSWER/END times');
$changed=$base;$changed[2]['internal']='100';$check(!TelefoniaPbxSegmentosService::hechos($changed,'102')['finalizada'],'Foreign named END cannot close own leg');
$changed=$base;$changed[1]['internal']='100';$changed[2]['internal']='102';$changed[2]['disposition']='no answer';$facts=TelefoniaPbxSegmentosService::hechos($changed,'102');
$check($facts['finalizada']&&!$facts['conectada'],'Foreign ANSWER cannot establish own connection');
$check(!TelefoniaPbxSegmentosService::hechos($base,'102','',3)['finalizada'],'Old terminal cannot finalize a new persisted attempt');
$changed=$base;array_splice($changed,2,0,[$event(4,'NOTIFY_INTERNAL','100',['transfer_from'=>'102']),$event(5,'NOTIFY_INTERNAL','102',['transfer_from'=>'100'])]);
$facts=TelefoniaPbxSegmentosService::hechos($changed,'102');$check(!$facts['finalizada']&&!$facts['conectada'],'Returned epoch cannot inherit prior answer or anonymous closing');
echo "PASS $count shared outgoing terminal assertions: exact PBX/start, optional internal, ambiguous/foreign transfer rejection, epoch fence, unknown/zero durations and audio ownership. Pure provider events.\n";
