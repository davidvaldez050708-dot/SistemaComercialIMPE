<?php
/** Prueba de la clasificación del repositorio personalizado sin usar mysqli. */
declare(strict_types=1);
$code=file_get_contents(__DIR__.'/../app/services/SupervisionLlamadasRepository.php');
if(!is_string($code)||$code==='')throw new RuntimeException('Falta el repositorio.');
$start=strpos($code,'public static function resultadoAuditable(');
$end=strpos($code,'    private function normalizar(', $start);
if($start===false||$end===false||$end<=$start)throw new RuntimeException('Método de resultado no encontrado.');
eval('final class SupervisionResultadoIsolado { '.substr($code,$start,$end-$start).' }');
$cases=[
 ['venta','Saliente',true,false,'',false,'No requiere resultado'],
 ['venta','Saliente',true,true,'',false,'Sin resultado registrado'],
 ['venta','Saliente',false,false,'',false,'Sin resultado confirmado'],
 ['venta','Saliente',true,false,'CONTACTADO',true,'Resultado registrado'],
 ['venta','Entrante',true,false,'',false,'Sin resultado confirmado'],
 ['venta','Entrante',true,true,'',false,'Sin resultado registrado'],
 ['interaccion','Saliente',true,false,'',false,'Sin resultado confirmado'],
 ['interaccion','Entrante',true,true,'CONTACTADO',true,'Resultado registrado'],
 ['sesion','Entrante',false,false,'',false,'Sin resultado confirmado']
];
foreach($cases as [$source,$direction,$final,$connected,$result,$saved,$state]){
 $actual=SupervisionResultadoIsolado::resultadoAuditable($source,$direction,$final,$connected,$result);
 if($actual!==['guardado'=>$saved,'estado'=>$state])
   throw new RuntimeException('Incorrecto: '.json_encode([$source,$direction,$final,$connected,$result,$actual]));
}
foreach([
 "'usuario_id'=>\$uid",
 "'resultado_guardado'=>\$resultadoGuardado",
 "'resultado_estado'=>\$resultadoEstado",
 "'estado_pbx'=>\$estadoPbx",
 "TelefoniaVentasLlamadasService::hechos",
 "'telefonia_ventas_resultados'",
 "['usuario_id'=>\$uid,'extension'=>\$ext,'pbx_call_id'=>\$ref]",
 "TelefoniaVentasEvidenciaService",
 "private const SOURCES",
 "public function acceso"
] as $fragment)if(strpos($code,$fragment)===false)throw new RuntimeException('Falta protección original: '.$fragment);
echo "PASS: 9 escenarios sin BD y conservación de permisos y atribución del repositorio.\n";
