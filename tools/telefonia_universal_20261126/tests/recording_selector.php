<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaGrabacionSelector.php';
$checks=0;$check=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
$reject=static function(array $ids,array $query,int $code)use($check){try{TelefoniaGrabacionSelector::indice($ids,$query);}catch(DomainException $e){$check($e->getCode()===$code,'Correct rejection status');return;}$check(false,'Unauthorized or unstable recording selection accepted');};
$a='17490000000000001234567890';$b='17490000000000001234567891.123456789012345678901234567890';$c='fixture-record-new';
$check(TelefoniaGrabacionSelector::indice([$a],[])===0,'Single authorized recording preserves old direct player');
$check(TelefoniaGrabacionSelector::indice([3=>$a],[])===3,'Sparse partial result selects its sole own record');
$check(TelefoniaGrabacionSelector::indice([3=>$a],['segmento'=>'3'])===3,'Single sparse legacy index remains compatible');
$check(TelefoniaGrabacionSelector::indice([$a,$b],['grabacion'=>$b])===1,'Decimal recording reference preserved exactly');
$check(TelefoniaGrabacionSelector::indice([$c,$a,$b],['grabacion'=>$b,'segmento'=>'1'])===2,'A late earlier segment cannot change the requested recording');
$hash=TelefoniaGrabacionSelector::catalogo([$a,$b]);
$check(TelefoniaGrabacionSelector::indice([$a,$b],['segmento'=>'1','catalogo'=>$hash])===1,'Explicit ordinal permitted only in the same catalog');
$reject([$c,$a,$b],['segmento'=>'1','catalogo'=>$hash],409);
$reject([$a,$b],[],409);$reject([$a,$b],['segmento'=>'0'],409);
$reject([$a,$b],['grabacion'=>'fixture-foreign-id','segmento'=>'0'],404);
$reject([$a],['grabacion'=>[$a]],404);$reject([$a],['grabacion'=>'../private'],404);
$reject([$a],['segmento'=>'-1'],404);$reject([$a],['segmento'=>'0.5'],404);
$reject([$a,$a],['grabacion'=>$a],409);$reject([123456],[],409);$reject(['invalid/id'],[],409);$reject([],[],404);
$many=[];for($i=0;$i<9;$i++)$many[]='fixture-record-'.$i;
$reject($many,['grabacion'=>$many[0]],409);array_pop($many);
$check(TelefoniaGrabacionSelector::indice($many,['grabacion'=>$many[7]])===7,'Eight verified segments supported');
$refs=TelefoniaGrabacionSelector::referencias([3=>['link'=>'https://fixture.invalid/secret-audio']],
 [['indice'=>3,'referencia'=>$b],['indice'=>2,'referencia'=>$a]]);
$check($refs===[3=>$b],'Only available verified metadata maps to server records; no URL leaked into selector');
echo "PASS $checks recording selector checks: exact opaque reference, late arrivals, sparse partial availability, immutable catalog, legacy single record, foreign/malformed/duplicate guards. No SQL/provider.\n";
