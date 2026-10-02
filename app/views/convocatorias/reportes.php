<?php
$resumenConvocatoriasReporte = is_array($resumenConvocatoriasReporte ?? null)
    ? $resumenConvocatoriasReporte
    : [];
$coberturaConvocatoriasReporte = is_array($coberturaConvocatoriasReporte ?? null)
    ? $coberturaConvocatoriasReporte
    : [];
$publicacionesPorTipoReporte = is_array($publicacionesPorTipoReporte ?? null)
    ? $publicacionesPorTipoReporte
    : [];

$totalConvocatorias = (int)($resumenConvocatoriasReporte['total'] ?? 0);
$totalActivas = (int)($resumenConvocatoriasReporte['activas'] ?? 0);
$totalInactivas = (int)($resumenConvocatoriasReporte['inactivas'] ?? 0);
$totalProximas = (int)($resumenConvocatoriasReporte['proximas_finalizar'] ?? 0);

$totalEstados = (int)($coberturaConvocatoriasReporte['total_estados'] ?? 0);
$estadosCubiertos = (int)($coberturaConvocatoriasReporte['estados_cubiertos'] ?? 0);

$totalBachillerato = (int)($publicacionesPorTipoReporte['bachillerato']['total'] ?? 0);
$totalTitulacion = (int)($publicacionesPorTipoReporte['titulacion']['total'] ?? 0);

$urlDashboardMarketing = BASE_URL . 'index.php?controller=home&action=index';
$urlConvocatorias = BASE_URL . 'index.php?controller=convocatoria&action=index';
$urlPublicacionesDia = BASE_URL .
    'index.php?controller=convocatoria&action=publicacionesDelDia&fecha=' .
    date('Y-m-d');
?>

<section class="report-module convocatoria-report-module">
    <section class="dashboard-panel report-intro-panel">
        <div>
            <span class="report-eyebrow">REPORTES DE CONVOCATORIAS</span>
            <h2 class="panel-title mb-1">Selecciona la información que deseas consultar</h2>
            <p class="page-subtitle mb-0">
                Consulta indicadores de publicaciones, vigencias, cobertura territorial
                y distribución por tipo utilizando la información registrada en Convocatorias.
            </p>
        </div>

        <span class="metric-icon report-intro-icon" aria-hidden="true">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </span>
    </section>

    <div class="report-catalog-grid">
        <article class="dashboard-panel report-catalog-card report-catalog-card--seguimiento">
            <div class="report-card-heading">
                <span class="metric-icon report-card-icon">
                    <i class="bi bi-megaphone"></i>
                </span>

                <div>
                    <span class="report-card-kicker">RESUMEN GENERAL</span>
                    <h3>Reporte de convocatorias</h3>
                </div>
            </div>

            <p class="report-card-description">
                Consulta el estado general de las convocatorias registradas,
                incluyendo publicaciones activas, inactivas y próximas a vencer.
            </p>

            <div class="report-card-meta">
                <span>
                    <i class="bi bi-files"></i>
                    <?= $totalConvocatorias ?> convocatorias registradas
                </span>
                <span>
                    <i class="bi bi-check2-circle"></i>
                    <?= $totalActivas ?> activas · <?= $totalInactivas ?> inactivas
                </span>
                <span>
                    <i class="bi bi-clock"></i>
                    <?= $totalProximas ?> próximas a vencer
                </span>
            </div>

            <a
                class="btn btn-system-save report-card-action"
                href="<?= $urlDashboardMarketing ?>">
                <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                <span>Consultar resumen</span>
            </a>

            <span
                class="report-card-decoration report-card-decoration--seguimiento"
                aria-hidden="true">
                <i class="bi bi-bar-chart-fill"></i>
            </span>
        </article>


    </div>
</section>
