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

<section class="dashboard-panel marketing-territory-coverage mt-4">
    <div class="analyst-panel-heading">
        <div>
            <span class="analyst-section-kicker">COBERTURA TERRITORIAL</span>
            <h2 class="panel-title mb-0">Cobertura territorial</h2>
        </div>

        <a class="analyst-panel-link" href="<?= $convocatoriasUrl ?>">
            Ver territorios
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="marketing-coverage-summary">
        <strong>
            <?= $estadosCubiertos ?> de <?= $totalEstadosCobertura ?> estados
        </strong>
        <span>con convocatorias activas</span>
    </div>

    <?php if (!empty($territoriosCobertura)): ?>
        <div class="marketing-coverage-list">
            <?php foreach ($territoriosCobertura as $territorio): ?>
                <div class="marketing-coverage-item">
                    <span><?= htmlspecialchars((string)($territorio['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= (int)($territorio['convocatorias_activas'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="marketing-coverage-empty">
            Aún no hay cobertura territorial activa.
        </div>
    <?php endif; ?>
</section>

</div>