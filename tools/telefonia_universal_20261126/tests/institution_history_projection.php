<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaHistorialInteraccionPbxService.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$e=static fn($id,$type,$ext,$p=[])=>['id'=>$id,'evento'=>$type,'internal'=>$ext,'duration'=>$p['duration']??0,'disposition'=>$p['disposition']??'','call_id_with_rec'=>$p['call_id_with_rec']??'','payload_json'=>json_encode($p),'received_at'=>'2026-11-25 12:00:00'];
$start=$e(1,'NOTIFY_OUT_START','102');$answer=$e(2,'NOTIFY_ANSWER','102');$binding=[['extension'=>'102','pbx_inicio_evento'=>1]];
$project=static fn(array $events)=>TelefoniaHistorialInteraccionPbxService::proyectar($events,$binding);
$own=$project([$start,$answer,$e(3,'NOTIFY_OUT_END','102',['duration'=>37,'disposition'=>'answered'])]);
$check($own['segundos']===37,'Native institution projection uses common own duration');
$check($project([$start,$answer,$e(3,'NOTIFY_OUT_END','101',['duration'=>900,'disposition'=>'answered'])])['segundos']===null,'Foreign END cannot complete institution duration');
$check($project([$start,$answer,$e(3,'NOTIFY_OUT_END','102',['disposition'=>'answered'])])['segundos']===null,'Absent duration cannot become SQL default zero');
$check($project([$start,$answer,$e(3,'NOTIFY_OUT_END','102',['duration'=>0,'disposition'=>'answered'])])['segundos']===0,'Explicit own zero preserved');
$transfer=$e(3,'NOTIFY_INTERNAL','101',['transfer_from'=>'102']);
$check($project([$start,$answer,$transfer,$e(4,'NOTIFY_OUT_END','102',['duration'=>900,'disposition'=>'answered','last_internal'=>'101'])])['segundos']===null,'Parent transfer duration never attributed to Analyst');
$audio='17490000000000001234567890';
$check($project([$start,$answer,$e(3,'NOTIFY_RECORD','101',['call_id_with_rec'=>$audio])])['ids']===[],'Foreign recording cannot set own ready state');
$check($project([$start,$answer,$e(3,'NOTIFY_RECORD','102',['call_id_with_rec'=>$audio])])['ids']===[$audio],'Own stable reference drives ready state');
echo "PASS $checks actual institutional history projection checks; shared PBX reducer, no provider/SQL.\n";
