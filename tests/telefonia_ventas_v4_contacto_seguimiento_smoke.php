<?php
// Ejecutar sin MySQL ni centralita: clasificaciones reales de Hostinger v4.
require_once __DIR__.'/../app/services/TelefoniaResultadoVentasCatalogo.php';
function prueba($ok,$mensaje){if(!$ok)throw new RuntimeException('TEST: '.$mensaje);}
function elegir($codes,$fecha=null,$contacto=null){
    return TelefoniaResultadoVentasCatalogo::normalizarSeleccion([
        'catalogo_version'=>'4',
        'resultados_seleccionados'=>json_encode($codes),
        'proxima_accion_at'=>$fecha,'contacto_persona'=>$contacto
    ]);
}
function bloqueado($codes,$fecha=null,$contacto=null){
    try{elegir($codes,$fecha,$contacto);return false;}
    catch(InvalidArgumentException $e){return true;}
}
$op=TelefoniaResultadoVentasCatalogo::opcionesSeleccion();
prueba(TelefoniaResultadoVentasCatalogo::VERSION==='4','Versión de Hostinger v4 conservada');
prueba(($op['CONVERSACION_PERSONA']??'')==='Se habló con el prospecto','nueva opción conversación');
prueba(($op['REQUIERE_SEGUIMIENTO']??'')==='En seguimiento','nueva opción seguimiento');
foreach(['BUZON_VOZ','NUMERO_INEXISTENTE','NO_INTERESADO','PROSPECTO_POTENCIAL',
'FUERA_PRESUPUESTO','WHATSAPP','SMS','INSCRITO','NO_CUMPLE_REQUISITOS',
'NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA','SIN_RESPUESTA','COMUNICACION_INTERRUMPIDA'] as $code){
    prueba(isset($op[$code]),'Preservar opción anterior: '.$code);
}
prueba(count($op)===15,'13 opciones anteriores y dos nuevas');
prueba(TelefoniaResultadoVentasCatalogo::conPersona('CONVERSACION_PERSONA'),'Se habló exige PBX conectado');
prueba(TelefoniaResultadoVentasCatalogo::conPersona('REQUIERE_SEGUIMIENTO'),'Seguimiento exige PBX conectado');
prueba(!TelefoniaResultadoVentasCatalogo::conPersona('WHATSAPP'),'Mensaje no acredita conversación');
prueba(elegir(['CONVERSACION_PERSONA'])['resultado']==='CONVERSACION_PERSONA','conversación');
prueba(elegir(['REQUIERE_SEGUIMIENTO'],'2027-12-31T15:00')['resultado']==='REQUIERE_SEGUIMIENTO','seguimiento');
prueba(bloqueado(['REQUIERE_SEGUIMIENTO']),'seguimiento requiere próxima fecha');
prueba(bloqueado(['REQUIERE_SEGUIMIENTO','SIN_RESPUESTA'],'2027-12-31T15:00'),'no seguimiento sin respuesta');
prueba(bloqueado(['REQUIERE_SEGUIMIENTO','NO_INTERESADO'],'2027-12-31T15:00'),'no seguimiento + no interesado');
foreach([['CONVERSACION_PERSONA','REQUIERE_SEGUIMIENTO'],
         ['REQUIERE_SEGUIMIENTO','CONVERSACION_PERSONA'],
         ['PROSPECTO_POTENCIAL','REQUIERE_SEGUIMIENTO']] as $par){
    $x=elegir($par,'2027-12-31T15:00');
    prueba($x['resultado']==='REQUIERE_SEGUIMIENTO','seguimiento prevalece sobre contacto');
    prueba($x['resultados_seleccionados']===$par,'conservar dos selecciones y orden original');
}
foreach([['CONVERSACION_PERSONA','PROSPECTO_POTENCIAL'],
         ['PROSPECTO_POTENCIAL','CONVERSACION_PERSONA']] as $par){
    prueba(elegir($par)['resultado']==='PROSPECTO_POTENCIAL','específico prevalece sobre conversación');
}
prueba(elegir(['WHATSAPP'],'','SI')['resultado']==='CONVERSACION_PERSONA','conversación informada con solo WhatsApp v4');
prueba(elegir(['WHATSAPP'],'','NO')['resultado']==='ENVIO_WHATSAPP','WhatsApp sin contacto v4');
prueba(elegir(['WHATSAPP','SMS'],'','NO')['resultado']==='ENVIO_WHATSAPP_SMS','doble envío sin contacto v4');
prueba(bloqueado(['WHATSAPP'],'',null),'pregunta v4 de contacto sigue obligatoria');
prueba(elegir(['CONVERSACION_PERSONA','WHATSAPP'])['envio_informacion']==='WHATSAPP','selección contacto + mensaje');
prueba(elegir(['SIN_RESPUESTA','WHATSAPP'])['resultado']==='SIN_RESPUESTA','sin respuesta + WhatsApp');
prueba(bloqueado(['CONVERSACION_PERSONA','SIN_RESPUESTA']),'contradicción humana');
prueba(bloqueado(['CONVERSACION_PERSONA','CONVERSACION_PERSONA']),'sin duplicaciones');
prueba(bloqueado(['CONVERSACION_PERSONA','WHATSAPP','SMS']),'máximo dos');
prueba(method_exists('TelefoniaResultadoVentasCatalogo','completarRegistros'),'consulta histórica v4 conservada');
prueba(method_exists('TelefoniaResultadoVentasCatalogo','opcionesFiltro'),'filtros históricos v4 conservados');
prueba(method_exists('TelefoniaResultadoVentasCatalogo','etiquetasRegistro'),'etiquetas múltiples v4 conservadas');
prueba(method_exists('TelefoniaResultadoVentasCatalogo','textoRegistro'),'historial legible v4 conservado');
prueba(method_exists('TelefoniaResultadoVentasCatalogo','coincideRegistro'),'reportes por resultado v4 conservados');
echo "CHECK OK: 13 opciones originales, 2 nuevas, contacto v4, doble selección, agenda y lectura histórica.\n";
