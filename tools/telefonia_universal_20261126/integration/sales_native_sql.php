<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$lab=getenv('IMPE_PHONE_LAB_ROOT');
if(!is_string($lab)||!is_file($lab.'/credentials.json'))throw new RuntimeException('Set IMPE_PHONE_LAB_ROOT to the existing private synthetic laboratory.');
date_default_timezone_set('America/Mexico_City');
$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Wrong fixture target');
require_once $lab.'/site/config/db_connection.php';
require_once $lab.'/site/app/services/TelefoniaResultadoVentasService.php';
require_once $lab.'/site/app/services/TelefoniaMarcacionesVentasService.php';
require_once $lab.'/site/app/services/TelefoniaVentasLlamadasService.php';
$db=Database::compartida();$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$grants=implode(' ',array_column($db->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM),0));
$assert(!preg_match('/\b(?:CREATE|ALTER|DROP)\b/i',$grants),'Fixture application must lack schema privileges');
$service=new TelefoniaResultadoVentasService($db);
new TelefoniaContactosService($db);new TelefoniaMarcacionesVentasService($db);
$checks++;
$id='fixture-native-sales-'.bin2hex(random_bytes(8));
$seed=static function(string $id,bool $connected=true)use($db):void{
$exists=$db->prepare('SELECT id FROM telefonia_contactos_personales WHERE usuario_id=41 AND telefono=?');
do{$phone='555'.random_int(1000000,9999999);$normalized='+52'.$phone;$exists->bind_param('s',$normalized);$exists->execute();$used=$exists->get_result()->num_rows>0;}while($used);
$exists->close();
$start=date('Y-m-d H:i:s',time()-90);$answer=date('Y-m-d H:i:s',time()-80);$end=date('Y-m-d H:i:s',time()-30);
$s=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,disposition,duration,received_at,payload_json,caller_id,destination,destination_digits,call_start) VALUES(?,?,'101',?,?,?,'{}',?,?,?,?)");
$events=[['NOTIFY_OUT_START','',0,$start]];
if($connected)$events[]=['NOTIFY_ANSWER','',0,$answer];
$events[]=['NOTIFY_OUT_END',$connected?'answered':'no answer',$connected?50:0,$end];
foreach($events as [$event,$disposition,$seconds,$at]){
 $s->bind_param('sssisssss',$id,$event,$disposition,$seconds,$at,$phone,$phone,$phone,$start);$s->execute();
}
$s->close();
};
$seed($id);
$data=['extension'=>'101','catalogo_version'=>'4','resultados_seleccionados'=>'["BUZON_VOZ"]','observacion'=>'Prueba nativa sintética: respondió un buzón.'];
$saved=$service->guardar(41,$id,'BUZON_VOZ',$data);
$assert($saved['resultado']==='BUZON_VOZ','Native result retained');
$assert($saved['contacto_real']===false,'PBX answer is not a human conversation');
$s=$db->prepare('SELECT * FROM telefonia_ventas_resultados WHERE usuario_id=41 AND extension=\'101\' AND pbx_call_id=BINARY ?');
$s->bind_param('s',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
$assert($row!==null&&$row['observacion']===$data['observacion'],'Native note committed without replacing history');
$resultId=(int)$row['id'];
$detail=$db->query('SELECT * FROM telefonia_ventas_resultado_detalles WHERE resultado_id='.$resultId)->fetch_assoc();
$assert($detail['catalogo_version']==='4'&&$detail['resultado_principal']==='BUZON_VOZ','Installed catalog details retained');
$assert($db->query('SELECT * FROM telefonia_ventas_resultado_selecciones WHERE resultado_id='.$resultId)->num_rows===1,'Native selection committed once');
$caught=false;try{$service->guardar(41,$id,'CONVERSACION_PERSONA',array_replace($data,['resultados_seleccionados'=>'["CONVERSACION_PERSONA"]']));}catch(DomainException $e){$caught=true;}
$assert($caught,'Repeated save cannot overwrite the native result');
$again=$db->query('SELECT resultado FROM telefonia_ventas_resultados WHERE id='.$resultId)->fetch_assoc();
$assert($again['resultado']==='BUZON_VOZ','Original native classification unchanged after conflicting retry');
$caught=false;try{$service->guardar(42,$id,'BUZON_VOZ',$data);}catch(DomainException $e){$caught=true;}
$assert($caught,'Analyst cannot save another role native sales result');
$missing=$id.'-missed';$seed($missing,false);
$conversation=array_replace($data,['resultados_seleccionados'=>'["CONVERSACION_PERSONA","WHATSAPP"]','prospecto_nombre'=>'fixture-native-contact','guardar_contacto'=>'1','correo'=>'fixture@example.invalid']);
$caught=false;try{$service->guardar(41,$missing,'CONVERSACION_PERSONA',$conversation);}catch(DomainException $e){$caught=true;}
$assert($caught,'Human conversation rejected when PBX has no own answer');
$q=$db->prepare('SELECT COUNT(*) n FROM telefonia_ventas_resultados WHERE pbx_call_id=BINARY ?');
$q->bind_param('s',$missing);$q->execute();$assert((int)$q->get_result()->fetch_assoc()['n']===0,'Rejected conversation creates no result');$q->close();
$contactCall=$id.'-contact';$seed($contactCall);
$result=$service->guardar(41,$contactCall,'CONVERSACION_PERSONA',$conversation);
$assert($result['contacto_real']===true,'Declared human conversation with PBX answer retained');
$q=$db->prepare('SELECT r.id,r.contacto_id,c.usuario_id,c.nombre FROM telefonia_ventas_resultados r JOIN telefonia_contactos_personales c ON c.id=r.contacto_id WHERE r.pbx_call_id=BINARY ?');
$q->bind_param('s',$contactCall);$q->execute();$contact=$q->get_result()->fetch_assoc();$q->close();
$assert($contact!==null&&(int)$contact['usuario_id']===41,'Native contact committed under the same actor');
$assert($db->query('SELECT codigo FROM telefonia_ventas_resultado_selecciones WHERE resultado_id='.(int)$contact['id'])->num_rows===2,'Two installed selections retained');
$rollback=$id.'-rollback';$seed($rollback);
$caught=false;try{$service->guardar(41,$rollback,'CONVERSACION_PERSONA',array_replace($conversation,['prospecto_nombre'=>'fixture-fail-contact']));}catch(mysqli_sql_exception $e){$caught=$e->getCode()===1644;}
$assert($caught,'Synthetic failure occurs after native result/details insertion');
$q=$db->prepare('SELECT COUNT(*) n FROM telefonia_ventas_resultados WHERE pbx_call_id=BINARY ?');
$q->bind_param('s',$rollback);$q->execute();$assert((int)$q->get_result()->fetch_assoc()['n']===0,'Native result rolls back when contact saving fails');$q->close();
$q=$db->prepare('SELECT nombre FROM telefonia_contactos_personales WHERE id=?');$q->bind_param('i',$contact['contacto_id']);$q->execute();
$assert($q->get_result()->fetch_assoc()['nombre']==='fixture-native-contact','Previously committed personal contact survives failed transaction');$q->close();
echo "PASS $checks native Sales SQL checks: actual installed commercial services/catalog, DML-only constructors, PBX answer distinct from contact, notes/details, immutable result and role scope. Synthetic DB only; no funnel acceptance yet.\n";
