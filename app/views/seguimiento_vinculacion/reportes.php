<?php

require_once __DIR__ . '/../../services/EvolucionActividadSeguimientoService.php';

$territorios = $territorios ?? [];
$municipiosPorEstado = $municipiosPorEstado ?? [];
$institucionesDisponibles = $institucionesDisponibles ?? [];
$responsablesDisponibles = $responsablesDisponibles ?? [];
$canalesDisponibles = $canalesDisponibles ?? [];
$estadosSeguimiento = $estadosSeguimiento ?? [];
$filtrosReporte = $filtrosReporte ?? [];
$resumenFiltros = $resumenFiltros ?? [];
$seguimientosReporte = $seguimientosReporte ?? [];
$seguimientosActividad = $seguimientosActividad ?? [];
$resumenReporte = $resumenReporte ?? [
    'total' => 0,
    'sin_actividad' => 0,
    'mas_7_dias' => 0,
    'por_estatus' => [],
    'por_municipio' => []
];
$generarReporte = $generarReporte ?? false;
$errorFiltros = $errorFiltros ?? '';
$errorExportacionPdf = $errorExportacionPdf ?? '';
$urlExportarPdf = $urlExportarPdf ?? '';
$modoModalReporte = (string)($_GET['modal'] ?? '') === '1';
$tipoReporteActual = (string)($filtrosReporte['tipo_reporte'] ?? 'cartera');
$analiticaReporte = is_array($analiticaReporte ?? null) ? $analiticaReporte : [];
$detalleInstitucionReporte = is_array($detalleInstitucionReporte ?? null)
    ? $detalleInstitucionReporte
    : [];
$llamadasReporte = is_array($analiticaReporte['llamadas'] ?? null)
    ? $analiticaReporte['llamadas']
    : [];
$canalesReporte = is_array($analiticaReporte['canales'] ?? null)
    ? $analiticaReporte['canales']
    : [];
$actividadRecienteReporte = is_array($analiticaReporte['actividad_reciente'] ?? null)
    ? $analiticaReporte['actividad_reciente']
    : [];
$etiquetaCanalReporte = static function ($canal) {
    $canal = strtoupper(trim((string)$canal));
    return [
        'LLAMADA_IP' => 'Llamada',
        'LLAMADA' => 'Llamada',
        'CORREO' => 'Correo',
        'WHATSAPP' => 'WhatsApp',
        'NOTA' => 'Nota'
    ][$canal] ?? ($canal !== '' ? ucfirst(strtolower($canal)) : 'Actividad');
};
$etiquetaResultadoReporte = static function ($resultado) {
    $resultado = strtoupper(trim((string)$resultado));
    return [
        'CONTACTADO' => 'Con contacto',
        'CONTACTO_CORRECTO' => 'Contacto correcto',
        'CONTACTO_REFERIDO' => 'Contacto referido',
        'SIN_RESPUESTA' => 'Sin respuesta',
        'NUMERO_INCORRECTO' => 'Número incorrecto',
        'SOLICITO_LLAMAR_DESPUES' => 'Solicitó llamar después',
        'SOLICITO_INFORMACION' => 'Solicitó información',
        'NO_INTERESADO' => 'No interesado'
    ][$resultado] ?? ($resultado !== '' ? ucfirst(strtolower(str_replace('_', ' ', $resultado))) : 'Registrada');
};
$titulosReporteAnalista = [
    'actividad' => [
        'titulo' => 'Mi actividad de seguimiento',
        'subtitulo' => 'Actividad e interacciones registradas dentro del periodo seleccionado.'
    ],
    'cartera' => [
        'titulo' => 'Mi cartera de seguimiento',
        'subtitulo' => 'Estado actual de los seguimientos incluidos en la consulta.'
    ],
    'institucion' => [
        'titulo' => 'Reporte de institución',
        'subtitulo' => 'Expediente ejecutivo del seguimiento seleccionado.'
    ]
];
$tituloReporteGenerado =
    $modoSeguimiento === 'analista'
        ? ($titulosReporteAnalista[$tipoReporteActual]['titulo'] ?? 'Reporte de Seguimiento de Vinculación')
        : 'Reporte de Seguimiento de Vinculación';
$subtituloReporteGenerado =
    $modoSeguimiento === 'analista'
        ? ($titulosReporteAnalista[$tipoReporteActual]['subtitulo'] ?? 'Resultados calculados con los criterios seleccionados.')
        : 'Resultados calculados con los criterios seleccionados.';

