<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaVentasGrabacionCdrService.php';
require_once __DIR__.'/../app/helpers/ZadarmaPbxIdentifierHelper.php';
$clock=new DateTimeImmutable('2026-11-05 10:30:00',new DateTimeZone('America/Mexico_City'));
$call=[
 'usuario_id'=>154,'extension'=>'154','direccion'=>'Saliente',
 'pbx_call_id'=>'out_TEST_PBX_ABC123','fecha'=>'2026-11-05 09:45:40',
 'finalizada'=>false,'conectada'=>true,'origen_extension'=>'',
 'registro'=>['id'=>4,'resultado'=>'CONVERSACION_PERSONA']
];
$stats=[
 'pbx_call_id'=>$call['pbx_call_id'],'sip'=>154,
 'callstart'=>$call['fecha'],'seconds'=>201,'is_recorded'=>true,
 'disposition'=>'answered','call_id'=>'1439981389.2702773'
];
$assert=static function(bool $ok,string $desc):void {
 if(!$ok)throw new RuntimeException('FAIL: '.$desc);
};
$verify=static function(array $c,array $rows)use($clock):?array {
 return TelefoniaVentasGrabacionCdrService::resolver($c,static function($from,$to)use($rows):array {
  if($from!=='2026-11-05 09:40:40'||$to!=='2026-11-05 10:30:00')
   throw new RuntimeException('Ventana temporal inesperada');
  return $rows;
 },$clock);
};
$result=$verify($call,[$stats]);
$assert($result!==null&&$result['call_id']==='1439981389.2702773'&&$result['seconds']===201,
 'Recupera grabación exacta 09:45 sin OUT_END');
foreach([
 ['otro PBX',array_replace($stats,['pbx_call_id'=>'out_OTRO_CDR'])],
 ['otro SIP',array_replace($stats,['sip'=>150])],
 ['sin grabación',array_replace($stats,['is_recorded'=>false])],
 ['sin respuesta',array_replace($stats,['disposition'=>'no answer'])],
 ['sin duración',array_replace($stats,['seconds'=>0])],
 ['inicio distinto',array_replace($stats,['callstart'=>'2026-11-05 10:02:40'])],
 ['call_id float impreciso',array_replace($stats,['call_id'=>1439981389.2702773])],
 ['call_id inseguro',array_replace($stats,['call_id'=>'../../secret'])],
] as $case){
 $assert($verify($call,[$case[1]])===null,$case[0]);
}
$assert($verify($call,[$stats,$stats])===null,'Dos registros del mismo tramo no son unívocos');
foreach([
 ['usuario ausente',array_replace($call,['usuario_id'=>0])],
 ['otras extensiones',array_replace($call,['extension'=>'150'])],
 ['llamada entrante',array_replace($call,['direccion'=>'Entrante'])],
 ['cierre webhook ya recibido',array_replace($call,['finalizada'=>true])],
 ['sin ANSWER',array_replace($call,['conectada'=>false])],
 ['sin resultado',array_replace($call,['registro'=>null])],
 ['resultado de buzón',array_replace($call,['registro'=>['resultado'=>'BUZON_VOZ']])],
 ['transferencia',array_replace($call,['origen_extension'=>'100'])],
 ['fecha antigua',array_replace($call,['fecha'=>'2026-05-01 09:45:40'])],
] as $case){
 $assert($verify($case[1],[$stats])===null,$case[0]);
}
$assert($verify($call,[])===null,'API sin audio');
echo "PASS: 09:45 grabada y exclusivamente propia; CDR único, float rechazado, SIN OUT_END y casos negativos.\n";
