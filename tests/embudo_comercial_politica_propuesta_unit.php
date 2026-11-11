<?php
/**
 * Fase inicial: contrato propuesto SIN conexión a BD/telefonía/Hostinger.
 * No cambiar las expectativas de las suites productivas hasta la migración.
 */
require_once __DIR__.'/../app/services/EmbudoComercialPoliticaPropuesta.php';

function verifica($condicion, string $explicacion): void {
    if (!$condicion) throw new RuntimeException('FALLO MATRIZ: '.$explicacion);
}
function igual($actual, $esperado, string $explicacion): void {
    if ($actual !== $esperado) {
        throw new RuntimeException(
            'FALLO MATRIZ: '.$explicacion.
            ' [esperado '.var_export($esperado, true).
            ', actual '.var_export($actual, true).']'
        );
    }
}
function llamada(string $etapa, string $resultado, int $intentos=0, array $datos=[]): array {
    return EmbudoComercialPoliticaPropuesta::evaluarLlamada($etapa,$resultado,$intentos,$datos);
}
function errorDominio(callable $test): bool {
    try { $test(); return false; } catch (DomainException $e) { return true; }
}
function errorArgumento(callable $test): bool {
    try { $test(); return false; } catch (InvalidArgumentException $e) { return true; }
}

$p='EmbudoComercialPoliticaPropuesta';
igual(count($p::ETAPAS_ACTIVAS),5,'solo 5 columnas activas');
igual(array_keys($p::ETAPAS_ACTIVAS),[
    'NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO','PROCESO_INSCRIPCION'
],'orden pactado de columnas');
igual(array_keys($p::BANDEJAS),['RECUPERACION','DESCARTADO'],'bandejas externas');
verifica(!isset($p::ETAPAS_ACTIVAS['INSCRITOS']),'Inscritos no se inventa como etapa');
verifica(!isset($p::ETAPAS_ACTIVAS['PAGO_CONFIRMADO']),'Pago no es etapa comercial nueva');
foreach ($p::ETAPAS_ACTIVAS+$p::BANDEJAS as $codigo=>$etiqueta) {
    verifica(isset($p::SUBETAPAS[$codigo]) && count($p::SUBETAPAS[$codigo])>0,
        'subetapas propias para '.$codigo);
    foreach ($p::SUBETAPAS[$codigo] as $sub=>$texto) {
        verifica($p::subetapaValida($codigo,$sub),'subetapa válida '.$sub);
    }
}
verifica(!$p::subetapaValida('CONTACTADO','PAGO_VERIFICADO'),
    'impedir subetapas prestadas de otras etapas');
verifica(errorDominio(function(){llamada('INTERESADO','SIN_RESPUESTA',1);}),
    'bloquear transición automática desde legado INTERESADO');
verifica(errorDominio(function(){llamada('PAGO_CONFIRMADO','CONVERSACION_PERSONA',0);}),
    'bloquear interpretación de PAGO_CONFIRMADO como Proceso Inscripción');
verifica(errorArgumento(function(){llamada('NUEVO','SIN_RESPUESTA',-1);}),
    'rechazar contador negativo');
verifica(errorDominio(function(){llamada('NUEVO','CODIGO_DESCONOCIDO',0);}),
    'rechazar resultado no definido');

// Tres intentos acreditados durante la MISMA jornada: no se cuenta el
// número de clics y no se cambian teléfonos, registros ni grabaciones.
foreach (['SIN_RESPUESTA','BUZON_VOZ','OCUPADO',
          'MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO'] as $sinContacto) {
    $uno=llamada('NUEVO',$sinContacto,1);
    igual($uno['etapa_propuesta'],'NUEVO','intento uno conserva Nuevos '.$sinContacto);
    igual($uno['subetapa_propuesta'],'INTENTO_1','primer intento '.$sinContacto);
    igual($uno['accion'],'PROGRAMAR_SIGUIENTE_INTENTO','primero pendiente');
    $dos=llamada('NUEVO',$sinContacto,2);
    igual($dos['etapa_propuesta'],'NUEVO','segundo conserva Nuevos');
    igual($dos['subetapa_propuesta'],'INTENTO_2','segundo intento');
    $tres=llamada('NUEVO',$sinContacto,3);
    igual($tres['etapa_propuesta'],'RECUPERACION','tres intentos reales '.$sinContacto);
    igual($tres['subetapa_propuesta'],'PENDIENTE_REVISION','revisión en recuperación');
    igual($tres['accion'],'REVISAR_REMARKETING_DIA_SIGUIENTE','remarketing no automático');
}
igual(llamada('POTENCIAL','SIN_RESPUESTA',3,['subetapa'=>'DOCUMENTACION_LISTA'])['etapa_propuesta'],
    'POTENCIAL','no degradar potencial con 3 llamadas sin respuesta');
