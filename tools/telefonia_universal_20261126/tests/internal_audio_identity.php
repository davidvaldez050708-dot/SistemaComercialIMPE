<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaInternaGrabacionService.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$ref='fixture-internal-exact-pbx';$id='17490000000000001234567890';
$s=['protocolo'=>2,'numero_origen'=>'100','extension'=>'102','pbx_inicio_evento'=>2,'pbx_call_id'=>$ref];
$e=static fn($n,$type,$ext,$audio='')=>['id'=>$n,'evento'=>$type,'internal'=>$ext,'pbx_call_id'=>$ref,'call_id_with_rec'=>$audio,'disposition'=>'answered','duration'=>12,'payload_json'=>'{"duration":12}'];
$events=[$e(1,'NOTIFY_INTERNAL','100'),$e(2,'NOTIFY_INTERNAL','102'),$e(3,'NOTIFY_ANSWER','102'),$e(4,'NOTIFY_END','102'),$e(5,'NOTIFY_RECORD','102',$id)];
$check(TelefoniaInternaGrabacionService::referenciaVerificada($s,$events)===$id,'Exact server journal PBX/start, both participants, answer/end and unique own recording');
$reject=static function(array $session,array $rows)use($check){$denied=false;try{TelefoniaInternaGrabacionService::referenciaVerificada($session,$rows);}catch(DomainException $e){$denied=true;}$check($denied,'Unproven or ambiguous internal recording rejected');};
$reject(array_replace($s,['protocolo'=>1]),$events);
$reject(array_replace($s,['pbx_call_id'=>'fixture-another-group']),$events);
$reject(array_replace($s,['pbx_inicio_evento'=>99]),$events);
$reject($s,array_values(array_filter($events,static fn($e)=>$e['id']!==1)));
$reject($s,array_values(array_filter($events,static fn($e)=>$e['id']!==4)));
$reject($s,array_merge($events,[$e(6,'NOTIFY_INTERNAL','101')]));
$reject($s,array_merge($events,[$e(6,'NOTIFY_RECORD','102','fixture-another-record')]));
$reject($s,array_replace($events,[4=>$e(5,'NOTIFY_RECORD','101',$id)]));
echo "PASS $checks actual internal audio identity checks; no phone/time matching, SQL/provider or playback.\n";
