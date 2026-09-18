<?php
$territorios = $territorios ?? [];
$estadoId = $estadoId ?? 0;
$generarReporte = $generarReporte ?? false;
$reporte = $reporte ?? null;
$errorReporte = $errorReporte ?? '';
$errorExportacionPdf = $errorExportacionPdf ?? '';
$urlExportarPdf = $urlExportarPdf ?? '';

$texto = static fn($valor) => htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$numero = static function ($valor, $decimales = 0) {
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return '—';
    }
    return number_format((float)$valor, (int)$decimales, '.', ',');
};
$fecha = static function ($valor) use ($texto) {
    if (!$valor) {
        return '—';
    }
    try {
        return (new DateTime((string)$valor))->format('d/m/Y H:i');
    } catch (Throwable $error) {
        return $texto($valor);
    }
};
$valor = static function ($dato) use ($texto) {
    return $dato === null || trim((string)$dato) === '' ? '—' : $texto($dato);
};
$diferencia = static function ($dato, $sufijo = ' pts.') use ($numero) {
    if ($dato === null || !is_numeric($dato)) {
        return '—';
    }
    $dato = (float)$dato;
    $signo = $dato > 0 ? '+' : ($dato < 0 ? '−' : '');
    return $signo . $numero(abs($dato), 2) . $sufijo;
};

$estado = is_array($reporte) ? ($reporte['estado'] ?? []) : [];
$actividad = is_array($reporte) ? ($reporte['actividad_economica'] ?? []) : [];
$poder = is_array($reporte) ? ($reporte['poder_adquisitivo'] ?? []) : [];
$rezago = is_array($reporte) ? ($reporte['rezago_educativo'] ?? []) : [];
$perfil = is_array($reporte) ? ($reporte['perfil_educativo'] ?? []) : [];
$indicadores = is_array($reporte) ? ($reporte['indicadores_educativos'] ?? []) : [];
$priorizacion = is_array($reporte) ? ($reporte['priorizacion_municipal'] ?? []) : [];
$secretarias = is_array($reporte) ? ($reporte['secretarias'] ?? []) : [];
$fuentes = is_array($reporte) ? ($reporte['fuentes'] ?? []) : [];
$calculos = is_array($reporte) ? ($reporte['calculos'] ?? []) : [];
$lecturas = is_array($reporte) ? ($reporte['lecturas'] ?? []) : [];

$mapaEstadoUrl = '';
if (
    $reporte &&
    trim((string)($estado['mapa_estado'] ?? '')) !== '' &&
    function_exists('obtenerUrlArchivoPublico')
) {
    $mapaEstadoUrl = obtenerUrlArchivoPublico(
        $estado['mapa_estado'],
        ['public/uploads/territorios/mapas']
    );
}

$sectoresGrafica = array_slice(array_values($actividad['sectores'] ?? []), 0, 5);
$maxSectorGrafica = 1;
foreach ($sectoresGrafica as $sectorGrafica) {
    $maxSectorGrafica = max($maxSectorGrafica, (int)($sectorGrafica['establecimientos'] ?? 0));
}
?>

