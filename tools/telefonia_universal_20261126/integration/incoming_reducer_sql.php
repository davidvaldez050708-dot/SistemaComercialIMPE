<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Fixture inválido');
require $lab.'/site/config/db_connection.php';require $lab.'/site/app/services/ZadarmaWebhookEventStoreService.php';
$db=Database::compartida();$store=new ZadarmaWebhookEventStoreService($db);$normalize=new ReflectionMethod($store,'normalizarFila');$project=new ReflectionMethod($store,'construirEstadoEntrante');
$count=0;$check=static function($ok,$why)use(&$count){if(!$ok)throw new RuntimeException($why);++$count;};
$rows=[];foreach([
 [1,'NOTIFY_INTERNAL','102',[],0],[2,'NOTIFY_ANSWER','100',[],0],[3,'NOTIFY_END','100',[],0],
]as[$id,$type,$ext,$payload,$duration])$rows[]=$normalize->invoke($store,['id'=>$id,'evento'=>$type,'internal'=>$ext,'pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:00','duration'=>$duration,'payload_json'=>json_encode($payload)]);
$check($rows[0]['id']===1&&!$rows[0]['has_duration'],'Normalización conserva ID y ausencia de duración');
$r=$project->invoke($store,'102',$rows[0],$rows);$check($r['estado']==='ringing'&&$r['answer_at']===''&&$r['end_at']==='','ANSWER/END de otra extensión no finaliza atención propia');
$rows[]=$normalize->invoke($store,['id'=>4,'evento'=>'NOTIFY_ANSWER','internal'=>'102','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:01','duration'=>0,'payload_json'=>'{}']);
$rows[]=$normalize->invoke($store,['id'=>5,'evento'=>'NOTIFY_END','internal'=>'102','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:08','duration'=>0,'payload_json'=>'{}']);
$r=$project->invoke($store,'102',$rows[0],$rows);$check($r['estado']==='ended'&&$r['duration']===null&&!$r['duration_known'],'No inventar duración ausente');
$rows[4]=$normalize->invoke($store,['id'=>5,'evento'=>'NOTIFY_END','internal'=>'102','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:08','duration'=>0,'payload_json'=>'{"duration":0}']);
$r=$project->invoke($store,'102',$rows[0],$rows);$check($r['duration']===0&&$r['duration_known'],'Duración explícita cero se conserva');
$rows[]=$normalize->invoke($store,['id'=>6,'evento'=>'NOTIFY_INTERNAL','internal'=>'100','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:09','duration'=>0,'payload_json'=>'{"transfer_from":"102"}']);
$rows[]=$normalize->invoke($store,['id'=>7,'evento'=>'NOTIFY_INTERNAL','internal'=>'102','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:12','duration'=>0,'payload_json'=>'{"transfer_from":"100"}']);
$r=$project->invoke($store,'102',$rows[0],$rows);$check($r['estado']==='ringing'&&$r['answer_at']===''&&$r['end_at']==='','Devolución explícita no hereda respuesta ni cierre del tramo anterior');
$rows[]=$normalize->invoke($store,['id'=>8,'evento'=>'NOTIFY_ANSWER','internal'=>'102','pbx_call_id'=>'fixture-incoming-reducer','received_at'=>'2026-11-27 10:00:14','duration'=>0,'payload_json'=>'{}']);
$r=$project->invoke($store,'102',$rows[0],$rows);$check($r['estado']==='answered'&&$r['answer_at']==='2026-11-27 10:00:14'&&$r['end_at']==='','Tramo devuelto acredita sólo su propia respuesta');
echo "PASS $count proyección entrante con reductor común, IDs, presencia de duración y devolución. Sin proveedor.\n";
