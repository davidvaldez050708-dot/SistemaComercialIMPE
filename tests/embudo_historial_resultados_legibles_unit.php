<?php
/**
 * Renderización de TODOS los resultados de Ventas en la ficha comercial.
 * Sin MySQL, Zadarma, sesión real, ni cambios de resultados/bitácora.
 */
require_once __DIR__.'/../app/services/TelefoniaResultadoVentasService.php';
if (!defined('BASE_URL')) define('BASE_URL','/');
function tienePermiso($codigo) {return false;}
final class EmbudoComercialRepository {
    public const ETAPAS=['NUEVO'=>'Nuevos','RECUPERACION'=>'En recuperación',
        'CONTACTADO'=>'Contactados','INTERESADO'=>'Interesados',
        'SEGUIMIENTO'=>'Seguimiento'];
}
function ensureHistorial($ok,$why) {
    if (!$ok) throw new RuntimeException('TEST FALLIDO: '.$why);
}
$vista='recuperacion';$error='';$supervisor=false;
$prospecto=[
    'id'=>15,'nombre_completo'=>'FICHA CONTROLADA',
    'etapa'=>'RECUPERACION','telefono_original'=>null,'correo'=>null,
    'pais_nombre'=>'México','estado_provincia'=>null,'ciudad'=>null,
    'asesor_id'=>22,'asignacion_id'=>2,
    'asignado_at'=>'2026-11-10 15:31:54',
    'fecha_trabajo'=>'2026-11-10',
    'proxima_accion_at'=>null,
    'programas'=>[],'origenes'=>[],'preferencias'=>[],
    'historial_truncado'=>false,'historial_movimientos'=>[]
];
$ref='out_eba55b69a8d65b86c6afb96585034355b70d767c';
function renderHistorial($motivo) {
    global $prospecto,$vista,$error,$supervisor;
    $prospecto['historial_movimientos']=[[
        'id'=>37,'etapa_anterior'=>'NUEVO','etapa_nueva'=>'RECUPERACION',
        'motivo'=>$motivo,'usuario_id'=>22,
        'creado_at'=>'2026-11-10 16:05:00','autor'=>'prueba llamadas'
    ]];
    ob_start();
    require __DIR__.'/../app/views/embudo_comercial/detalle.php';
    return ob_get_clean();
}
$opciones=TelefoniaResultadoVentasService::opciones();
ensureHistorial(count($opciones)===14,'catálogo actual de 14 resultados telefónicos');
foreach ($opciones as $codigo=>$etiqueta) {
    $raw='Resultado PBX verificado: '.$codigo.' · ref '.$ref;
    $html=renderHistorial($raw);
    $visible='Llamada comercial — '.$etiqueta;
    ensureHistorial(strpos($html,
        '<p class="impe-embudo-timeline-motivo">'.$visible.'</p>')!==false,
        'etiqueta legible del resultado '.$codigo);
    ensureHistorial(strpos($html,'<summary>Ver referencia técnica</summary>')!==false,
        'referencia plegada: '.$codigo);
    ensureHistorial(strpos($html,'<code>'.htmlspecialchars($raw,ENT_QUOTES,'UTF-8').
        '</code>')!==false,'evidencia técnica idéntica, '.$codigo);
    ensureHistorial(strpos($html,'<p class="impe-embudo-timeline-motivo">'.$raw)===false,
        'ningún código ni ID a simple vista, '.$codigo);
    ensureHistorial(strpos($html,'Nuevos')!==false &&
        strpos($html,'En recuperación')!==false &&
        strpos($html,'prueba llamadas')!==false &&
        strpos($html,'10 nov 2026, 10:05 a. m.')!==false,
        'etapas, autor y fecha conservados');
}
$unknown='Resultado PBX verificado: RESULTADO_FUTURO_2 · ref '.$ref;
$unknownHtml=renderHistorial($unknown);
ensureHistorial(strpos($unknownHtml,'Llamada comercial — Resultado telefónico no identificado')!==false,
    'código futuro no se presenta como etiqueta oficial');
ensureHistorial(strpos($unknownHtml,'<code>'.$unknown.'</code>')!==false,
    'código futuro conservado solo en detalles técnicos');
$manual='Cambiar etapa: revisión <script>alert("test")</script>';
$manualHtml=renderHistorial($manual);
ensureHistorial(strpos($manualHtml,'Ver referencia técnica')===false,
    'movimiento manual no debe aparentar ser telefónico');
ensureHistorial(strpos($manualHtml,htmlspecialchars($manual,ENT_QUOTES,'UTF-8'))!==false,
    'texto manual original y escapado');
ensureHistorial(strpos($manualHtml,'<script>alert')===false,'XSS escapado');
$incomplete='Resultado PBX verificado: SIN_RESPUESTA · ref ';
$incompleteHtml=renderHistorial($incomplete);
ensureHistorial(strpos($incompleteHtml,'Ver referencia técnica')===false,
    'registro técnico incompleto no se interpreta');
$xss='Resultado PBX verificado: SIN_RESPUESTA · ref '.$ref.'<script>';
$xssHtml=renderHistorial($xss);
ensureHistorial(strpos($xssHtml,'<script>')===false,'entrada no confiable no ejecutable');
ensureHistorial(strpos($xssHtml,'Ver referencia técnica')===false,
    'referencia no válida no se oculta como auditable');
echo "CHECK OK: catálogo completo de 14 resultados, datos técnicos plegados, XSS y motivos manuales intactos.\n";