if (
    $modoSeguimiento === 'analista' &&
    $tipoReporteActual === 'institucion' &&
    count($seguimientosReporte) === 1
) {
    $institucionEncabezado = $seguimientosReporte[0];
    $nombreInstitucionEncabezado = trim((string)($institucionEncabezado['nombre_entidad'] ?? ''));
    $ubicacionInstitucionEncabezado = implode(', ', array_values(array_filter([
        trim((string)($institucionEncabezado['municipio'] ?? '')),
        trim((string)($institucionEncabezado['estado_nombre'] ?? ''))
    ])));

    $subtituloReporteGenerado = trim(
        $nombreInstitucionEncabezado .
        ($ubicacionInstitucionEncabezado !== '' ? ' · ' . $ubicacionInstitucionEncabezado : '')
    );
}

$fechaHoraReporte = static function ($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '—';
    }

    try {
        return (new DateTime($valor))->format('d/m/Y · H:i');
    } catch (Throwable $error) {
        return $valor;
    }
};
$evolucionActividad = [
    'periodos' => [],
    'total' => 0,
    'mayor' => ['etiqueta' => '—', 'total' => 0],
    'menor' => ['etiqueta' => '—', 'total' => 0],
    'variacion' => null,
    'total_anterior' => null,
    'comparacion_disponible' => false,
    'granularidad' => 'dia',
    'fecha_inicial' => '',
    'fecha_final' => '',
    'sin_datos' => true
];

if (!$modoModalReporte && $generarReporte && $errorFiltros === '') {
    try {
        $evolucionActividad = (new EvolucionActividadSeguimientoService())->construir(
            $seguimientosActividad,
            $filtrosReporte
        );
    } catch (Throwable $error) {
        error_log('[reporte_evolucion_actividad] ' . $error->getMessage());
    }
}

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

if ($modoModalReporte) {
    $urlLimpiar .= '&modal=1';
}

$etiquetaEstatus = static function ($codigo) use ($estadosSeguimiento) {
    return $estadosSeguimiento[$codigo] ?? 'Sin estado';
};

?>

<?php if ($modoModalReporte): ?>
    <style>
        body {
            background: #ffffff !important;
        }

        .admin-sidebar,
        .admin-topbar {
            display: none !important;
        }

        .admin-shell,
        .admin-main {
            min-height: 0 !important;
        }

        .admin-main {
            margin-left: 0 !important;
            background: #ffffff !important;
        }

        .admin-content {
            padding: 0 !important;
        }
    </style>
    <script>
        document.querySelector('.admin-sidebar')?.remove();
        document.querySelector('.admin-topbar')?.remove();
    </script>
<?php endif; ?>

<?php if (!$modoModalReporte): ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <a
            class="linkage-back-link"
            href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver a Seguimiento de Vinculación
        </a>
    </div>
<?php endif; ?>

