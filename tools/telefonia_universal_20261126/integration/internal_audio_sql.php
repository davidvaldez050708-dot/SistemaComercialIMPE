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
echo "PASS $checks native internal audio checks with DML-only account and READ ONLY resolution; no provider or runtime DDL.\n";
