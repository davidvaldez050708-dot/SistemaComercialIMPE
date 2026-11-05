<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/SupervisionVentasAudioCdrPolicy.php';
require_once __DIR__.'/../app/services/TelefoniaVentasGrabacionCdrService.php';

// En Git los servicios personalizados de transferencia se administran en
// Hostinger. Cargar el código bajo prueba sin importar esos otros flujos,
// que no se ejecutan aquí. NO crear stubs dentro de producción.
$source=file_get_contents(__DIR__.'/../app/services/SupervisionLlamadasAudioService.php');
$source=preg_replace('/^<\\?php\\s*/','',$source,1);
$source=preg_replace('/^require_once\\s+__DIR__.*?;\\s*$/m','',$source);
$source=str_replace('declare(strict_types=1);','',$source);
eval($source);

$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
};
$now=new DateTimeImmutable('2026-11-05 10:30:00',new DateTimeZone('America/Mexico_City'));
$call=[
 'key'=>'venta:154','source'=>'venta','area'=>'asesores',
 'usuario_id'=>154,'extension'=>'154',
 'pbx_call_id'=>'out_PRUEBA_154_20261105','direccion'=>'Saliente',
 'fecha'=>'2026-11-05 09:45:40',
 'finalizada'=>false,'conectada'=>true,'resultado_guardado'=>true,
 'resultado'=>'PROSPECTO_POTENCIAL',
 'origen_extension'=>'','solicitud_id'=>null
];
$stat=[
 'pbx_call_id'=>$call['pbx_call_id'],
 'sip'=>154,'callstart'=>$call['fecha'],
 'seconds'=>201,'is_recorded'=>true,'disposition'=>'answered',
 'call_id'=>'1439981389.2702773'
];
$stats=static fn($desde,$hasta)=>[$stat];
$provider=new class{
 public array $calls=[];
 public function obtenerGrabacionPorCallId(string $id):array{
     $this->calls[]=$id;
     return ['call_id_with_rec'=>$id,'link'=>'https://example.invalid/audio'];
 }
};
$assert(SupervisionVentasAudioCdrPolicy::candidata($call),'09:45 debe ofrecer consulta CDR');
$out=SupervisionLlamadasAudioService::resolverCdrPendiente($call,$provider,$stats,$now);
$assert($out['disponible']===true&&$out['segmentos']===1,'Audio verificable en Supervisión');
$assert(($out['segmentos_info'][0]['segundos']??null)===201,'Conservar duración CDR de 201 segundos');
$assert($provider->calls===['1439981389.2702773'],'Consultar solo call_id exacto');
$assert(count($out['records'])===1,'Un solo tramo propio');

foreach([
 ['otro asesor',['usuario_id'=>0]],
 ['otra área',['area'=>'analistas']],
 ['otra fuente',['source'=>'sesion']],
 ['entrante',['direccion'=>'Entrante']],
 ['ya finalizada',['finalizada'=>true]],
 ['sin ANSWER',['conectada'=>false]],
 ['resultado ausente',['resultado_guardado'=>false]],
 ['buzón',['resultado'=>'BUZON_VOZ']],
 ['sin transferencia permitida',['origen_extension'=>'100']],
 ['solicitud transferida',['solicitud_id'=>4]],
 ['ref incompleta',['pbx_call_id'=>'out_']],
] as $case){
 $invalid=array_replace($call,$case[1]);
 $assert(!SupervisionVentasAudioCdrPolicy::candidata($invalid),'Rechazar: '.$case[0]);
 try {
     SupervisionLlamadasAudioService::resolverCdrPendiente($invalid,$provider,$stats,$now);
     throw new RuntimeException('No se rechazó '.$case[0]);
 }catch(DomainException $e){}
}
$invalidStat=array_replace($stat,['sip'=>150]);
$notOwn=SupervisionLlamadasAudioService::resolverCdrPendiente(
 $call,$provider,static fn($desde,$hasta)=>[$invalidStat],$now);
$assert(!$notOwn['disponible']&&$notOwn['segmentos']===0,'No consultar grabaciones de otra extensión');
$assert(count($provider->calls)===1,'La validación errónea no llamó al proveedor');

$providerPending=new class{
 public function obtenerGrabacionPorCallId(string $id):?array{return null;}
};
$pending=SupervisionLlamadasAudioService::resolverCdrPendiente($call,$providerPending,$stats,$now);
$assert(!$pending['disponible']&&$pending['estado']==='GRABACION_EN_PREPARACION',
 'Audio todavía no disponible no debe anunciarse como reproducible');

$providerError=new class{
 public function obtenerGrabacionPorCallId(string $id):?array{throw new RuntimeException('Zadarma');}
};
$error=SupervisionLlamadasAudioService::resolverCdrPendiente($call,$providerError,$stats,$now);
$assert(!$error['disponible']&&$error['estado']==='ERROR_TEMPORAL','Error de Zadarma no concede audio');
echo "PASS: Supervisión 09:45, 201s, un tramo propio, controles de permisos, proveedor y rechazos.\\n";
