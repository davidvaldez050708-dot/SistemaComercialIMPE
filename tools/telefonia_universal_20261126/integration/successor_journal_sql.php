<?php
declare(strict_types=1);
require __DIR__.'/transfer_journal_link_sql.php';
$dialog=['call_id'=>'fixture-replaced-call','local_tag'=>'local-tag','remote_tag'=>'remote-tag'];
$service->registrarEvento(42,$base+['evento'=>'ended','secuencia'=>5,'sdk_dialogo'=>json_encode($dialog)],2);
$child='sdk-'.bin2hex(random_bytes(16));
$childBase=['session_id'=>$child,'direccion'=>'ENTRANTE','numero'=>'5550000000','sdk_id'=>'fixture-successor-sip',
 'sdk_dialogo'=>json_encode(['call_id'=>'fixture-next-call','local_tag'=>'next-local','remote_tag'=>'next-remote'])];
foreach(['ringing','answered','ended'] as $n=>$kind)$service->registrarEvento(42,$childBase+['evento'=>$kind,'secuencia'=>$n+1],2);
$link=$childBase+['evento'=>'successor_link','secuencia'=>4,'operacion_id'=>$op,'intento'=>1,
 'predecesora_session_id'=>$id,'replaces_dialogo'=>json_encode($dialog)];
$rejected(fn()=>$service->registrarEvento(42,array_replace($link,['replaces_dialogo'=>json_encode(array_replace($dialog,['remote_tag'=>'another-dialog']))]),2),'SIP call-id alone without exact tags cannot identify successor');
$rejected(fn()=>$service->registrarEvento(42,array_replace($link,['intento'=>2]),2),'Successor cannot cross transfer attempt');
$rejected(fn()=>$service->registrarEvento(41,$link,2),'Successor cannot cross user');
$before=$service->obtener(42,$child);$parentBefore=$service->obtener(42,$id);
$service->registrarEvento(42,$link,2);$linked=$service->obtener(42,$child);
$assert($linked['pbx_call_id']===$ref&&$linked['predecesora_session_id']===$id,'Exact Replaces edge binds successor to reserved delivered operation');
$assert($linked['fecha_fin']===$before['fecha_fin']&&$linked['clasificacion']===$before['clasificacion'],'Late successor binding never reopens transport or rewrites business history');
$assert($service->obtener(42,$id)===$parentBefore,'Successor never mutates preceding dialogue');
$service->registrarEvento(42,$link,2);$assert((int)$service->obtener(42,$child)['ultima_secuencia']===4,'Successor ACK replay is idempotent');
// A server-side reconciliation can finish the exact persisted reservation
// after SDK END, without another browser sequence or an active UI token.
$late='sdk-'.bin2hex(random_bytes(16));$lateRef='fixture-late-operation-'.bin2hex(random_bytes(7));
$lateBase=['session_id'=>$late,'direccion'=>'ENTRANTE','numero'=>'100','sdk_id'=>'fixture-late-operation-sip'];
foreach(['ringing','answered','ended'] as $n=>$kind)$service->registrarEvento(42,$lateBase+['evento'=>$kind,'secuencia'=>$n+1],2);
$before=$service->obtener(42,$late);$marker=(int)$db->query('SELECT COALESCE(MAX(id),0) n FROM telefonia_zadarma_eventos')->fetch_assoc()['n'];
$q=$db->prepare("INSERT INTO telefonia_transferencia_operaciones(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,modo,estado,evento_desde,sdk_consulta,protocolo,created_at,updated_at) VALUES(43,'100',42,'102',?,'5550000000','DIRECTA','ENTREGADA',?,?,2,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$q->bind_param('sis',$lateRef,$marker,$late);$q->execute();$lateOp=(int)$db->insert_id;$q->close();
$seed($lateRef,'102');
require_once $lab.'/site/app/services/TelefoniaTransferenciaOperacionService.php';
(new TelefoniaTransferenciaOperacionService($db))->obtener(42,$lateOp,1);
$recovered=$service->obtener(42,$late);
$assert($recovered['pbx_call_id']===$lateRef,'Late persisted delivered reservation recovers after local END without an active browser');
$assert($recovered['fecha_fin']===$before['fecha_fin']&&$recovered['ultima_secuencia']===$before['ultima_secuencia']&&$recovered['clasificacion']===$before['clasificacion'],'Derived recovery preserves SDK sequence, local closure and human classification');
echo "PASS $checks accumulated exact transfer/successor SQL assertions: complete SIP identity, owner, attempt, late evidence and immutable history. Synthetic only.\n";
