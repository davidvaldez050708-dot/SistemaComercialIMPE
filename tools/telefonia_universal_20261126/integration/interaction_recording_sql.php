<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/services/TelefoniaGrabacionInteraccionService.php';
$_SESSION=['usuario_id'=>42,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes','seguimientos_vinculacion.operar_propios']];
$ref='out_'.bin2hex(random_bytes(16));$seed($ref,'102');
$result=(new TelefoniaEntrantesAnalistaService($db))->registrar(42,['pbx_call_id'=>$ref,'decision'=>'nueva','nombre_entidad'=>'Audio sintético '.bin2hex(random_bytes(6)),'tipo_entidad'=>'INSTITUCION','estado_id'=>'1','resultado'=>'CONTACTO_CORRECTO','telefono_capturado'=>'5550000101','correo_capturado'=>'fixture@example.invalid','persona_atendio'=>'Persona sintética']);
$iid=(int)$result['interaccion_id'];
$interaction=$db->query('SELECT * FROM interacciones_vinculacion WHERE id='.$iid)->fetch_assoc();
$own='17490000000000001234567890';$foreign='fixture-foreign-'.bin2hex(random_bytes(4));
$q=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,call_id_with_rec,payload_json,received_at) VALUES(?,'NOTIFY_RECORD',?,?, '{}',NOW())");
foreach([['101',$foreign],['102',$own]] as [$ext,$id]){$q->bind_param('sss',$ref,$ext,$id);$q->execute();}$q->close();
$before=$db->query('SELECT COUNT(*) n FROM interacciones_vinculacion')->fetch_assoc()['n'];
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
 $catalog=(new TelefoniaGrabacionInteraccionService($db))->resolver($interaction);
 $assert($catalog['extension']==='102','Historical native binding supplies original owner extension');
 $assert($catalog['ids']===[$own],'Foreign first recording cannot replace own exact reference');
 $assert($db->query('SELECT COUNT(*) n FROM interacciones_vinculacion')->fetch_assoc()['n']===$before,'Resolver works inside READ ONLY and leaves native history intact');
}finally{$db->rollback();}
echo "PASS $checks native historical audio SQL checks in READ ONLY; no provider or binary playback.\n";
if(in_array('--emit-fixture',$argv,true))echo json_encode(['interaccion_id'=>$iid,'own_record'=>$own,'foreign_record'=>$foreign],JSON_THROW_ON_ERROR)."\n";
