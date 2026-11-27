<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
foreach([['VENTAS',41,'101','Asesor de Ventas'],['RECEPCION',43,'100','Marketing'],['ANALISTA',42,'102','Analista de Datos']] as [$adapter,$user,$extension,$role]){
 $_SESSION=['usuario_id'=>$user,'rol_id'=>['VENTAS'=>3,'RECEPCION'=>5,'ANALISTA'=>4][$adapter],'rol'=>$role,'permisos'=>['telefonia.usar','telefonia.recibir','telefonia.salientes']];
 $id='fixture-approved-'.strtolower($adapter).'-'.bin2hex(random_bytes(7));$seed($id,$extension);
 $service=new TelefoniaResultadoConfirmacion($db);
 $values=match($adapter){
  'VENTAS'=>['extension'=>$extension,'catalogo_version'=>'4','resultados_seleccionados'=>'["BUZON_VOZ"]','resultado'=>'BUZON_VOZ','observacion'=>'Fixture approved result'],
  'RECEPCION'=>['resultado'=>'CONTACTO','nota'=>'Fixture approved result'],
  default=>['decision'=>'sin_identificar','observacion'=>'Fixture approved result']
 };
 $data=$make($id,$values);
 $save=static function(array $data,?callable $confirm)use($db,$adapter,$user):array{
  return match($adapter){
   'VENTAS'=>(new TelefoniaResultadoVentasService($db))->guardar($user,$data['pbx_call_id'],$data['resultado'],$data,$confirm),
   'RECEPCION'=>(new TelefoniaRecepcionMarketingService($db))->guardarResultado($user,$data['pbx_call_id'],$data['resultado'],$data['nota'],$confirm),
   default=>(new TelefoniaEntrantesAnalistaService($db))->registrar($user,$data,$confirm)
  };
 };
 $a=$service->ejecutar($user,$adapter,$data,$save);
 $meta=json_decode($data['captura_confirmacion'],true);
 $receipt=(new TelefoniaBorradorService($db))->leerAplicacion($user,[$id],$meta['request_id']);
 $assert($receipt!==null&&$receipt['respuesta']===$a,$adapter.' native and receipt committed together');
 $called=false;$b=$service->ejecutar($user,$adapter,$data,static function()use(&$called):array{$called=true;throw new RuntimeException('Must not save twice');});
 $assert(!$called&&$a===$b,$adapter.' lost ACK replay returns exact committed response');
 $changed=$data;$changed['observacion']='Changed result payload';$rejected=false;
 try{$service->ejecutar($user,$adapter,$changed,$save);}catch(DomainException $e){$rejected=true;}
 $assert($rejected,$adapter.' same request cannot confirm a changed payload');
 $failureId=$id.'-rollback';$seed($failureId,$extension);$failure=$make($failureId,$values);
 $rejected=false;
 try{$save($failure,static function(){throw new RuntimeException('Synthetic receipt failure');});}catch(RuntimeException $e){$rejected=true;}
 $assert($rejected,$adapter.' receipt failure propagated');
 $table=['VENTAS'=>'telefonia_ventas_resultados','RECEPCION'=>'telefonia_recepcion_resultados','ANALISTA'=>'telefonia_analista_entrantes'][$adapter];
 $s=$db->prepare('SELECT COUNT(*) n FROM '.$table.' WHERE pbx_call_id=BINARY ?');$s->bind_param('s',$failureId);$s->execute();$count=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
 $assert($count===0,$adapter.' native result rolled back on receipt failure');
}
echo "PASS $checks native result/receipt SQL assertions across three adapters: lost ACK, immutable payload, same-transaction rollback. Analyst classification only; institutional/funnel integration validated separately.\n";
