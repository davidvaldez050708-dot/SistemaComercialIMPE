<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');putenv('IMPE_VENTAS_V3_UI_ASESORES=41');
$_SESSION=['usuario_id'=>41,'rol_id'=>3,'rol'=>'Asesor de Ventas','permisos'=>['telefonia.usar','telefonia.salientes','telefonia.recibir','ventas.embudo.ver','ventas.embudo.mover','ventas.recuperacion.ver','ventas.recuperacion.gestionar','ventas.descartados.ver','ventas.descartados.gestionar']];
$assignment=static function(string $stage='NUEVO')use($db):int{
 $db->query("INSERT INTO prospectos_comerciales(telefono_normalizado,pais_nombre) VALUES('+525550000101','México')");$pid=(int)$db->insert_id;
 $db->query("INSERT INTO prospecto_asignaciones(prospecto_id,asesor_id,responsable_id,vigente) VALUES($pid,41,41,1)");$id=(int)$db->insert_id;
 $q=$db->prepare('INSERT INTO prospecto_embudo_etapas(asignacion_id,etapa,cambiado_por,actualizado_at) VALUES(?,?,41,UTC_TIMESTAMP())');$q->bind_param('is',$id,$stage);$q->execute();$q->close();return $id;
};
$save=static function(int $assignment,string $result,array $extra=[],?callable $fail=null)use($db,$seed,$make):array{
 $pbx='fixture-v3-'.bin2hex(random_bytes(10));$seed($pbx,'101');
 $values=['extension'=>'101','resultado'=>$result,'catalogo_version'=>'4','resultados_seleccionados'=>json_encode([$result]),'observacion'=>'Conversación sintética V3','prospecto_asignacion_id'=>(string)$assignment]+$extra;
 $data=$make($pbx,$values);
 $native=static fn(array $d,?callable $c):array=>(new TelefoniaResultadoVentasService($db))->guardar(41,$d['pbx_call_id'],$d['resultado'],$d,$fail??$c);
 return [(new TelefoniaResultadoConfirmacion($db))->ejecutar(41,'VENTAS',$data,$native),$pbx,$data];
};
try{
 $id=$assignment();$db->query("INSERT INTO prospecto_embudo_agenda(asignacion_id,proxima_accion_at,revision_fecha,actualizado_por) VALUES($id,'2077-01-01 12:00:00','2077-01-02',41)");
 [$result]=$save($id,'CONVERSACION_PERSONA',['proxima_accion_at'=>date('Y-m-d H:i:s',time()+3600)]);
 $assert($db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='SEGUIMIENTO','V3 scheduled conversation promotes and schedules within native transaction');
 $activity=$db->query("SELECT * FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=$id")->fetch_assoc();
 $assert($activity['tipo_actividad']==='LLAMADA'&&$activity['accion']==='PROGRAMAR','V3 activity kind and intent retained');
 $assert($db->query("SELECT proxima_accion_at FROM prospecto_embudo_agenda WHERE asignacion_id=$id")->fetch_assoc()['proxima_accion_at']==='2077-01-01 12:00:00','V3 scheduling preserves independent legacy agenda');
 $id=$assignment('CONTACTADO');[$result]=$save($id,'PROSPECTO_POTENCIAL');
 $assert($db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='POTENCIAL','Explicit qualified conversation preserves existing V3 potential transition');
 $id=$assignment();[$result]=$save($id,'NO_INTERESADO');
 $assert($db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='DESCARTADO','Own human No interesado with required permissions preserves existing V3 discard');
 $assert($db->query("SELECT subetapa FROM prospecto_embudo_subetapas WHERE asignacion_id=$id")->fetch_assoc()['subetapa']==='NO_INTERESADO','Discard does not invent No contactar consent');
 $id=$assignment();for($n=0;$n<3;++$n)[$result]=$save($id,'BUZON_VOZ');
 $assert($db->query("SELECT COUNT(*) n FROM prospecto_embudo_llamadas WHERE asignacion_id=$id")->fetch_assoc()['n']==='3','Three distinct saved PBX attempts retained');
 $assert($db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='RECUPERACION','Existing V3 three-attempt recovery uses current transaction evidence');
 $assert(!empty($result['recuperacion']['revision_fecha']),'Recovery reports next review from committed native movement');
 $id=$assignment();$failed=false;
 try{$save($id,'NO_INTERESADO',[],static function(){throw new RuntimeException('Fixture receipt rollback');});}catch(RuntimeException $e){$failed=true;}
 $assert($failed&&$db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='NUEVO','Native confirmation failure rolls back V3 discard');
 $assert($db->query("SELECT COUNT(*) n FROM prospecto_embudo_subetapas_historial WHERE asignacion_id=$id")->fetch_assoc()['n']==='0','Rolled-back V3 discard leaves no audit movement');
 $id=$assignment();$failed=false;
 try{$save($id,'CONVERSACION_PERSONA',['proxima_accion_at'=>date('Y-m-d H:i:s',time()+3600)],static function(){throw new RuntimeException('Fixture receipt rollback');});}catch(RuntimeException $e){$failed=true;}
 $assert($failed&&$db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=$id")->fetch_assoc()['etapa']==='NUEVO','Native confirmation failure rolls back V3 scheduling');
 $assert($db->query("SELECT COUNT(*) n FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=$id")->fetch_assoc()['n']==='0','No orphan future activity after rollback');
 echo "PASS $checks V3 native transaction assertions: scheduling, legacy agenda preservation, qualification, discard, three own attempts and complete rollback. Existing private UI pilot enabled only in this CLI fixture.\n";
}finally{putenv('IMPE_VENTAS_V3_UI');putenv('IMPE_VENTAS_V3_UI_ASESORES');}
