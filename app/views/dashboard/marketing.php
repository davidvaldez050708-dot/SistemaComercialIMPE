<div class="marketing-module-typography">
<?php
$resumenMarketing = $resumenMarketing ?? [];
$coberturaMarketing = $coberturaMarketing ?? [
    'total_estados' => 0,
    'estados_cubiertos' => 0,
    'territorios' => []
];
$convocatoriasUrl = BASE_URL . 'index.php?controller=convocatoria&action=index';
$totalEstadosCobertura = (int)($coberturaMarketing['total_estados'] ?? 0);
$estadosCubiertos = (int)($coberturaMarketing['estados_cubiertos'] ?? 0);
$territoriosCobertura = is_array($coberturaMarketing['territorios'] ?? null)
    ? $coberturaMarketing['territorios']
    : [];
?>

<div class="metric-grid mb-4">
    <article class="metric-card">
        <div class="metric-icon"><i class="bi bi-megaphone"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['total'] ?? 0) ?></p>
            <p class="metric-label">Total de convocatorias</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-success"><i class="bi bi-check-circle"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['activas'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias activas</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-muted"><i class="bi bi-slash-circle"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['inactivas'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias inactivas</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['vigentes'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias vigentes</p>
        </div>
    </article>
</div>

<section class="dashboard-panel">
    <div class="table-panel-header">
        <div>
            <h2 class="panel-title mb-0">Gestión de Convocatorias</h2>
            <p class="panel-subtitle mb-0">
                <?= (int)($resumenMarketing['proximas_finalizar'] ?? 0) ?>
                convocatorias activas finalizan en los próximos 7 días.
            </p>
        </div>

        <?php if (tienePermiso('convocatorias.ver')): ?>
            <a class="btn btn-system-light" href="<?= $convocatoriasUrl ?>">
                <i class="bi bi-arrow-right-circle me-2"></i>
                Ir a convocatorias
            </a>
        <?php endif; ?>
    </div>
</section>

<?php
$porcentajeCobertura = $totalEstadosCobertura > 0
    ? (int)round(($estadosCubiertos / $totalEstadosCobertura) * 100)
    : 0;
$maxConvocatoriasTerritorio = 0;
foreach ($territoriosCobertura as $territorioCobertura) {
    $maxConvocatoriasTerritorio = max(
        $maxConvocatoriasTerritorio,
        (int)($territorioCobertura['convocatorias_activas'] ?? 0)
    );
}
?>

<section class="dashboard-panel marketing-territory-coverage mt-4">
    <div class="marketing-coverage-heading">
        <div>
            <span class="analyst-section-kicker">COBERTURA TERRITORIAL</span>
            <h2 class="panel-title mb-0">Cobertura territorial de convocatorias activas</h2>
        </div>

        <a class="analyst-panel-link" href="<?= $convocatoriasUrl ?>">
            Ver territorios
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="marketing-coverage-grid">
        <div class="marketing-coverage-overview">
            <div
                class="marketing-coverage-donut"
                style="--coverage-percent: <?= $porcentajeCobertura ?>%;">
                <div class="marketing-coverage-donut-center">
                    <strong><?= $estadosCubiertos ?> de <?= $totalEstadosCobertura ?></strong>
                    <span>estados</span>
                </div>
            </div>

            <div class="marketing-coverage-copy">
                <strong>con convocatorias activas</strong>
                <p>
                    Actualmente existen convocatorias activas en el
                    <?= $porcentajeCobertura ?>% del territorio nacional.
                </p>
            </div>
        </div>

        <div class="marketing-coverage-ranking">
            <h3>Estados con más convocatorias activas</h3>

            <?php if (!empty($territoriosCobertura)): ?>
                <div class="marketing-coverage-list">
                    <?php foreach ($territoriosCobertura as $indice => $territorio): ?>
                        <?php
                        $cantidadActivas = (int)($territorio['convocatorias_activas'] ?? 0);
                        $anchoBarra = $maxConvocatoriasTerritorio > 0
                            ? ($cantidadActivas / $maxConvocatoriasTerritorio) * 100
                            : 0;
                        ?>
                        <div class="marketing-coverage-item">
                            <span class="marketing-coverage-position"><?= $indice + 1 ?></span>
                            <span class="marketing-coverage-state">
                                <?= htmlspecialchars((string)($territorio['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="marketing-coverage-track" aria-hidden="true">
                                <span
                                    class="marketing-coverage-bar"
                                    style="width: <?= number_format($anchoBarra, 2, '.', '') ?>%;">
                                </span>
                            </span>
                            <strong><?= $cantidadActivas ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="marketing-coverage-empty">
                    Aún no hay cobertura territorial activa.
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
$periodoPublicaciones = (int)($periodoPublicaciones ?? 30);
$totalPublicacionesPeriodo = (int)($totalPublicacionesPeriodo ?? 0);
?>

<section class="dashboard-panel marketing-publications-chart mt-4">
    <div class="marketing-publications-heading">
        <h2>Publicaciones totales</h2>

        <form method="GET" action="<?= BASE_URL ?>index.php" class="marketing-publications-filter">
            <input type="hidden" name="controller" value="home">
            <input type="hidden" name="action" value="index">
            <select
                class="form-select system-form-control"
                name="periodo_publicaciones"
                aria-label="Periodo de publicaciones"
                onchange="this.form.submit()">
                <option value="7" <?= $periodoPublicaciones === 7 ? 'selected' : '' ?>>Últimos 7 días</option>
                <option value="30" <?= $periodoPublicaciones === 30 ? 'selected' : '' ?>>Últimos 30 días</option>
                <option value="90" <?= $periodoPublicaciones === 90 ? 'selected' : '' ?>>Últimos 90 días</option>
            </select>
        </form>
    </div>

    <?php if ($totalPublicacionesPeriodo > 0): ?>
        <div class="marketing-publications-plot">
            <div class="marketing-publications-column">
                <strong><?= $totalPublicacionesPeriodo ?></strong>
                <div class="marketing-publications-bar-space" aria-hidden="true">
                    <span class="marketing-publications-bar" style="height: 100%;"></span>
                </div>
                <span class="marketing-publications-state">Publicaciones</span>
            </div>
        </div>
    <?php else: ?>
        <div class="marketing-publications-empty">
            No hubo publicaciones en los últimos <?= $periodoPublicaciones ?> días.
        </div>
    <?php endif; ?>
</section>

</div>