igual(llamada('PROCESO_INSCRIPCION','BUZON_VOZ',3)['etapa_propuesta'],
    'PROCESO_INSCRIPCION','no degradar inscripción iniciada');
igual(llamada('CONTACTADO','SIN_RESPUESTA',3)['accion'],
    'REVISAR_TRES_INTENTOS_SIN_DEGRADAR','no degradar contacto previo automáticamente');

igual(llamada('NUEVO','CONVERSACION_PERSONA')['etapa_propuesta'],'CONTACTADO',
    'conversación real mueve a Contactados');
igual(llamada('RECUPERACION','CONVERSACION_PERSONA')['etapa_propuesta'],'CONTACTADO',
    'conversación termina recuperación');
foreach (['CONTACTADO','POTENCIAL','SEGUIMIENTO','PROCESO_INSCRIPCION'] as $etapa) {
    igual(llamada($etapa,'CONVERSACION_PERSONA')['etapa_propuesta'],$etapa,
        'conversación no regresa etapa avanzada '.$etapa);
}
igual(llamada('NUEVO','PROSPECTO_POTENCIAL',0)['etapa_propuesta'],
    'CONTACTADO','potencial sin validación solo queda Contactado');
igual(llamada('NUEVO','PROSPECTO_POTENCIAL',0,['calificacionConfirmada'=>true])['etapa_propuesta'],
    'POTENCIAL','calificación confirmada mueve a Potenciales');
igual(llamada('SEGUIMIENTO','PROSPECTO_POTENCIAL',0,['calificacionConfirmada'=>true])['etapa_propuesta'],
    'POTENCIAL','seguimiento correctamente calificado vuelve a Potenciales');
igual(llamada('PROCESO_INSCRIPCION','PROSPECTO_POTENCIAL',0,['calificacionConfirmada'=>true])['etapa_propuesta'],
    'PROCESO_INSCRIPCION','no regresión desde trámite');

igual(llamada('NUEVO','COMUNICACION_INTERRUMPIDA',1)['accion'],
    'ACLARAR_CONTACTO_ANTES_CORTE','corte exige pregunta humana');
igual(llamada('NUEVO','COMUNICACION_INTERRUMPIDA',3,['contactoAntesCorte'=>'NO'])['etapa_propuesta'],
    'RECUPERACION','corte sin conversación = tercer intento');
igual(llamada('NUEVO','COMUNICACION_INTERRUMPIDA',3,['contactoAntesCorte'=>'SI'])['etapa_propuesta'],
    'CONTACTADO','corte con conversación = contacto');
igual(llamada('POTENCIAL','COMUNICACION_INTERRUMPIDA',3,['contactoAntesCorte'=>'SI'])['etapa_propuesta'],
    'POTENCIAL','corte con conversación no regresa potencial');

foreach(['WHATSAPP','SMS','ENVIO_WHATSAPP_SMS'] as $complemento) {
    igual(llamada('NUEVO',$complemento,0)['accion'],
        'PREGUNTAR_SI_HUBO_CONVERSACION','solo complemento pregunta contacto');
    igual(llamada('NUEVO',$complemento,1,['contactoPersona'=>'SI'])['etapa_propuesta'],
        'CONTACTADO','complemento más conversación verificada');
    igual(llamada('NUEVO',$complemento,3,['contactoPersona'=>'NO'])['etapa_propuesta'],
        'RECUPERACION','sin persona, 3 intentos acreditados');
}
igual(llamada('POTENCIAL','WHATSAPP',0,['contactoPersona'=>'SI'])['etapa_propuesta'],
    'POTENCIAL','envío nunca degrada potencial');
igual(llamada('NUEVO','REQUIERE_SEGUIMIENTO',0)['accion'],
    'PROGRAMAR_FECHA_FUTURA','seguimiento exige fecha futura');
igual(llamada('CONTACTADO','REQUIERE_SEGUIMIENTO',0,['fechaAccionVerificada'=>true])['etapa_propuesta'],
    'CONTACTADO','próxima acción independiente de etapa');
igual(llamada('CONTACTADO','REQUIERE_SEGUIMIENTO',0,
    ['fechaAccionVerificada'=>true,'pendienteDecision'=>true])['etapa_propuesta'],
    'SEGUIMIENTO','decisión pendiente y cita sí mueve a Seguimiento');
