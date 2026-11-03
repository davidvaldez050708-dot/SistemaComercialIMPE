<section class="central-summary prospectos-summary" aria-label="Indicadores de prospectos">
<?php foreach ([['personas','bi-people','Prospectos únicos','Por ID comercial; coincidencias por revisar'],['aliados','bi-buildings','Registros de aliados','Solicitudes recibidas en formularios'],['generales','bi-ui-checks','Formularios generales','Solicitudes sin procedencia de aliado'],['meta','bi-megaphone','Registros de Meta','Registros incorporados al almacén']] as [$clave,$icono,$titulo,$nota]): ?>
<article class="central-kpi" data-prospectos-indicador="<?= $clave ?>"><span class="central-icon"><i class="bi <?= $icono ?>" aria-hidden="true"></i></span><div><strong><?= isset($indicadores[$clave])?number_format((int)$indicadores[$clave]):'—' ?></strong><span><?= $titulo ?></span><small><?= isset($indicadores[$clave])?$nota:'No disponible' ?></small></div></article>
<?php endforeach; ?>
</section>
<?php if (!empty($indicadores['por_incorporar'])): ?><p class="central-note"><?= (int)$indicadores['por_incorporar'] ?> registros originales pendientes de incorporación. Continúan disponibles en Aliados.</p><?php endif; ?>
