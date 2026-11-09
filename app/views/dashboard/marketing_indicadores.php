<?php
$indicadores = $indicadoresClaveMarketing ?? [];
$numeroIndicador = static fn($valor): string => $valor === null ? '—' : number_format((int)$valor, 0, '.', ',');
$porcentajeIndicador = static fn($valor): string => $valor === null ? '—' : rtrim(rtrim(number_format((float)$valor, 1, '.', ''), '0'), '.');
$barraIndicador = static function ($valor, string $etiqueta): void {
    if ($valor === null) { return; }
    $valor = max(0, min(100, (float)$valor));
    ?>
    <div class="marketing-key-bar" role="progressbar"
         aria-label="<?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?>"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $valor ?>"
         title="<?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?>">
        <span style="width:<?= $valor ?>%"></span>
    </div>
    <?php
};
?>
<section class="marketing-key-indicators mb-4" aria-labelledby="marketingKeyTitle">
    <div class="marketing-key-intro">
        <h2 id="marketingKeyTitle">INDICADORES CLAVE</h2>
        <p>Estado actual de tus actividades de Marketing.</p>
    </div>
    <article class="marketing-key-item" data-marketing-key="publicaciones">
        <div class="marketing-key-main">
            <span class="marketing-key-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
            <div><strong><?= $numeroIndicador($indicadores['publicaciones'] ?? null) ?></strong><h3>Convocatorias publicadas</h3></div>
        </div>
        <p>Total de publicaciones en el sistema</p>
    </article>
    <article class="marketing-key-item" data-marketing-key="convenios">
        <div class="marketing-key-main">
            <span class="marketing-key-icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
            <div><strong><?= $numeroIndicador($indicadores['convenios'] ?? null) ?></strong><h3>Convenios registrados</h3></div>
        </div>
        <?php $barraIndicador($indicadores['convenios_porcentaje'] ?? null, 'Porcentaje de entidades del catálogo con convenios registrados'); ?>
        <p><?= $numeroIndicador($indicadores['estados_convenios'] ?? null) ?> estados con convenios</p>
    </article>
    <article class="marketing-key-item" data-marketing-key="aliados">
        <div class="marketing-key-main">
            <span class="marketing-key-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
            <div><strong><?= $numeroIndicador($indicadores['aliados'] ?? null) ?></strong><h3>Aliados institucionales</h3></div>
        </div>
        <?php $barraIndicador($indicadores['aliados_porcentaje'] ?? null, 'Aliados con al menos una convocatoria activa / total de aliados'); ?>
        <p><?= isset($indicadores['aliados_porcentaje']) ? $porcentajeIndicador($indicadores['aliados_porcentaje']) . '% con convocatoria activa' : 'Relación no disponible' ?></p>
    </article>
    <article class="marketing-key-item" data-marketing-key="cobertura">
        <div class="marketing-key-main">
            <span class="marketing-key-icon" aria-hidden="true"><i class="bi bi-pie-chart"></i></span>
            <div><strong><?= $numeroIndicador($indicadores['estados_cubiertos'] ?? null) ?> de <?= $numeroIndicador($indicadores['total_estados'] ?? null) ?></strong><h3>Cobertura territorial</h3></div>
        </div>
        <?php $barraIndicador($indicadores['cobertura_porcentaje'] ?? null, 'Entidades con convocatoria activa / entidades del catálogo'); ?>
        <p><?= $porcentajeIndicador($indicadores['cobertura_porcentaje'] ?? null) ?>% del territorio con cobertura</p>
    </article>
    <article class="marketing-key-item" data-marketing-key="proximas">
        <div class="marketing-key-main">
            <span class="marketing-key-icon" aria-hidden="true"><i class="bi bi-clock"></i></span>
            <div><strong><?= $numeroIndicador($indicadores['proximas'] ?? null) ?></strong><h3>Próximas a vencer</h3></div>
        </div>
        <?php $barraIndicador($indicadores['proximas_porcentaje'] ?? null, 'Convocatorias que vencen entre hoy y los próximos 7 días / convocatorias activas'); ?>
        <p>En los próximos 7 días</p>
    </article>
    <div class="marketing-key-action">
        <?php if (tienePermiso('convocatorias.ver')): ?>
        <a class="marketing-key-details" href="<?= htmlspecialchars($convocatoriasUrl, ENT_QUOTES, 'UTF-8') ?>" title="Abrir Gestión de Convocatorias">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Ver más detalles</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
    </div>
</section>
