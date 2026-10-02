<?php
$reporteConvocatorias = is_array($reporteConvocatorias ?? null)
    ? $reporteConvocatorias
    : [];

$resumen = is_array($reporteConvocatorias['resumen'] ?? null)
    ? $reporteConvocatorias['resumen']
    : [];

$cobertura = is_array($reporteConvocatorias['cobertura'] ?? null)
    ? $reporteConvocatorias['cobertura']
    : [];

$porTipo = is_array($reporteConvocatorias['por_tipo'] ?? null)
    ? $reporteConvocatorias['por_tipo']
    : [];

$detalle = is_array($reporteConvocatorias['detalle'] ?? null)
    ? $reporteConvocatorias['detalle']
    : [];

$hallazgos = is_array($reporteConvocatorias['hallazgos'] ?? null)
    ? $reporteConvocatorias['hallazgos']
    : [];

$alertasVencimiento = is_array(
    $reporteConvocatorias['alertas_vencimiento'] ?? null
)
    ? $reporteConvocatorias['alertas_vencimiento']
    : [];

$territorios = is_array($cobertura['territorios'] ?? null)
    ? $cobertura['territorios']
    : [];

$urlExportarPdf = (string)($urlExportarPdf ?? '');
$puedeExportarPdf = tienePermiso('reportes.exportar');

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$fecha = static function ($valor) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return '—';
    }

    $timestamp = strtotime($valor);

    return $timestamp !== false ? date('d/m/Y', $timestamp) : $valor;
};

$tipoLabel = static function ($tipo) {
    $tipo = strtolower(trim((string)$tipo));

    if ($tipo === 'bachillerato') {
        return 'Bachillerato';
    }

    if ($tipo === 'titulacion') {
        return 'Titulación';
    }

    if ($tipo === 'sindicatos') {
        return 'Sindicatos';
    }

    return $tipo !== '' ? ucfirst($tipo) : '—';
};

$subtipoLabel = static function ($subtipo) {
    $subtipo = trim((string)$subtipo);

    return $subtipo !== ''
        ? ucwords(str_replace('-', ' ', $subtipo))
        : '—';
};

$estadoLabels = [
    'activa' => 'Activa',
    'proxima' => 'Próxima a vencer',
    'finalizada' => 'Finalizada',
    'inactiva' => 'Inactiva'
];

$mesesBachillerato = is_array($porTipo['bachillerato']['meses'] ?? null)
    ? $porTipo['bachillerato']['meses']
    : [];
$mesesTitulacion = is_array($porTipo['titulacion']['meses'] ?? null)
    ? $porTipo['titulacion']['meses']
    : [];

$serieMensual = [];
$maximoMensual = 1;

for ($indiceMes = 0; $indiceMes < max(count($mesesBachillerato), count($mesesTitulacion)); $indiceMes++) {
    $filaBachillerato = $mesesBachillerato[$indiceMes] ?? [];
    $filaTitulacion = $mesesTitulacion[$indiceMes] ?? [];

    $labelMes = (string)(
        $filaBachillerato['label'] ??
        $filaTitulacion['label'] ??
        ''
    );
    $totalBachilleratoMes = (int)($filaBachillerato['total'] ?? 0);
    $totalTitulacionMes = (int)($filaTitulacion['total'] ?? 0);

    $maximoMensual = max(
        $maximoMensual,
        $totalBachilleratoMes,
        $totalTitulacionMes
    );

    $serieMensual[] = [
        'label' => $labelMes,
        'bachillerato' => $totalBachilleratoMes,
        'titulacion' => $totalTitulacionMes
    ];
}
?>

