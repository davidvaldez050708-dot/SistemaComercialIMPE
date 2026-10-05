<?php

$territorios = is_array($territorios ?? null) ? $territorios : [];
$municipios = is_array($municipios ?? null) ? $municipios : [];
$filtrosReporte = is_array($filtrosReporte ?? null) ? $filtrosReporte : [];
$generarReporte = (bool)($generarReporte ?? false);
$urlExportarPdf = (string)($urlExportarPdf ?? '');
$errorExportacionPdf = (string)($errorExportacionPdf ?? '');
$reporteAliados = is_array($reporteAliados ?? null)
    ? $reporteAliados
    : [];

$resumen = is_array($reporteAliados['resumen'] ?? null)
    ? $reporteAliados['resumen']
    : [];
$porEstado = is_array($reporteAliados['por_estado'] ?? null)
    ? $reporteAliados['por_estado']
    : [];
$porMunicipio = is_array($reporteAliados['por_municipio'] ?? null)
    ? $reporteAliados['por_municipio']
    : [];
$atencion = is_array($reporteAliados['atencion'] ?? null)
    ? $reporteAliados['atencion']
    : [];
$detalle = is_array($reporteAliados['detalle'] ?? null)
    ? $reporteAliados['detalle']
    : [];
$hallazgos = is_array($reporteAliados['hallazgos'] ?? null)
    ? $reporteAliados['hallazgos']
    : [];

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$fecha = static function ($valor, $conHora = false) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '—';
    }

    $timestamp = strtotime($valor);
    if ($timestamp === false) {
        return $valor;
    }

    return date($conHora ? 'd/m/Y · H:i' : 'd/m/Y', $timestamp);
};

$canal = static function ($valor) {
    $valor = strtoupper(trim((string)$valor));
    $mapa = [
        'WHATSAPP_MANUAL' => 'WhatsApp manual',
        'WHATSAPP' => 'WhatsApp',
        'CORREO' => 'Correo'
    ];

    return $mapa[$valor] ?? ($valor !== '' ? $valor : '—');
};

$situaciones = [
    'todos' => 'Todos los aliados',
    'con_difusion' => 'Con difusión',
    'sin_difusion' => 'Sin difusión',
    'pendientes' => 'Seguimiento pendiente',
    'vencidos' => 'Seguimiento vencido',
    'esperando_respuesta' => 'Esperando respuesta',
    'sin_respuesta' => 'Sin respuesta',
    'solicita_informacion' => 'Solicita información',
    'difusion_confirmada' => 'Difusión confirmada',
    'no_participara' => 'No participará'
];

$estadoSeleccionado = 'Todos mis territorios';
foreach ($territorios as $territorio) {
    if (
        (int)($territorio['id'] ?? 0) ===
        (int)($filtrosReporte['estado_id'] ?? 0)
    ) {
        $estadoSeleccionado = (string)($territorio['nombre'] ?? '');
        break;
    }
}

$municipioSeleccionado = 'Todos';
foreach ($municipios as $municipio) {
    if (
        (int)($municipio['id'] ?? 0) ===
        (int)($filtrosReporte['municipio_id'] ?? 0)
    ) {
        $municipioSeleccionado = (string)($municipio['nombre'] ?? '');
        break;
    }
}

$situacionSeleccionada = (string)($filtrosReporte['situacion'] ?? 'todos');
$situacionSeleccionadaTexto =
    $situaciones[$situacionSeleccionada] ?? 'Todos los aliados';

$modoAnalisis = (string)($reporteAliados['modo'] ?? '');
if ($modoAnalisis === '') {
    $modoAnalisis = (int)($filtrosReporte['municipio_id'] ?? 0) > 0
        ? 'municipio'
        : (
            (int)($filtrosReporte['estado_id'] ?? 0) > 0
                ? 'estado'
                : 'red'
        );
}

