<?php
require_once __DIR__.'/../app/services/TelefoniaResultadoAnalistaService.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function rechaza(callable $fn) { try {$fn();} catch (DomainException $e) {return;} throw new RuntimeException('Debía rechazar la captura'); }
$ref='out_'.str_repeat('a',32); $otro='out_'.str_repeat('b',32);
$inicio=['event'=>'NOTIFY_OUT_START','internal'=>'150','destination'=>'527771234567','pbx_call_id'=>$ref];
$fin=['event'=>'NOTIFY_OUT_END','internal'=>'150','pbx_call_id'=>$ref];
$answer=['event'=>'NOTIFY_ANSWER','internal'=>'150','pbx_call_id'=>$ref];
$humanos=['CONTACTO_CORRECTO','CONTACTO_REFERIDO','CONTACTO_INCORRECTO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'];
foreach (['busy'=>'OCUPADO','no answer'=>'SIN_RESPUESTA','no_answer'=>'SIN_RESPUESTA','cancelled'=>'OTRO','failed'=>'OTRO'] as $estado=>$permitido) {
    $reglas=TelefoniaResultadoAnalistaService::reglasEventos([$inicio,$fin+['disposition'=>$estado]],$ref,'150',['7771234567']);
    check(!$reglas['permite_verificar'],'Un fallo no acredita respuesta');
    TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>$permitido]);
    foreach ($humanos as $resultado) rechaza(fn()=>TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>$resultado]));
    rechaza(fn()=>TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>$permitido,'verificacion_telefono_confirmado'=>1]));
}
$reglas=TelefoniaResultadoAnalistaService::reglasEventos([$inicio,$answer,$fin+['disposition'=>'answered']],$ref,'150',['+52 1 777 123 4567']);
foreach ($humanos as $resultado) TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>$resultado]);
TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'CONTACTO_CORRECTO','verificacion_telefono_confirmado'=>1]);
TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'BUZON_VOZ']);
rechaza(fn()=>TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'BUZON_VOZ','verificacion_correo_confirmado'=>1]));
rechaza(fn()=>TelefoniaResultadoAnalistaService::validarCaptura($reglas,['resultado'=>'CONTACTO_INCORRECTO','verificacion_contacto_confirmado'=>1]));
foreach ([[$inicio],[$fin],[$inicio,$answer],[$inicio,array_replace($fin,['pbx_call_id'=>$otro])],[$inicio,array_replace($fin,['internal'=>'151'])]] as $eventos) {
    rechaza(fn()=>TelefoniaResultadoAnalistaService::reglasEventos($eventos,$ref,'150',['7771234567']));
}
rechaza(fn()=>TelefoniaResultadoAnalistaService::reglasEventos([$inicio,$fin],$ref,'151',['7771234567']));
rechaza(fn()=>TelefoniaResultadoAnalistaService::reglasEventos([$inicio,$fin],$ref,'150',['7779999999']));
// La duración de cliente y un estado completed inventado no son evidencia PBX.
$sinRespuesta=TelefoniaResultadoAnalistaService::reglasEventos([$inicio,$fin+['disposition'=>'busy','duration'=>99]],$ref,'150',['7771234567']);
rechaza(fn()=>TelefoniaResultadoAnalistaService::validarCaptura($sinRespuesta,['resultado'=>'CONTACTO_CORRECTO','status'=>'completed','duration_client'=>900]));
TelefoniaResultadoAnalistaService::validarLlamada(1,1,['canal'=>'CORREO','origen_llamada'=>'MANUAL']);
rechaza(fn()=>TelefoniaResultadoAnalistaService::validarLlamada(1,1,['canal'=>'CORREO','origen_llamada'=>'ZADARMA','llamada_pbx_id'=>$ref]));
rechaza(fn()=>TelefoniaResultadoAnalistaService::validarLlamada(1,1,['canal'=>'LLAMADA','origen_llamada'=>'ZADARMA']));
echo "PASS: ocupada/sin respuesta/cancelada/fallida; conversación/buzón; verificaciones; identidad exacta; pendientes; duración manipulada; canales manuales.\n";
