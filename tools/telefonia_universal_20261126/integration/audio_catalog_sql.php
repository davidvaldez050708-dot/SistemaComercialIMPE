<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Synthetic loopback only');
require_once $lab.'/site/config/db_connection.php';
// Exercise the candidate service without changing the frozen HTTP load fixture.
require_once dirname(__DIR__,3).'/app/services/TelefoniaVentasEvidenciaService.php';
require_once dirname(__DIR__,3).'/app/services/TelefoniaPbxEstadisticasService.php';
$db=Database::compartida();$service=new TelefoniaVentasEvidenciaService($db);$checks=0;
$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$ref='out_fixture_audio_'.bin2hex(random_bytes(8));$id='17490000000000001234567890';$window=null;$recorded=[];
$call=['usuario_id'=>41,'extension'=>'101','pbx_call_id'=>$ref,'fecha'=>'2026-11-25 12:00:00','conectada'=>true,'finalizada'=>true,
 'segundos'=>null,'segmentos'=>[],'segmentos_info'=>[],'audio_bloqueadas'=>[]];
$api=new class($ref,$id){
 public function __construct(private string $ref,private string $id){}
 public function call($path,$params,$type):string{
  if($path==='/v1/info/timezone/')return json_encode(['status'=>'success','timezone'=>'UTC+0','unixtime'=>time(),'datetime'=>gmdate('Y-m-d H:i:s')]);
  $row=['pbx_call_id'=>$this->ref,'call_id'=>$this->id,'sip'=>'101','destination'=>'5550000101','callstart'=>'2026-11-25 18:00:00','seconds'=>40,'disposition'=>'answered','is_recorded'=>true];
  $rows=[];
  if($params['skip']===0){$rows=[$row];for($n=1;$n<1000;$n++)$rows[]=array_replace($row,['pbx_call_id'=>'out_unrelated_'.$n,'call_id'=>'fixture-other-'.$n]);}
  elseif($params['skip']===1000)$rows=[array_replace($row,['pbx_call_id'=>'out_other_tail','call_id'=>'fixture-other-tail'])];
  return json_encode(['status'=>'success','stats'=>$rows]);
 }
};
$catalog=new TelefoniaPbxEstadisticasService($api);
$stats=static function(string $from,string $to)use(&$window,$catalog):array{
 $window=[$from,$to];return $catalog->consultar(TelefoniaPbxEstadisticasService::fechaLocal($from)->getTimestamp(),TelefoniaPbxEstadisticasService::fechaLocal($to)->getTimestamp());
};
$record=static function(string $value)use(&$recorded):array{$recorded[]=$value;return ['link'=>'https://fixture.invalid/audio-sintetico'];};
$result=$service->consultar($call,$stats,$record);
$check($window===['2026-11-24 12:00:00','2026-11-26 12:00:00'],'Both evidence window boundaries stay in Mexico');
$check($recorded===[$id],'Only exact own recording requested after complete 1001-row catalog');
$check($result['segundos']===40&&$result['segmentos']===[$id],'Native evidence retains own duration and opaque recording reference');
$again=$service->consultar($call,$stats,$record);
$check(count($recorded)===1&&$again['segmentos']===[$id],'Persisted evidence and existing cooldown avoid repeat provider/audio requests');
$s=$db->prepare('SELECT COUNT(*) FROM telefonia_ventas_resultados WHERE pbx_call_id=?');$s->bind_param('s',$ref);$s->execute();
$check((int)$s->get_result()->fetch_row()[0]===0,'Technical audio verification cannot create a commercial result');
echo "PASS $checks native audio catalog SQL checks: exact own recording across pages, Mexico bounds, durable evidence/cooldown and no invented commercial result. Synthetic provider/audio only.\n";
