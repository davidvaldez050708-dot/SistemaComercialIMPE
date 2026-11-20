<div class="wa-chat-tags" aria-label="Prospecto y responsable">
    <?php if(!empty($chatEtiqueta['prospecto_id'])): ?>
    <span class="wa-chat-tag is-prospect">Prospecto #<?= (int)$chatEtiqueta['prospecto_id'] ?></span>
    <?php elseif(!empty($chatEtiqueta['aliado_seguimiento_id'])): ?>
    <span class="wa-chat-tag is-prospect">Seguimiento #<?= (int)$chatEtiqueta['aliado_seguimiento_id'] ?></span>
    <?php else: ?>
    <span class="wa-chat-tag is-muted" title="Sin prospecto vinculado">Sin prospecto</span>
    <?php endif; ?>
    <?php $responsableEtiqueta=trim((string)($chatEtiqueta['responsable_nombre']??''))?:(!empty($chatEtiqueta['prospecto_id'])?'Sin asesor vigente':'Sin asignar'); ?>
    <span class="wa-chat-tag is-owner" title="<?= $texto($responsableEtiqueta) ?>"><?= $texto($responsableEtiqueta) ?></span>
    <?php if(($chatEtiqueta['cuenta_tipo']??'')==='PRUEBA'): ?><span class="wa-chat-tag is-muted" title="Canal de prueba">Prueba</span><?php endif; ?>
</div>