<section class="<?= $modoModalReporte ? 'mb-0' : 'dashboard-panel mb-4 py-3' ?>">
    <?php if (!$modoModalReporte): ?>
        <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
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
    <?php endif; ?>

    <?php if ($errorFiltros !== ''): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorFiltros) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$modoModalReporte && $errorExportacionPdf !== ''): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorExportacionPdf) ?></span>
        </div>
    <?php endif; ?>

    <form
        action="<?= BASE_URL ?>index.php"
        method="GET"
        data-report-form
        <?= $modoModalReporte ? 'class="system-form-modal border-0 shadow-none" target="_top"' : '' ?>>
        <input type="hidden" name="controller" value="seguimientoVinculacionReporte">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="generar" value="1">
        <input
            type="hidden"
            name="tipo_reporte"
            value="<?= $texto($filtrosReporte['tipo_reporte'] ?? 'cartera') ?>"
            data-report-type-input>

        <?php if ($modoSeguimiento === 'analista'): ?>
            <div class="report-mode-selector" data-analyst-report-modes>
                <button
                    type="button"
                    class="report-mode-card"
                    data-report-mode="actividad"
                    aria-pressed="false">
                    <span class="report-mode-icon"><i class="bi bi-activity"></i></span>
                    <span>
                        <strong>Mi actividad</strong>
                        <small>Lo que hiciste durante un periodo.</small>
                    </span>
                </button>
                <button
                    type="button"
                    class="report-mode-card"
                    data-report-mode="cartera"
                    aria-pressed="false">
                    <span class="report-mode-icon"><i class="bi bi-kanban"></i></span>
                    <span>
                        <strong>Mi cartera</strong>
                        <small>Estado actual de tus seguimientos.</small>
                    </span>
                </button>
                <button
                    type="button"
                    class="report-mode-card"
                    data-report-mode="institucion"
                    aria-pressed="false">
                    <span class="report-mode-icon"><i class="bi bi-building"></i></span>
                    <span>
                        <strong>Una institución</strong>
                        <small>Expediente ejecutivo de un seguimiento.</small>
                    </span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($modoSeguimiento === 'analista'): ?>
            <div class="report-period-shortcuts d-none" data-report-period-shortcuts>
                <span>Periodo rápido:</span>
                <button type="button" class="btn btn-sm btn-light" data-report-period="today">Hoy</button>
                <button type="button" class="btn btn-sm btn-light" data-report-period="week">Esta semana</button>
                <button type="button" class="btn btn-sm btn-light" data-report-period="month">Este mes</button>
            </div>
        <?php endif; ?>

        <?php if ($modoModalReporte): ?>
            <div class="modal-body">
        <?php endif; ?>

        <div class="<?= $modoModalReporte ? 'row g-3' : 'row gx-3 gy-2' ?>">
            <div class="col-md-6 col-xl-3" data-report-field="periodo">
                <label class="form-label" for="reporte_fecha_inicial">Fecha inicial</label>
                <input
                    class="form-control"
                    type="date"
                    id="reporte_fecha_inicial"
                    name="fecha_inicial"
                    value="<?= $texto($filtrosReporte['fecha_inicial'] ?? '') ?>">
            </div>

            <div class="col-md-6 col-xl-3" data-report-field="periodo">
                <label class="form-label" for="reporte_fecha_final">Fecha final</label>
                <input
                    class="form-control"
                    type="date"
                    id="reporte_fecha_final"
                    name="fecha_final"
                    value="<?= $texto($filtrosReporte['fecha_final'] ?? '') ?>">
            </div>

            <div class="col-md-6 col-xl-3" data-report-field="territorio">
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

            <div class="col-md-6 col-xl-3" data-report-field="municipio">
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

            <div class="col-md-6 col-xl-4" data-report-field="institucion">
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

            <div class="col-md-6 col-xl-4<?= $modoSeguimiento === 'analista' ? ' d-none' : '' ?>" data-report-field="responsable">
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

            <div class="col-md-6 col-xl-4" data-report-field="estatus">
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
                <div class="col-md-6 col-xl-4" data-report-field="actividad">
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
                    <div class="form-text">Filtra el reporte por el canal de interacción registrado.</div>
                </div>
            <?php endif; ?>

            <div class="col-md-6 col-xl-4" data-report-field="inactividad">
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

        <div class="form-text <?= $modoModalReporte ? 'mt-3' : 'mt-2' ?>" data-report-mode-help>
            El periodo se aplica sobre la fecha de inicio registrada en cada seguimiento.
        </div>

        <?php if ($modoModalReporte): ?>
            </div>
        <?php endif; ?>

        <div class="<?= $modoModalReporte ? 'modal-footer' : 'd-flex flex-wrap justify-content-end gap-2 mt-3' ?>">
            <a
                class="btn <?= $modoModalReporte ? 'btn-system-cancel' : 'btn-secondary' ?>"
                href="<?= $texto($urlLimpiar) ?>">
                <i class="bi bi-arrow-counterclockwise me-2"></i>
                Limpiar filtros
            </a>
            <button
                class="btn <?= $modoModalReporte ? 'btn-system-save' : 'btn-system-primary' ?>"
                type="submit">
                <i class="bi bi-bar-chart me-2"></i>
                Generar reporte
            </button>
        </div>
    </form>
</section>

