<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/ZadarmaCallLookupService.php';
final class ExactLookupFakeApi {public function __construct(private array $rows){}public function call(...$args):string{return json_encode(['status'=>'success','stats'=>$this->rows],JSON_THROW_ON_ERROR);}}
$checks=0;$assert=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$lookup=static function(array $rows):ZadarmaCallLookupService{$r=new ReflectionClass(ZadarmaCallLookupService::class);$s=$r->newInstanceWithoutConstructor();$r->getProperty('api')->setValue($s,new ExactLookupFakeApi($rows));$r->getProperty('timezone')->setValue($s,new DateTimeZone('America/Mexico_City'));return $s;};
$ref='out_'.str_repeat('a',32);$base=['pbx_call_id'=>$ref,'call_id'=>'17490000000000001234567890','sip'=>'102','destination'=>'5550000101','callstart'=>date('Y-m-d H:i:s'),'disposition'=>'answered','seconds'=>12,'is_recorded'=>true];
$a=$lookup([$base])->buscarPorPbxCallId($ref,'102');$assert($a['call_id']===$base['call_id'],'Large recording id preserved exactly');
$assert($lookup([$base])->buscarPorPbxCallId(strtoupper($ref),'102')===null,'Exact PBX case required');
$foreign=array_replace($base,['sip'=>'100','call_id'=>'17490000000000001234567891','seconds'=>900]);
$assert($lookup([$foreign,$base])->buscarPorPbxCallId($ref,'102')['seconds']===12,'Own segment chosen by explicit extension, not first row or highest duration');
$assert($lookup([$foreign,$base])->buscarPorPbxCallId($ref)===null,'Unscoped lookup cannot choose between participants');
$assert($lookup([$base,$base])->buscarPorPbxCallId($ref,'102')['seconds']===12,'Identical provider duplicate row is harmless');
$assert($lookup([$base,array_replace($base,['seconds'=>13])])->buscarPorPbxCallId($ref,'102')===null,'Contradictory own rows remain ambiguous');
$assert($lookup([$base,array_replace($base,['sip'=>'100'])])->buscarPorPbxCallId($ref,'102')===null,'Shared recording id cannot be assigned to one participant');
$assert($lookup([array_replace($base,['sip'=>'0102'])])->buscarPorPbxCallId($ref,'102')===null,'Different extension with leading zero is not silently equated');
$assert($lookup([array_replace($base,['seconds'=>900000])])->buscarPorPbxCallId($ref,'102')===null,'Invalid duration cannot enrich a call');
$assert($lookup(array_fill(0,1000,$base))->buscarPorPbxCallId($ref,'102')===null,'Truncated statistics page cannot establish a unique recording');
echo "PASS $checks exact PBX CDR lookup assertions: explicit participant, ambiguity, foreign/shared recording, immutable identifiers and valid duration. Fake provider; no network/SQL.\n";
