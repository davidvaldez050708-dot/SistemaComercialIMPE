<?php
/** Contrato aislado: auditar PBX no modifica la etapa ni envía mensajes. */
require_once __DIR__.'/../app/services/EmbudoComercialV3RecuperacionAuditoria.php';
require_once __DIR__.'/../app/services/EmbudoComercialPoliticaPropuesta.php';

function audita(bool $ok,string $msg): void {
    if (!$ok) throw new RuntimeException('AUDITORIA_RECUPERACION: '.$msg);
}
$c='EmbudoComercialV3RecuperacionAuditoria';
foreach ([
    [0,'SIN_INTENTOS',false],
    [1,'INTENTO_1',false],
    [2,'INTENTO_2',false],
    [3,'CANDIDATO_REVISION_3',true],
    [4,'CANDIDATO_REVISION_3',true],
] as $item) {
    $r=$c::clasificar('NUEVO',$item[0]);
    audita($r['codigo']===$item[1] && $r['candidato']===$item[2],
        'NUEVO '.$item[0].' intentos');
    $plan=EmbudoComercialPoliticaPropuesta::evaluarLlamada(
        'NUEVO','SIN_RESPUESTA',$item[0]);
    audita($plan['etapa_propuesta']===($item[0]>=3?'RECUPERACION':'NUEVO'),
        'Política V3 protege 0/1/2 intentos sin contacto');
}
foreach ([
    ['RECUPERACION',0,'YA_EN_RECUPERACION'],
    ['RECUPERACION',3,'YA_EN_RECUPERACION'],
    ['EN_CONTACTO',1,'LEGADO_REQUIERE_REVISION'],
    ['DESCARTADO',3,'DESCARTADO_SIN_REACTIVACION'],
    ['CONTACTADO',3,'ETAPA_PROTEGIDA'],
    ['SEGUIMIENTO',3,'ETAPA_PROTEGIDA'],
    ['POTENCIAL',3,'ETAPA_PROTEGIDA'],
    ['INTERESADO',3,'ETAPA_PROTEGIDA'],
    ['PROCESO_INSCRIPCION',3,'ETAPA_PROTEGIDA'],
    ['PAGO_CONFIRMADO',3,'ETAPA_PROTEGIDA'],
] as $caso) {
    $r=$c::clasificar($caso[0],$caso[1]);
    audita($r['codigo']===$caso[2] && !$r['candidato'],
        'No degradar etapa '.$caso[0]);
}
try {
    $c::clasificar('NUEVO',-1);
    throw new RuntimeException('Se permitió contador negativo.');
} catch (InvalidArgumentException $ok) {}

$base=file_get_contents(__DIR__.'/../app/services/EmbudoComercialEtapas.php');
$bridge=file_get_contents(__DIR__.'/../app/services/EmbudoComercialResultadoBridge.php');
audita($c::inconsistenciaBridgeLegacy($base,$bridge),
    'La política vieja debe identificarse como riesgo, sin modificarla');
audita(!$c::inconsistenciaBridgeLegacy('<?php return null;', $bridge),
    'No inventar hallazgo al faltar código de política antigua');

$script=file_get_contents(__DIR__.'/../tools/ventas_v3_recuperacion_auditoria_hostinger.php');
$source=file_get_contents(__DIR__.'/../app/services/EmbudoComercialV3RecuperacionAuditoria.php');
foreach ([
    'INSERT INTO','UPDATE prospecto_','DELETE FROM','DROP TABLE',
    'ALTER TABLE','curl_exec','file_put_contents','enviarSMS','ZadarmaApi'
] as $prohibido) {
    audita(strpos($script,$prohibido)===false && strpos($source,$prohibido)===false,
        'Auditor no debe escribir ni contactar: '.$prohibido);
}
audita(strpos($script,'START TRANSACTION READ ONLY')!==false,
    'Auditor usa transacción de lectura');
audita(strpos($script,'php_sapi_name')!==false || strpos($script,"PHP_SAPI !== 'cli'")!==false,
    'Auditor restringido a CLI');
audita(strpos($script,'intentosDelDia')!==false,
    'Contador PBX verificado es origen exclusivo');
audita(strpos($script,'OFFSET')!==false,
    'Auditor paginado y limitado');
echo "CHECK_OK: Recuperación V3 solamente diagnóstica, 0/1/2/3+ llamadas, no contacto, legados y descarte protegido.\n";