<section class="report-module report-territorial-module convocatoria-report-preview">
    <div class="convocatoria-report-preview-toolbar">
        <a
            class="linkage-back-link territorial-back-link"
            href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver a Reportes
        </a>

        <?php if ($puedeExportarPdf): ?>
            <a
                class="btn btn-system-save report-export-action"
                href="<?= $texto($urlExportarPdf) ?>">
                <i class="bi bi-file-earmark-pdf"></i>
                Exportar PDF
            </a>
        <?php endif; ?>
    </div>

    <section class="dashboard-panel report-intro-panel mb-4">
        <div>
            <span class="report-eyebrow">REPORTE DE CONVOCATORIAS</span>
            <h2 class="panel-title mb-1">Resumen ejecutivo</h2>
            <p class="page-subtitle mb-0">
                Vista consolidada del estado de las convocatorias, su cobertura territorial,
                distribución por tipo y detalle operativo actual.
            </p>
        </div>

        <span class="metric-icon report-intro-icon" aria-hidden="true">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </span>
    </section>

    <div class="territorial-section-title">
        <h2>RESUMEN EJECUTIVO</h2>
        <p>Indicadores calculados con el mismo alcance utilizado por el archivo PDF.</p>
    </div>

    <section class="metric-grid report-summary-grid mb-4">
        <article class="metric-card">
            <div class="metric-icon">
                <i class="bi bi-files"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['total'] ?? 0) ?></p>
                <p class="metric-label">Registradas</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon metric-icon-success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['activas'] ?? 0) ?></p>
                <p class="metric-label">Activas</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon metric-icon-muted">
                <i class="bi bi-pause-circle"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['inactivas'] ?? 0) ?></p>
                <p class="metric-label">Inactivas</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon">
                <i class="bi bi-calendar-plus"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['publicaciones_mes_actual'] ?? 0) ?></p>
                <p class="metric-label">Publicadas este mes</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon">
                <i class="bi bi-calendar-day"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['publicaciones_hoy'] ?? 0) ?></p>
                <p class="metric-label">Publicadas hoy</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon convocatoria-report-icon-danger">
                <i class="bi bi-exclamation-octagon"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['vencen_hoy'] ?? 0) ?></p>
                <p class="metric-label">Vencen hoy</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon convocatoria-report-icon-warning">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['vencen_2_dias'] ?? 0) ?></p>
                <p class="metric-label">Vencen en 1–2 días</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon">
                <i class="bi bi-map"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['porcentaje_cobertura'] ?? 0) ?>%</p>
                <p class="metric-label">Cobertura territorial</p>
            </div>
        </article>
    </section>

    <div class="territorial-section-title">
        <h2>PUBLICACIONES POR TIPO</h2>
        <p>Total de publicaciones por tipo de convocatoria y comportamiento de los últimos 4 meses.</p>
    </div>

    <section class="dashboard-panel convocatoria-report-analysis-card mb-4">
        <div class="convocatoria-report-chart-heading">
            <div>
                <span class="report-card-kicker">TENDENCIA MENSUAL</span>
                <h3>Publicaciones de los últimos 4 meses</h3>
                <p class="page-subtitle mb-0">
                    Comparativo mensual entre publicaciones de Bachillerato y Titulación.
                </p>
            </div>

            <div class="convocatoria-report-chart-legend">
                <span><i class="is-bachillerato"></i>Bachillerato</span>
                <span><i class="is-titulacion"></i>Titulación</span>
            </div>
        </div>

        <div class="convocatoria-report-grouped-chart">
            <?php foreach ($serieMensual as $mesSerie): ?>
                <?php
                $bachilleratoMes = (int)($mesSerie['bachillerato'] ?? 0);
                $titulacionMes = (int)($mesSerie['titulacion'] ?? 0);
                $alturaBachillerato = max(
                    5,
                    (int)round(($bachilleratoMes / $maximoMensual) * 130)
                );
                $alturaTitulacion = max(
                    5,
                    (int)round(($titulacionMes / $maximoMensual) * 130)
                );
                ?>
                <div class="convocatoria-report-group">
                    <div class="convocatoria-report-group-bars">
                        <div class="convocatoria-report-chart-bar-wrap">
                            <strong><?= $bachilleratoMes ?></strong>
                            <span
                                class="convocatoria-report-chart-bar is-bachillerato"
                                style="height: <?= $alturaBachillerato ?>px"></span>
                        </div>
                        <div class="convocatoria-report-chart-bar-wrap">
                            <strong><?= $titulacionMes ?></strong>
                            <span
                                class="convocatoria-report-chart-bar is-titulacion"
                                style="height: <?= $alturaTitulacion ?>px"></span>
                        </div>
                    </div>
                    <small><?= $texto($mesSerie['label'] ?? '') ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-panel convocatoria-report-analysis-card mb-4">
        <div class="convocatoria-report-section-heading">
            <div>
                <span class="report-card-kicker">HALLAZGOS</span>
                <h3>Lectura ejecutiva</h3>
            </div>
            <i class="bi bi-lightbulb"></i>
        </div>

        <ul class="convocatoria-report-findings">
            <?php foreach ($hallazgos as $hallazgo): ?>
                <li>
                    <i class="bi bi-check2-circle"></i>
                    <span><?= $texto($hallazgo) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <div class="territorial-section-title">
        <h2>ALERTAS DE VENCIMIENTO</h2>
        <p>Convocatorias activas que vencen hoy o dentro de los próximos 2 días.</p>
    </div>

    <section class="dashboard-panel convocatoria-report-table-panel mb-4">
        <div class="table-responsive">
            <table class="table users-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Convocatoria</th>
                        <th>Tipo</th>
                        <th>Territorio(s)</th>
                        <th>Fecha de vencimiento</th>
                        <th>Prioridad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alertasVencimiento)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-table-message">
                                    No hay convocatorias con vencimiento hoy o en los próximos 2 días.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alertasVencimiento as $alerta): ?>
                            <?php
                            $diasRestantes = (int)($alerta['dias_restantes'] ?? 0);
                            $prioridadTexto = $diasRestantes === 0
                                ? 'Vence hoy'
                                : ($diasRestantes === 1 ? 'Vence mañana' : 'Vence en 2 días');
                            $prioridadClase = $diasRestantes === 0
                                ? 'is-danger'
                                : 'is-warning';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= $texto($alerta['titulo'] ?? '') ?></strong>
                                </td>
                                <td>
                                    <?= $texto($tipoLabel($alerta['tipo_convocatoria'] ?? '')) ?>
                                </td>
                                <td><?= $texto($alerta['estados'] ?? '—') ?></td>
                                <td><?= $texto($fecha($alerta['fecha_termino'] ?? '')) ?></td>
                                <td>
                                    <span class="convocatoria-report-priority <?= $texto($prioridadClase) ?>">
                                        <?= $texto($prioridadTexto) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="territorial-section-title">
        <h2>COBERTURA TERRITORIAL</h2>
        <p>Estados con convocatorias activas al momento de generar el reporte.</p>
    </div>

    <section class="dashboard-panel convocatoria-report-table-panel mb-4">
        <div class="table-responsive">
            <table class="table users-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Estado</th>
                        <th class="text-end">Convocatorias activas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($territorios)): ?>
                        <tr>
                            <td colspan="2">
                                <div class="empty-table-message">
                                    No hay cobertura territorial activa registrada.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($territorios as $territorio): ?>
                            <tr>
                                <td><?= $texto($territorio['nombre'] ?? '') ?></td>
                                <td class="text-end">
                                    <strong><?= (int)($territorio['convocatorias_activas'] ?? 0) ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="territorial-section-title">
        <h2>DETALLE DE CONVOCATORIAS</h2>
        <p>Listado completo utilizado para construir el resumen y el PDF.</p>
    </div>

    <section class="dashboard-panel convocatoria-report-table-panel">
        <div class="table-responsive">
            <table class="table users-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Subtipo</th>
                        <th>Periodo</th>
                        <th>Territorio(s)</th>
                        <th>Estatus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($detalle)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-table-message">
                                    No hay convocatorias registradas.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($detalle as $fila): ?>
                            <?php
                            $estadoProceso = (string)($fila['estado_proceso'] ?? 'inactiva');
                            ?>
                            <tr>
                                <td>
                                    <strong><?= $texto($fila['titulo'] ?? '') ?></strong>
                                </td>
                                <td><?= $texto($tipoLabel($fila['tipo_convocatoria'] ?? '')) ?></td>
                                <td><?= $texto($subtipoLabel($fila['subtipo_convocatoria'] ?? '')) ?></td>
                                <td>
                                    <?= $texto($fecha($fila['fecha_inicio'] ?? '')) ?>
                                    -
                                    <?= $texto($fecha($fila['fecha_termino'] ?? '')) ?>
                                </td>
                                <td><?= $texto($fila['estados'] ?? '—') ?></td>
                                <td>
                                    <span class="convocatoria-process-badge convocatoria-process-<?= $texto($estadoProceso) ?>">
                                        <?= $texto($estadoLabels[$estadoProceso] ?? 'Inactiva') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
