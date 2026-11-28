<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Synthetic loopback only');
require_once $lab.'/site/config/db_connection.php';
require_once $lab.'/site/app/services/SupervisionLlamadasAudioService.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$db=Database::compartida();new SupervisionLlamadasAudioService($db);
$check(class_exists(SupervisionVentasAudioCdrPolicy::class),'Installed audio service and policy load with existing native dependencies');
foreach([41,42,43] as $uid){$denied=false;try{(new SupervisionLlamadasRepository($db))->acceso($uid,true);}catch(DomainException $e){$denied=true;}$check($denied,'Personal telephone role cannot grant itself supervisor audio');}
$call=['source'=>'venta','area'=>'asesores','direccion'=>'Saliente','finalizada'=>false,'conectada'=>true,'resultado_guardado'=>true,
 'usuario_id'=>41,'pbx_call_id'=>'out_fixture_supervisor_audio','extension'=>'101','origen_extension'=>'','solicitud_id'=>null,'resultado'=>'CONVERSACION_PERSONA'];
$check(SupervisionVentasAudioCdrPolicy::candidata($call),'Existing own conversation policy preserved');
foreach([['resultado'=>'BUZON_VOZ'],['resultado_guardado'=>false],['conectada'=>false],['finalizada'=>true],['origen_extension'=>'100'],['solicitud_id'=>1],['area'=>'analistas']] as $patch)
 $check(!SupervisionVentasAudioCdrPolicy::candidata(array_replace($call,$patch)),'Pending/no-contact/foreign transfer is not a CDR shortcut');
$audio=['body'=>'0123456789','content_type'=>'audio/mpeg','status'=>200,'headers'=>[]];
$r=SupervisionLlamadasAudioService::respuesta($audio);$check($r['status']===200&&$r['body']==='0123456789','Original full audio response');
foreach(['bytes=0-3'=>'0123','bytes=-3'=>'789','bytes=5-'=>'56789'] as $range=>$expected){$r=SupervisionLlamadasAudioService::respuesta($audio,$range);$check($r['status']===206&&$r['body']===$expected,'Original local Range fallback');}
$check(SupervisionLlamadasAudioService::respuesta($audio,'bytes=100-200')['status']===416,'Original unsatisfiable Range rejection');
$check(SupervisionLlamadasAudioService::respuesta($audio,'bytes=invalid')['status']===416,'Original malformed Range rejection');
$rejected=false;try{SupervisionLlamadasAudioService::respuesta(array_replace($audio,['content_type'=>'text/html']));}catch(RuntimeException $e){$rejected=true;}
$check($rejected,'Original stream refuses HTML');
echo "PASS $checks restored installed supervision dependency checks: load, personal-role denial, unchanged CDR policy and existing basic audio/Range behavior. Synthetic SQL/bytes only; no provider or complete audio acceptance.\n";