$tituloResultado = 'Panorama de Aliados';
$subtituloResultado = 'Todos mis territorios · ' . $situacionSeleccionadaTexto;
$descripcionContexto =
    'Fotografía general de la red institucional dentro de todos tus territorios autorizados.';

if ($modoAnalisis === 'estado') {
    $tituloResultado = 'Aliados en ' . $estadoSeleccionado;
    $subtituloResultado =
        'Análisis municipal · ' . $situacionSeleccionadaTexto;
    $descripcionContexto =
        'Lectura del estado seleccionado con desglose de cobertura y seguimiento por municipio.';
} elseif ($modoAnalisis === 'municipio') {
    $tituloResultado = 'Aliados en ' . $municipioSeleccionado;
    $subtituloResultado =
        $estadoSeleccionado . ' · ' . $situacionSeleccionadaTexto;
    $descripcionContexto =
        'Vista operativa de las instituciones aliadas dentro del municipio seleccionado.';
}
?>

<section class="report-module aliados-report-module">
    <a
        class="linkage-back-link territorial-back-link"
        href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a Reportes
    </a>

    <?php if ($errorExportacionPdf !== ''): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorExportacionPdf) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$generarReporte): ?>
        <section class="dashboard-panel report-filter-panel aliados-report-generator mb-4">
            <div class="report-filter-heading">
                <div>
                    <span class="report-eyebrow">ALIADOS</span>
                    <h2 class="panel-title mb-1">Generar panorama de aliados</h2>
                    <p class="page-subtitle mb-0">
                        Selecciona el nivel territorial y la situación que deseas analizar.
                        El reporte adapta automáticamente el detalle a red, estado o municipio.
                    </p>
                </div>
                <span class="metric-icon" aria-hidden="true">
                    <i class="bi bi-diagram-3"></i>
                </span>
            </div>

            <?php require __DIR__ . '/partials/aliados_panorama_filtros.php'; ?>
        </section>

        <section class="dashboard-panel data-empty-state report-empty-state">
            <span><i class="bi bi-file-earmark-bar-graph"></i></span>
            <strong>Configura el alcance para preparar el reporte.</strong>
            <p>
                Todos los estados compara territorios; un estado profundiza por municipio;
                un municipio muestra la situación concreta de sus aliados.
            </p>
        </section>
    <?php else: ?>
        <div class="aliados-report-result-toolbar">
            <div>
                <h1><?= $texto($tituloResultado) ?></h1>
                <p><?= $texto($subtituloResultado) ?></p>
            </div>
            <div class="aliados-report-result-actions">
                <button
                    type="button"
                    class="btn btn-system-light"
                    data-bs-toggle="collapse"
                    data-bs-target="#aliadosReportFilters"
                    aria-expanded="false"
                    aria-controls="aliadosReportFilters">
                    <i class="bi bi-sliders"></i>
                    Editar filtros
                </button>
                <?php if ($urlExportarPdf !== ''): ?>
                    <a
                        class="btn btn-system-save"
                        href="<?= $texto($urlExportarPdf) ?>">
                        <i class="bi bi-file-earmark-pdf"></i>
                        Generar PDF
                    </a>
                <?php endif; ?>
                <span class="aliados-report-generated">
                    <i class="bi bi-check2-circle"></i>
                    Actualizado <?= $texto(date('H:i')) ?>
                </span>
            </div>
        </div>

        <div class="collapse mb-3" id="aliadosReportFilters">
            <section class="dashboard-panel report-filter-panel aliados-report-generator">
                <div class="report-filter-heading">
                    <div>
                        <span class="report-eyebrow">ALCANCE DEL REPORTE</span>
                        <h2 class="panel-title mb-1">Editar filtros</h2>
                        <p class="page-subtitle mb-0">
                            Ajusta territorio, municipio o situación y vuelve a generar el panorama.
                        </p>
                    </div>
                </div>

                <?php require __DIR__ . '/partials/aliados_panorama_filtros.php'; ?>
            </section>
        </div>

        <section class="dashboard-panel analyst-portfolio-context mb-3" aria-label="Contexto del reporte">
            <div class="analyst-portfolio-section-heading">
                <div>
                    <span class="report-eyebrow">CONTEXTO DEL REPORTE</span>
                    <h3 class="panel-title mb-1">
                        <?= $modoAnalisis === 'red'
                            ? 'Red supervisada'
                            : ($modoAnalisis === 'estado'
                                ? 'Estado analizado'
                                : 'Municipio analizado') ?>
                    </h3>
                    <p class="page-subtitle mb-0">
                        <?= $texto($descripcionContexto) ?>
                    </p>
                </div>
                <span class="analyst-portfolio-detail-count">
                    <?= (int)($resumen['total'] ?? 0) ?>
                    <?= (int)($resumen['total'] ?? 0) === 1 ? 'aliado' : 'aliados' ?>
                </span>
            </div>

            <div class="analyst-portfolio-context-grid">
                <?php if ($modoAnalisis === 'red'): ?>
                    <div>
                        <span>Alcance territorial</span>
                        <strong>Todos mis territorios</strong>
                    </div>
                    <div>
                        <span>Estados con aliados</span>
                        <strong><?= (int)($resumen['estados'] ?? 0) ?></strong>
                    </div>
                    <div>
                        <span>Municipios cubiertos</span>
                        <strong><?= (int)($resumen['municipios'] ?? 0) ?></strong>
                    </div>
                    <div>
                        <span>Situación</span>
                        <strong><?= $texto($situacionSeleccionadaTexto) ?></strong>
                    </div>
                <?php elseif ($modoAnalisis === 'estado'): ?>
                    <div>
                        <span>Estado</span>
                        <strong><?= $texto($estadoSeleccionado) ?></strong>
                    </div>
                    <div>
                        <span>Municipios con aliados</span>
                        <strong><?= (int)($resumen['municipios'] ?? 0) ?></strong>
                    </div>
                    <div>
                        <span>Situación</span>
                        <strong><?= $texto($situacionSeleccionadaTexto) ?></strong>
                    </div>
                    <div>
                        <span>Aliados incluidos</span>
                        <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                    </div>
                <?php else: ?>
                    <div>
                        <span>Estado</span>
                        <strong><?= $texto($estadoSeleccionado) ?></strong>
                    </div>
                    <div>
                        <span>Municipio</span>
                        <strong><?= $texto($municipioSeleccionado) ?></strong>
                    </div>
                    <div>
                        <span>Situación</span>
                        <strong><?= $texto($situacionSeleccionadaTexto) ?></strong>
                    </div>
                    <div>
                        <span>Aliados incluidos</span>
                        <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="analyst-portfolio-kpis mb-3" aria-label="Indicadores del panorama">
            <?php if ($modoAnalisis === 'red'): ?>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-buildings"></i></span>
                    <div>
                        <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                        <span>Aliados en la red</span>
                        <small>Instituciones incluidas en la consulta</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-map"></i></span>
                    <div>
                        <strong><?= (int)($resumen['estados'] ?? 0) ?></strong>
                        <span>Estados con aliados</span>
                        <small>Territorios con presencia institucional</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-geo-alt"></i></span>
                    <div>
                        <strong><?= (int)($resumen['municipios'] ?? 0) ?></strong>
                        <span>Municipios cubiertos</span>
                        <small>Distribución territorial de la red</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-megaphone"></i></span>
                    <div>
                        <strong><?= (int)($resumen['con_difusion'] ?? 0) ?></strong>
                        <span>Con difusión</span>
                        <small><?= $texto(number_format((float)($resumen['cobertura_difusion'] ?? 0), 1)) ?>% de la red</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi analyst-portfolio-kpi--attention">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-exclamation-circle"></i></span>
                    <div>
                        <strong><?= (int)($resumen['requieren_atencion'] ?? 0) ?></strong>
                        <span>Requieren atención</span>
                        <small>Aliados con una acción prioritaria</small>
                    </div>
                </article>
            <?php elseif ($modoAnalisis === 'estado'): ?>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-buildings"></i></span>
                    <div>
                        <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                        <span>Aliados en el estado</span>
                        <small>Instituciones incluidas en la consulta</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-geo-alt"></i></span>
                    <div>
                        <strong><?= (int)($resumen['municipios'] ?? 0) ?></strong>
                        <span>Municipios con aliados</span>
                        <small>Cobertura dentro del estado</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-megaphone"></i></span>
                    <div>
                        <strong><?= (int)($resumen['con_difusion'] ?? 0) ?></strong>
                        <span>Con difusión</span>
                        <small><?= $texto(number_format((float)($resumen['cobertura_difusion'] ?? 0), 1)) ?>% de los aliados</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi analyst-portfolio-kpi--success">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-patch-check"></i></span>
                    <div>
                        <strong><?= (int)($resumen['difusion_confirmada'] ?? 0) ?></strong>
                        <span>Difusión confirmada</span>
                        <small><?= $texto(number_format((float)($resumen['tasa_confirmacion'] ?? 0), 1)) ?>% sobre aliados con difusión</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi analyst-portfolio-kpi--attention">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-exclamation-circle"></i></span>
                    <div>
                        <strong><?= (int)($resumen['requieren_atencion'] ?? 0) ?></strong>
                        <span>Requieren atención</span>
                        <small>Prioridad operativa dentro del estado</small>
                    </div>
                </article>
            <?php else: ?>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-buildings"></i></span>
                    <div>
                        <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                        <span>Aliados en el municipio</span>
                        <small>Instituciones incluidas en la consulta</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-megaphone"></i></span>
                    <div>
                        <strong><?= (int)($resumen['con_difusion'] ?? 0) ?></strong>
                        <span>Con difusión</span>
                        <small><?= $texto(number_format((float)($resumen['cobertura_difusion'] ?? 0), 1)) ?>% de los aliados</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-whatsapp"></i></span>
                    <div>
                        <strong><?= (int)($resumen['con_whatsapp'] ?? 0) ?></strong>
                        <span>WhatsApp disponible</span>
                        <small><?= $texto(number_format((float)($resumen['cobertura_whatsapp'] ?? 0), 1)) ?>% del municipio</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi analyst-portfolio-kpi--success">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-patch-check"></i></span>
                    <div>
                        <strong><?= (int)($resumen['difusion_confirmada'] ?? 0) ?></strong>
                        <span>Difusión confirmada</span>
                        <small>Aliados con confirmación registrada</small>
                    </div>
                </article>
                <article class="analyst-portfolio-kpi analyst-portfolio-kpi--attention">
                    <span class="analyst-portfolio-kpi-icon"><i class="bi bi-exclamation-circle"></i></span>
                    <div>
                        <strong><?= (int)($resumen['requieren_atencion'] ?? 0) ?></strong>
                        <span>Requieren atención</span>
                        <small>Instituciones que conviene revisar</small>
                    </div>
                </article>
            <?php endif; ?>
        </section>

        <?php if ($modoAnalisis === 'red'): ?>
            <section class="dashboard-panel p-0 overflow-hidden mb-3">
                <div class="table-panel-header">
                    <div>
                        <span class="report-eyebrow">COMPARATIVO TERRITORIAL</span>
                        <h3 class="panel-title mb-0">Red por estado</h3>
                        <p class="page-subtitle mb-0 mt-1">
                            Compara tamaño de red, cobertura de difusión y carga de seguimiento entre estados.
                        </p>
                    </div>
                    <span class="analyst-portfolio-detail-count">
                        <?= count($porEstado) ?>
                        <?= count($porEstado) === 1 ? 'estado' : 'estados' ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0 aliados-report-territory-table">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th class="text-end">Aliados</th>
                                <th class="text-end">Municipios</th>
                                <th class="text-end">Con difusión</th>
                                <th class="text-end">Confirmadas</th>
                                <th class="text-end">Pendientes</th>
                                <th class="text-end">Vencidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($porEstado)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-table-message">
                                            No hay estados con aliados que coincidan con los filtros seleccionados.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($porEstado as $fila): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $texto($fila['estado'] ?? '') ?></strong>
                                            <small class="d-block text-muted">
                                                <?= $texto(number_format((float)($fila['cobertura_difusion'] ?? 0), 1)) ?>% con difusión
                                            </small>
                                        </td>
                                        <td class="text-end"><?= (int)($fila['aliados'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['municipios'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['con_difusion'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['difusion_confirmada'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['pendientes'] ?? 0) ?></td>
                                        <td class="text-end">
                                            <strong class="<?= (int)($fila['vencidos'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                <?= (int)($fila['vencidos'] ?? 0) ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($modoAnalisis === 'estado'): ?>
            <section class="dashboard-panel p-0 overflow-hidden mb-3">
                <div class="table-panel-header">
                    <div>
                        <span class="report-eyebrow">DESGLOSE MUNICIPAL</span>
                        <h3 class="panel-title mb-0">Cobertura por municipio</h3>
                        <p class="page-subtitle mb-0 mt-1">
                            Compara dónde se concentra la red y qué municipios requieren mayor seguimiento.
                        </p>
                    </div>
                    <span class="analyst-portfolio-detail-count">
                        <?= count($porMunicipio) ?>
                        <?= count($porMunicipio) === 1 ? 'municipio' : 'municipios' ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0 aliados-report-territory-table">
                        <thead>
                            <tr>
                                <th>Municipio</th>
                                <th class="text-end">Aliados</th>
                                <th class="text-end">Con difusión</th>
                                <th class="text-end">Confirmadas</th>
                                <th class="text-end">Sin respuesta</th>
                                <th class="text-end">Pendientes</th>
                                <th class="text-end">Vencidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($porMunicipio)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-table-message">
                                            No hay municipios con aliados que coincidan con los filtros seleccionados.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($porMunicipio as $fila): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $texto($fila['municipio'] ?? '') ?></strong>
                                            <small class="d-block text-muted">
                                                <?= $texto(number_format((float)($fila['cobertura_difusion'] ?? 0), 1)) ?>% con difusión
                                            </small>
                                        </td>
                                        <td class="text-end"><?= (int)($fila['aliados'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['con_difusion'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['difusion_confirmada'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['sin_respuesta'] ?? 0) ?></td>
                                        <td class="text-end"><?= (int)($fila['pendientes'] ?? 0) ?></td>
                                        <td class="text-end">
                                            <strong class="<?= (int)($fila['vencidos'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                                <?= (int)($fila['vencidos'] ?? 0) ?>
                                            </strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-portfolio-attention">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">ATENCIÓN OPERATIVA</span>
                            <h3 class="panel-title mb-1">Aliados que conviene revisar</h3>
                            <p class="page-subtitle mb-0">
                                Prioriza seguimientos vencidos, solicitudes de información,
                                casos sin respuesta y aliados sin difusión registrada.
                            </p>
                        </div>
                        <span class="analyst-portfolio-attention-count">
                            <?= (int)($resumen['requieren_atencion'] ?? 0) ?> por revisar
                        </span>
                    </div>

                    <?php if (!empty($atencion)): ?>
                        <div class="analyst-portfolio-priority-list">
                            <?php foreach ($atencion as $fila): ?>
                                <article>
                                    <div class="analyst-portfolio-priority-main">
                                        <strong><?= $texto($fila['institucion'] ?? '') ?></strong>
                                        <span>
                                            <?= $texto($fila['municipio'] ?? '') ?>
                                            <?= $modoAnalisis === 'red'
                                                ? ' · ' . $texto($fila['estado'] ?? '')
                                                : '' ?>
                                        </span>
                                    </div>
                                    <span class="aliados-report-priority is-<?= $texto($fila['tipo'] ?? '') ?>">
                                        <?= $texto($fila['etiqueta'] ?? '') ?>
                                    </span>
                                    <div class="analyst-portfolio-priority-meta">
                                        <span>
                                            <i class="bi bi-megaphone"></i>
                                            <?= $texto(trim((string)($fila['convocatoria'] ?? '')) !== ''
                                                ? $fila['convocatoria']
                                                : 'Sin convocatoria difundida') ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-arrow-right-circle"></i>
                                            <?= $texto($fila['accion'] ?? '') ?>
                                        </span>
                                        <?php if (trim((string)($fila['proximo_seguimiento_at'] ?? '')) !== ''): ?>
                                            <span>
                                                <i class="bi bi-clock"></i>
                                                <?= $texto($fecha($fila['proximo_seguimiento_at'], true)) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="analyst-portfolio-empty">
                            <i class="bi bi-check-circle"></i>
                            <div>
                                <strong>Sin prioridades de atención.</strong>
                                <span>
                                    Con los filtros actuales no hay aliados que requieran una acción inmediata.
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-portfolio-health">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">SALUD DE LA RED</span>
                            <h3 class="panel-title mb-1">
                                <?= $modoAnalisis === 'municipio'
                                    ? 'Contactabilidad y continuidad'
                                    : 'Estado operativo' ?>
                            </h3>
                            <p class="page-subtitle mb-0">
                                Señales rápidas para detectar cobertura incompleta o relaciones que necesitan seguimiento.
                            </p>
                        </div>
                    </div>

                    <div class="analyst-portfolio-health-grid">
                        <div>
                            <span>WhatsApp disponible</span>
                            <strong><?= (int)($resumen['con_whatsapp'] ?? 0) ?></strong>
                        </div>
                        <?php if ($modoAnalisis === 'municipio'): ?>
                            <div>
                                <span>Correo disponible</span>
                                <strong><?= (int)($resumen['con_correo'] ?? 0) ?></strong>
                            </div>
                        <?php else: ?>
                            <div>
                                <span>Sin difusión</span>
                                <strong><?= (int)($resumen['sin_difusion'] ?? 0) ?></strong>
                            </div>
                        <?php endif; ?>
                        <div>
                            <span>Sin respuesta</span>
                            <strong><?= (int)($resumen['sin_respuesta'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Solicita información</span>
                            <strong><?= (int)($resumen['solicita_informacion'] ?? 0) ?></strong>
                        </div>
                    </div>

                    <div class="analyst-portfolio-health-note">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            <?= $texto(number_format((float)($resumen['cobertura_whatsapp'] ?? 0), 1)) ?>%
                            de los aliados analizados cuenta con WhatsApp confirmado o verificado.
                        </span>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel aliados-report-insights">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">LECTURA EJECUTIVA</span>
                            <h3 class="panel-title mb-1">
                                <?= $modoAnalisis === 'red'
                                    ? 'Hallazgos entre territorios'
                                    : ($modoAnalisis === 'estado'
                                        ? 'Hallazgos del estado'
                                        : 'Hallazgos del municipio') ?>
                            </h3>
                            <p class="page-subtitle mb-0">
                                Síntesis automática construida según el nivel territorial seleccionado.
                            </p>
                        </div>
                    </div>

                    <div class="aliados-report-findings">
                        <?php foreach ($hallazgos as $hallazgo): ?>
                            <article>
                                <i class="bi bi-lightbulb"></i>
                                <p><?= $texto($hallazgo) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel aliados-report-followup-state">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">DISTRIBUCIÓN</span>
                            <h3 class="panel-title mb-1">Situación del seguimiento</h3>
                            <p class="page-subtitle mb-0">
                                La suma corresponde a los aliados incluidos en el alcance actual.
                            </p>
                        </div>
                    </div>

                    <div class="aliados-report-followup-grid">
                        <div>
                            <span>Difusión confirmada</span>
                            <strong><?= (int)($resumen['difusion_confirmada'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Esperando respuesta</span>
                            <strong><?= (int)($resumen['esperando_respuesta'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Sin respuesta</span>
                            <strong><?= (int)($resumen['sin_respuesta'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Solicita información</span>
                            <strong><?= (int)($resumen['solicita_informacion'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>No participará</span>
                            <strong><?= (int)($resumen['no_participara'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Sin seguimiento</span>
                            <strong><?= (int)($resumen['sin_seguimiento'] ?? 0) ?></strong>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="dashboard-panel p-0 overflow-hidden">
            <div class="table-panel-header">
                <div>
                    <span class="report-eyebrow">DETALLE DEL REPORTE</span>
                    <h3 class="panel-title mb-0">
                        <?= $modoAnalisis === 'red'
                            ? 'Aliados de la red'
                            : ($modoAnalisis === 'estado'
                                ? 'Aliados del estado'
                                : 'Aliados del municipio') ?>
                    </h3>
                    <p class="page-subtitle mb-0 mt-1">
                        Instituciones utilizadas para construir los indicadores y hallazgos anteriores.
                    </p>
                </div>
                <span class="analyst-portfolio-detail-count">
                    <?= count($detalle) ?>
                    <?= count($detalle) === 1 ? 'aliado' : 'aliados' ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table users-table align-middle mb-0 aliados-report-detail-table">
                    <thead>
                        <tr>
                            <th>Institución</th>
                            <th>
                                <?= $modoAnalisis === 'red'
                                    ? 'Territorio'
                                    : ($modoAnalisis === 'estado'
                                        ? 'Municipio'
                                        : 'Contactabilidad') ?>
                            </th>
                            <th>Formalización</th>
                            <th>Última difusión</th>
                            <th>Seguimiento</th>
                            <th>Próximo contacto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detalle)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-table-message">
                                        No hay aliados que coincidan con los filtros seleccionados.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detalle as $fila): ?>
                                <tr>
                                    <td>
                                        <strong><?= $texto($fila['institucion'] ?? '') ?></strong>
                                        <small class="d-block text-muted">
                                            <?= $texto($fila['analista'] ?? '') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($modoAnalisis === 'red'): ?>
                                            <?= $texto($fila['municipio'] ?? '') ?>
                                            <small class="d-block text-muted">
                                                <?= $texto($fila['estado'] ?? '') ?>
                                            </small>
                                        <?php elseif ($modoAnalisis === 'estado'): ?>
                                            <?= $texto($fila['municipio'] ?? '') ?>
                                        <?php else: ?>
                                            <div class="aliados-report-contactability">
                                                <span class="<?= !empty($fila['tiene_whatsapp']) ? 'is-ready' : '' ?>">
                                                    <i class="bi bi-whatsapp"></i>
                                                    WhatsApp
                                                </span>
                                                <span class="<?= !empty($fila['tiene_correo']) ? 'is-ready' : '' ?>">
                                                    <i class="bi bi-envelope"></i>
                                                    Correo
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $texto($fecha($fila['formalizado_at'] ?? '')) ?></td>
                                    <td>
                                        <?php if (trim((string)($fila['ultima_convocatoria'] ?? '')) === ''): ?>
                                            <span class="text-muted">Sin difusión</span>
                                        <?php else: ?>
                                            <strong><?= $texto($fila['ultima_convocatoria']) ?></strong>
                                            <small class="d-block text-muted">
                                                <?= $texto($canal($fila['ultimo_canal'] ?? '')) ?>
                                                ·
                                                <?= $texto($fecha($fila['ultimo_envio_at'] ?? '', true)) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="aliados-report-status <?= !empty($fila['vencido']) ? 'is-overdue' : '' ?>">
                                            <?= $texto($fila['estado_seguimiento_label'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= trim((string)($fila['proximo_seguimiento_at'] ?? '')) !== ''
                                            ? $texto($fecha($fila['proximo_seguimiento_at'], true))
                                            : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</section>