<?php if (!$modoModalReporte && $generarReporte && $errorFiltros === ''): ?>
    <section
        class="seguimiento-report-results<?= $modoSeguimiento === 'analista' ? ' analyst-report-output' : '' ?>"
        aria-labelledby="titulo-reporte-seguimiento"
        <?= $modoSeguimiento === 'analista'
            ? 'data-analyst-report-output data-report-type="' . $texto($tipoReporteActual) . '"'
            : '' ?>>
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h2 class="page-title" id="titulo-reporte-seguimiento"><?= $texto($tituloReporteGenerado) ?></h2>
                <p class="page-subtitle mb-0"><?= $texto($subtituloReporteGenerado) ?></p>
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                <span class="status-pill status-pill-active">
                    <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
                        <?= (int)($analiticaReporte['interacciones'] ?? 0) ?> interacciones
                    <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'institucion'): ?>
                        1 institución
                    <?php else: ?>
                        <?= (int)$resumenReporte['total'] ?> <?= (int)$resumenReporte['total'] === 1 ? 'seguimiento' : 'seguimientos' ?>
                    <?php endif; ?>
                </span>
                <?php if ($urlExportarPdf !== ''): ?>
                    <a class="btn btn-system-save linkage-action-button" href="<?= $texto($urlExportarPdf) ?>">
                        <i class="bi bi-file-earmark-pdf"></i>
                        Exportar PDF
                    </a>
                <?php endif; ?>
            </div>
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

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
        <section class="metric-grid linkage-summary-grid mb-4" aria-label="Indicadores de actividad del analista">
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-activity"></i></div>
                <div>
                    <p class="metric-value"><?= (int)($analiticaReporte['interacciones'] ?? 0) ?></p>
                    <p class="metric-label">Interacciones registradas</p>
                </div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-telephone"></i></div>
                <div>
                    <p class="metric-value"><?= (int)($llamadasReporte['total'] ?? 0) ?></p>
                    <p class="metric-label">Llamadas realizadas</p>
                </div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-person-check"></i></div>
                <div>
                    <p class="metric-value"><?= (int)($llamadasReporte['contactadas'] ?? 0) ?></p>
                    <p class="metric-label">Llamadas con contacto</p>
                </div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-patch-check"></i></div>
                <div>
                    <p class="metric-value"><?= (int)($llamadasReporte['verificaciones_efectivas'] ?? 0) ?></p>
                    <p class="metric-label">Verificaciones efectivas</p>
                </div>
            </article>
        </section>

        <section class="dashboard-panel mb-4" aria-label="Desglose del trabajo realizado">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h3 class="panel-title mb-1">Desglose del trabajo realizado</h3>
                    <p class="page-subtitle mb-0">Las verificaciones efectivas se contabilizan una vez por institución y día.</p>
                </div>
                <span class="status-pill status-pill-active">
                    <?= number_format((float)($llamadasReporte['tasa_contacto'] ?? 0), 1) ?>% contacto
                </span>
            </div>
            <div class="row g-3">
                <div class="col-6 col-lg-3"><strong><?= (int)($canalesReporte['correos'] ?? 0) ?></strong><span class="d-block text-muted small">Correos</span></div>
                <div class="col-6 col-lg-3"><strong><?= (int)($canalesReporte['whatsapp'] ?? 0) ?></strong><span class="d-block text-muted small">WhatsApp</span></div>
                <div class="col-6 col-lg-3"><strong><?= (int)($llamadasReporte['sin_respuesta'] ?? 0) ?></strong><span class="d-block text-muted small">Llamadas sin respuesta</span></div>
                <div class="col-6 col-lg-3"><strong><?= (int)($llamadasReporte['volver_llamar'] ?? 0) ?></strong><span class="d-block text-muted small">Solicitaron llamar después</span></div>
            </div>
        </section>
    <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'institucion'): ?>
        <section class="metric-grid linkage-summary-grid mb-4" aria-label="Indicadores de la institución">
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-chat-square-text"></i></div>
                <div><p class="metric-value"><?= (int)($analiticaReporte['interacciones'] ?? 0) ?></p><p class="metric-label">Interacciones del analista</p></div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-telephone"></i></div>
                <div><p class="metric-value"><?= (int)($llamadasReporte['total'] ?? 0) ?></p><p class="metric-label">Llamadas realizadas</p></div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-person-check"></i></div>
                <div><p class="metric-value"><?= (int)($llamadasReporte['contactadas'] ?? 0) ?></p><p class="metric-label">Llamadas con contacto</p></div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon"><i class="bi bi-patch-check"></i></div>
                <div><p class="metric-value"><?= (int)($llamadasReporte['verificaciones_efectivas'] ?? 0) ?></p><p class="metric-label">Verificaciones efectivas</p></div>
            </article>
        </section>

        <?php
        $contactoInstitucion = is_array($detalleInstitucionReporte['contacto'] ?? null)
            ? $detalleInstitucionReporte['contacto']
            : [];
        $seguimientoInstitucion = $seguimientosReporte[0] ?? [];
        ?>
        <section class="dashboard-panel mb-4 analyst-institution-profile" aria-labelledby="ficha-institucion-reporte">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h3 class="panel-title mb-1" id="ficha-institucion-reporte">Ficha técnica de la institución</h3>
                    <p class="page-subtitle mb-0"><?= $texto($seguimientoInstitucion['nombre_entidad'] ?? 'Institución') ?></p>
                </div>
                <span class="status-pill status-pill-active"><?= $texto($seguimientoInstitucion['estado_label'] ?? 'Seguimiento') ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><span class="d-block text-muted small">Ubicación</span><strong><?= $texto(trim((string)($seguimientoInstitucion['municipio'] ?? '')) !== '' ? ($seguimientoInstitucion['municipio'] . ', ' . ($seguimientoInstitucion['estado_nombre'] ?? '')) : ($seguimientoInstitucion['estado_nombre'] ?? '—')) ?></strong></div>
                <div class="col-md-4"><span class="d-block text-muted small">Contacto</span><strong><?= $texto(trim((string)($contactoInstitucion['nombre'] ?? '')) !== '' ? $contactoInstitucion['nombre'] : '—') ?></strong></div>
                <div class="col-md-4"><span class="d-block text-muted small">Cargo / área</span><strong><?= $texto(trim((string)($contactoInstitucion['cargo'] ?? '')) !== '' ? $contactoInstitucion['cargo'] : '—') ?></strong></div>
                <div class="col-md-4"><span class="d-block text-muted small">Teléfono</span><strong><?= $texto(trim((string)($contactoInstitucion['telefono'] ?? '')) !== '' ? $contactoInstitucion['telefono'] : '—') ?></strong></div>
                <div class="col-md-4"><span class="d-block text-muted small">Correo</span><strong><?= $texto(trim((string)($contactoInstitucion['correo'] ?? '')) !== '' ? $contactoInstitucion['correo'] : '—') ?></strong></div>
                <div class="col-md-4"><span class="d-block text-muted small">Próxima acción</span><strong><?= $texto($seguimientoInstitucion['proxima_accion_label'] ?? '—') ?></strong></div>
            </div>
        </section>
    <?php else: ?>
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
    <?php endif; ?>

        <?php if ($modoSeguimiento !== 'analista' || $tipoReporteActual === 'cartera'): ?>
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
        <?php endif; ?>

        <?php if ($modoSeguimiento !== 'analista' || $tipoReporteActual === 'actividad'): ?>
        <section class="dashboard-panel mb-4" aria-labelledby="grafica-evolucion-actividad-titulo">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h3 class="panel-title mb-1" id="grafica-evolucion-actividad-titulo">Evolución de la actividad</h3>
                    <p class="page-subtitle mb-0">Actividades registradas durante el periodo seleccionado.</p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card h-100">
                        <div class="metric-icon">
                            <i class="bi bi-activity"></i>
                        </div>
                        <div>
                            <p class="metric-value"><?= (int)$evolucionActividad['total'] ?></p>
                            <p class="metric-label">Actividades en el periodo</p>
                        </div>
                    </article>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card h-100">
                        <div class="metric-icon">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                        <div>
                            <p class="metric-value" style="font-size: 18px; line-height: 1.2;"><?= $texto($evolucionActividad['mayor']['etiqueta'] ?? '—') ?></p>
                            <p class="metric-label"><?= (int)($evolucionActividad['mayor']['total'] ?? 0) ?> actividades · Mayor actividad</p>
                        </div>
                    </article>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card h-100">
                        <div class="metric-icon metric-icon-muted">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                        <div>
                            <p class="metric-value" style="font-size: 18px; line-height: 1.2;"><?= $texto($evolucionActividad['menor']['etiqueta'] ?? '—') ?></p>
                            <p class="metric-label"><?= (int)($evolucionActividad['menor']['total'] ?? 0) ?> actividades · Menor actividad</p>
                        </div>
                    </article>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <article class="metric-card h-100">
                        <div class="metric-icon metric-icon-muted">
                            <i class="bi bi-percent"></i>
                        </div>
                        <div>
                            <?php if (!empty($evolucionActividad['comparacion_disponible'])): ?>
                                <?php $variacionActividad = (float)$evolucionActividad['variacion']; ?>
                                <p class="metric-value">
                                    <?= $variacionActividad > 0 ? '+' : '' ?><?= number_format($variacionActividad, 1) ?>%
                                </p>
                                <p class="metric-label">Variación vs. periodo anterior</p>
                            <?php else: ?>
                                <p class="metric-value" style="font-size: 16px; line-height: 1.2;">Sin comparación disponible</p>
                                <p class="metric-label">Variación vs. periodo anterior</p>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            </div>

            <?php $periodosActividad = $evolucionActividad['periodos'] ?? []; ?>
            <?php if (!empty($periodosActividad)): ?>
                <?php
                $svgAncho = 1000;
                $svgAlto = 340;
                $margenIzquierdo = 58;
                $margenDerecho = 24;
                $margenSuperior = 20;
                $margenInferior = 58;
                $anchoArea = $svgAncho - $margenIzquierdo - $margenDerecho;
                $altoArea = $svgAlto - $margenSuperior - $margenInferior;
                $maxActividad = max(1, max(array_map(static function ($periodo) {
                    return (int)($periodo['total'] ?? 0);
                }, $periodosActividad)));
                $cantidadPeriodos = count($periodosActividad);
                $pasoX = $cantidadPeriodos > 1 ? $anchoArea / ($cantidadPeriodos - 1) : 0;
                $puntosLinea = [];
                $puntosSvg = [];

                foreach ($periodosActividad as $indicePeriodo => $periodoActividad) {
                    $x = $cantidadPeriodos > 1
                        ? $margenIzquierdo + ($pasoX * $indicePeriodo)
                        : $margenIzquierdo + ($anchoArea / 2);
                    $totalPeriodo = (int)($periodoActividad['total'] ?? 0);
                    $y = $margenSuperior + $altoArea - (($totalPeriodo / $maxActividad) * $altoArea);
                    $puntosLinea[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
                    $puntosSvg[] = [
                        'x' => $x,
                        'y' => $y,
                        'total' => $totalPeriodo,
                        'etiqueta' => (string)($periodoActividad['etiqueta'] ?? ''),
                        'tooltip' => (string)($periodoActividad['tooltip'] ?? '')
                    ];
                }

                $saltoEtiquetas = max(1, (int)ceil($cantidadPeriodos / 8));
                ?>
                <div class="w-100 overflow-hidden">
                    <svg
                        viewBox="0 0 <?= $svgAncho ?> <?= $svgAlto ?>"
                        width="100%"
                        role="img"
                        aria-labelledby="grafica-evolucion-actividad-titulo">
                        <?php for ($nivel = 0; $nivel <= 4; $nivel++): ?>
                            <?php
                            $valorNivel = (int)round($maxActividad * (1 - ($nivel / 4)));
                            $yNivel = $margenSuperior + (($altoArea / 4) * $nivel);
                            ?>
                            <line
                                x1="<?= $margenIzquierdo ?>"
                                y1="<?= number_format($yNivel, 2, '.', '') ?>"
                                x2="<?= $svgAncho - $margenDerecho ?>"
                                y2="<?= number_format($yNivel, 2, '.', '') ?>"
                                style="stroke: var(--color-border); stroke-width: 1;" />
                            <text
                                x="<?= $margenIzquierdo - 10 ?>"
                                y="<?= number_format($yNivel + 4, 2, '.', '') ?>"
                                text-anchor="end"
                                style="fill: var(--color-text-secondary); font-size: 12px;">
                                <?= $valorNivel ?>
                            </text>
                        <?php endfor; ?>

                        <polyline
                            points="<?= $texto(implode(' ', $puntosLinea)) ?>"
                            fill="none"
                            style="stroke: var(--color-primary); stroke-width: 3; stroke-linecap: round; stroke-linejoin: round;" />

                        <?php foreach ($puntosSvg as $indicePunto => $puntoSvg): ?>
                            <circle
                                cx="<?= number_format($puntoSvg['x'], 2, '.', '') ?>"
                                cy="<?= number_format($puntoSvg['y'], 2, '.', '') ?>"
                                r="5"
                                style="fill: var(--color-primary); stroke: #ffffff; stroke-width: 2;">
                                <title><?= $texto($puntoSvg['tooltip']) ?> · <?= (int)$puntoSvg['total'] ?> actividades</title>
                            </circle>

                            <?php if ($indicePunto % $saltoEtiquetas === 0 || $indicePunto === $cantidadPeriodos - 1): ?>
                                <text
                                    x="<?= number_format($puntoSvg['x'], 2, '.', '') ?>"
                                    y="<?= $svgAlto - 22 ?>"
                                    text-anchor="middle"
                                    style="fill: var(--color-text-secondary); font-size: 12px;">
                                    <?= $texto($puntoSvg['etiqueta']) ?>
                                </text>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </svg>
                </div>

                <?php if (!empty($evolucionActividad['sin_datos'])): ?>
                    <p class="text-muted mb-0 mt-2">No se registraron actividades durante el periodo seleccionado.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted mb-0">No se registraron actividades durante el periodo seleccionado.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
        <section class="dashboard-panel p-0 overflow-hidden">
            <div class="table-panel-header">
                <div>
                    <h3 class="panel-title mb-0">Actividad registrada en el periodo</h3>
                    <p class="page-subtitle mb-0 mt-1">Hasta 60 interacciones, ordenadas de la más reciente a la más antigua.</p>
                </div>
            </div>
            <?php if (!empty($actividadRecienteReporte)): ?>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Institución</th><th>Actividad</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($actividadRecienteReporte as $actividad): ?>
                            <tr>
                                <td><?= $texto($actividad['fecha_inicio'] ?? '—') ?></td>
                                <td><?= $texto($actividad['nombre_entidad'] ?? '—') ?></td>
                                <td><?= $texto($etiquetaCanalReporte($actividad['canal'] ?? '')) ?></td>
                                <td><?= $texto($etiquetaResultadoReporte($actividad['resultado'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="data-empty-state py-5">
                    <span><i class="bi bi-activity"></i></span>
                    <strong>No hay actividad registrada en el periodo seleccionado.</strong>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'institucion'): ?>
        <?php $interaccionesInstitucion = is_array($detalleInstitucionReporte['interacciones_recientes'] ?? null) ? $detalleInstitucionReporte['interacciones_recientes'] : []; ?>
        <section class="dashboard-panel p-0 overflow-hidden analyst-institution-history">
            <div class="table-panel-header">
                <div>
                    <h3 class="panel-title mb-0">Interacciones recientes</h3>
                    <p class="page-subtitle mb-0 mt-1">Actividad humana más reciente con esta institución.</p>
                </div>
            </div>
            <?php if (!empty($interaccionesInstitucion)): ?>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Interacción</th><th>Resultado</th><th>Resumen</th></tr></thead>
                        <tbody>
                        <?php foreach ($interaccionesInstitucion as $actividad): ?>
                            <tr>
                                <?php
                                $presentacionActividad = is_array($actividad['presentacion'] ?? null)
                                    ? $actividad['presentacion']
                                    : [];
                                $tituloActividad = trim((string)($presentacionActividad['titulo'] ?? ''));
                                $resultadoActividad = trim((string)($presentacionActividad['resultado_label'] ?? ''));
                                $resumenActividad = trim((string)($presentacionActividad['resumen'] ?? ''));
                                ?>
                                <td class="analyst-history-date"><?= $texto($fechaHoraReporte($actividad['fecha_inicio'] ?? '')) ?></td>
                                <td>
                                    <strong class="analyst-history-title"><?= $texto($tituloActividad !== '' ? $tituloActividad : $etiquetaCanalReporte($actividad['canal'] ?? '')) ?></strong>
                                    <span class="analyst-history-owner"><?= $texto(trim((string)($actividad['nombre'] ?? '') . ' ' . (string)($actividad['apellidos'] ?? ''))) ?></span>
                                </td>
                                <td>
                                    <span class="analyst-history-result"><?= $texto($resultadoActividad !== '' ? $resultadoActividad : $etiquetaResultadoReporte($actividad['resultado'] ?? '')) ?></span>
                                </td>
                                <td class="analyst-history-summary"><?= $texto($resumenActividad !== '' ? $resumenActividad : 'Sin detalle adicional') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="data-empty-state py-5">
                    <span><i class="bi bi-building"></i></span>
                    <strong>No hay interacciones humanas registradas para esta institución.</strong>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
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
