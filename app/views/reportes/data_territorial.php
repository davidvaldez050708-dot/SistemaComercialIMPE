<?php
require_once __DIR__ . '/../../helpers/AvatarHelper.php';

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
$perfil2549 = is_array($reporte) ? ($reporte['perfil_educativo_25_49'] ?? []) : [];
$escolaridadAdulta = is_array($reporte) ? ($reporte['escolaridad_adulta'] ?? []) : [];
$indicadores = is_array($reporte) ? ($reporte['indicadores_educativos'] ?? []) : [];
$priorizacion = is_array($reporte) ? ($reporte['priorizacion_municipal'] ?? []) : [];
$secretarias = is_array($reporte) ? ($reporte['secretarias'] ?? []) : [];
$fuentes = is_array($reporte) ? ($reporte['fuentes'] ?? []) : [];
$calculos = is_array($reporte) ? ($reporte['calculos'] ?? []) : [];
$resumen = is_array($reporte) ? ($reporte['resumen_ejecutivo'] ?? []) : [];
$lecturas = is_array($reporte) ? ($reporte['lecturas'] ?? []) : [];

$secretariasActivas = array_values(array_filter(
    $secretarias,
    static fn($secretaria) => (int)($secretaria['estado'] ?? 0) === 1
));
$conteosPrioridad = is_array($priorizacion['conteos'] ?? null)
    ? $priorizacion['conteos']
    : ['ALTA' => 0, 'MEDIA' => 0, 'BAJA' => 0];
