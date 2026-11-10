<?php
// Catálogo aislado: no conecta a MySQL, Telefonía ni a una centralita.
require_once __DIR__.'/../app/services/TelefoniaResultadoVentasCatalogo.php';

function assertVentas($ok, $mensaje) {
    if (!$ok) throw new RuntimeException('ERROR: '.$mensaje);
}
function seleccionVentas(array $codes, ?string $fecha=null): array {
    return TelefoniaResultadoVentasCatalogo::normalizarSeleccion([
        'resultados_seleccionados'=>json_encode($codes,JSON_UNESCAPED_UNICODE),
        'proxima_accion_at'=>$fecha
    ]);
}
function rechazarVentas(array $codes,?string $fecha,$mensaje) {
    $blocked=false;
    try{ seleccionVentas($codes,$fecha); }
    catch(InvalidArgumentException $e){$blocked=true;}
    assertVentas($blocked,$mensaje);
}

assertVentas(TelefoniaResultadoVentasCatalogo::VERSION==='3','versión compatible con formularios guardados');
$opciones=TelefoniaResultadoVentasCatalogo::opcionesSeleccion();
assertVentas(($opciones['CONVERSACION_PERSONA']??null)==='Se habló con el prospecto','etiqueta contacto');
assertVentas(($opciones['REQUIERE_SEGUIMIENTO']??null)==='En seguimiento','etiqueta seguimiento');
foreach(['BUZON_VOZ','NUMERO_INEXISTENTE','NO_INTERESADO','PROSPECTO_POTENCIAL',
    'FUERA_PRESUPUESTO','WHATSAPP','SMS','INSCRITO',
    'NO_CUMPLE_REQUISITOS','NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA',
    'SIN_RESPUESTA','COMUNICACION_INTERRUMPIDA'] as $code) {
    assertVentas(isset($opciones[$code]),'resultado anterior intacto '.$code);
}
assertVentas(count($opciones)===15,'13 resultados anteriores + 2 nuevos');
assertVentas(TelefoniaResultadoVentasCatalogo::conPersona('CONVERSACION_PERSONA'),'conversación humana');
assertVentas(TelefoniaResultadoVentasCatalogo::conPersona('REQUIERE_SEGUIMIENTO'),'seguimiento humano');
assertVentas(!TelefoniaResultadoVentasCatalogo::conPersona('WHATSAPP'),'WhatsApp no prueba conversación');
assertVentas(!TelefoniaResultadoVentasCatalogo::conPersona('SIN_RESPUESTA'),'sin respuesta no es contacto');
assertVentas(seleccionVentas(['CONVERSACION_PERSONA'])['resultado']==='CONVERSACION_PERSONA',
    'conversación a Contactados');
assertVentas(seleccionVentas(['REQUIERE_SEGUIMIENTO'],'2026-11-12T16:00')['resultado']==='REQUIERE_SEGUIMIENTO',
    'seguimiento requiere fecha');
foreach([['CONVERSACION_PERSONA','REQUIERE_SEGUIMIENTO'],
         ['REQUIERE_SEGUIMIENTO','CONVERSACION_PERSONA'],
         ['PROSPECTO_POTENCIAL','REQUIERE_SEGUIMIENTO']] as $par) {
    $out=seleccionVentas($par,'2026-11-12T16:00');
    assertVentas($out['resultado']==='REQUIERE_SEGUIMIENTO','seguimiento prioritario sin importar orden');
    assertVentas($out['resultados_seleccionados']===$par,'se guardan ambos resultados originales');
    assertVentas($out['contacto_humano']===true,'se acredita contacto declarado');
}
foreach([['CONVERSACION_PERSONA','PROSPECTO_POTENCIAL'],
         ['PROSPECTO_POTENCIAL','CONVERSACION_PERSONA']] as $par) {
    assertVentas(seleccionVentas($par)['resultado']==='PROSPECTO_POTENCIAL',
        'resultado específico no se pierde por conversación genérica');
}
assertVentas(seleccionVentas(['CONVERSACION_PERSONA','NO_INTERESADO'])['resultado']==='NO_INTERESADO',
    'no interesado no se convierte en contactado');
assertVentas(seleccionVentas(['CONVERSACION_PERSONA','WHATSAPP'])['envio_informacion']==='WHATSAPP',
    'conversación + WhatsApp: conservar envío');
assertVentas(seleccionVentas(['SIN_RESPUESTA','WHATSAPP'])['resultado']==='SIN_RESPUESTA',
    'sin respuesta + WhatsApp no inventa contacto');
assertVentas(seleccionVentas(['WHATSAPP','SMS'])['resultado']==='ENVIO_WHATSAPP_SMS',
    'canales solos no prueban conversación');
rechazarVentas(['REQUIERE_SEGUIMIENTO'],null,'seguimiento sin fecha rechazado');
rechazarVentas(['REQUIERE_SEGUIMIENTO','NO_INTERESADO'],'2026-11-12T16:00','seguimiento contradictorio');
rechazarVentas(['REQUIERE_SEGUIMIENTO','NO_CUMPLE_REQUISITOS'],'2026-11-12T16:00','sin requisitos');
rechazarVentas(['REQUIERE_SEGUIMIENTO','SIN_RESPUESTA'],'2026-11-12T16:00','sin respuesta + seguimiento');
rechazarVentas(['CONVERSACION_PERSONA','SIN_RESPUESTA'],null,'contacto + sin respuesta');
rechazarVentas(['PROSPECTO_POTENCIAL','NO_INTERESADO'],null,'interés y rechazo');
rechazarVentas(['CONVERSACION_PERSONA','CONVERSACION_PERSONA'],null,'no duplicar');
rechazarVentas(['CONVERSACION_PERSONA','WHATSAPP','SMS'],null,'máximo dos');
echo "CHECK OK: dos opciones nuevas, 13 anteriores, seguimiento prioritario, fecha y contradicciones.\n";
