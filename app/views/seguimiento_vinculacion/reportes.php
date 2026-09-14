<?php

$territorios = $territorios ?? [];
$municipiosPorEstado = $municipiosPorEstado ?? [];
$institucionesDisponibles = $institucionesDisponibles ?? [];
$responsablesDisponibles = $responsablesDisponibles ?? [];
$canalesDisponibles = $canalesDisponibles ?? [];
$estadosSeguimiento = $estadosSeguimiento ?? [];
$filtrosReporte = $filtrosReporte ?? [];
$resumenFiltros = $resumenFiltros ?? [];
$seguimientosReporte = $seguimientosReporte ?? [];
$resumenReporte = $resumenReporte ?? [
    'total' => 0,
    'sin_actividad' => 0,
    'mas_7_dias' => 0,
    'por_estatus' => [],
    'por_municipio' => []
];
$generarReporte = $generarReporte ?? false;
$errorFiltros = $errorFiltros ?? '';

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$seleccionado = static function ($actual, $valor) {
    return (string)$actual === (string)$valor ? 'selected' : '';
};

$estadoIdActual = (int)($filtrosReporte['estado_id'] ?? 0);
$municipioIdActual = (int)($filtrosReporte['municipio_id'] ?? 0);
$municipiosActuales = $estadoIdActual > 0
    ? ($municipiosPorEstado[$estadoIdActual] ?? [])
    : [];
$maxEstatus = !empty($resumenReporte['por_estatus'])
    ? max(1, max($resumenReporte['por_estatus']))
    : 1;
$maxMunicipio = !empty($resumenReporte['por_municipio'])
    ? max(1, max($resumenReporte['por_municipio']))
    : 1;
$urlLimpiar = BASE_URL . 'index.php?controller=seguimientoVinculacionReporte&action=index';

$etiquetaEstatus = static function ($codigo) use ($estadosSeguimiento) {
    return $estadosSeguimiento[$codigo] ?? 'Sin estado';
};

?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <a
        class="linkage-back-link"
        href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a Seguimiento de Vinculación
    </a>
</div>

