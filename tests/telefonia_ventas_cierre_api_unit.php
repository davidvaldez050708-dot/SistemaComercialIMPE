<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaVentasCierreApiVerificado.php';
$tz=new DateTimeZone('America/Mexico_City');
$now=new DateTimeImmutable('2026-11-05 10:30:00',$tz);
$call=['direccion'=>'Saliente','finalizada'=>false,'conectada'=>true,'registro'=>null,
  'pbx_call_id'=>'out_TEST_PBX_ABC123','extension'=>'154','fecha'=>'2026-11-05 09:45:40'];
$provider=['pbx_call_id'=>$call['pbx_call_id'],'sip'=>'154','disposition'=>'answered',
  'seconds'=>201,'is_recorded'=>true,'callstart'=>'2026-11-05 09:45:40'];
$expectedFrom='2026-11-05 09:40:40';
$get=static function(string $from,string $to) use($provider,$expectedFrom): array {
    if($from!==$expectedFrom || $to!=='2026-11-05 10:30:00')throw new RuntimeException('Intervalo inválido.');
    return [$provider];
};
$verify=static fn(array $c,callable $fn,?DateTimeImmutable $clock=null):bool=>
    TelefoniaVentasCierreApiVerificado::verificar($c,$fn,$clock??$now);
$assert=static function($test,string $title):void{
  if(!$test)throw new RuntimeException('Falló: '.$title);
};
$assert($verify($call,$get),'Zadarma confirma saliente de Natalia 201 segundos');
foreach([
  ['misma PBX, otra extensión',array_replace($provider,['sip'=>'150'])],
  ['otra PBX',array_replace($provider,['pbx_call_id'=>'out_TEST_PBX_OTRA'])],
  ['no contestada',array_replace($provider,['disposition'=>'no answer'])],
  ['duración desconocida',array_replace($provider,['seconds'=>null])],
  ['duración cero',array_replace($provider,['seconds'=>0])],
  ['fecha equivocada',array_replace($provider,['callstart'=>'2026-11-05 10:02:40'])],
] as [$label,$stat])$assert(!$verify($call,static fn()=>[$stat]),$label);
$assert(!$verify($call,static fn()=>[$provider,$provider]),'PBX duplicada no se atribuye');
foreach([
 ['llamada entrante',array_replace($call,['direccion'=>'Entrante'])],
 ['no conectada',array_replace($call,['conectada'=>false])],
 ['ya finalizada',array_replace($call,['finalizada'=>true])],
 ['resultado existente',array_replace($call,['registro'=>['id'=>12]])],
 ['referencia no saliente',array_replace($call,['pbx_call_id'=>'in_TEST_PBX_ABC123'])],
 ['fecha futura',array_replace($call,['fecha'=>'2026-11-05 11:00:00'])],
] as [$label,$c])$assert(!$verify($c,$get),$label);
$inCall=new DateTimeImmutable('2026-11-05 09:47:00',$tz);
$assert(!$verify($call,static fn()=>[$provider],$inCall),'No acredita una llamada que aún podría estar activa');
$assert($verify($call,static fn()=>[array_replace($provider,['is_recorded'=>false])]),
  'Grabación no es condición para el resultado humano');
$assert(!$verify($call,static fn()=>[]),'Proveedor sin coincidencia');
echo "PASS: PBX y extensión exactas, duración y fin verificados, sin inferir por teléfono ni por grabación.\n";
