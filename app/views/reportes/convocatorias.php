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

$territorios = is_array($cobertura['territorios'] ?? null)
    ? $cobertura['territorios']
    : [];

$urlExportarPdf = (string)($urlExportarPdf ?? '');

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

$bachillerato30 = (int)($resumen['bachillerato_30'] ?? 0);
$titulacion30 = (int)($resumen['titulacion_30'] ?? 0);
$totalTipos30 = max(1, $bachillerato30 + $titulacion30);
$bachilleratoPct = (int)round(($bachillerato30 / $totalTipos30) * 100);
$titulacionPct = (int)round(($titulacion30 / $totalTipos30) * 100);
?>

<section class="report-module report-territorial-module convocatoria-report-preview">
    <div class="convocatoria-report-preview-toolbar">
        <a
            class="linkage-back-link territorial-back-link"
            href="<?= BASE_URL ?>index.php?controller=convocatoria&action=reportes">
            <i class="bi bi-arrow-left"></i>
            Volver a Reportes
        </a>

        <a
            class="btn btn-system-save report-export-action"
            href="<?= $texto($urlExportarPdf) ?>">
            <i class="bi bi-file-earmark-pdf"></i>
            Exportar PDF
        </a>
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
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['vigentes'] ?? 0) ?></p>
                <p class="metric-label">Vigentes</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon">
                <i class="bi bi-clock"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['proximas_finalizar'] ?? 0) ?></p>
                <p class="metric-label">Próximas a vencer</p>
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

    <div class="convocatoria-report-preview-grid mb-4">
        <section class="dashboard-panel convocatoria-report-analysis-card">
            <div class="convocatoria-report-section-heading">
                <div>
                    <span class="report-card-kicker">PUBLICACIONES</span>
                    <h3>Distribución por tipo</h3>
                </div>
                <i class="bi bi-pie-chart"></i>
            </div>

            <div class="convocatoria-report-type-list">
                <div>
                    <div class="convocatoria-report-type-copy">
                        <span>Bachillerato</span>
                        <strong><?= $bachillerato30 ?></strong>
                    </div>
                    <div class="convocatoria-report-progress">
                        <span style="width: <?= $bachilleratoPct ?>%"></span>
                    </div>
                    <small>Últimos 30 días</small>
                </div>

                <div>
                    <div class="convocatoria-report-type-copy">
                        <span>Titulación</span>
                        <strong><?= $titulacion30 ?></strong>
                    </div>
                    <div class="convocatoria-report-progress">
                        <span style="width: <?= $titulacionPct ?>%"></span>
                    </div>
                    <small>Últimos 30 días</small>
                </div>
            </div>
        </section>

        <section class="dashboard-panel convocatoria-report-analysis-card">
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
    </div>

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
