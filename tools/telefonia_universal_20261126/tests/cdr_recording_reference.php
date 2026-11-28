<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaVentasGrabacionCdrService.php';
require_once dirname(__DIR__,3).'/app/services/TelefoniaGrabacionSelector.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$ref='out_fixture_cdr_exact';$id='17490000000000001234567890.123456789012345678901234567890';
$call=['direccion'=>'Saliente','finalizada'=>false,'conectada'=>true,'registro'=>['resultado'=>'CONVERSACION_PERSONA'],
 'pbx_call_id'=>$ref,'extension'=>'101','usuario_id'=>41,'fecha'=>'2026-11-25 12:00:00'];
$row=['pbx_call_id'=>$ref,'sip'=>'101','disposition'=>'answered','is_recorded'=>true,'seconds'=>45,'callstart'=>'2026-11-25 12:00:00','call_id'=>$id];
$now=new DateTimeImmutable('2026-11-25 13:00:00',new DateTimeZone('America/Mexico_City'));
$resolve=static fn(array $rows,array $patch=[])=>TelefoniaVentasGrabacionCdrService::resolver(array_replace($call,$patch),static fn()=> $rows,$now);
$cdr=$resolve([$row]);$check($cdr['call_id']===$id,'Native exact own CDR preserves long opaque recording ID');
$check(TelefoniaGrabacionSelector::indice([$cdr['call_id']],[])===0,'Single original URL remains compatible');
$check(TelefoniaGrabacionSelector::indice([$cdr['call_id']],['grabacion'=>$id])===0,'Explicit stable own reference permitted');
$denied=false;try{TelefoniaGrabacionSelector::indice([$id],['grabacion'=>'fixture-foreign-record']);}catch(DomainException $e){$denied=$e->getCode()===404;}$check($denied,'Foreign reference cannot override the CDR result');
foreach([['sip'=>'102'],['pbx_call_id'=>'out_other_group'],['disposition'=>'no answer'],['is_recorded'=>false],['call_id'=>1.234],['seconds'=>0]] as $patch)
 $check($resolve([array_replace($row,$patch)])===null,'Invalid/unowned CDR cannot advertise playable reference');
$check($resolve([$row,$row])===null,'Multiple own candidates remain ambiguous');
$check($resolve([$row],['registro'=>['resultado'=>'BUZON_VOZ']])===null,'Connected voicemail never grants commercial conversation audio');
echo "PASS $checks actual CDR resolver/stable reference checks. Synthetic catalog and explicit test date, no SQL/provider/audio.\n";