<section class="report-module report-territorial-module<?= $reporte ? ' report-territorial-generated' : '' ?>">
    <a class="linkage-back-link territorial-back-link" href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a Reportes
    </a>

    <?php if ($reporte): ?>
        <div class="territorial-report-toolbar">
            <div class="territorial-report-title">
                <h1>Reporte de Información Territorial</h1>
                <p><?= $texto($estado['nombre'] ?? 'Territorio') ?> · Información territorial</p>
            </div>
            <div class="territorial-report-actions">
                <button
                    type="button"
                    class="btn btn-system-light"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCambiarTerritorio">
                    <i class="bi bi-sliders me-2"></i>
                    Cambiar territorio
                </button>
                <?php if ($urlExportarPdf !== ''): ?>
                    <a class="btn btn-system-save" href="<?= $texto($urlExportarPdf) ?>">
                        <i class="bi bi-file-earmark-pdf me-2"></i>
                        Exportar PDF
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($errorReporte !== '' || $errorExportacionPdf !== ''): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorReporte !== '' ? $errorReporte : $errorExportacionPdf) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$reporte): ?>
        <section class="dashboard-panel report-filter-panel mb-4">
            <div class="report-filter-heading">
                <div>
                    <span class="report-eyebrow">INFORMACIÓN TERRITORIAL</span>
                    <h2 class="panel-title mb-1">Generar reporte territorial</h2>
                    <p class="page-subtitle mb-0">
                        Selecciona uno de tus territorios para preparar una lectura ejecutiva con datos registrados, comparaciones y fuentes disponibles.
                    </p>
                </div>
                <span class="metric-icon" aria-hidden="true"><i class="bi bi-map"></i></span>
            </div>

            <form class="report-filter-form" action="<?= BASE_URL ?>index.php" method="GET">
                <input type="hidden" name="controller" value="dataTerritorialReporte">
                <input type="hidden" name="action" value="index">
                <input type="hidden" name="generar" value="1">

                <div class="report-filter-field">
                    <label class="form-label" for="reporte_territorio">Territorio</label>
                    <select class="form-select" id="reporte_territorio" name="estado_id" required>
                        <option value="">Seleccionar Estado</option>
                        <?php foreach ($territorios as $territorio): ?>
                            <option
                                value="<?= (int)($territorio['id'] ?? 0) ?>"
                                <?= (int)$estadoId === (int)($territorio['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= $texto($territorio['nombre'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="btn btn-system-primary" type="submit">
                    <i class="bi bi-bar-chart me-2"></i>
                    Generar reporte
                </button>
            </form>
        </section>
    <?php endif; ?>

    <?php if (!$reporte): ?>
        <section class="dashboard-panel data-empty-state report-empty-state">
            <span><i class="bi bi-file-earmark-bar-graph"></i></span>
            <strong>Selecciona un territorio para preparar el reporte.</strong>
            <p>Verás los datos del módulo de Información territorial junto con indicadores y comparaciones calculadas a partir de la información disponible.</p>
        </section>
    <?php else: ?>
        <section class="report-preview territorial-report-preview" aria-label="Vista previa del reporte territorial">
            <section class="dashboard-panel territorial-focus-card mb-4">
                <div class="territorial-focus-main">
                    <div class="territorial-focus-copy">
                        <span class="report-eyebrow">TERRITORIO SELECCIONADO</span>
                        <h2><?= $texto($estado['nombre'] ?? 'Territorio') ?></h2>
                        <p>
                            <?= trim((string)($estado['capital'] ?? '')) !== '' ? 'Capital: ' . $texto($estado['capital']) . ' · ' : '' ?>
                            Última actualización: <?= $fecha($estado['fecha_actualizacion'] ?? null) ?>
                        </p>
                    </div>
                    <?php if ($mapaEstadoUrl !== ''): ?>
                        <div class="territorial-focus-map" aria-label="Mapa de <?= $texto($estado['nombre'] ?? 'territorio') ?>">
                            <img src="<?= $texto($mapaEstadoUrl) ?>" alt="Mapa de <?= $texto($estado['nombre'] ?? 'territorio') ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="territorial-focus-grid">
                    <div><span>Población</span><strong><?= $numero($estado['poblacion'] ?? null) ?></strong></div>
                    <div><span>Municipios</span><strong><?= $numero($estado['total_municipios'] ?? $estado['municipios_cargados'] ?? null) ?></strong></div>
                    <div><span>Capital</span><strong><?= $valor($estado['capital'] ?? null) ?></strong></div>
                    <div><span>Secretarías activas</span><strong><?= $numero($calculos['total_secretarias_activas'] ?? null) ?></strong></div>
                    <div><span>Periodo de gobierno</span><strong><?= $valor($estado['periodo_gobierno'] ?? null) ?></strong></div>
                    <div><span>Estado de información</span><strong>Datos registrados y fuentes disponibles</strong></div>
                </div>
            </section>

            <div class="territorial-section-title">
                <h2>Panorama territorial</h2>
                <p>Indicadores principales del territorio seleccionado.</p>
            </div>

            <section class="metric-grid report-summary-grid territorial-summary-grid mb-4" aria-label="Resumen territorial">
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($estado['poblacion'] ?? null) ?></p>
                        <p class="metric-label">Población</p>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-geo-alt"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($estado['total_municipios'] ?? $estado['municipios_cargados'] ?? null) ?></p>
                        <p class="metric-label">Municipios</p>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-buildings"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($actividad['total_establecimientos'] ?? null) ?></p>
                        <p class="metric-label">Establecimientos registrados</p>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-calculator"></i></div>
                    <div>
                        <p class="metric-value"><?= ($calculos['establecimientos_por_10000_habitantes'] ?? null) !== null ? $numero($calculos['establecimientos_por_10000_habitantes'], 1) : '—' ?></p>
                        <p class="metric-label">Establecimientos por 10 mil habitantes</p>
                    </div>
                </article>
            </section>

            <section class="dashboard-panel report-section mb-4">
                <div class="report-section-heading">
                    <div>
                        <span>FICHA TERRITORIAL</span>
                        <h3>Gobierno y contexto institucional</h3>
                    </div>
                </div>
                <dl class="report-definition-grid">
                    <div><dt>Capital</dt><dd><?= $valor($estado['capital'] ?? null) ?></dd></div>
                    <div><dt>Titular del gobierno</dt><dd><?= $valor($estado['titular_gobierno'] ?? null) ?></dd></div>
                    <div><dt>Cargo</dt><dd><?= $valor($estado['cargo_titular'] ?? null) ?></dd></div>
                    <div><dt>Partido político</dt><dd><?= $valor($estado['partido_politico'] ?? null) ?></dd></div>
                    <div><dt>Periodo de gobierno</dt><dd><?= $valor($estado['periodo_gobierno'] ?? null) ?></dd></div>
                    <div><dt>Secretarías activas</dt><dd><?= $numero($calculos['total_secretarias_activas'] ?? null) ?></dd></div>
                    <div><dt>Teléfono</dt><dd><?= $valor($estado['telefono'] ?? null) ?></dd></div>
                    <div><dt>Población promedio por municipio</dt><dd><?= $numero($calculos['poblacion_promedio_municipio'] ?? null) ?></dd></div>
                </dl>
            </section>

            <section class="dashboard-panel report-section mb-4">
                <div class="report-section-heading">
                    <div>
                        <span>ACTIVIDAD ECONÓMICA</span>
                        <h3>Distribución de establecimientos</h3>
                    </div>
                </div>

                <?php if (!empty($actividad['sectores'])): ?>
                    <div class="report-inline-metrics">
                        <div>
                            <span>Sector con mayor presencia</span>
                            <strong><?= $texto($calculos['sector_principal']['nombre_sector'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span>Concentración de los 5 principales sectores</span>
                            <strong><?= ($calculos['concentracion_top_5_sectores'] ?? null) !== null ? $numero($calculos['concentracion_top_5_sectores'], 2) . ' %' : '—' ?></strong>
                        </div>
                        <div>
                            <span>Participación en establecimientos nacionales</span>
                            <strong><?= ($calculos['participacion_establecimientos_nacional'] ?? null) !== null ? $numero($calculos['participacion_establecimientos_nacional'], 2) . ' %' : '—' ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($sectoresGrafica)): ?>
                        <div class="territorial-bar-chart mt-3" aria-label="Principales sectores económicos">
                            <?php foreach ($sectoresGrafica as $sectorGrafica): ?>
                                <?php
                                $establecimientosSector = (int)($sectorGrafica['establecimientos'] ?? 0);
                                $anchoSector = $maxSectorGrafica > 0
                                    ? max(3, ($establecimientosSector / $maxSectorGrafica) * 100)
                                    : 0;
                                ?>
                                <div class="territorial-bar-row">
                                    <div class="territorial-bar-label">
                                        <span><?= $texto($sectorGrafica['nombre_sector'] ?? '—') ?></span>
                                        <strong><?= $numero($establecimientosSector) ?></strong>
                                    </div>
                                    <div class="territorial-bar-track">
                                        <span style="width: <?= number_format($anchoSector, 2, '.', '') ?>%"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive mt-3">
                        <table class="table users-table data-table align-middle report-table">
                            <thead>
                                <tr><th>Sector</th><th class="text-end">Establecimientos</th><th class="text-end">Participación</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice(array_values($actividad['sectores']), 0, 8) as $sector): ?>
                                    <tr>
                                        <td><strong><?= $texto($sector['nombre_sector'] ?? '—') ?></strong></td>
                                        <td class="text-end"><?= $numero($sector['establecimientos'] ?? null) ?></td>
                                        <td class="text-end"><?= $numero($sector['porcentaje'] ?? 0, 2) ?> %</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="data-empty-text mb-0">No hay actividad económica oficial registrada para este territorio.</p>
                <?php endif; ?>

                <?php if (trim((string)($estado['actividad_economica'] ?? '')) !== ''): ?>
                    <div class="report-context-box mt-3">
                        <span>Contexto económico registrado</span>
                        <p><?= nl2br($texto($estado['actividad_economica'])) ?></p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="report-two-column mb-4">
                <article class="dashboard-panel report-section">
                    <div class="report-section-heading">
                        <div>
                            <span>CONDICIONES SOCIOECONÓMICAS</span>
                            <h3>Poder adquisitivo</h3>
                        </div>
                    </div>

                    <?php if (($poder['disponible'] ?? false) === true): ?>
                        <div class="report-stat-list">
                            <div>
                                <span>Ingreso laboral real per cápita</span>
                                <strong>$<?= $numero($poder['ingreso_laboral_real_per_capita'] ?? 0, 2) ?></strong>
                            </div>
                            <div>
                                <span>Pobreza laboral</span>
                                <strong><?= $numero($poder['pobreza_laboral'] ?? 0, 2) ?> %</strong>
                            </div>
                            <div>
                                <span>Diferencia de ingreso vs. nacional</span>
                                <strong><?= ($poder['diferencia_ingreso_nacional'] ?? null) !== null ? '$' . $diferencia($poder['diferencia_ingreso_nacional'], '') : '—' ?></strong>
                            </div>
                            <div>
                                <span>Diferencia de pobreza vs. nacional</span>
                                <strong><?= $diferencia($poder['diferencia_pobreza_nacional'] ?? null) ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="data-empty-text mb-0">No hay indicadores oficiales de poder adquisitivo disponibles.</p>
                    <?php endif; ?>
                </article>

                <article class="dashboard-panel report-section">
                    <div class="report-section-heading">
                        <div>
                            <span>EDUCACIÓN</span>
                            <h3>Rezago y perfil educativo</h3>
                        </div>
                    </div>

                    <div class="report-stat-list">
                        <div>
                            <span>Rezago educativo</span>
                            <strong><?= ($rezago['disponible'] ?? false) === true ? $numero($rezago['porcentaje'] ?? 0, 2) . ' %' : '—' ?></strong>
                        </div>
                        <div>
                            <span>Diferencia vs. nacional</span>
                            <strong><?= $diferencia($rezago['diferencia_nacional'] ?? null) ?></strong>
                        </div>
                        <div>
                            <span><?= $texto($perfil['nombre_indicador'] ?? 'Perfil educativo') ?></span>
                            <strong><?= ($perfil['disponible'] ?? false) === true ? $numero($perfil['porcentaje'] ?? 0, 2) . ' %' : '—' ?></strong>
                        </div>
                        <div>
                            <span>Población base del perfil educativo</span>
                            <strong><?= ($perfil['disponible'] ?? false) === true ? $numero($perfil['poblacion_base'] ?? null) : '—' ?></strong>
                        </div>
                    </div>
                </article>
            </section>

            <?php if (!empty($indicadores)): ?>
                <section class="dashboard-panel report-section mb-4">
                    <div class="report-section-heading">
                        <div>
                            <span>INDICADORES EDUCATIVOS</span>
                            <h3>Información registrada en el territorio</h3>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle report-table">
                            <thead><tr><th>Indicador</th><th class="text-end">Valor</th><th class="text-end">Periodo</th></tr></thead>
                            <tbody>
                                <?php foreach ($indicadores as $indicador): ?>
                                    <?php
                                    $porcentajeIndicador = $indicador['porcentaje'] ?? null;
                                    $valorIndicador = $porcentajeIndicador !== null && $porcentajeIndicador !== ''
                                        ? $numero($porcentajeIndicador, 2) . ' %'
                                        : trim((string)($indicador['valor'] ?? '')) . (trim((string)($indicador['unidad'] ?? '')) !== '' ? ' ' . trim((string)$indicador['unidad']) : '');
                                    ?>
                                    <tr>
                                        <td><strong><?= $texto($indicador['situacion'] ?? '—') ?></strong></td>
                                        <td class="text-end"><?= $texto($valorIndicador !== '' ? $valorIndicador : '—') ?></td>
                                        <td class="text-end"><?= $valor($indicador['periodo'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-panel report-section report-insight-section mb-4">
                <div class="report-section-heading">
                    <div>
                        <span>LECTURA TERRITORIAL</span>
                        <h3>Cálculos derivados de los datos disponibles</h3>
                        <p>Estos resultados complementan los valores originales y no reemplazan sus fuentes.</p>
                    </div>
                </div>

                <?php if (!empty($lecturas)): ?>
                    <div class="report-insight-list">
                        <?php foreach ($lecturas as $lectura): ?>
                            <div class="report-insight-item">
                                <i class="bi bi-calculator"></i>
                                <p><?= $texto($lectura) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="data-empty-text mb-0">Todavía no hay suficientes datos para generar cálculos complementarios.</p>
                <?php endif; ?>
            </section>

            <?php if (!empty($priorizacion['recomendados'])): ?>
                <section class="dashboard-panel report-section mb-4">
                    <div class="report-section-heading">
                        <div>
                            <span>MUNICIPIOS</span>
                            <h3>Municipios destacados en la priorización territorial</h3>
                            <p>Se reutiliza la priorización ya calculada por Información territorial para mantener consistencia con el módulo.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle report-table">
                            <thead><tr><th>Municipio</th><th>Estrategia</th><th>Puntaje</th><th class="text-end">Población</th><th class="text-end">Ranking</th></tr></thead>
                            <tbody>
                                <?php foreach ($priorizacion['recomendados'] as $municipio): ?>
                                    <?php
                                    $puntajeMunicipio = max(0, min(100, (int)($municipio['puntaje'] ?? 0)));
                                    $accionMunicipio = trim((string)($municipio['accion'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= $texto($municipio['nombre'] ?? '—') ?></strong>
                                            <small class="d-block text-muted mt-1">Prioridad <?= $texto($municipio['prioridad'] ?? '—') ?></small>
                                        </td>
                                        <td><span class="territorial-strategy-pill"><?= $texto($accionMunicipio !== '' ? $accionMunicipio : '—') ?></span></td>
                                        <td>
                                            <div class="territorial-score">
                                                <strong><?= $puntajeMunicipio ?></strong>
                                                <span><i style="width: <?= $puntajeMunicipio ?>%"></i></span>
                                            </div>
                                        </td>
                                        <td class="text-end"><?= $numero($municipio['poblacion'] ?? null) ?></td>
                                        <td class="text-end"><?= ($municipio['ranking'] ?? null) !== null ? (int)$municipio['ranking'] . ' de ' . (int)($municipio['total_ranking'] ?? 0) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php
            $secretariasActivas = array_values(array_filter(
                $secretarias,
                static fn($secretaria) => (int)($secretaria['estado'] ?? 0) === 1
            ));
            ?>
            <?php if (!empty($secretariasActivas)): ?>
                <section class="dashboard-panel report-section mb-4">
                    <div class="report-section-heading">
                        <div>
                            <span>ESTRUCTURA INSTITUCIONAL</span>
                            <h3>Secretarías registradas</h3>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle report-table">
                            <thead><tr><th>Secretaría</th><th>Titular</th><th>Contacto</th></tr></thead>
                            <tbody>
                                <?php foreach ($secretariasActivas as $secretaria): ?>
                                    <?php $contacto = trim((string)($secretaria['correo'] ?? '')) ?: trim((string)($secretaria['telefono'] ?? '')); ?>
                                    <tr>
                                        <td><strong><?= $texto($secretaria['nombre'] ?? '—') ?></strong></td>
                                        <td><?= $valor($secretaria['titular'] ?? null) ?></td>
                                        <td><?= $texto($contacto !== '' ? $contacto : '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-panel report-section mb-4">
                <div class="report-section-heading">
                    <div>
                        <span>FUENTES</span>
                        <h3>Fuentes y periodos de referencia</h3>
                    </div>
                </div>

                <div class="report-source-grid">
                    <?php $fuentesMostradas = 0; ?>
                    <?php foreach ($fuentes as $seccion => $fuente): ?>
                        <?php if (!is_array($fuente) || trim((string)($fuente['fuente'] ?? '')) === '') continue; ?>
                        <?php $fuentesMostradas++; ?>
                        <article>
                            <span><?= $texto(str_replace('_', ' ', $seccion)) ?></span>
                            <strong><?= $texto($fuente['fuente'] ?? '—') ?></strong>
                            <small>Periodo: <?= $valor($fuente['periodo'] ?? null) ?></small>
                        </article>
                    <?php endforeach; ?>

                    <?php if (($perfil['disponible'] ?? false) === true && trim((string)($perfil['fuente'] ?? '')) !== ''): ?>
                        <?php $fuentesMostradas++; ?>
                        <article>
                            <span>PERFIL EDUCATIVO</span>
                            <strong><?= $texto($perfil['fuente']) ?></strong>
                            <small>Periodo: <?= $texto($perfil['anio'] ?? '—') ?></small>
                        </article>
                    <?php endif; ?>

                    <?php if ($fuentesMostradas === 0): ?>
                        <p class="data-empty-text mb-0">No hay fuentes registradas para mostrar.</p>
                    <?php endif; ?>
                </div>
            </section>
        </section>
    <?php endif; ?>

    <?php if ($reporte): ?>
        <div
            class="modal fade territorial-filter-modal"
            id="modalCambiarTerritorio"
            tabindex="-1"
            aria-labelledby="modalCambiarTerritorioTitulo"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title" id="modalCambiarTerritorioTitulo">Cambiar territorio</h2>
                            <p>Selecciona otro Estado y vuelve a generar el reporte.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <form action="<?= BASE_URL ?>index.php" method="GET">
                        <div class="modal-body">
                            <input type="hidden" name="controller" value="dataTerritorialReporte">
                            <input type="hidden" name="action" value="index">
                            <input type="hidden" name="generar" value="1">

                            <label class="form-label" for="reporte_territorio_modal">Territorio</label>
                            <select class="form-select" id="reporte_territorio_modal" name="estado_id" required>
                                <?php foreach ($territorios as $territorio): ?>
                                    <option
                                        value="<?= (int)($territorio['id'] ?? 0) ?>"
                                        <?= (int)$estadoId === (int)($territorio['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= $texto($territorio['nombre'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-system-save">
                                <i class="bi bi-bar-chart me-2"></i>
                                Generar reporte
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
