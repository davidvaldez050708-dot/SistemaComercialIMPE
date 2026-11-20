<div class="d-flex gap-1 flex-wrap my-1" aria-label="Prospecto y responsable">
    <?php if(!empty($chatEtiqueta['prospecto_id'])): ?>
    <span class="badge bg-light text-primary border">Prospecto #<?= (int)$chatEtiqueta['prospecto_id'] ?></span>
    <?php elseif(!empty($chatEtiqueta['aliado_seguimiento_id'])): ?>
    <span class="badge bg-light text-primary border">Seguimiento #<?= (int)$chatEtiqueta['aliado_seguimiento_id'] ?></span>
    <?php else: ?>
    <span class="badge bg-light text-secondary border">Sin prospecto vinculado</span>
    <?php endif; ?>
    <span class="badge bg-light text-dark border"><?= $texto(trim((string)($chatEtiqueta['responsable_nombre']??''))?:(!empty($chatEtiqueta['prospecto_id'])?'Sin asesor vigente':'Sin asignar')) ?></span>
    <?php if(($chatEtiqueta['cuenta_tipo']??'')==='PRUEBA'): ?><span class="badge bg-light text-secondary border">Canal de prueba</span><?php endif; ?>
</div>
