<?php
declare(strict_types=1);
require __DIR__.'/fixture.php';
require_once $lab.'/site/app/services/TelefoniaSesionLocalService.php';
$journal=new TelefoniaSesionLocalService($db);
$record=static function(array $prepared,string $phone,string $sip)use($journal):void{
 $journal->registrarEvento(41,['session_id'=>$prepared['session_id'],'secuencia'=>1,'evento'=>'dialed','numero'=>$phone,'sdk_id'=>$sip],2);
};
$start=static function(string $pbx,string $phone)use($db):void{
 $date=date('Y-m-d H:i:s');$s=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,received_at,destination,destination_digits,payload_json) VALUES(?,'NOTIFY_OUT_START','101',?,?,?,'{}')");
 $s->bind_param('ssss',$pbx,$date,$phone,$phone);$s->execute();$s->close();
};
$phone='555'.random_int(1000000,9999999);$intent=$journal->prepararSaliente(41,$phone);
$assert($journal->resolverSalientePreparada(41,$intent['session_id'])['inicio']===null,'Prepared intent alone never invents a dial');
$record($intent,$phone,'fixture-sdk-'.bin2hex(random_bytes(5)));
// Same suffix in a different country must not identify the call.
$wrong='out-fixture-country-'.bin2hex(random_bytes(5));$start($wrong,'44'.$phone);
$assert($journal->resolverSalientePreparada(41,$intent['session_id'])['inicio']===null,'International suffix match rejected');
$pbx='out-fixture-exact-'.bin2hex(random_bytes(5));$start($pbx,$phone);
$bound=$journal->resolverSalientePreparada(41,$intent['session_id']);
$assert($bound['inicio']['pbx_call_id']===$pbx,'Own exact country-normalized destination and server marker bound');
$assert($journal->resolverSalientePreparada(41,$intent['session_id'])===$bound,'Persisted binding replays unchanged');
$denied=false;try{$journal->resolverSalientePreparada(42,$intent['session_id']);}catch(DomainException $e){$denied=true;}
$assert($denied,'Foreign user cannot resolve an intent');
$phone='555'.random_int(1000000,9999999);$ambiguous=$journal->prepararSaliente(41,$phone);$record($ambiguous,$phone,'fixture-sdk-ambiguous');
$start('out-fixture-multiple-a-'.bin2hex(random_bytes(5)),$phone);$start('out-fixture-multiple-b-'.bin2hex(random_bytes(5)),$phone);
$assert($journal->resolverSalientePreparada(41,$ambiguous['session_id'])['identificacion']==='AMBIGUA','Two matching PBX groups are quarantined, never most-recent selection');
$phone='555'.random_int(1000000,9999999);$old=$journal->prepararSaliente(41,$phone);$record($old,$phone,'fixture-sdk-old');
$new=$journal->prepararSaliente(41,$phone);$record($new,$phone,'fixture-sdk-new');$start('out-fixture-late-'.bin2hex(random_bytes(5)),$phone);
$assert($journal->resolverSalientePreparada(41,$old['session_id'])['inicio']===null,'Late start crossing next persisted intention cannot bind to the old call');
echo "PASS $checks persisted outgoing intent SQL checks: physical observation, full destination, owner, stable binding, ambiguity and subsequent-intent fence. Synthetic only.\n";
