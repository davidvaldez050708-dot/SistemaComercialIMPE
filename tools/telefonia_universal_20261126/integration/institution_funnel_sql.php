<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
$_SESSION=['usuario_id'=>42,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes','seguimientos_vinculacion.operar_propios']];
$id='fixture-institution-'.bin2hex(random_bytes(8));$seed($id,'102');
$values=['decision'=>'nueva','nombre_entidad'=>'Institución sintética '.bin2hex(random_bytes(8)),
 'tipo_entidad'=>'INSTITUCION','estado_id'=>'1','municipio_id'=>'','resultado'=>'CONTACTO_CORRECTO',
 'persona_atendio'=>'Persona sintética','telefono_capturado'=>'5550000101','correo_capturado'=>'fixture@example.invalid',
 'verificacion_telefono_confirmado'=>'1','verificacion_correo_confirmado'=>'1','observacion'=>'Captura sintética',
 'actualizar_datos_llamada'=>'1','nuevo_correo_contacto'=>'fixture-new@example.invalid','nuevo_contacto_cargo'=>'Área sintética'];
$data=$make($id,$values);$registration=new TelefoniaEntrantesAnalistaService($db);$confirmation=new TelefoniaResultadoConfirmacion($db);
$save=static fn(array $d,?callable $c):array=>$registration->registrar(42,$d,$c);
$result=$confirmation->ejecutar(42,'ANALISTA',$data,$save);
$assert($result['seguimiento_id']>0&&$result['interaccion_id']>0,'Native institution and interaction created');
$assert($result['verificacion_confirmada']===true,'Own PBX answer/end plus explicit phone/email qualifies');
$institution=$db->query('SELECT * FROM seguimientos_vinculacion WHERE id='.(int)$result['seguimiento_id'])->fetch_assoc();
$assert($institution['correo_verificado']==='fixture-new@example.invalid'&&$institution['contacto_cargo']==='Área sintética','Reviewed draft contact applied in native transaction');
$assert($db->query('SELECT * FROM telefonia_verificacion_evidencias WHERE interaccion_id='.(int)$result['interaccion_id']." AND estado='CONFIRMADA'")->num_rows===2,'Two explicit verification evidences retained');
$assert($confirmation->ejecutar(42,'ANALISTA',$data,$save)===$result,'Lost ACK cannot create second institution');
$failId=$id.'-rollback';$seed($failId,'102');$failure=$make($failId,array_replace($values,['nombre_entidad'=>$values['nombre_entidad'].' revertida']));
$before=(int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n'];$failed=false;
try{$registration->registrar(42,$failure,static function(){throw new RuntimeException('Fixture confirmation failure');});}catch(RuntimeException $e){$failed=true;}
$assert($failed&&(int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n']===$before,'Institution creation rolls back if confirmation fails');

$_SESSION=['usuario_id'=>41,'rol_id'=>3,'rol'=>'Asesor de Ventas','permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes','ventas.embudo.ver','ventas.embudo.mover']];
$prospect=$db->prepare("INSERT INTO prospectos_comerciales(telefono_normalizado,pais_nombre) VALUES('+525550000101','México')");$prospect->execute();$pid=(int)$db->insert_id;$prospect->close();
$db->query("INSERT INTO prospecto_asignaciones(prospecto_id,asesor_id,responsable_id,vigente) VALUES($pid,41,41,1)");$assignment=(int)$db->insert_id;
$id='fixture-funnel-'.bin2hex(random_bytes(8));$seed($id,'101');
$values=['extension'=>'101','resultado'=>'CONVERSACION_PERSONA','catalogo_version'=>'4','resultados_seleccionados'=>'["CONVERSACION_PERSONA"]','observacion'=>'Conversación sintética','prospecto_asignacion_id'=>(string)$assignment];
$data=$make($id,$values);$sales=new TelefoniaResultadoVentasService($db);
$save=static fn(array $d,?callable $c):array=>$sales->guardar(41,$d['pbx_call_id'],$d['resultado'],$d,$c);
$result=$confirmation->ejecutar(41,'VENTAS',$data,$save);
$assert($db->query("SELECT * FROM prospecto_embudo_llamadas WHERE asignacion_id=$assignment")->num_rows===1,'Native sales result linked to exact own assignment');
$assert($db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$assignment")->fetch_assoc()['etapa']==='CONTACTADO','Authorized funnel transition committed');
$assert($confirmation->ejecutar(41,'VENTAS',$data,$save)===$result,'Lost ACK cannot duplicate funnel movement');
$failId=$id.'-rollback';$seed($failId,'101');$failure=$make($failId,$values);$failed=false;
try{$save($failure,static function(){throw new RuntimeException('Fixture confirmation failure');});}catch(RuntimeException $e){$failed=true;}
$s=$db->prepare('SELECT COUNT(*) n FROM prospecto_embudo_llamadas WHERE pbx_call_id=BINARY ?');$s->bind_param('s',$failId);$s->execute();$count=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
$assert($failed&&$count===0,'Funnel link rolls back with native result and receipt');
$s=$db->prepare('SELECT COUNT(*) n FROM telefonia_ventas_resultados WHERE pbx_call_id=BINARY ?');$s->bind_param('s',$failId);$s->execute();$count=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
$assert($count===0,'No definitive result is orphaned after funnel rollback');
echo "PASS $checks native institution/funnel SQL assertions: creation, reviewed contact, verified evidence, exact assignment, replay and atomic failure. Synthetic only.\n";
