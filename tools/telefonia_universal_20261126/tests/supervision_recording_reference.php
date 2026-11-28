<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/SupervisionLlamadasAudioService.php';
require_once dirname(__DIR__,3).'/app/services/TelefoniaGrabacionSelector.php';
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$a='17490000000000001234567890';$b='17490000000000001234567891';
$make=static fn(array $ids):array=>SupervisionLlamadasAudioService::sanear([
 'disponible'=>true,'records'=>array_map(static fn($id)=>['link'=>'https://fixture.invalid/'.$id],$ids),
 'segmentos_info'=>array_map(static fn($i,$id)=>['indice'=>$i,'referencia'=>$id,'etiqueta'=>'SDK private label'],array_keys($ids),$ids)]);
$old=$make([$a,$b]);$late=$make(['fixture-late-record',$a,$b]);
$check($old['segmentos_info'][1]['referencia']===$b,'Sanitizer preserves exact authorized decimal string');
$check(!str_contains($old['segmentos_info'][0]['etiqueta'],'SDK'),'Existing institutional label sanitation remains');
$ids=TelefoniaGrabacionSelector::referencias($old['records'],$old['segmentos_info']);
$check(TelefoniaGrabacionSelector::indice($ids,['grabacion'=>$b])===1,'Own authorized record selection');
$lateIds=TelefoniaGrabacionSelector::referencias($late['records'],$late['segmentos_info']);
$check(TelefoniaGrabacionSelector::indice($lateIds,['grabacion'=>$b,'segmento'=>'1'])===2,'Late earlier recording cannot change selected binary');
foreach([['segmento'=>'1'],['grabacion'=>'fixture-foreign-record']] as $query){
 $rejected=false;try{TelefoniaGrabacionSelector::indice($lateIds,$query);}catch(DomainException $e){$rejected=in_array($e->getCode(),[404,409],true);}
 $check($rejected,'Stale ordinal and foreign reference denied');
}
$clean=SupervisionLlamadasAudioService::sanear(['segmentos_info'=>[['indice'=>0,'referencia'=>'../private']]]);
$check(!isset($clean['segmentos_info'][0]['referencia']),'Malformed reference is not advertised');
$check(TelefoniaGrabacionSelector::indice(TelefoniaGrabacionSelector::referencias($make([$a])['records'],$make([$a])['segmentos_info']),[])===0,'One-record legacy URL remains supported');
echo "PASS $checks actual supervision sanitizer/selector checks; synthetic metadata only, no provider or binary playback.\n";
