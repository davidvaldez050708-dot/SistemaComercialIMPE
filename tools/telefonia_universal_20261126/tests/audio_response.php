<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaAudioRespuesta.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$body="RIFF\x00\x01\xffsynthetic-wave-bytes";
$full=['body'=>$body,'status'=>200,'content_type'=>'audio/wav','headers'=>[]];
$response=TelefoniaAudioRespuesta::respuesta($full);
$check($response['body']===$body&&$response['status']===200,'Full binary preserved, including NUL and high bytes');
$size=strlen($body);
for($start=0;$start<$size;$start++)for($end=$start;$end<$size;$end++){
 $range="bytes=$start-$end";$r=TelefoniaAudioRespuesta::respuesta($full,$range);
 $expected=substr($body,$start,$end-$start+1);
 $check($r['status']===206&&$r['body']===$expected&&$r['headers']['Content-Range']==="bytes $start-$end/$size"&&$r['headers']['Content-Length']===(string)strlen($expected),'Provider ignored Range: exact fallback bytes and headers');
 $partial=array_replace($full,['status'=>206,'body'=>$expected,'headers'=>['content-range'=>"bytes $start-$end/$size"]]);
 $check(TelefoniaAudioRespuesta::respuesta($partial,$range)===$r,'Provider honored Range: exact same authorized response');
}
foreach(['bytes=-3'=>substr($body,-3),'bytes=3-'=>substr($body,3),'bytes=0-999'=>$body,'bytes=-999999999999999999999999999999'=>$body] as $range=>$expected)
 $check(TelefoniaAudioRespuesta::respuesta($full,$range)['body']===$expected,'Suffix/open/oversize range semantics');
foreach(['bytes=-0','bytes=-','bytes=999-','bytes=7-3','bytes=0-1,3-4',"bytes=0-1\r\nInjected: yes"] as $range)
 $check(TelefoniaAudioRespuesta::respuesta($full,$range)['status']===416,'Invalid or unsatisfiable range rejected');
$throws=static function(array $audio,string $range='')use($check){$rejected=false;try{TelefoniaAudioRespuesta::respuesta($audio,$range);}catch(RuntimeException $e){$rejected=true;}$check($rejected,'Wrong, incomplete or failed provider response rejected');};
foreach([404,429,500,502] as $status)$throws(array_replace($full,['status'=>$status]));
foreach(['text/html','application/json',"audio/mpeg\r\nInjected: yes",''] as $mime)$throws(array_replace($full,['content_type'=>$mime]));
$throws(array_replace($full,['body'=>'']));
$partial=array_replace($full,['status'=>206,'body'=>substr($body,0,4),'headers'=>['content-range'=>"bytes 0-3/$size"]]);
$throws($partial);$throws($partial,'bytes=4-7');$throws($partial,'bytes=0-2');
$throws(array_replace($partial,['body'=>'abc']),'bytes=0-3');
$throws(array_replace($partial,['headers'=>['content-range'=>'bytes 0-3/3']]),'bytes=0-3');
$throws(array_replace($partial,['headers'=>['content-range'=>'bytes 0-3/99999999999999999999999999999']]),'bytes=0-3');
foreach(['audio/wav'=>'wav','audio/ogg'=>'ogg','audio/mp4'=>'m4a','audio/mpeg'=>'mp3'] as $mime=>$extension)
 $check(TelefoniaAudioRespuesta::extension($mime)===$extension,'Download extension retained');
echo "PASS $checks shared binary audio/Range assertions, synthetic bytes and fake provider responses. No real provider or browser playback.\n";
