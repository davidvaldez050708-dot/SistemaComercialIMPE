<?php
/** V3 aislado: ninguna conexión a PBX/Hostinger ni escritura. */
require_once __DIR__.'/../app/services/EmbudoComercialPlanificadorV3.php';
function okV3($x,$why){if(!$x)throw new RuntimeException('FALLO V3: '.$why);}
function eqV3($a,$b,$why){okV3($a===$b,$why.' esperado='.var_export($b,true).' actual='.var_export($a,true));}
$p='EmbudoComercialPlanificadorV3';
$a=$p::interpretar('REQUIERE_SEGUIMIENTO',
    ['PROSPECTO_POTENCIAL','REQUIERE_SEGUIMIENTO'],'4','');
eqV3($a['codigo'],'PROSPECTO_POTENCIAL','V4 no debe ocultar potencial detrás del seguimiento');
eqV3($a['seguimiento'],true,'fecha de seguimiento sigue siendo acción independiente');
eqV3($a['requiere_revision'],false,'selecciones coherentes y registradas');
$a=$p::interpretar('REQUIERE_SEGUIMIENTO',
    ['REQUIERE_SEGUIMIENTO','WHATSAPP'],'4','');
eqV3($a['codigo'],'REQUIERE_SEGUIMIENTO','envío no cambia categoría');
eqV3($a['acciones'],['WHATSAPP'],'WhatsApp se mantiene como complemento');
$a=$p::interpretar('CONVERSACION_PERSONA',['WHATSAPP','SMS'],'4','');
eqV3($a['codigo'],'CONVERSACION_PERSONA','solo mensajes con conversación confirmada');
eqV3($a['acciones'],['WHATSAPP','SMS'],'ambos envíos independientes');
$a=$p::interpretar('ENVIO_WHATSAPP',['WHATSAPP'],'4','');
eqV3($a['codigo'],'SIN_RESPUESTA','solo WhatsApp V4 sin conversación');
$a=$p::interpretar('ENVIO_SMS',[],'3','');
eqV3($a['requiere_revision'],true,'envío V3 ambiguo no acredita intento');
eqV3($a['motivo'],'ENVIO_LEGACY_AMBIGUO','advertir ambigüedad histórica');
$a=$p::interpretar('COMUNICACION_INTERRUMPIDA_PERSONA',
    ['COMUNICACION_INTERRUMPIDA'],'4','SI');
eqV3($a['codigo'],'COMUNICACION_INTERRUMPIDA','normalizar corte V4 humano');
$a=$p::interpretar('COMUNICACION_INTERRUMPIDA',
    ['COMUNICACION_INTERRUMPIDA'],'4','');
eqV3($a['requiere_revision'],true,'corte sin respuesta debe solicitar aclaración');
$a=$p::interpretar('SIN_RESPUESTA',['WHATSAPP','SIN_RESPUESTA'],'4','');
eqV3($a['codigo'],'SIN_RESPUESTA','no convertir ausencia en contacto');
$a=$p::interpretar('PROSPECTO_POTENCIAL',
    ['PROSPECTO_POTENCIAL','NO_INTERESADO'],'4','');
eqV3($a['requiere_revision'],true,'selecciones humanas contradictorias no se aplican');
$a=$p::interpretar('NO_INTERESADO',[],'','');
eqV3($a['codigo'],'NO_INTERESADO','resultado previo claro conserva sentido');
$a=$p::interpretar('INTERESADO',[],'','');
eqV3($a['requiere_revision'],true,'código legacy ambiguo necesita revisar');
$a=$p::interpretar('SIN_RESPUESTA',['CODIGO_FALSO'],'4','');
eqV3($a['requiere_revision'],true,'código desconocido nunca se infiere');

$src=file_get_contents(__DIR__.'/../app/services/EmbudoComercialPlanificadorV3.php');
foreach(['UPDATE prospecto_','INSERT INTO prospecto_','DELETE FROM prospecto_',
         '->commit(', '->begin_transaction(', '->rollback(',
         'new TelefoniaResultadoVentasService', 'new EmbudoComercialResultadoBridge'] as $x){
    okV3(strpos($src,$x)===false,'planificador no tiene mutación: '.$x);
}
echo "CHECK OK: V4 multiselección, complementos, corte, legados y solo lectura; sin alterar resultados ni llamadas.\n";
