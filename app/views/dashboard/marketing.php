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

<?php
$estadosSinConvocatoria = is_array($estadosSinConvocatoria ?? null)
    ? $estadosSinConvocatoria
    : [];
$totalSinConvocatoria = count($estadosSinConvocatoria);
$porcentajeSinConvocatoria = $totalEstadosCobertura > 0
    ? (int)round(($totalSinConvocatoria / $totalEstadosCobertura) * 100)
    : 0;
?>

<div class="marketing-bottom-grid mt-4">
<section class="dashboard-panel marketing-publications-by-type">
    <?php
    $publicacionesPorTipoMarketing = is_array($publicacionesPorTipoMarketing ?? null)
        ? $publicacionesPorTipoMarketing
        : [];

    $tiposPublicacionesMarketing = [
        'bachillerato' => [
            'titulo' => 'Bachillerato',
            'icono' => 'bi-book'
        ],
        'titulacion' => [
            'titulo' => 'Titulación',
            'icono' => 'bi-mortarboard'
        ]
    ];
    ?>

    <div class="marketing-publications-types-heading">
        <div>
            <span class="analyst-section-kicker">PUBLICACIONES POR TIPO</span>
            <h2>Total de publicaciones según el tipo de convocatoria.</h2>
        </div>

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

    <div class="marketing-publications-type-grid">
        <?php foreach ($tiposPublicacionesMarketing as $tipoClave => $tipoConfig): ?>
            <?php
            $datosTipo = is_array($publicacionesPorTipoMarketing[$tipoClave] ?? null)
                ? $publicacionesPorTipoMarketing[$tipoClave]
                : [];
            $mesesTipo = is_array($datosTipo['meses'] ?? null)
                ? $datosTipo['meses']
                : [];
            $valoresMes = array_map(
                static fn($mes) => (int)($mes['total'] ?? 0),
                $mesesTipo
            );
            $maxValorMes = !empty($valoresMes) ? max($valoresMes) : 0;
            $escalaMes = max(1, $maxValorMes);
            ?>

            <article class="marketing-publication-type-card">
                <div class="marketing-publication-type-header">
                    <span class="marketing-publication-type-icon">
                        <i class="bi <?= htmlspecialchars($tipoConfig['icono'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </span>
                    <strong><?= htmlspecialchars($tipoConfig['titulo'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <div class="marketing-publication-type-body">
                    <div class="marketing-publication-type-total">
                        <strong><?= (int)($datosTipo['total'] ?? 0) ?></strong>
                        <span>publicaciones</span>
                    </div>

                    <div class="marketing-publication-type-chart">
                        <?php foreach ($mesesTipo as $mes): ?>
                            <?php
                            $cantidadMes = (int)($mes['total'] ?? 0);
                            $alturaBarra = $cantidadMes > 0
                                ? max(18, (int)round(($cantidadMes / $escalaMes) * 100))
                                : 10;
                            $esDestacado = $maxValorMes > 0 && $cantidadMes === $maxValorMes;
                            ?>
                            <div class="marketing-publication-type-month">
                                <strong><?= $cantidadMes ?></strong>
                                <div class="marketing-publication-type-bar-space" aria-hidden="true">
                                    <span
                                        class="<?= $esDestacado ? 'is-highlighted' : '' ?>"
                                        style="height: <?= $alturaBarra ?>%;">
                                    </span>
                                </div>
                                <small>
                                    <?= htmlspecialchars((string)($mes['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

    <section class="dashboard-panel marketing-pending-coverage">
        <div class="marketing-pending-heading">
            <div>
                <span class="analyst-section-kicker">COBERTURA PENDIENTE</span>
                <h2>Estados sin convocatoria activa</h2>
                <p>Territorios que actualmente no cuentan con convocatorias activas.</p>
            </div>

            <a class="analyst-panel-link" href="<?= $convocatoriasUrl ?>">
                Ver territorios
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div class="marketing-pending-content">
            <div class="marketing-pending-summary">
                <div class="marketing-pending-count">
                    <span class="marketing-pending-icon">
                        <i class="bi bi-dash-circle"></i>
                    </span>
                    <div>
                        <strong><?= $totalSinConvocatoria ?> de <?= $totalEstadosCobertura ?></strong>
                        <span>estados sin convocatoria activa</span>
                    </div>
                </div>

                <div class="marketing-pending-progress" aria-hidden="true">
                    <span style="width: <?= $porcentajeSinConvocatoria ?>%;"></span>
                </div>

                <p>
                    <strong><?= $porcentajeSinConvocatoria ?>%</strong>
                    del territorio sin convocatoria activa.
                </p>
            </div>

            <div class="marketing-pending-list">
                <?php if (!empty($estadosSinConvocatoria)): ?>
                    <?php foreach ($estadosSinConvocatoria as $indice => $estadoPendiente): ?>
                        <div class="marketing-pending-item">
                            <span><?= $indice + 1 ?></span>
                            <strong>
                                <?= htmlspecialchars((string)($estadoPendiente['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            <small>Sin convocatoria</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="marketing-pending-empty">
                        Todos los estados cuentan con al menos una convocatoria activa.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

</div>