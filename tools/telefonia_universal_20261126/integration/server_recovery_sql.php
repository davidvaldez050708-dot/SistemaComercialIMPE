<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Synthetic loopback only');
require_once $lab.'/site/config/db_connection.php';
require_once $lab.'/site/app/services/TelefoniaVerificacionEvidenciaService.php';
$db=Database::compartida();$service=new TelefoniaVerificacionEvidenciaService($db);$count=0;
$check=static function(bool $ok,string $why)use(&$count){if(!$ok)throw new RuntimeException($why);++$count;};
$rows=$db->query("SELECT i.id,i.seguimiento_id,i.id_externo,b.datos_json FROM interacciones_vinculacion i
 JOIN telefonia_borradores b ON b.usuario_id=i.usuario_id AND b.referencia=BINARY i.id_externo
 WHERE i.usuario_id=42 AND i.proveedor_externo='ZADARMA' AND EXISTS(SELECT 1 FROM telefonia_verificacion_evidencias e WHERE e.interaccion_id=i.id)
 ORDER BY i.id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$target=null;
foreach($rows as $row){$saved=json_decode($row['datos_json'],true);foreach((array)($saved['aplicaciones']??[]) as $app)
 if((int)($app['id']??0)===(int)$row['id']&&in_array($app['adaptador']??'',['SEGUIMIENTO','INFORMATIVA'],true)){$target=$row;break 2;}}
if(!$target)throw new RuntimeException('First run followup_receipt.cjs --server-recovery in the same synthetic lab');
$id=(int)$target['id'];$sid=(int)$target['seguimiento_id'];$ref=$target['id_externo'];$original=$target['datos_json'];
$find=static function(int $uid=42,int $after=0)use($service,$id):?array{foreach($service->conciliacionesSalientes($uid,$after)['conciliaciones'] as $job)if($job['interactionId']===$id)return $job;return null;};
$write=static function(string $data)use($db,$ref){$s=$db->prepare('UPDATE telefonia_borradores SET datos_json=? WHERE usuario_id=42 AND referencia=BINARY ?');$s->bind_param('ss',$data,$ref);$s->execute();$s->close();};
$db->begin_transaction();
try{
 $db->query("UPDATE telefonia_verificacion_evidencias SET estado='PENDIENTE' WHERE interaccion_id=$id AND usuario_id=42 AND campo='telefono'");
 $job=$find(42,$id-1);$check($job!==null,'A single pending field is recoverable with modern native receipt');
 $check($job['metadata']['pbx_call_id']===$ref&&$job['metadata']['seguimiento_id']===$sid,'Exact native IDs and PBX, no phone association');
 $check($find(41,$id-1)===null,'Another authenticated actor cannot recover this native result');
 $check($find(42,$id)===null,'Cursor advances past already visited native ID');
 $saved=json_decode($original,true);unset($saved['aplicaciones']);$write(json_encode($saved));
 $check($find(42,$id-1)===null,'Legacy/manual row without receipt never becomes an automatic reconciliation');
 $saved=json_decode($original,true);foreach($saved['aplicaciones'] as &$app)if((int)$app['id']===$id)$app['id']=$id+1;unset($app);$write(json_encode($saved));
 $check($find(42,$id-1)===null,'Receipt for another native interaction is rejected');
 $write($original);$check($find(42,$id-1)!==null,'Closed receipt retains recovery proof');
 $db->query("UPDATE seguimientos_vinculacion SET analista_id=41 WHERE id=$sid AND analista_id=42");
 $check($find(42,$id-1)===null,'Reassigned institution is excluded by current scope');
}finally{$db->rollback();}
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{$service->conciliacionesSalientes(42);$check(true,'Complete recovery listing succeeds in READ ONLY transaction');}finally{$db->rollback();}
echo "PASS $count native server recovery SQL checks: real receipts, current institution/actor, partial confirmation, cursor, missing/wrong receipt rejection and READ ONLY. Test mutations rolled back in synthetic DB.\n";