igual(llamada('POTENCIAL','REQUIERE_SEGUIMIENTO',0,
    ['fechaAccionVerificada'=>true,'pendienteDecision'=>true])['etapa_propuesta'],
    'POTENCIAL','seguimiento no desclasifica potencial');
igual(llamada('PROCESO_INSCRIPCION','REQUIERE_SEGUIMIENTO',0,
    ['fechaAccionVerificada'=>true,'pendienteDecision'=>true])['etapa_propuesta'],
    'PROCESO_INSCRIPCION','tareas no sacan de inscripción');

igual(llamada('NUEVO','FUERA_PRESUPUESTO')['requiere_revision'],true,
    'presupuesto sin decisión necesita clasificación');
igual(llamada('CONTACTADO','FUERA_PRESUPUESTO',0,
    ['aceptaSeguimiento'=>true,'fechaAccionVerificada'=>true])['subetapa_propuesta'],
    'POR_PRESUPUESTO','presupuesto con cita');
igual(llamada('POTENCIAL','FUERA_PRESUPUESTO',0,
    ['aceptaSeguimiento'=>true,'fechaAccionVerificada'=>true])['subetapa_propuesta'],
    'PRESUPUESTO_PENDIENTE','presupuesto no quita categoría potencial');
igual(llamada('CONTACTADO','FUERA_PRESUPUESTO',0,
    ['rechazoDefinitivo'=>true])['etapa_propuesta'],
    'DESCARTADO','rechazo de presupuesto confirmado');

igual(llamada('POTENCIAL','SIGUIENTE_APERTURA')['accion'],
    'CONFIRMAR_APERTURA_Y_FECHA','no inventar fecha +2 meses');
igual(llamada('POTENCIAL','SIGUIENTE_APERTURA',0,
    ['aperturaConfirmada'=>true,'fechaAccionVerificada'=>true])['subetapa_propuesta'],
    'SIGUIENTE_APERTURA','siguiente apertura con fecha válida');
igual(llamada('PROCESO_INSCRIPCION','SIGUIENTE_APERTURA',0,
    ['aperturaConfirmada'=>true,'fechaAccionVerificada'=>true])['etapa_propuesta'],
    'PROCESO_INSCRIPCION','no abandonar trámite en curso');

igual(llamada('CONTACTADO','NO_INTERESADO')['etapa_propuesta'],
    'DESCARTADO','rechazo explícito va a Descartados');
igual(llamada('NUEVO','NUMERO_INEXISTENTE')['accion'],
    'VERIFICAR_OTRO_NUMERO','verificar si hay número alternativo');
igual(llamada('NUEVO','NUMERO_INEXISTENTE',0,
    ['numeroInvalidoConfirmado'=>true])['subetapa_propuesta'],
    'NUMERO_INVALIDO','número invalidado confirmado');
igual(llamada('PROCESO_INSCRIPCION','NUMERO_INEXISTENTE',0,
    ['numeroInvalidoConfirmado'=>true])['etapa_propuesta'],
    'PROCESO_INSCRIPCION','revisar número en trámite sin desechar');
igual(llamada('CONTACTADO','NO_CUMPLE_REQUISITOS')['requiere_revision'],true,
    'requisitos insuficientes necesitan revisar alternativa');
igual(llamada('CONTACTADO','NO_SOLICITO_INFORMACION')['requiere_revision'],true,
    'posible problema de consentimiento/origen');

// Seleccionar "Inscrito" en una llamada nunca crea una inscripción real.
foreach(['NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO',
         'PROCESO_INSCRIPCION','RECUPERACION'] as $etapa) {
    $out=llamada($etapa,'INSCRITO');
    igual($out['etapa_propuesta'],$etapa,'teléfono no acredita Inscritos');
    igual($out['accion'],'VALIDAR_INSCRIPCION_INSTITUCIONAL',
        'exigir módulo de Inscritos');
}
igual(llamada('DESCARTADO','CONVERSACION_PERSONA')['etapa_propuesta'],
    'DESCARTADO','no reactivar automáticamente descartados');

