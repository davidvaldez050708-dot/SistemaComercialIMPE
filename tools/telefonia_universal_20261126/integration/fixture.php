<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Only synthetic loopback');
date_default_timezone_set('America/Mexico_City');
require $lab.'/site/config/db_connection.php';
require $lab.'/site/app/helpers/PermissionHelper.php';
foreach(['TelefoniaResultadoConfirmacion','TelefoniaResultadoVentasService','TelefoniaRecepcionMarketingService','TelefoniaEntrantesAnalistaService'] as $name)require_once $lab.'/site/app/services/'.$name.'.php';
$db=Database::compartida();$checks=0;
$assert=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$seed=static function(string $id,string $extension)use($db):void{
 $s=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,received_at,call_start,caller_id,destination,disposition,duration,payload_json) VALUES(?,?,?,?,?,'5550000101','5550000101',?,?,?)");
 $start=date('Y-m-d H:i:s',time()-90);
 foreach([['NOTIFY_OUT_START',90,'',0],['NOTIFY_ANSWER',80,'',0],['NOTIFY_OUT_END',30,'answered',50]] as [$event,$ago,$disposition,$duration]){
  $at=date('Y-m-d H:i:s',time()-$ago);$payload=json_encode(['duration'=>$duration]);
  $s->bind_param('ssssssis',$id,$event,$extension,$at,$start,$disposition,$duration,$payload);$s->execute();
 }
 $s->close();
};

$make=static function(string $id,array $values,string $request='')use($db):array{
 $revision=(int)floor(microtime(true)*1000);$request=$request?:bin2hex(random_bytes(16));
 return $values+['pbx_call_id'=>$id,'captura_confirmacion'=>json_encode(['ids'=>[$id],'revision'=>$revision,'request_id'=>$request])];
};