<section class="dashboard-panel mb-4">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h2 class="panel-title mb-1">Generar reporte de seguimiento</h2>
            <p class="page-subtitle mb-0">
                Selecciona los criterios que deseas utilizar para personalizar el reporte.
            </p>
        </div>
        <span class="metric-icon" aria-hidden="true">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </span>
    </div>

    <?php if ($errorFiltros !== ''): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorFiltros) ?></span>
        </div>
    <?php endif; ?>

    <form action="<?= BASE_URL ?>index.php" method="GET" data-report-form>
        <input type="hidden" name="controller" value="seguimientoVinculacionReporte">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="generar" value="1">

        <div class="row g-3">
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="reporte_fecha_inicial">Fecha inicial</label>
                <input
                    class="form-control"
                    type="date"
                    id="reporte_fecha_inicial"
                    name="fecha_inicial"
                    value="<?= $texto($filtrosReporte['fecha_inicial'] ?? '') ?>">
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="reporte_fecha_final">Fecha final</label>
                <input
                    class="form-control"
                    type="date"
                    id="reporte_fecha_final"
                    name="fecha_final"
                    value="<?= $texto($filtrosReporte['fecha_final'] ?? '') ?>">
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="reporte_estado">Estado</label>
                <select
                    class="form-select"
                    id="reporte_estado"
                    name="estado_id"
                    data-report-state>
                    <option value="0">Todos</option>
                    <?php foreach ($territorios as $territorio): ?>
                        <option
                            value="<?= (int)($territorio['id'] ?? 0) ?>"
                            <?= $seleccionado($estadoIdActual, (int)($territorio['id'] ?? 0)) ?>>
                            <?= $texto($territorio['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="reporte_municipio">Municipio</label>
                <select
                    class="form-select"
                    id="reporte_municipio"
                    name="municipio_id"
                    data-report-municipality
                    <?= $estadoIdActual > 0 ? '' : 'disabled' ?>>
                    <option value="0">Todos</option>
                    <?php foreach ($municipiosActuales as $municipio): ?>
                        <option
                            value="<?= (int)($municipio['id'] ?? 0) ?>"
                            <?= $seleccionado($municipioIdActual, (int)($municipio['id'] ?? 0)) ?>>
                            <?= $texto($municipio['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="reporte_institucion">Institución</label>
                <select class="form-select" id="reporte_institucion" name="institucion">
                    <option value="">Todas</option>
                    <?php foreach ($institucionesDisponibles as $institucion): ?>
                        <option
                            value="<?= $texto($institucion) ?>"
                            <?= $seleccionado($filtrosReporte['institucion'] ?? '', $institucion) ?>>
                            <?= $texto($institucion) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="reporte_responsable">Responsable</label>
                <select class="form-select" id="reporte_responsable" name="responsable_id">
                    <option value="0">Todos</option>
                    <?php foreach ($responsablesDisponibles as $responsableId => $responsableNombre): ?>
                        <option
                            value="<?= (int)$responsableId ?>"
                            <?= $seleccionado($filtrosReporte['responsable_id'] ?? 0, $responsableId) ?>>
                            <?= $texto($responsableNombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="reporte_estatus">Etapa / Estatus</label>
                <select class="form-select" id="reporte_estatus" name="estado_seguimiento">
                    <option value="">Todos</option>
                    <?php foreach ($estadosSeguimiento as $codigo => $etiqueta): ?>
                        <option
                            value="<?= $texto($codigo) ?>"
                            <?= $seleccionado($filtrosReporte['estado_seguimiento'] ?? '', $codigo) ?>>
                            <?= $texto($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($canalesDisponibles)): ?>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label" for="reporte_actividad">Tipo de actividad / interacción</label>
                    <select class="form-select" id="reporte_actividad" name="tipo_actividad">
                        <option value="">Todos</option>
                        <?php foreach ($canalesDisponibles as $canal => $etiqueta): ?>
                            <option
                                value="<?= $texto($canal) ?>"
                                <?= $seleccionado($filtrosReporte['tipo_actividad'] ?? '', $canal) ?>>
                                <?= $texto($etiqueta) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Se utiliza el canal de la última interacción registrada.</div>
                </div>
            <?php endif; ?>

            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="reporte_dias_sin_actividad">Días sin actividad</label>
                <select class="form-select" id="reporte_dias_sin_actividad" name="dias_sin_actividad">
                    <option value="0" <?= $seleccionado($filtrosReporte['dias_sin_actividad'] ?? 0, 0) ?>>Todos</option>
                    <option value="3" <?= $seleccionado($filtrosReporte['dias_sin_actividad'] ?? 0, 3) ?>>Más de 3 días</option>
                    <option value="7" <?= $seleccionado($filtrosReporte['dias_sin_actividad'] ?? 0, 7) ?>>Más de 7 días</option>
                    <option value="15" <?= $seleccionado($filtrosReporte['dias_sin_actividad'] ?? 0, 15) ?>>Más de 15 días</option>
                    <option value="30" <?= $seleccionado($filtrosReporte['dias_sin_actividad'] ?? 0, 30) ?>>Más de 30 días</option>
                </select>
            </div>
        </div>

        <div class="form-text mt-3">
            El periodo se aplica sobre la fecha de inicio registrada en cada seguimiento.
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a class="btn btn-secondary" href="<?= $texto($urlLimpiar) ?>">
                <i class="bi bi-arrow-counterclockwise me-2"></i>
                Limpiar filtros
            </a>
            <button class="btn btn-system-primary" type="submit">
                <i class="bi bi-bar-chart me-2"></i>
                Generar reporte
            </button>
        </div>
    </form>
</section>

<?php if ($generarReporte && $errorFiltros === ''): ?>
    <section aria-labelledby="titulo-reporte-seguimiento">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h2 class="page-title" id="titulo-reporte-seguimiento">Reporte de Seguimiento de Vinculación</h2>
                <p class="page-subtitle mb-0">Resultados calculados con los criterios seleccionados.</p>
            </div>
            <span class="status-pill status-pill-active">
                <?= (int)$resumenReporte['total'] ?> <?= (int)$resumenReporte['total'] === 1 ? 'seguimiento' : 'seguimientos' ?>
            </span>
        </div>

        <section class="dashboard-panel mb-4" aria-label="Filtros utilizados">
            <h3 class="panel-title">Filtros utilizados</h3>
            <div class="row g-3">
                <?php foreach ($resumenFiltros as $nombreFiltro => $valorFiltro): ?>
                    <div class="col-sm-6 col-lg-3">
                        <span class="d-block text-muted small mb-1"><?= $texto($nombreFiltro) ?></span>
                        <strong><?= $texto($valorFiltro) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="metric-grid linkage-summary-grid mb-4" aria-label="Indicadores del reporte">
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon">
                    <i class="bi bi-kanban"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)$resumenReporte['total'] ?></p>
                    <p class="metric-label">Total de seguimientos</p>
                </div>
            </article>

            <article class="metric-card linkage-summary-card">
                <div class="metric-icon metric-icon-muted">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)$resumenReporte['sin_actividad'] ?></p>
                    <p class="metric-label">Sin actividad registrada</p>
                </div>
            </article>

            <article class="metric-card linkage-summary-card">
                <div class="metric-icon metric-icon-muted">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)$resumenReporte['mas_7_dias'] ?></p>
                    <p class="metric-label">Más de 7 días sin actividad</p>
                </div>
            </article>

            <?php foreach ($resumenReporte['por_estatus'] as $codigo => $totalEstatus): ?>
                <article class="metric-card linkage-summary-card">
                    <div class="metric-icon">
                        <i class="bi bi-record-circle"></i>
                    </div>
                    <div>
                        <p class="metric-value"><?= (int)$totalEstatus ?></p>
                        <p class="metric-label"><?= $texto($etiquetaEstatus($codigo)) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <section class="dashboard-panel h-100" aria-labelledby="grafica-estatus-titulo">
                    <h3 class="panel-title" id="grafica-estatus-titulo">Seguimientos por estatus</h3>
                    <?php if (!empty($resumenReporte['por_estatus'])): ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($resumenReporte['por_estatus'] as $codigo => $totalEstatus): ?>
                                <?php $porcentaje = ((int)$totalEstatus / $maxEstatus) * 100; ?>
                                <div>
                                    <div class="d-flex justify-content-between gap-3 mb-1 small">
                                        <span><?= $texto($etiquetaEstatus($codigo)) ?></span>
                                        <strong><?= (int)$totalEstatus ?></strong>
                                    </div>
                                    <div class="progress" role="img" aria-label="<?= $texto($etiquetaEstatus($codigo)) ?>: <?= (int)$totalEstatus ?>">
                                        <div
                                            class="progress-bar"
                                            style="width: <?= number_format($porcentaje, 2, '.', '') ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No hay información de estatus para los criterios seleccionados.</p>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-6">
                <section class="dashboard-panel h-100" aria-labelledby="grafica-municipios-titulo">
                    <h3 class="panel-title" id="grafica-municipios-titulo">Seguimientos por municipio</h3>
                    <?php if (!empty($resumenReporte['por_municipio'])): ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($resumenReporte['por_municipio'] as $municipioNombre => $totalMunicipio): ?>
                                <?php $porcentaje = ((int)$totalMunicipio / $maxMunicipio) * 100; ?>
                                <div>
                                    <div class="d-flex justify-content-between gap-3 mb-1 small">
                                        <span><?= $texto($municipioNombre) ?></span>
                                        <strong><?= (int)$totalMunicipio ?></strong>
                                    </div>
                                    <div class="progress" role="img" aria-label="<?= $texto($municipioNombre) ?>: <?= (int)$totalMunicipio ?>">
                                        <div
                                            class="progress-bar"
                                            style="width: <?= number_format($porcentaje, 2, '.', '') ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No hay información municipal para los criterios seleccionados.</p>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <section class="dashboard-panel p-0 overflow-hidden">
            <div class="table-panel-header">
                <div>
                    <h3 class="panel-title mb-0">Detalle del reporte</h3>
                </div>
            </div>

            <?php if (!empty($seguimientosReporte)): ?>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Institución</th>
                                <th>Estado</th>
                                <th>Municipio</th>
                                <th>Responsable</th>
                                <th>Última actividad</th>
                                <th>Estatus</th>
                                <th>Días sin actividad</th>
                                <th>Próxima acción</th>
                                <th>Folio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seguimientosReporte as $seguimiento): ?>
                                <tr>
                                    <td><?= $texto($seguimiento['nombre_entidad'] ?? '—') ?></td>
                                    <td><?= $texto($seguimiento['estado_nombre'] ?? '—') ?></td>
                                    <td><?= $texto(trim((string)($seguimiento['municipio'] ?? '')) !== '' ? $seguimiento['municipio'] : '—') ?></td>
                                    <td><?= $texto($seguimiento['responsable_nombre'] ?? '—') ?></td>
                                    <td>
                                        <?= trim((string)($seguimiento['ultima_interaccion_at'] ?? '')) !== ''
                                            ? $texto($seguimiento['ultima_actividad_label'] ?? '—')
                                            : 'Sin actividad registrada' ?>
                                    </td>
                                    <td>
                                        <span class="status-pill status-pill-active">
                                            <?= $texto($seguimiento['estado_label'] ?? 'Sin estado') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $seguimiento['dias_sin_actividad'] === null
                                            ? '—'
                                            : (int)$seguimiento['dias_sin_actividad'] ?>
                                    </td>
                                    <td><?= $texto($seguimiento['proxima_accion_label'] ?? '—') ?></td>
                                    <td><?= $texto(trim((string)($seguimiento['folio'] ?? '')) !== '' ? $seguimiento['folio'] : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="data-empty-state py-5">
                    <span><i class="bi bi-search"></i></span>
                    <strong>No se encontraron seguimientos con los criterios seleccionados.</strong>
                    <p class="mb-0">Modifica los filtros y vuelve a generar el reporte.</p>
                </div>
            <?php endif; ?>
        </section>
    </section>
<?php endif; ?>

<script>
(function () {
    const estado = document.querySelector('[data-report-state]');
    const municipio = document.querySelector('[data-report-municipality]');
    const municipiosPorEstado = <?= json_encode(
        $municipiosPorEstado,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    if (!estado || !municipio) {
        return;
    }

    const cargarMunicipios = function (estadoId, seleccionado) {
        const municipios = municipiosPorEstado[String(estadoId)] || [];
        municipio.replaceChildren(new Option('Todos', '0'));

        municipios.forEach(function (item) {
            const opcion = new Option(String(item.nombre || ''), String(item.id || '0'));
            opcion.selected = String(item.id || '0') === String(seleccionado || '0');
            municipio.add(opcion);
        });

        municipio.disabled = !estadoId || String(estadoId) === '0';

        if (municipio.disabled) {
            municipio.value = '0';
        }
    };

    estado.addEventListener('change', function () {
        cargarMunicipios(this.value, '0');
    });
})();
</script>
