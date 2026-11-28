<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/services/TelefoniaInternaGrabacionService.php';
$session='sdk-audio-internal-'.bin2hex(random_bytes(12));$ref='out_'.bin2hex(random_bytes(16));$id='fixture-internal-audio-'.bin2hex(random_bytes(8));
$q=$db->prepare("INSERT INTO telefonia_analista_webrtc_sesiones(usuario_id,extension,session_id,numero_origen,estado,clasificacion,fecha_inicio,fecha_atendida,fecha_fin) VALUES(42,'102',?,'100','ended','INTERNA',UTC_TIMESTAMP()-INTERVAL 90 SECOND,UTC_TIMESTAMP()-INTERVAL 80 SECOND,UTC_TIMESTAMP()-INTERVAL 30 SECOND)");
$q->bind_param('s',$session);$q->execute();$q->close();
$q=$db->prepare("INSERT INTO telefonia_webrtc_grabaciones_internas(usuario_id,session_id,pbx_call_id,call_id_with_rec,extension_origen,extension_destino) VALUES(42,?,?,?,'100','102')");
$q->bind_param('sss',$session,$ref,$id);$q->execute();$q->close();
$service=new TelefoniaInternaGrabacionService($db);
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
 $assert(($service->obtener(42,$session)['call_id_with_rec']??null)===$id,'Exact ended own internal session authorizes its stored recording under READ ONLY');
 $assert($service->obtener(41,$session)===null,'Other owner cannot borrow internal audio');
 $assert(isset($service->porUsuario(42)[$session]),'History reads original catalog without DDL');
 $assert($service->sincronizar(42,$session)===true,'Already linked internal call returns without DDL or provider');
}finally{$db->rollback();}
$q=$db->prepare("UPDATE telefonia_analista_webrtc_sesiones SET clasificacion='VINCULADA',seguimiento_id=1 WHERE usuario_id=42 AND session_id=?");$q->bind_param('s',$session);$q->execute();$q->close();
$assert($service->obtener(42,$session)===null,'Institution-linked session cannot use internal audio bypass');
$create=static function()use($db):array{
 $session='sdk-audio-proof-'.bin2hex(random_bytes(12));$ref='out_'.bin2hex(random_bytes(16));$audio='fixture-proof-audio-'.bin2hex(random_bytes(8));
 $q=$db->prepare("INSERT INTO telefonia_analista_webrtc_sesiones(usuario_id,extension,session_id,numero_origen,estado,clasificacion,fecha_inicio,fecha_atendida,fecha_fin,protocolo,pbx_call_id) VALUES(42,'102',?,'100','ended','INTERNA',UTC_TIMESTAMP()-INTERVAL 90 SECOND,UTC_TIMESTAMP()-INTERVAL 80 SECOND,UTC_TIMESTAMP()-INTERVAL 30 SECOND,2,?)");
 $q->bind_param('ss',$session,$ref);$q->execute();$q->close();
 $q=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,received_at,disposition,duration,call_id_with_rec,payload_json) VALUES(?,?,?,NOW(),'answered',12,?,'{\"duration\":12}')");$marker=0;
 foreach([['NOTIFY_INTERNAL','100',''],['NOTIFY_INTERNAL','102',''],['NOTIFY_ANSWER','102',''],['NOTIFY_END','102',''],['NOTIFY_RECORD','102',$audio]] as [$type,$ext,$id]){$q->bind_param('ssss',$ref,$type,$ext,$id);$q->execute();if($type==='NOTIFY_INTERNAL'&&$ext==='102')$marker=(int)$db->insert_id;}$q->close();
 $q=$db->prepare('UPDATE telefonia_analista_webrtc_sesiones SET pbx_inicio_evento=? WHERE usuario_id=42 AND session_id=?');$q->bind_param('is',$marker,$session);$q->execute();$q->close();return [$session,$ref,$audio];
};
$provider=new class {public array $ids=[];public $action=null;public function obtenerGrabacionPorCallId(string $id):array{$this->ids[]=$id;if($this->action)($this->action)();return ['link'=>'https://fixture.invalid/audio'];}};
[$session,$ref,$audio]=$create();$exact=new TelefoniaInternaGrabacionService($db,$provider);
$assert($exact->sincronizar(42,$session),'Exact native journal/PBX proof can reconcile with fake recording provider');
$assert($provider->ids===[$audio],'Provider receives only the exact opaque recording reference');
$assert(($exact->obtener(42,$session)['pbx_call_id']??null)===$ref,'Exact native link committed');
$assert($exact->sincronizar(42,$session)&&count($provider->ids)===1,'Retry uses stored native link without second provider query');
[$session,$ref,$audio]=$create();
$provider->action=static function()use($db,$session){$q=$db->prepare("UPDATE telefonia_analista_webrtc_sesiones SET clasificacion='VINCULADA',seguimiento_id=1 WHERE usuario_id=42 AND session_id=?");$q->bind_param('s',$session);$q->execute();$q->close();};
$denied=false;try{$exact->sincronizar(42,$session);}catch(DomainException $e){$denied=true;}
$assert($denied,'Late provider response cannot bind after the native session changed');
$q=$db->prepare('SELECT id FROM telefonia_webrtc_grabaciones_internas WHERE usuario_id=42 AND session_id=?');$q->bind_param('s',$session);$q->execute();$assert($q->get_result()->num_rows===0,'Late rejected link leaves no orphan recording');$q->close();
echo "PASS $checks native internal audio checks with DML-only account and READ ONLY resolution; fake recording provider only; no real provider or runtime DDL.\n";
