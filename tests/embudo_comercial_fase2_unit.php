<?php
// Pruebas de decisiones puras: no requieren base de datos ni telefonía real.
$_SESSION=['usuario_id'=>42,'rol_id'=>3,'permisos'=>[
    'ventas.embudo.ver','ventas.embudo.mover',
    'ventas.recuperacion.gestionar','ventas.descartados.gestionar'
]];
require_once __DIR__.'/../app/services/EmbudoComercialEtapas.php';
require_once __DIR__.'/../app/services/EmbudoComercialMovimientoService.php';
require_once __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
function assertTrue($ok,$mensaje){if (!$ok) throw new RuntimeException('TEST FALLIDO: '.$mensaje);}
function rejects(callable $fn): bool {
    try {$fn();return false;} catch(DomainException $e){return true;}
}
assertTrue(count(EmbudoComercialEtapas::ACTIVAS)===5,'cinco etapas');
assertTrue(!isset(EmbudoComercialEtapas::ACTIVAS['EN_CONTACTO']),'En contacto retirada');
assertTrue(!isset(EmbudoComercialEtapas::ACTIVAS['INSCRITOS']),'Inscritos sigue fuera');
assertTrue(EmbudoComercialEtapas::visual('EN_CONTACTO')==='RECUPERACION','compatibilidad legacy');
foreach(['SIN_RESPUESTA','OCUPADO','BUZON_VOZ','MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO'] as $cod) {
    assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado($cod,null)==='RECUPERACION',
        'sin respuesta conduce a recuperación: '.$cod);
}
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('CONVERSACION_PERSONA',null)==='CONTACTADO',
    'conversación comprobada');
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('INTERESADO',null)==='INTERESADO','interés');
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('SOLICITA_INFORMACION',null)==='INTERESADO',
    'solicita información');
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('PROSPECTO_POTENCIAL',null)==='INTERESADO',
    'opción V4 Prospecto potencial mueve a Interesados');
foreach(['NO_INTERESADO','FUERA_PRESUPUESTO','INSCRITO','NO_CUMPLE_REQUISITOS',
         'NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA','WHATSAPP','SMS'] as $codigoV4) {
    assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado($codigoV4,null)===null,
        'opción V4 distinta no se convierte en interés: '.$codigoV4);
}
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('REQUIERE_SEGUIMIENTO',null)===null,
    'seguimiento necesita fecha');
assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado('REQUIERE_SEGUIMIENTO','2027-12-31 12:00:00')==='SEGUIMIENTO',
    'seguimiento con fecha');
foreach(['NO_INTERESADO','NUMERO_INEXISTENTE','FUERA_HORARIO',
         'COMUNICACION_INTERRUMPIDA','OTRO','ANSWERED'] as $cod) {
    assertTrue(EmbudoComercialEtapas::sugerenciaPorResultado($cod,null)===null,
        'no inferir descartado/contacto de resultado ambiguo: '.$cod);
}
assertTrue(EmbudoComercialMovimientoService::permisosMovimiento('NUEVO','RECUPERACION')===
    ['ventas.embudo.mover','ventas.recuperacion.gestionar'],'permiso separado de recuperación');
assertTrue(EmbudoComercialMovimientoService::permisosMovimiento('NUEVO','DESCARTADO')===
    ['ventas.embudo.mover','ventas.descartados.gestionar'],'permiso separado de descarte');
assertTrue(rejects(function(){
    EmbudoComercialMovimientoService::validarPropuesta('NUEVO','PAGO_CONFIRMADO','Ya pagó','');
}),'ningún arrastre confirma pago');
assertTrue(rejects(function(){
    EmbudoComercialMovimientoService::validarPropuesta('NUEVO','CONTACTADO','','');
}),'contacto manual requiere evidencia escrita');
assertTrue(rejects(function(){
    EmbudoComercialMovimientoService::validarPropuesta('NUEVO','SEGUIMIENTO','','');
}),'seguimiento manual requiere fecha');
assertTrue(rejects(function(){
    EmbudoComercialMovimientoService::validarPropuesta('DESCARTADO','CONTACTADO',
        'quiero reactivarlo','');
}),'descartado no se reactiva automáticamente');
assertTrue(rejects(function(){
    EmbudoComercialMovimientoService::fechaSeguimientoUtc('2025-11-06T12:00');
}),'no aceptar fecha pasada');
assertTrue(EmbudoComercialResultadoBridge::numeroCanonico('+52 (777) 123-4567')==='527771234567',
    'normalizar sólo separadores');
assertTrue(EmbudoComercialResultadoBridge::numeroCanonico('12345')==='','evitar números cortos');
echo "CHECK OK: 5 etapas, resultado telefónico, recuperación, evidencia, permisos y pago restringido.\n";
