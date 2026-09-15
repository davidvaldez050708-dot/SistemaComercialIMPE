<?php

$evolucionActividad = $evolucionActividad ?? [
    'disponible' => false,
    'mensaje' => 'No fue posible obtener la evolución de la actividad.',
    'granularidad_label' => '',
    'periodos' => [],
    'total' => 0,
    'mayor' => null,
    'menor' => null,
    'variacion_label' => 'Sin comparación disponible',
    'periodo_anterior' => ''
];
$periodosActividad = $evolucionActividad['periodos'] ?? [];
$graficaDisponible = !empty($evolucionActividad['disponible']) && !empty($periodosActividad);
$anchoGrafica = 1000;
$altoGrafica = 320;
$margenIzquierdo = 70;
$margenDerecho = 30;
$margenSuperior = 20;
$margenInferior = 62;
$anchoTrazado = $anchoGrafica - $margenIzquierdo - $margenDerecho;
$altoTrazado = $altoGrafica - $margenSuperior - $margenInferior;
$puntosLinea = [];
$puntosActividad = [];
$marcasEjeY = [];
$saltoEtiquetaX = 1;

if ($graficaDisponible) {
    $totalesActividad = array_map(static function ($periodo) {
        return (int)($periodo['total'] ?? 0);
    }, $periodosActividad);
    $maximoActividad = max(1, max($totalesActividad));
    $cantidadPeriodos = count($periodosActividad);
    $saltoEtiquetaX = max(1, (int)ceil($cantidadPeriodos / 8));

    foreach ($periodosActividad as $indice => $periodo) {
        $x = $cantidadPeriodos > 1
            ? $margenIzquierdo + (($anchoTrazado * $indice) / ($cantidadPeriodos - 1))
            : $margenIzquierdo + ($anchoTrazado / 2);
        $totalPeriodo = (int)($periodo['total'] ?? 0);
        $y = $margenSuperior + $altoTrazado - (($totalPeriodo / $maximoActividad) * $altoTrazado);
        $puntosLinea[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
        $puntosActividad[] = [
            'x' => $x,
            'y' => $y,
            'total' => $totalPeriodo,
            'label' => (string)($periodo['label'] ?? ''),
            'tooltip' => (string)($periodo['tooltip'] ?? $periodo['label'] ?? '')
        ];
    }

    $valoresY = [
        0,
        (int)ceil($maximoActividad * 0.25),
        (int)ceil($maximoActividad * 0.50),
        (int)ceil($maximoActividad * 0.75),
        $maximoActividad
    ];
    $valoresY = array_values(array_unique($valoresY));
    sort($valoresY);

    foreach ($valoresY as $valorY) {
        $y = $margenSuperior + $altoTrazado - (($valorY / $maximoActividad) * $altoTrazado);
        $marcasEjeY[] = ['valor' => $valorY, 'y' => $y];
    }
}
?>

<section class="dashboard-panel mb-4" aria-labelledby="grafica-evolucion-actividad-titulo">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h3 class="panel-title mb-1" id="grafica-evolucion-actividad-titulo">Evolución de la actividad</h3>
            <p class="page-subtitle mb-0">Actividades registradas durante el periodo seleccionado.</p>
        </div>
        <?php if (!empty($evolucionActividad['granularidad_label'])): ?>
            <span class="status-pill status-pill-active">
                <?= $texto($evolucionActividad['granularidad_label']) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (!$graficaDisponible): ?>
        <div class="data-empty-state py-4">
            <span><i class="bi bi-activity"></i></span>
            <strong><?= $texto($evolucionActividad['mensaje'] ?? 'No fue posible obtener la evolución de la actividad.') ?></strong>
        </div>
    <?php else: ?>
        <section class="metric-grid linkage-summary-grid mb-3" aria-label="Indicadores de actividad">
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon">
                    <i class="bi bi-activity"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)($evolucionActividad['total'] ?? 0) ?></p>
                    <p class="metric-label">Actividades en el periodo</p>
                </div>
            </article>

            <article class="metric-card linkage-summary-card">
                <div class="metric-icon">
                    <i class="bi bi-arrow-up-circle"></i>
                </div>
                <div>
                    <p class="metric-value"><?= $texto($evolucionActividad['mayor']['label'] ?? '—') ?></p>
                    <p class="metric-label">
                        Mayor actividad · <?= (int)($evolucionActividad['mayor']['total'] ?? 0) ?> actividades
                    </p>
                </div>
            </article>

            <article class="metric-card linkage-summary-card">
                <div class="metric-icon metric-icon-muted">
                    <i class="bi bi-arrow-down-circle"></i>
                </div>
                <div>
                    <p class="metric-value"><?= $texto($evolucionActividad['menor']['label'] ?? '—') ?></p>
                    <p class="metric-label">
                        Menor actividad · <?= (int)($evolucionActividad['menor']['total'] ?? 0) ?> actividades
                    </p>
                </div>
            </article>

            <article class="metric-card linkage-summary-card">
                <div class="metric-icon metric-icon-muted">
                    <i class="bi bi-percent"></i>
                </div>
                <div>
                    <p class="metric-value"><?= $texto($evolucionActividad['variacion_label'] ?? '—') ?></p>
                    <p class="metric-label">Variación vs. periodo anterior</p>
                </div>
            </article>
        </section>

        <?php if (($evolucionActividad['mensaje'] ?? '') !== ''): ?>
            <p class="text-muted small mb-3">
                <?= $texto($evolucionActividad['mensaje']) ?>
            </p>
        <?php endif; ?>

        <div class="overflow-hidden">
            <svg
                class="w-100"
                viewBox="0 0 <?= $anchoGrafica ?> <?= $altoGrafica ?>"
                role="img"
                aria-label="Gráfica de línea de actividades registradas durante el periodo seleccionado"
                preserveAspectRatio="xMidYMid meet">
                <?php foreach ($marcasEjeY as $marcaY): ?>
                    <line
                        x1="<?= $margenIzquierdo ?>"
                        y1="<?= number_format($marcaY['y'], 2, '.', '') ?>"
                        x2="<?= $anchoGrafica - $margenDerecho ?>"
                        y2="<?= number_format($marcaY['y'], 2, '.', '') ?>"
                        stroke="var(--color-border)"
                        stroke-width="1" />
                    <text
                        x="<?= $margenIzquierdo - 12 ?>"
                        y="<?= number_format($marcaY['y'] + 4, 2, '.', '') ?>"
                        text-anchor="end"
                        font-size="12"
                        fill="var(--color-text-secondary)"><?= (int)$marcaY['valor'] ?></text>
                <?php endforeach; ?>

                <line
                    x1="<?= $margenIzquierdo ?>"
                    y1="<?= $margenSuperior + $altoTrazado ?>"
                    x2="<?= $anchoGrafica - $margenDerecho ?>"
                    y2="<?= $margenSuperior + $altoTrazado ?>"
                    stroke="var(--color-border)"
                    stroke-width="1" />

                <polyline
                    fill="none"
                    stroke="var(--color-primary)"
                    stroke-width="4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    points="<?= $texto(implode(' ', $puntosLinea)) ?>" />

                <?php foreach ($puntosActividad as $indice => $punto): ?>
                    <circle
                        cx="<?= number_format($punto['x'], 2, '.', '') ?>"
                        cy="<?= number_format($punto['y'], 2, '.', '') ?>"
                        r="5"
                        fill="var(--color-primary)">
                        <title><?= $texto($punto['tooltip'] . ': ' . $punto['total'] . ' actividades') ?></title>
                    </circle>

                    <?php if ($indice % $saltoEtiquetaX === 0 || $indice === count($puntosActividad) - 1): ?>
                        <text
                            x="<?= number_format($punto['x'], 2, '.', '') ?>"
                            y="<?= $altoGrafica - 24 ?>"
                            text-anchor="middle"
                            font-size="12"
                            fill="var(--color-text-secondary)"><?= $texto($punto['label']) ?></text>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
        </div>

        <?php if (($evolucionActividad['periodo_anterior'] ?? '') !== ''): ?>
            <p class="form-text mb-0">
                Periodo anterior comparado: <?= $texto($evolucionActividad['periodo_anterior']) ?>.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
