<?php
/**
 * Comprobación sin MySQL del método real de capturabilidad.
 * Extrae solo la función pura, sin cargar la conexión ni servicios del CRM.
 */
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../app/services/TelefoniaVentasLlamadasService.php');
if(!$source)throw new RuntimeException('Servicio no disponible.');
$start=strpos($source,'public static function resultadoCapturable(array $call): bool {');
if($start===false)throw new RuntimeException('No existe método de capturabilidad.');
$end=strpos($source,"\n    }\n",$start);
if($end===false)throw new RuntimeException('No se pudo delimitar el método.');
$method=substr($source,$start,$end+7-$start);
eval('final class TelefoniaVentasCapturableAislado {'.$method.'}');
$base=['pbx_call_id'=>'PBX_TEST_151','direccion'=>'Saliente','registro'=>null,
    'finalizada'=>true,'conectada'=>false,'extension'=>'151','solicitud_id'=>null,
    'origen_extension'=>''];
$assert=static function($condition,$description){
    if(!$condition)throw new RuntimeException('ERROR: '.$description);
};
foreach(['Cancelada','Ocupada','Sin respuesta'] as $estado){
    $fila=$base+['estado_pbx'=>$estado];
    $assert(!TelefoniaVentasCapturableAislado::resultadoCapturable($fila),
        'No pedir resultado por saliente '.$estado);
}
$confirmada=$base;$confirmada['conectada']=true;
$assert(TelefoniaVentasCapturableAislado::resultadoCapturable($confirmada),
    'Una saliente conectada debe permitir resultado.');
$yaGuardada=$confirmada;$yaGuardada['registro']=['resultado'=>'CONVERSACION'];
$assert(!TelefoniaVentasCapturableAislado::resultadoCapturable($yaGuardada),
    'Resultado guardado no debe quedar pendiente.');
$entrante=$base;$entrante['direccion']='Entrante';$entrante['conectada']=false;
$assert(TelefoniaVentasCapturableAislado::resultadoCapturable($entrante),
    'El criterio de entrantes sigue intacto.');
$transferida=$entrante;$transferida['finalizada']=false;$transferida['conectada']=true;
$transferida['solicitud_id']=32;$transferida['origen_extension']='100';
$assert(TelefoniaVentasCapturableAislado::resultadoCapturable($transferida),
    'La transferencia de Recepción mantiene su captura anticipada.');
$vista=file_get_contents(__DIR__.'/../app/views/telefonia/ventas_llamadas.php');
$assert(strpos($vista,'TelefoniaVentasLlamadasService::resultadoCapturable($call)')!==false,
    'La vista debe usar la regla de capturabilidad.');
echo "PASS: canceladas/ocupadas/sin respuesta no requieren resultado; conectadas y entrantes conservadas.\n";
