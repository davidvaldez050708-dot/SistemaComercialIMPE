<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$meta=json_decode(file_get_contents($lab.'/lab.json'),true,32,JSON_THROW_ON_ERROR);
if(($meta['synthetic']??false)!==true)throw new RuntimeException('Synthetic lab only');
require $lab.'/site/vendor/autoload.php';require $lab.'/site/app/services/TelefoniaZadarmaApi.php';
$wire=null;$count=0;
$api=new TelefoniaZadarmaApi('synthetic-key','synthetic-secret',false,static function($r)use(&$wire,&$count){$wire=$r;++$count;return ['status'=>200,'headers'=>['X-RateLimit-Remaining'=>'7'],'body'=>str_contains($r['url'],'/pbx/record/')?'{' . '"status":"success","link":"https://fixture.invalid/audio"}':'{"status":"success","stats":[]}'];});
$body=$api->call('/v1/statistics/pbx/',['pbx_call_id'=>'fixture-provider-sdk']);
$sorted=['format'=>'json','pbx_call_id'=>'fixture-provider-sdk'];$query=http_build_query($sorted,'','&',PHP_QUERY_RFC1738);
$expected='synthetic-key:'.$api->encodeSignature('/v1/statistics/pbx/'.$query.md5($query));
if($count!==1||$wire['headers'][0]!=='Authorization: '.$expected||$wire['verify_peer']!==true||$wire['verify_host']!==2||$api->getHttpCode()!==200||$api->getLimits()['Remaining']!==7||json_decode($body,true)['status']!=='success')throw new RuntimeException('Actual SDK compatibility regression');
echo "PASS actual locked Zadarma PHP SDK compatibility: inheritance, native signature, response/limits and required TLS options. Fake transport, no provider connection.\n";
require_once $lab.'/site/app/services/ZadarmaRecordingService.php';
$reflection=new ReflectionClass(ZadarmaRecordingService::class);$recording=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('api')->setValue($recording,$api);
$id='17490000000000001234567890.123456789012345678901234567890';
$result=$recording->obtenerGrabacionPorCallId($id);parse_str((string)parse_url($wire['url'],PHP_URL_QUERY),$query);
if($result['call_id_with_rec']!==$id||($query['call_id']??null)!==$id||isset($query['pbx_call_id'])||$count!==2)
 throw new RuntimeException('Native SDK must preserve exact opaque recording reference without PBX-group fallback');
echo "PASS actual recording getter/native SDK: exact long decimal call_id survives request and response; no group fallback. Fake transport only, no binary audio.\n";
