<?php
declare(strict_types=1);
// Regresión de reglas de cierre sin conexión DB ni llamadas externas.
// Ejecutar: php tests/telefonia_recepcion_transferencia_resultado_smoke.php
require_once dirname(__DIR__).'/app/services/TelefoniaRecepcionMarketingService.php';
$cases=[
 ['entrante_transferida', ['tipo'=>'Entrante','estado'=>'Transferida','finalizada'=>true,'resultado'=>'','atendida'=>true,'estado_cierre'=>'Atendida'],false],
 ['saliente_transferida', ['tipo'=>'Saliente','estado'=>'Transferida','finalizada'=>true,'resultado'=>'','atendida'=>true,'estado_cierre'=>'Atendida'],false],
 ['saliente_atendida_sin_resultado', ['tipo'=>'Saliente','estado'=>'Atendida','finalizada'=>true,'resultado'=>'','atendida'=>true,'estado_cierre'=>'Atendida'],true],
 ['entrante_atendida_sin_resultado', ['tipo'=>'Entrante','estado'=>'Atendida','finalizada'=>true,'resultado'=>'','atendida'=>true,'estado_cierre'=>'Atendida'],true],
 ['entrante_perdida', ['tipo'=>'Entrante','estado'=>'Perdida','finalizada'=>true,'resultado'=>'','atendida'=>false,'estado_cierre'=>'Sin respuesta'],false],
 ['resultado_ya_guardado', ['tipo'=>'Saliente','estado'=>'Atendida','finalizada'=>true,'resultado'=>'CONTACTO','atendida'=>true,'estado_cierre'=>'Atendida'],false],
];
foreach($cases as [$name,$call,$expected]) {
 $actual=TelefoniaRecepcionMarketingService::requiereResultado($call);
 if($actual!==$expected){fwrite(STDERR,"ERROR: $name\n");exit(1);}
}
echo "OK: transferencias entrantes y salientes, pendientes y perdidas\n";