$municipiosRecomendados = array_values($priorizacion['recomendados'] ?? []);
$sectoresRegistrados = array_values($actividad['sectores'] ?? []);
$sectoresGrafica = array_slice($sectoresRegistrados, 0, 5);
$otrosSectores = array_slice($sectoresRegistrados, 5, 3);

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
            <section class="dashboard-panel territorial-focus-card territorial-executive-context mb-4">
                <div class="territorial-focus-main">
                    <div class="territorial-focus-copy">
                        <span class="report-eyebrow">CONTEXTO DEL REPORTE</span>
                        <h2>Territorio analizado</h2>
                        <p>Lectura ejecutiva de la información territorial registrada y de sus referencias disponibles.</p>
                    </div>
                    <?php if ($mapaEstadoUrl !== ''): ?>
                        <div class="territorial-focus-map" aria-label="Mapa de <?= $texto($estado['nombre'] ?? 'territorio') ?>">
                            <img src="<?= $texto($mapaEstadoUrl) ?>" alt="Mapa de <?= $texto($estado['nombre'] ?? 'territorio') ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="territorial-context-grid">
                    <div>
                        <span>Territorio</span>
                        <strong><?= $texto($estado['nombre'] ?? 'Territorio') ?></strong>
                    </div>
                    <div>
                        <span>Capital</span>
                        <strong><?= $valor($estado['capital'] ?? null) ?></strong>
                    </div>
                    <div>
                        <span>Última actualización del expediente</span>
                        <strong><?= $fecha($estado['fecha_actualizacion'] ?? null) ?></strong>
                    </div>
                    <div>
                        <span>Fuentes / referencias disponibles</span>
                        <strong><?= $numero($resumen['fuentes_disponibles'] ?? 0) ?></strong>
                    </div>
                </div>
            </section>

            <section class="metric-grid territorial-kpi-grid mb-4" aria-label="Panorama territorial">
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($resumen['poblacion'] ?? null) ?></p>
                        <p class="metric-label">Población</p>
                        <small class="territorial-metric-note">Habitantes registrados</small>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-geo-alt"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($resumen['municipios'] ?? null) ?></p>
                        <p class="metric-label">Municipios</p>
                        <small class="territorial-metric-note">Cobertura territorial</small>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-buildings"></i></div>
                    <div>
                        <p class="metric-value"><?= $numero($resumen['establecimientos'] ?? null) ?></p>
                        <p class="metric-label">Establecimientos</p>
                        <small class="territorial-metric-note">Registros económicos</small>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-calculator"></i></div>
                    <div>
                        <p class="metric-value"><?= ($resumen['establecimientos_por_10000_habitantes'] ?? null) !== null ? $numero($resumen['establecimientos_por_10000_habitantes'], 1) : '—' ?></p>
                        <p class="metric-label">Est. / 10 mil hab.</p>
                        <small class="territorial-metric-note">Densidad registrada</small>
                    </div>
                </article>
                <article class="metric-card territorial-kpi-priority">
                    <div class="metric-icon"><i class="bi bi-bullseye"></i></div>
                    <div>
                        <p class="metric-value"><?= ($resumen['priorizacion_disponible'] ?? false) === true ? $numero($resumen['prioridad_alta'] ?? 0) : '—' ?></p>
                        <p class="metric-label">Municipios ATACAR</p>
                        <small class="territorial-metric-note"><?= ($resumen['priorizacion_disponible'] ?? false) === true ? 'Prioridad alta sugerida' : 'Sin datos suficientes' ?></small>
                    </div>
                </article>
            </section>

            <section class="dashboard-panel report-section territorial-strategic-section mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>LECTURA ESTRATÉGICA</span>
                        <h3>Señales principales del territorio</h3>
                        <p>Indicadores que permiten entender rápidamente el contexto económico, laboral y educativo.</p>
                    </div>
                </div>

                <div class="territorial-strategy-grid">
                    <article class="territorial-strategy-card">
                        <div class="territorial-strategy-icon"><i class="bi bi-buildings"></i></div>
                        <div class="territorial-strategy-copy">
                            <span>ECONOMÍA</span>
                            <strong><?= $texto($resumen['sector_principal']['nombre_sector'] ?? 'Sin dato disponible') ?></strong>
                            <p>Sector con mayor presencia registrada</p>
                            <div class="territorial-strategy-meta">
                                <b><?= ($resumen['concentracion_top_5'] ?? null) !== null ? $numero($resumen['concentracion_top_5'], 2) . ' %' : '—' ?></b>
                                <small>concentración Top 5</small>
                            </div>
                        </div>
                    </article>

                    <article class="territorial-strategy-card">
                        <div class="territorial-strategy-icon"><i class="bi bi-wallet2"></i></div>
                        <div class="territorial-strategy-copy">
                            <span>CONDICIONES SOCIOECONÓMICAS</span>
                            <strong><?= ($resumen['pobreza_laboral'] ?? null) !== null ? $numero($resumen['pobreza_laboral'], 2) . ' %' : '—' ?></strong>
                            <p>Pobreza laboral registrada</p>
                            <div class="territorial-strategy-meta">
                                <b><?= $diferencia($resumen['diferencia_pobreza_nacional'] ?? null) ?></b>
                                <small>vs. referencia nacional</small>
                            </div>
                        </div>
                    </article>

                    <article class="territorial-strategy-card">
                        <div class="territorial-strategy-icon"><i class="bi bi-mortarboard"></i></div>
                        <div class="territorial-strategy-copy">
                            <span>EDUCACIÓN</span>
                            <?php if (($perfil2549['disponible'] ?? false) === true): ?>
                                <strong><?= $numero($perfil2549['sin_media_superior_25_49_pct'] ?? null, 2) ?> %</strong>
                                <p>Personas de 25–49 años sin media superior concluida</p>
                                <div class="territorial-strategy-meta">
                                    <b><?= $numero($perfil2549['sin_media_superior_25_49'] ?? null) ?> personas</b>
                                    <small>Perfil prioritario disponible</small>
                                </div>
                            <?php else: ?>
                                <strong><?= ($resumen['rezago_educativo'] ?? null) !== null ? $numero($resumen['rezago_educativo'], 2) . ' %' : '—' ?></strong>
                                <p>Rezago educativo registrado</p>
                                <div class="territorial-strategy-meta">
                                    <b><?= $diferencia($resumen['diferencia_rezago_nacional'] ?? null) ?></b>
                                    <small>vs. referencia nacional</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            </section>

            <?php if (($priorizacion['disponible'] ?? false) === true): ?>
                <section class="dashboard-panel report-section territorial-priority-section mb-4">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>PRIORIZACIÓN TERRITORIAL</span>
                            <h3>Municipios destacados para vinculación</h3>
                            <p>Priorización orientativa del módulo territorial; compara municipios dentro del mismo Estado y conserva su cobertura de datos.</p>
                        </div>
                    </div>

                    <div class="territorial-priority-summary">
                        <div><span>ATACAR</span><strong><?= (int)($conteosPrioridad['ALTA'] ?? 0) ?></strong><small>Prioridad alta</small></div>
                        <div><span>OFRECER</span><strong><?= (int)($conteosPrioridad['MEDIA'] ?? 0) ?></strong><small>Prioridad media</small></div>
                        <div><span>OBSERVAR</span><strong><?= (int)($conteosPrioridad['BAJA'] ?? 0) ?></strong><small>Seguimiento</small></div>
                        <div><span>CLASIFICADOS</span><strong><?= (int)($priorizacion['total_municipios_clasificables'] ?? 0) ?></strong><small>Con datos para priorización</small></div>
                    </div>

                    <?php if (!empty($municipiosRecomendados)): ?>
                        <div class="territorial-priority-list">
                            <?php foreach ($municipiosRecomendados as $municipio): ?>
                                <?php
                                $puntajeMunicipio = max(0, min(100, (int)($municipio['puntaje'] ?? 0)));
                                $accionMunicipio = trim((string)($municipio['accion'] ?? '')) ?: 'OBSERVAR';
                                $coberturaMunicipio = max(0, min(100, (int)($municipio['cobertura_datos'] ?? 0)));
                                $rankingMunicipio = (int)($municipio['ranking'] ?? 0);
                                $totalRankingMunicipio = (int)($municipio['total_ranking'] ?? 0);
                                ?>
                                <article class="territorial-priority-card">
                                    <div class="territorial-priority-main">
                                        <div class="territorial-priority-name">
                                            <strong><?= $texto($municipio['nombre'] ?? '—') ?></strong>
                                            <span class="territorial-strategy-pill"><?= $texto($accionMunicipio) ?></span>
                                        </div>
                                        <p><?= $texto($municipio['motivo'] ?? 'Priorización calculada con los datos disponibles del territorio.') ?></p>
                                        <div class="territorial-priority-tags">
                                            <span><?= $numero($municipio['poblacion'] ?? null) ?> habitantes</span>
                                            <span><?= $puntajeMunicipio ?>/100</span>
                                            <span>Cobertura <?= $coberturaMunicipio ?> %</span>
                                            <?php if ($rankingMunicipio > 0 && $totalRankingMunicipio > 0): ?>
                                                <span>Ranking <?= $rankingMunicipio ?> de <?= $totalRankingMunicipio ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="territorial-priority-score" aria-label="Puntaje <?= $puntajeMunicipio ?> de 100">
                                        <strong><?= $puntajeMunicipio ?></strong>
                                        <span><i style="width: <?= $puntajeMunicipio ?>%"></i></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="data-empty-text mb-0">No hay municipios priorizados para mostrar.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="dashboard-panel report-section mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>ACTIVIDAD ECONÓMICA</span>
                        <h3>Estructura productiva registrada</h3>
                        <p>Principales sectores y concentración de establecimientos dentro del territorio.</p>
                    </div>
                </div>

                <?php if (!empty($sectoresRegistrados)): ?>
                    <div class="territorial-economic-metrics">
                        <div>
                            <span>Sector principal</span>
                            <strong><?= $texto($calculos['sector_principal']['nombre_sector'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span>Concentración Top 5</span>
                            <strong><?= ($calculos['concentracion_top_5_sectores'] ?? null) !== null ? $numero($calculos['concentracion_top_5_sectores'], 2) . ' %' : '—' ?></strong>
                        </div>
                        <div>
                            <span>Participación nacional</span>
                            <strong><?= ($calculos['participacion_establecimientos_nacional'] ?? null) !== null ? $numero($calculos['participacion_establecimientos_nacional'], 2) . ' %' : '—' ?></strong>
                        </div>
                    </div>

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
                                    <strong>
                                        <?= $numero($establecimientosSector) ?>
                                        <small>· <?= $numero($sectorGrafica['porcentaje'] ?? 0, 2) ?> %</small>
                                    </strong>
                                </div>
                                <div class="territorial-bar-track"><span style="width: <?= number_format($anchoSector, 2, '.', '') ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($otrosSectores)): ?>
                        <div class="territorial-secondary-sectors mt-3">
                            <span>Otros sectores registrados</span>
                            <div>
                                <?php foreach ($otrosSectores as $sector): ?>
                                    <article>
                                        <strong><?= $texto($sector['nombre_sector'] ?? '—') ?></strong>
                                        <small><?= $numero($sector['establecimientos'] ?? null) ?> · <?= $numero($sector['porcentaje'] ?? 0, 2) ?> %</small>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="data-empty-text mb-0">No hay actividad económica oficial registrada para este territorio.</p>
                <?php endif; ?>
            </section>

            <section class="report-two-column territorial-context-columns mb-4">
                <article class="dashboard-panel report-section">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>CONDICIONES SOCIOECONÓMICAS</span>
                            <h3>Poder adquisitivo</h3>
                        </div>
                    </div>
                    <?php if (($poder['disponible'] ?? false) === true): ?>
                        <div class="report-stat-list">
                            <div><span>Ingreso laboral real per cápita</span><strong>$<?= $numero($poder['ingreso_laboral_real_per_capita'] ?? 0, 2) ?></strong></div>
                            <div><span>Pobreza laboral</span><strong><?= $numero($poder['pobreza_laboral'] ?? 0, 2) ?> %</strong></div>
                            <div><span>Diferencia de ingreso vs. nacional</span><strong><?= ($poder['diferencia_ingreso_nacional'] ?? null) !== null ? '$' . $diferencia($poder['diferencia_ingreso_nacional'], '') : '—' ?></strong></div>
                            <div><span>Diferencia de pobreza vs. nacional</span><strong><?= $diferencia($poder['diferencia_pobreza_nacional'] ?? null) ?></strong></div>
                        </div>
                    <?php else: ?>
                        <p class="data-empty-text mb-0">No hay indicadores oficiales de poder adquisitivo disponibles.</p>
                    <?php endif; ?>
                </article>

                <article class="dashboard-panel report-section">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>EDUCACIÓN</span>
                            <h3>Rezago y perfil educativo</h3>
                        </div>
                    </div>
                    <div class="report-stat-list">
                        <div><span>Rezago educativo</span><strong><?= ($rezago['disponible'] ?? false) === true ? $numero($rezago['porcentaje'] ?? 0, 2) . ' %' : '—' ?></strong></div>
                        <div><span>Diferencia vs. nacional</span><strong><?= $diferencia($rezago['diferencia_nacional'] ?? null) ?></strong></div>
                        <div><span><?= $texto($perfil['nombre_indicador'] ?? 'Perfil educativo') ?></span><strong><?= ($perfil['disponible'] ?? false) === true ? $numero($perfil['porcentaje'] ?? 0, 2) . ' %' : '—' ?></strong></div>
                        <div><span>Población base del perfil</span><strong><?= ($perfil['disponible'] ?? false) === true ? $numero($perfil['poblacion_base'] ?? null) : '—' ?></strong></div>
                    </div>
                </article>
            </section>

            <section class="dashboard-panel report-section territorial-education-profile mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>ESCOLARIDAD ADULTA</span>
                        <h3>Brecha de escolaridad por grupo de edad</h3>
                        <p>Indicadores incorporados de tabulados con fuente y metodología declaradas, sin estimaciones de datos faltantes.</p>
                    </div>
                </div>
                <div class="territorial-education-grid territorial-education-grid-adultos">
                    <?php foreach ([
                        ['codigo' => 'SIN_EDUCACION_SUPERIOR_25_MAS', 'nombre' => 'Sin educación superior · 25 años o más'],
                        ['codigo' => 'SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS', 'nombre' => 'Sin media superior concluida · 18 años o más']
                    ] as $tipoAdulto): ?>
                        <?php
                            $adulto = $escolaridadAdulta[$tipoAdulto['codigo']] ?? [];
                            $adultoDisponible = ($adulto['disponible'] ?? false) === true;
                        ?>
                        <article>
                            <span><?= $texto($tipoAdulto['nombre']) ?><?= str_contains((string)($adulto['metodologia'] ?? ''), 'Conteo mínimo identificable') ? ' (mínimo identificado)' : '' ?></span>
                            <strong><?= $adultoDisponible ? $numero($adulto['cantidad_personas']) : 'Pendiente' ?></strong>
                            <?php if ($adultoDisponible): ?>
                                <small><?= $numero($adulto['porcentaje'], 2) ?> % de <?= $numero($adulto['poblacion_base']) ?> personas · <?= (int)$adulto['anio'] ?></small>
                                <small>Fuente declarada: <?= $texto($adulto['fuente']) ?></small>
                                <small><a href="<?= $texto($adulto['referencia_url']) ?>" target="_blank" rel="noopener noreferrer">Consultar referencia INEGI</a></small>
                                <?php if (str_contains((string)($adulto['metodologia'] ?? ''), 'Conteo mínimo identificable')): ?>
                                    <small>Valor mínimo: no implica que toda la población sin media superior concluida haya sido identificada.</small>
                                <?php endif; ?>
                                <small>Metodología: <?= $texto($adulto['metodologia']) ?></small>
                            <?php else: ?>
                                <small>Sin información validada para el rango de edad.</small>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="territorial-education-note">La importación no certifica la coincidencia con INEGI; las cifras deben cotejarse con la referencia citada.</p>
            </section>

            <?php if (($perfil2549['disponible'] ?? false) === true): ?>
                <section class="dashboard-panel report-section territorial-education-profile mb-4">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>PERFIL EDUCATIVO PRIORITARIO</span>
                            <h3>Población de 25 a 49 años</h3>
                            <p>
                                Universo educativo utilizado por la priorización municipal para dimensionar
                                la brecha de media superior dentro del territorio.
                            </p>
                        </div>
                        <span class="territorial-education-period">
                            <?= $texto($perfil2549['anio'] ?? 'Periodo disponible') ?>
                        </span>
                    </div>

                    <div class="territorial-education-grid">
                        <article>
                            <span>Población 25–49 con perfil disponible</span>
                            <strong><?= $numero($perfil2549['poblacion_25_49'] ?? null) ?></strong>
                            <small>Base agregada de municipios con información</small>
                        </article>
                        <article class="territorial-education-card-emphasis">
                            <span>Sin estudios de media superior</span>
                            <strong><?= $numero($perfil2549['sin_media_superior_25_49'] ?? null) ?></strong>
                            <small><?= $numero($perfil2549['sin_media_superior_25_49_pct'] ?? null, 2) ?> % del grupo 25–49</small>
                        </article>
                        <article>
                            <span>Media superior, sin superior</span>
                            <strong><?= $numero($perfil2549['media_superior_sin_superior_25_49'] ?? null) ?></strong>
                            <small><?= $numero($perfil2549['media_superior_sin_superior_25_49_pct'] ?? null, 2) ?> % del grupo 25–49</small>
                        </article>
                        <article>
                            <span>Con educación superior</span>
                            <strong><?= $numero($perfil2549['con_educacion_superior_25_49'] ?? null) ?></strong>
                            <small><?= $numero($perfil2549['con_educacion_superior_25_49_pct'] ?? null, 2) ?> % del grupo 25–49</small>
                        </article>
                    </div>

                    <div class="territorial-education-note">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            Perfil disponible en
                            <strong><?= (int)($perfil2549['municipios_con_datos'] ?? 0) ?></strong>
                            de
                            <strong><?= (int)($perfil2549['municipios_clasificables'] ?? 0) ?></strong>
                            municipios clasificables.
                            <?php if (trim((string)($perfil2549['fuente'] ?? '')) !== ''): ?>
                                Fuente: <?= $texto($perfil2549['fuente']) ?>.
                            <?php endif; ?>
                        </span>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($indicadores)): ?>
                <section class="dashboard-panel report-section mb-4">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>INDICADORES EDUCATIVOS COMPLEMENTARIOS</span>
                            <h3>Información registrada en el territorio</h3>
                            <p>Indicadores adicionales conservados con su periodo de referencia.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle report-table territorial-compact-table">
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

            <section class="dashboard-panel report-section mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>CONTEXTO INSTITUCIONAL</span>
                        <h3>Gobierno y estructura del territorio</h3>
                        <p>Ficha institucional de referencia; no interviene en el cálculo de priorización municipal.</p>
                    </div>
                </div>
                <dl class="report-definition-grid territorial-government-grid">
                    <div><dt>Capital</dt><dd><?= $valor($estado['capital'] ?? null) ?></dd></div>
                    <div><dt>Titular del gobierno</dt><dd><?= $valor($estado['titular_gobierno'] ?? null) ?></dd></div>
                    <div><dt>Cargo</dt><dd><?= $valor($estado['cargo_titular'] ?? null) ?></dd></div>
                    <div><dt>Partido político</dt><dd><?= $valor($estado['partido_politico'] ?? null) ?></dd></div>
                    <div><dt>Periodo de gobierno</dt><dd><?= $valor($estado['periodo_gobierno'] ?? null) ?></dd></div>
                    <div><dt>Teléfono</dt><dd><?= $valor($estado['telefono'] ?? null) ?></dd></div>
                </dl>
            </section>

            <?php if (!empty($secretariasActivas)): ?>
                <section class="dashboard-panel report-section mb-4">
                    <div class="territorial-report-section-heading">
                        <div>
                            <span>ESTRUCTURA INSTITUCIONAL</span>
                            <h3>Secretarías registradas</h3>
                            <p>Contactos institucionales disponibles en la ficha territorial.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle report-table territorial-compact-table">
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

            <section class="dashboard-panel report-section territorial-insights-section mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>LECTURA TERRITORIAL</span>
                        <h3>Hallazgos de los datos disponibles</h3>
                        <p>Síntesis calculada a partir de los indicadores registrados; no reemplaza las fuentes originales.</p>
                    </div>
                </div>
                <?php if (!empty($lecturas)): ?>
                    <div class="territorial-insight-grid">
                        <?php foreach ($lecturas as $lectura): ?>
                            <article>
                                <i class="bi bi-lightbulb"></i>
                                <div>
                                    <strong><?= $texto($lectura['titulo'] ?? 'Hallazgo') ?></strong>
                                    <p><?= $texto($lectura['texto'] ?? '') ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="data-empty-text mb-0">Todavía no hay suficientes datos para generar una lectura territorial.</p>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel report-section mb-4">
                <div class="territorial-report-section-heading">
                    <div>
                        <span>FUENTES Y VIGENCIA</span>
                        <h3>Periodos de referencia</h3>
                        <p>La fecha de actualización del expediente no sustituye el periodo estadístico propio de cada fuente.</p>
                    </div>
                </div>

                <div class="report-source-grid territorial-source-grid">
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

                    <?php if (($perfil2549['disponible'] ?? false) === true && trim((string)($perfil2549['fuente'] ?? '')) !== ''): ?>
                        <?php $fuentesMostradas++; ?>
                        <article>
                            <span>PERFIL EDUCATIVO 25–49</span>
                            <strong><?= $texto($perfil2549['fuente']) ?></strong>
                            <small>Periodo: <?= $texto($perfil2549['anio'] ?? '—') ?></small>
                        </article>
                    <?php endif; ?>

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
