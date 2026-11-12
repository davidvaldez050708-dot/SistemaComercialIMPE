<?php
/** V3: la política antigua no debe mover a Recuperación antes de revisión. */
require_once __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
require_once __DIR__.'/../app/services/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/../app/services/EmbudoComercialV3AccesoControlado.php';

function comprobarRecuperacionBridge(bool $ok,string $detalle): void {
    if (!$ok) throw new RuntimeException('FALLO RECUPERACION BRIDGE: '.$detalle);
}
$bridge='EmbudoComercialResultadoBridge';
foreach ([
    ['RECUPERACION',true,true],
    ['RECUPERACION',false,false],
    ['CONTACTADO',true,false],
    ['SEGUIMIENTO',true,false],
    ['INTERESADO',true,false],
    ['',true,false],
] as $caso) {
    comprobarRecuperacionBridge(
        $bridge::bloquearRecuperacionAutomaticaV3($caso[0],$caso[1])===$caso[2],
        'aislar bloqueo: '.implode('|',array_map('strval',$caso)));
}
// Ninguna conversación telefónica reactiva automáticamente RECUPERACION en V3.
comprobarRecuperacionBridge(
    $bridge::bloquearRecuperacionAutomaticaV3('CONTACTADO',true,'RECUPERACION'),
    'reactivación desde Recuperación requiere gestión manual V3');
comprobarRecuperacionBridge(
    !$bridge::bloquearRecuperacionAutomaticaV3('CONTACTADO',false,'RECUPERACION') &&
    !$bridge::bloquearRecuperacionAutomaticaV3('CONTACTADO',true,'NUEVO'),
    'no alterar conversaciones nuevas ni asesores legacy');
$puente=file_get_contents(__DIR__.'/../app/services/EmbudoComercialResultadoBridge.php');
$idxLlamada=strpos($puente,'INSERT INTO prospecto_embudo_llamadas');
$idxGuarda=strpos($puente,'EmbudoComercialV3AccesoControlado::habilitadoParaAsesor');
$idxEtapas=strpos($puente,'INSERT INTO prospecto_embudo_etapas');
comprobarRecuperacionBridge($idxLlamada!==false && $idxGuarda!==false &&
    $idxEtapas!==false && $idxLlamada<$idxGuarda && $idxGuarda<$idxEtapas,
    'PBX se vincula primero; guardia V3 antes de cualquier movimiento');
comprobarRecuperacionBridge(strpos($puente,'IMPE_VENTAS_V3_PILOTO')===false &&
    strpos($puente,'EmbudoComercialV3PilotoControlado')===false,
    'no reintroducir piloto antiguo retirado de Hostinger');
comprobarRecuperacionBridge(strpos($puente,"return ['vinculada'=>true,'movida'=>false,'etapa'=>\$etapaAnterior]")!==false,
    'llamada vinculada y etapa intacta al bloquear');
foreach (['SIN_RESPUESTA','OCUPADO','BUZON_VOZ','MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO'] as $cod) {
    $legacy=EmbudoComercialEtapas::sugerenciaPorResultado($cod,null);
    comprobarRecuperacionBridge($legacy==='RECUPERACION',
        'documentar recomendación heredada: '.$cod);
    comprobarRecuperacionBridge($bridge::bloquearRecuperacionAutomaticaV3($legacy,true),
        'bloquear traslado prematuro por '.$cod);
    foreach ([1,2] as $intentos) {
        $plan=EmbudoComercialPoliticaPropuesta::evaluarLlamada('NUEVO',$cod,$intentos);
        comprobarRecuperacionBridge($plan['etapa_propuesta']==='NUEVO',
            'la matriz V3 conserva NUEVO con '.$intentos.' intentos');
    }
    $plan=EmbudoComercialPoliticaPropuesta::evaluarLlamada('NUEVO',$cod,3);
    comprobarRecuperacionBridge($plan['etapa_propuesta']==='RECUPERACION' &&
        $plan['mover']===true &&
        $bridge::bloquearRecuperacionAutomaticaV3($legacy,true),
        'tercer intento genera candidato a revisión; bridge V3 no escribe todavía');
}
echo "CHECK_OK: puente protegido solo para asesores V3, sin piloto, teléfono y etapa conservados; legacy sin V3 intacto.\n";
