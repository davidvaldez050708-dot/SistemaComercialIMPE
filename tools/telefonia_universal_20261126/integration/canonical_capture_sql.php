<?php
declare(strict_types=1);
require __DIR__.'/successor_journal_sql.php';
require_once $lab.'/site/app/services/TelefoniaBorradorService.php';
$grandchild='sdk-'.bin2hex(random_bytes(16));
$grandBase=['session_id'=>$grandchild,'direccion'=>'ENTRANTE','numero'=>'5550000000','sdk_id'=>'fixture-successor-second',
 'sdk_dialogo'=>json_encode(['call_id'=>'fixture-third-call','local_tag'=>'third-local','remote_tag'=>'third-remote'])];
foreach(['ringing','answered','ended'] as $n=>$kind)$service->registrarEvento(42,$grandBase+['evento'=>$kind,'secuencia'=>$n+1],2);
$service->registrarEvento(42,$grandBase+['evento'=>'successor_link','secuencia'=>4,'operacion_id'=>$op,'intento'=>1,
 'predecesora_session_id'=>$child,'replaces_dialogo'=>$childBase['sdk_dialogo']],2);
$drafts=new TelefoniaBorradorService($db);$at=(int)floor(microtime(true)*1000);
$saved=$drafts->guardar(42,[$id],['persona'=>'Persona sintética','correo'=>'fixture@example.invalid'],$at,['adaptador'=>'ANALISTA']);
$assert(count($saved['ids'])===4,'Canonical group includes PBX and all exact SDK successors, beyond original three-reference limit');
$assert($drafts->leer(42,$grandchild)['valores']['correo']==='fixture@example.invalid','Another browser can recover by latest successor only');
$saved=$drafts->guardar(42,[$ref],['notas'=>'Edición desde otro navegador'],$at+1,['adaptador'=>'ANALISTA']);
$assert($saved['valores']['persona']==='Persona sintética'&&$saved['valores']['notas']==='Edición desde otro navegador','Canonical PBX edit preserves captured contact fields');
$assert($drafts->leer(42,$id)['ids']===$saved['ids'],'Server JSON retains full exact identity group');
$assert($drafts->leer(41,$ref)===null,'Another user cannot read canonical capture');
$assert($drafts->leer(42,strtoupper($ref))===null,'Case-changed PBX reference cannot recover another capture');
$failed=false;try{$drafts->guardar(42,[$ref],['persona'=>'Overwrite same revision'],$at+1);}catch(DomainException $e){$failed=true;}
$assert($failed&&$drafts->leer(42,$ref)['valores']['persona']==='Persona sintética','Same-revision conflicting edit preserves previous capture');
$drafts->eliminar(42,[$grandchild],$at+1);
$closed=$drafts->leer(42,$id);$assert(!empty($closed['closed'])&&$closed['valores']['correo']==='fixture@example.invalid','Canonical close returns a tombstone with original capture for cross-browser ACK recovery');
echo "PASS $checks accumulated transfer/canonical capture assertions: exact successor chains, cross-browser alias recovery, full group metadata, user/case isolation, explicit conflict and non-destructive close. Synthetic only.\n";
