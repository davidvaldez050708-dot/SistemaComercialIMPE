<?php

$territorios = is_array($territorios ?? null) ? $territorios : [];
$municipios = is_array($municipios ?? null) ? $municipios : [];
$filtrosReporte = is_array($filtrosReporte ?? null) ? $filtrosReporte : [];
$generarReporte = (bool)($generarReporte ?? false);
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

$maxMunicipios = 8;
$municipiosVisibles = array_slice($porMunicipio, 0, $maxMunicipios);
$maxAliadosMunicipio = 1;
foreach ($municipiosVisibles as $filaMunicipio) {
    $maxAliadosMunicipio = max(
        $maxAliadosMunicipio,
        (int)($filaMunicipio['aliados'] ?? 0)
    );
}

$textoAlcance = $estadoSeleccionado;
if ($municipioSeleccionado !== 'Todos') {
    $textoAlcance .= ' · ' . $municipioSeleccionado;
}
?>

<section class="report-module aliados-report-module">
    <a
        class="linkage-back-link territorial-back-link"
        href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a Reportes
    </a>

    <?php if (!$generarReporte): ?>
        <section class="dashboard-panel report-filter-panel aliados-report-generator mb-4">
            <div class="report-filter-heading">
                <div>
                    <span class="report-eyebrow">ALIADOS</span>
                    <h2 class="panel-title mb-1">Generar panorama de aliados</h2>
                    <p class="page-subtitle mb-0">
                        Selecciona el territorio y la situación que deseas analizar.
                        El alcance siempre respeta los aliados autorizados para tu usuario.
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
                Verás cobertura de la red, difusión de convocatorias,
                seguimiento operativo y prioridades de atención.
            </p>
        </section>
    <?php else: ?>
        <div class="aliados-report-result-toolbar">
            <div>
                <h1>Panorama de Aliados</h1>
                <p><?= $texto($textoAlcance) ?> · <?= $texto($situacionSeleccionadaTexto) ?></p>
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
                    <h3 class="panel-title mb-1">Red analizada</h3>
                    <p class="page-subtitle mb-0">
                        Fotografía actual de los aliados institucionales incluidos en el alcance seleccionado.
                    </p>
                </div>
                <span class="analyst-portfolio-detail-count">
                    <?= (int)($resumen['total'] ?? 0) ?>
                    <?= (int)($resumen['total'] ?? 0) === 1 ? 'aliado' : 'aliados' ?>
                </span>
            </div>

            <div class="analyst-portfolio-context-grid">
                <div>
                    <span>Territorio</span>
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
                    <span>Cobertura</span>
                    <strong>
                        <?= (int)($resumen['municipios'] ?? 0) ?>
                        <?= (int)($resumen['municipios'] ?? 0) === 1 ? 'municipio' : 'municipios' ?>
                    </strong>
                </div>
            </div>
        </section>

        <section class="analyst-portfolio-kpis mb-3" aria-label="Panorama de aliados">
            <article class="analyst-portfolio-kpi">
                <span class="analyst-portfolio-kpi-icon">
                    <i class="bi bi-buildings"></i>
                </span>
                <div>
                    <strong><?= (int)($resumen['total'] ?? 0) ?></strong>
                    <span>Aliados en la red</span>
                    <small>Instituciones incluidas en la consulta</small>
                </div>
            </article>

            <article class="analyst-portfolio-kpi">
                <span class="analyst-portfolio-kpi-icon">
                    <i class="bi bi-megaphone"></i>
                </span>
                <div>
                    <strong><?= (int)($resumen['con_difusion'] ?? 0) ?></strong>
                    <span>Con difusión</span>
                    <small><?= $texto(number_format((float)($resumen['cobertura_difusion'] ?? 0), 1)) ?>% de la red</small>
                </div>
            </article>

            <article class="analyst-portfolio-kpi">
                <span class="analyst-portfolio-kpi-icon">
                    <i class="bi bi-arrow-repeat"></i>
                </span>
                <div>
                    <strong><?= (int)($resumen['pendientes'] ?? 0) ?></strong>
                    <span>Seguimientos abiertos</span>
                    <small>Aliados que todavía requieren gestión</small>
                </div>
            </article>

            <article class="analyst-portfolio-kpi analyst-portfolio-kpi--attention">
                <span class="analyst-portfolio-kpi-icon">
                    <i class="bi bi-exclamation-circle"></i>
                </span>
                <div>
                    <strong><?= (int)($resumen['vencidos'] ?? 0) ?></strong>
                    <span>Requieren atención</span>
                    <small>Seguimientos con fecha ya vencida</small>
                </div>
            </article>

            <article class="analyst-portfolio-kpi analyst-portfolio-kpi--success">
                <span class="analyst-portfolio-kpi-icon">
                    <i class="bi bi-patch-check"></i>
                </span>
                <div>
                    <strong><?= (int)($resumen['difusion_confirmada'] ?? 0) ?></strong>
                    <span>Difusión confirmada</span>
                    <small><?= $texto(number_format((float)($resumen['tasa_confirmacion'] ?? 0), 1)) ?>% sobre aliados con difusión</small>
                </div>
            </article>
        </section>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-portfolio-attention h-100">
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
                            <?= count($atencion) ?> por revisar
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
                                            ·
                                            <?= $texto($fila['estado'] ?? '') ?>
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
                                    Con los filtros actuales no hay seguimientos vencidos
                                    ni aliados que requieran una acción inmediata.
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-portfolio-health h-100">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">SALUD DE LA RED</span>
                            <h3 class="panel-title mb-1">Estado operativo</h3>
                            <p class="page-subtitle mb-0">
                                Señales rápidas sobre capacidad de contacto y continuidad de difusión.
                            </p>
                        </div>
                    </div>

                    <div class="analyst-portfolio-health-grid">
                        <div>
                            <span>WhatsApp disponible</span>
                            <strong><?= (int)($resumen['con_whatsapp'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Sin difusión</span>
                            <strong><?= (int)($resumen['sin_difusion'] ?? 0) ?></strong>
                        </div>
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
                            de la red cuenta con WhatsApp confirmado o verificado.
                        </span>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel aliados-report-insights h-100">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">LECTURA EJECUTIVA</span>
                            <h3 class="panel-title mb-1">Hallazgos de la red</h3>
                            <p class="page-subtitle mb-0">
                                Síntesis automática construida con los indicadores del alcance seleccionado.
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
                <section class="dashboard-panel aliados-report-followup-state h-100">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">DISTRIBUCIÓN</span>
                            <h3 class="panel-title mb-1">Situación del seguimiento</h3>
                            <p class="page-subtitle mb-0">
                                El total de categorías corresponde a los aliados incluidos en el reporte.
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

        <?php if (count($porEstado) > 1): ?>
            <section class="dashboard-panel p-0 overflow-hidden mb-3">
                <div class="table-panel-header">
                    <div>
                        <span class="report-eyebrow">COBERTURA TERRITORIAL</span>
                        <h3 class="panel-title mb-0">Red por estado</h3>
                        <p class="page-subtitle mb-0 mt-1">
                            Comparativo de aliados, difusión y pendientes dentro del alcance autorizado.
                        </p>
                    </div>
                    <span class="analyst-portfolio-detail-count">
                        <?= count($porEstado) ?>
                        <?= count($porEstado) === 1 ? 'estado' : 'estados' ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th class="text-end">Aliados</th>
                                <th class="text-end">Municipios</th>
                                <th class="text-end">Con difusión</th>
                                <th class="text-end">Cobertura</th>
                                <th class="text-end">Pendientes</th>
                                <th class="text-end">Vencidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($porEstado as $fila): ?>
                                <tr>
                                    <td><strong><?= $texto($fila['estado'] ?? '') ?></strong></td>
                                    <td class="text-end"><?= (int)($fila['aliados'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($fila['municipios'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($fila['con_difusion'] ?? 0) ?></td>
                                    <td class="text-end"><?= $texto(number_format((float)($fila['cobertura_difusion'] ?? 0), 1)) ?>%</td>
                                    <td class="text-end"><?= (int)($fila['pendientes'] ?? 0) ?></td>
                                    <td class="text-end">
                                        <strong class="<?= (int)($fila['vencidos'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                                            <?= (int)($fila['vencidos'] ?? 0) ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="dashboard-panel analyst-portfolio-territory mb-3">
            <div class="analyst-portfolio-section-heading">
                <div>
                    <span class="report-eyebrow">COBERTURA MUNICIPAL</span>
                    <h3 class="panel-title mb-1">Concentración de aliados</h3>
                    <p class="page-subtitle mb-0">
                        Municipios con mayor número de instituciones aliadas dentro del alcance seleccionado.
                    </p>
                </div>
                <span class="analyst-portfolio-detail-count">
                    <?= count($porMunicipio) ?>
                    <?= count($porMunicipio) === 1 ? 'municipio' : 'municipios' ?>
                </span>
            </div>

            <?php if (empty($municipiosVisibles)): ?>
                <div class="analyst-portfolio-empty">
                    <i class="bi bi-geo-alt"></i>
                    <div>
                        <strong>Sin municipios para mostrar.</strong>
                        <span>No hay aliados dentro del alcance seleccionado.</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="aliados-report-bars">
                    <?php foreach ($municipiosVisibles as $fila): ?>
                        <?php
                        $aliadosMunicipio = (int)($fila['aliados'] ?? 0);
                        $porcentajeBarra = max(
                            6,
                            (int)round(
                                ($aliadosMunicipio / $maxAliadosMunicipio) * 100
                            )
                        );
                        ?>
                        <div class="aliados-report-bar-row">
                            <div class="aliados-report-bar-label">
                                <div>
                                    <strong><?= $texto($fila['municipio'] ?? '') ?></strong>
                                    <span><?= $texto($fila['estado'] ?? '') ?></span>
                                </div>
                                <div>
                                    <strong>
                                        <?= $aliadosMunicipio ?>
                                        <?= $aliadosMunicipio === 1 ? 'aliado' : 'aliados' ?>
                                    </strong>
                                    <span>
                                        <?= $texto(number_format((float)($fila['cobertura_difusion'] ?? 0), 1)) ?>% con difusión
                                    </span>
                                </div>
                            </div>
                            <div class="aliados-report-bar-track">
                                <span style="width: <?= $porcentajeBarra ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel p-0 overflow-hidden">
            <div class="table-panel-header">
                <div>
                    <span class="report-eyebrow">DETALLE DEL REPORTE</span>
                    <h3 class="panel-title mb-0">Aliados incluidos</h3>
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
                            <th>Territorio</th>
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
                                        <?= $texto($fila['municipio'] ?? '') ?>
                                        <small class="d-block text-muted">
                                            <?= $texto($fila['estado'] ?? '') ?>
                                        </small>
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