$sinEvidencia=$p::evaluarEventoInstitucional('POTENCIAL','TRAMITE_INICIADO',false);
igual($sinEvidencia['etapa_propuesta'],'POTENCIAL','sin prueba no inicia trámite');
$tramite=$p::evaluarEventoInstitucional('POTENCIAL','TRAMITE_INICIADO',true);
igual($tramite['etapa_propuesta'],'PROCESO_INSCRIPCION','trámite acreditado');
igual($tramite['subetapa_propuesta'],'SOLICITUD_INICIADA','subetapa inicial trámite');
$sinTramite=$p::evaluarEventoInstitucional('POTENCIAL','PAGO_VERIFICADO',true);
igual($sinTramite['accion'],'CONCILIAR_PAGO_CON_TRAMITE','pago aislado requiere conciliación');
$pago=$p::evaluarEventoInstitucional('PROCESO_INSCRIPCION','PAGO_VERIFICADO',true);
igual($pago['subetapa_propuesta'],'PAGO_VERIFICADO','pago verificado en proceso');
igual($pago['etapa_propuesta'],'PROCESO_INSCRIPCION','pago no acredita inscripción');
$inscrito=$p::evaluarEventoInstitucional('PROCESO_INSCRIPCION','INSCRIPCION_CONFIRMADA',true);
igual($inscrito['accion'],'CERRAR_CON_INSCRIPCION_REAL','inscripción acreditada requiere cierre separado');
igual($inscrito['etapa_propuesta'],'PROCESO_INSCRIPCION',
    'no inventar movimiento SQL de Inscritos');
igual($p::evaluarEventoInstitucional('DESCARTADO','TRAMITE_INICIADO',true)['etapa_propuesta'],
    'DESCARTADO','descartados nunca se reactivan automáticamente');
verifica(errorDominio(function()use($p){$p::evaluarEventoInstitucional('NUEVO','OTRO',true);}),
    'no permitir evento institucional no contemplado');

// Contador aislado: sólo datos ficticios cuya evidencia ya sería validada
// por backend; duplicado de PBX y fecha distinta jamás suman.
$base=[
    'fecha_local'=>'2026-11-11','direccion'=>'Saliente',
    'pbx_confirmada'=>true,'resultado_guardado'=>true,
    'asignacion_verificada'=>true,'extension'=>'150','resultado'=>'SIN_RESPUESTA'
];
$a=array_replace($base,['pbx_call_id'=>'out_123456']);
$b=array_replace($base,['pbx_call_id'=>'out_654321','resultado'=>'BUZON_VOZ']);
$c=array_replace($base,['pbx_call_id'=>'out_555555','resultado'=>'OCUPADO']);
igual($p::contarIntentosJornada([$a,$a,$b,$c],'2026-11-11'),3,
    'mismo ID PBX no duplica intentos');
igual($p::contarIntentosJornada([$a,$b,$c],'2026-11-12'),0,
    'intentos de ayer no cumplen automáticamente la jornada siguiente');
igual($p::contarIntentosJornada([
    $a,$b,
    array_replace($base,['pbx_call_id'=>'out_777777','direccion'=>'Entrante']),
    array_replace($base,['pbx_call_id'=>'out_888888','pbx_confirmada'=>false]),
    array_replace($base,['pbx_call_id'=>'out_999999','resultado_guardado'=>false]),
    array_replace($base,['pbx_call_id'=>'out_212121','asignacion_verificada'=>false]),
    array_replace($base,['pbx_call_id'=>'out_313131','resultado'=>'CONVERSACION_PERSONA']),
],'2026-11-11'),2,'excluir entrada, falta de evidencia y contacto efectivo');
igual($p::contarIntentosJornada([
    array_replace($base,['pbx_call_id'=>'out_444444','resultado'=>'COMUNICACION_INTERRUMPIDA',
           'contacto_antes_corte'=>'NO']),
    array_replace($base,['pbx_call_id'=>'out_454545','resultado'=>'COMUNICACION_INTERRUMPIDA',
           'contacto_antes_corte'=>'SI'])
],'2026-11-11'),1,'comunicación interrumpida sin persona sí cuenta');
verifica(errorArgumento(function()use($p){$p::contarIntentosJornada([],'mañana');}),
    'rechazar jornada sin fecha normalizada');

$source=file_get_contents(__DIR__.'/../app/services/EmbudoComercialPoliticaPropuesta.php');
foreach(['new mysqli','new Database','curl_exec(','file_put_contents(',
          'UPDATE prospecto_','INSERT INTO prospecto_','window.location'] as $prohibido) {
    verifica(strpos($source,$prohibido)===false,'contrato no escribe producción: '.$prohibido);
}
echo "CHECK OK: matriz de 5 etapas, 7 catálogos, tres intentos/día, protección de avances, casos V4, complementos, eventos institucionales y deduplicación PBX. Sin BD ni telefonía.\n";
