<?php
require __DIR__.'/../app/services/InteraccionProximaAccion.php';
require __DIR__.'/../app/services/SeguimientoFlujoService.php';
date_default_timezone_set('America/Mexico_City');
$checks=0;
function check($v,$m){global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function rejects($input){try{InteraccionProximaAccion::validar($input,strtotime('2030-01-01 10:00:00'));}catch(DomainException $e){check(true,$e->getMessage());return;}throw new RuntimeException('Entrada inválida aceptada');}
$callback=['resultado'=>'SOLICITO_LLAMAR_DESPUES','proxima_accion'=>'Volver a llamar','fecha_inicio'=>'2030-01-01T09:00'];
rejects($callback);
foreach(['','2030-01-01T09:00','2030-02-30T11:00','2030-13-01T11:00','texto'] as $date)rejects($callback+['proxima_accion_at'=>$date]);
$valid=$callback+['proxima_accion_at'=>'2030-01-02T11:30'];
check(InteraccionProximaAccion::validar($valid,1893513600)['fecha']==='2030-01-02 11:30:00','Fecha de México normalizada');
rejects(array_replace($valid,['proxima_accion'=>'Enviar WhatsApp']));
foreach(['Volver a llamar','Enviar WhatsApp','Confirmar contacto de RH','Revisar correo','Investigar nuevo contacto','Enviar oficio/correo'] as $action){
 $data=['resultado'=>'OTRO','proxima_accion'=>$action,'proxima_accion_at'=>'2030-01-02T11:30'];
 check(InteraccionProximaAccion::validar($data,strtotime('2030-01-01'))['accion']===$action,'Acción programable');
 rejects(array_replace($data,['proxima_accion_at'=>'']));
}
rejects(['resultado'=>'OTRO','proxima_accion_at'=>'2030-01-02T11:30']);
rejects(['resultado'=>'OTRO','proxima_accion'=>'<script>']);
rejects(['resultado'=>'OTRO','proxima_accion_at'=>['valor']]);
check(InteraccionProximaAccion::validar(['resultado'=>'NO_INTERESADO']+$valid)['fecha']===null,'No interesado no programa ni exige fecha');
check(InteraccionProximaAccion::validar(['resultado'=>'CONTACTO_CORRECTO'])['fecha']===null,'Contacto sin acuerdo no inventa agenda');
$r=new ReflectionClass(SeguimientoFlujoService::class);$svc=$r->newInstanceWithoutConstructor();$method=$r->getMethod('resolverFlujo');$method->setAccessible(true);
$record=['id'=>1,'estado_seguimiento'=>'DATOS_VERIFICADOS','datos_verificados'=>1,'proxima_accion_at'=>'2030-01-02 11:30:00'];
check($method->invoke($svc,$record)['contexto']['proxima_accion_at']==='','El ciclo antiguo continúa cerrado');
check($method->invoke($svc,$record+['proxima_accion_programada'=>1])['contexto']['proxima_accion_at']==='2030-01-02 11:30:00','Programación nueva visible sin perder verificación');
echo "PASS $checks reglas: programación, fechas, resultados y ruta verificada\n";
