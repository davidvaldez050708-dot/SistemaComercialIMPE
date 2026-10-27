<?php
/**
 * Conciliación de la vista de tarjetas REAL de Hostinger.
 * Solo prepara una copia; no toca la vista de producción.
 * Requiere que el proveedor de datos entregue estado_pbx,
 * resultado_guardado y resultado_estado por cada llamada.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Uso: php conciliar_supervision_tarjetas_hostinger_v1.php origen.php candidato.php\n");
    exit(2);
}
$source=$argv[1];$target=$argv[2];
if (!is_file($source)||is_link($source)||$source===$target) {
    fwrite(STDERR,"ERROR: ruta original no válida. SITIO INTACTO.\n");exit(1);
}
$src=file_get_contents($source);
if (!is_string($src)||$src==='') {
    fwrite(STDERR,"ERROR: vista ilegible. SITIO INTACTO.\n");exit(1);
}
$oldBadges=<<<'PHP'
    <div class="linkage-call-result-group"><span class="linkage-call-result-badge <?= $c['conectada']?'is-contact':'' ?>"><?= $esc($c['direccion']) ?> · <?= $c['conectada']?'Conectada':'Sin conexión confirmada' ?></span><span class="linkage-call-result-badge is-result"><?= $esc($c['resultado_texto']) ?></span></div>
PHP;
$oldButton=<<<'PHP'
     <button type="button" class="linkage-incoming-open" data-supervision-detail><i class="bi bi-card-checklist" aria-hidden="true"></i> Consultar resultado</button>
PHP;
$newBadges=<<<'PHP'
    <?php /* IMPE_SUPERVISION_TARJETAS_PBX_ESTADO_V1 */
    $estadoPbx = trim((string)($c['estado_pbx'] ?? ''));
    if ($estadoPbx === '') $estadoPbx = !empty($c['conectada']) ? 'Conectada' : 'Sin conexión confirmada';
    $resultadoGuardado = !empty($c['resultado_guardado']);
    $textoResultado = $resultadoGuardado
        ? (string)($c['resultado_texto'] ?? 'Resultado registrado')
        : (string)($c['resultado_estado'] ?? 'Sin resultado registrado');
    ?>
    <div class="linkage-call-result-group"><span class="linkage-call-result-badge <?= !empty($c['conectada'])?'is-contact':'' ?>"><?= $esc($c['direccion']) ?> · <?= $esc($estadoPbx) ?></span><span class="linkage-call-result-badge is-result"><?= $esc($textoResultado) ?></span></div>
PHP;
$newButton=<<<'PHP'
     <?php if($resultadoGuardado): ?><button type="button" class="linkage-incoming-open" data-supervision-detail><i class="bi bi-card-checklist" aria-hidden="true"></i> Consultar resultado</button><?php endif; ?>
PHP;
if (substr_count($src,'IMPE_SUPERVISION_TARJETAS_PBX_ESTADO_V1')===1
    && substr_count($src,$newButton)===1) {
    $out=$src;
    $msg="VISTA YA CONCILIADA. Sin cambios.\n";
} elseif (substr_count($src,$oldBadges)===1 && substr_count($src,$oldButton)===1) {
    $out=str_replace([$oldBadges,$oldButton],[$newBadges,$newButton],$src,$changes);
    if ($changes!==2) {
        fwrite(STDERR,"CONFLICTO: cantidad de cambios inesperada. SITIO INTACTO.\n");
        exit(1);
    }
    $msg="VISTA PREPARADA: dos fragmentos; clases, filtros, paginación y audios conservados.\n";
} else {
    fwrite(STDERR,"CONFLICTO: la vista de tarjetas ya cambió o contiene duplicados. SITIO INTACTO.\n");
    exit(1);
}
if (strpos($out,'data-supervision-calls')===false ||
    strpos($out,'data-supervision-audio')===false ||
    strpos($out,'data-supervision-detail')===false ||
    strpos($out,'supervision_llamadas.css')===false) {
    fwrite(STDERR,"ERROR: se perdió un control de Supervisión. SITIO INTACTO.\n");
    exit(1);
}
if (file_put_contents($target,$out)!==strlen($out)) {
    fwrite(STDERR,"ERROR: no se pudo guardar el candidato. SITIO INTACTO.\n");
    exit(1);
}
echo $msg;
