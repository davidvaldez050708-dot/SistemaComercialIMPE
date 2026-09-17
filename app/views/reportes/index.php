<?php
$puedeReporteTerritorial = $puedeReporteTerritorial ?? false;
$puedeReporteSeguimiento = $puedeReporteSeguimiento ?? false;
$puedeReporteAdministrador = $puedeReporteAdministrador ?? false;
?>

<section class="report-module">
    <section class="dashboard-panel report-intro-panel">
        <div>
            <span class="report-eyebrow">CENTRO DE REPORTES</span>
            <h2 class="panel-title mb-1">Selecciona la información que deseas analizar</h2>
            <p class="page-subtitle mb-0">
                Cada reporte conserva el alcance de información permitido para tu usuario y utiliza los datos registrados en su módulo de origen.
            </p>
        </div>
        <span class="metric-icon report-intro-icon" aria-hidden="true">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </span>
    </section>

    <div class="report-catalog-grid">
        <?php if ($puedeReporteTerritorial): ?>
            <article class="dashboard-panel report-catalog-card">
                <div class="report-card-heading">
                    <span class="metric-icon">
                        <i class="bi bi-map"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">INFORMACIÓN TERRITORIAL</span>
                        <h3>Reporte territorial</h3>
                    </div>
                </div>

                <p>
                    Integra datos generales del Estado, actividad económica, poder adquisitivo,
                    educación, priorización municipal, cálculos derivados y fuentes oficiales.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-check2-circle"></i> Datos oficiales y registrados</span>
                    <span><i class="bi bi-calculator"></i> Indicadores y comparaciones</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Exportación a PDF</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="<?= BASE_URL ?>index.php?controller=dataTerritorialReporte&action=index">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Generar reporte</span>
                </a>
            </article>
        <?php endif; ?>

        <?php if ($puedeReporteSeguimiento): ?>
            <article class="dashboard-panel report-catalog-card">
                <div class="report-card-heading">
                    <span class="metric-icon">
                        <i class="bi bi-kanban"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">SEGUIMIENTO DE VINCULACIÓN</span>
                        <h3>Reporte de seguimiento</h3>
                    </div>
                </div>

                <p>
                    Consulta el generador de reportes de Seguimiento de vinculación con sus filtros,
                    indicadores y exportación actualmente implementados.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-funnel"></i> Filtros de seguimiento</span>
                    <span><i class="bi bi-activity"></i> Actividad e indicadores</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Consulta y exportación</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacionReporte&action=index&origen=reportes">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Abrir reporte</span>
                </a>
            </article>
        <?php endif; ?>

        <?php if ($puedeReporteAdministrador): ?>
            <article class="dashboard-panel report-catalog-card">
                <div class="report-card-heading">
                    <span class="metric-icon">
                        <i class="bi bi-people"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">ADMINISTRACIÓN</span>
                        <h3>Reporte de usuarios</h3>
                    </div>
                </div>

                <p>
                    Consolida la información de usuarios del sistema, su estado, último acceso,
                    carga de seguimientos, acciones programadas y casos que requieren atención.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-person-check"></i> Usuarios, roles y estado</span>
                    <span><i class="bi bi-list-check"></i> Seguimientos y pendientes</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Exportación a PDF</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="<?= BASE_URL ?>index.php?controller=reporteAdministrador&action=exportarPdf">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Generar reporte</span>
                </a>
            </article>
        <?php endif; ?>
    </div>
</section>
