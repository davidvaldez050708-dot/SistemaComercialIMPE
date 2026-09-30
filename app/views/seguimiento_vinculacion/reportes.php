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
    'acciones_vencidas' => 0,
    'formalizados' => 0,
    'descartados' => 0,
    'en_gestion' => 0,
    'requieren_atencion' => 0,
    'por_estatus' => [],
    'por_etapa' => [],
    'por_municipio' => [],
    'prioritarios' => []
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
$rendimientoTelefonicoResumen = is_array($analiticaReporte['rendimiento_telefonico'] ?? null)
    ? $analiticaReporte['rendimiento_telefonico']
    : ['granularidad' => 'dia', 'periodos' => []];
$rendimientoTelefonicoPeriodos = is_array($rendimientoTelefonicoResumen['periodos'] ?? null)
    ? $rendimientoTelefonicoResumen['periodos']
    : [];
$rendimientoTelefonicoGranularidad = (string)($rendimientoTelefonicoResumen['granularidad'] ?? 'dia');
$rendimientoTelefonicoHoy = is_array($analiticaReporte['rendimiento_telefonico_hoy'] ?? null)
    ? $analiticaReporte['rendimiento_telefonico_hoy']
    : [];
$institucionesActividadReporte = is_array($analiticaReporte['instituciones_actividad'] ?? null)
    ? $analiticaReporte['instituciones_actividad']
    : [];
$totalInstitucionesActividadReporte = max(0, (int)($analiticaReporte['seguimientos_con_actividad'] ?? 0));
$metaDiariaEfectivas = max(1, (int)($analiticaReporte['meta_diaria_efectivas'] ?? 25));
$tipoInteraccionActividad = strtoupper(trim((string)($filtrosReporte['tipo_actividad'] ?? '')));
$mostrarRendimientoTelefonico =
    $tipoInteraccionActividad === '' ||
    in_array($tipoInteraccionActividad, ['LLAMADA', 'LLAMADA_IP'], true);
$efectivasHoyReporte = max(0, (int)($rendimientoTelefonicoHoy['efectivas'] ?? 0));
$llamadasHoyReporte = max(0, (int)($rendimientoTelefonicoHoy['llamadas'] ?? 0));
$contactosHoyReporte = max(0, (int)($rendimientoTelefonicoHoy['con_contacto'] ?? 0));
$fechaHoyReporte = date('Y-m-d');
$fechaInicialActividadReporte = trim((string)($filtrosReporte['fecha_inicial'] ?? ''));
$fechaFinalActividadReporte = trim((string)($filtrosReporte['fecha_final'] ?? ''));
$hoyIncluidoEnPeriodo =
    ($fechaInicialActividadReporte === '' || $fechaHoyReporte >= $fechaInicialActividadReporte) &&
    ($fechaFinalActividadReporte === '' || $fechaHoyReporte <= $fechaFinalActividadReporte);
$etiquetaGranularidadTelefonica = [
    'dia' => 'día',
    'semana' => 'semana',
    'mes' => 'mes'
][$rendimientoTelefonicoGranularidad] ?? 'periodo';
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
        'NO_INTERESADO' => 'No interesado',
        'OTRO' => 'Sin clasificación',
        'REGISTRADA' => 'Registrada'
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
    $tipoReporteActual === 'institucion'
) {
    $institucionEncabezado = is_array($detalleInstitucionReporte['seguimiento'] ?? null)
        ? $detalleInstitucionReporte['seguimiento']
        : (count($seguimientosReporte) === 1 ? $seguimientosReporte[0] : []);
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
    'comparacion_periodo_disponible' => false,
    'comparacion_etiqueta' => '',
    'comparacion_fecha_inicial' => '',
    'comparacion_fecha_final' => '',
    'comparacion_motivo' => '',
    'granularidad' => 'dia',
    'fecha_inicial' => '',
    'fecha_final' => '',
    'sin_datos' => true
];

if (!$modoModalReporte && $generarReporte && $errorFiltros === '') {
    try {
        $evolucionActividad = (new EvolucionActividadSeguimientoService())->construir(
            $seguimientosActividad,
            $filtrosReporte,
            (int)($_SESSION['usuario_id'] ?? 0),
            (string)$modoSeguimiento
        );
    } catch (Throwable $error) {
        error_log('[reporte_evolucion_actividad] ' . $error->getMessage());
    }
}

$etiquetaGranularidadEvolucion = [
    'dia' => 'día',
    'semana' => 'semana',
    'mes' => 'mes'
][(string)($evolucionActividad['granularidad'] ?? 'dia')] ?? 'periodo';

$comparacionActividadRango = '';
if (!empty($evolucionActividad['comparacion_periodo_disponible'])) {
    $comparacionDesde = trim((string)($evolucionActividad['comparacion_fecha_inicial'] ?? ''));
    $comparacionHasta = trim((string)($evolucionActividad['comparacion_fecha_final'] ?? ''));
    try {
        if ($comparacionDesde !== '' && $comparacionHasta !== '') {
            $comparacionActividadRango =
                (new DateTimeImmutable($comparacionDesde))->format('d/m/Y') .
                ' - ' .
                (new DateTimeImmutable($comparacionHasta))->format('d/m/Y');
        }
    } catch (Throwable $error) {
        $comparacionActividadRango = '';
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

$origenReporte = trim((string)($_GET['origen'] ?? ''));
$urlVolverReporte = BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index';
$textoVolverReporte = 'Volver a Seguimiento de Vinculación';

if ($origenReporte === 'reportes') {
    if ($modoSeguimiento === 'analista' && $generarReporte) {
        $urlVolverReporte = BASE_URL . 'index.php?controller=seguimientoVinculacionReporte&action=index&origen=reportes';
        $textoVolverReporte = 'Volver a Reportes de Seguimiento';
    } else {
        $urlVolverReporte = BASE_URL . 'index.php?controller=reporte&action=index';
        $textoVolverReporte = 'Volver a Reportes';
    }
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
            href="<?= $texto($urlVolverReporte) ?>">
            <i class="bi bi-arrow-left"></i>
            <?= $texto($textoVolverReporte) ?>
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
                <label class="form-label" for="reporte_fecha_inicial"><?= $tipoReporteActual === 'actividad' ? 'Actividad desde' : 'Fecha inicial' ?></label>
                <input
                    class="form-control"
                    type="date"
                    id="reporte_fecha_inicial"
                    name="fecha_inicial"
                    value="<?= $texto($filtrosReporte['fecha_inicial'] ?? '') ?>">
            </div>

            <div class="col-md-6 col-xl-3" data-report-field="periodo">
                <label class="form-label" for="reporte_fecha_final"><?= $tipoReporteActual === 'actividad' ? 'Actividad hasta' : 'Fecha final' ?></label>
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
                <?php if ($modoSeguimiento === 'analista'): ?>
                    <label class="form-label" for="reporte_institucion_selector">Institución</label>
                    <input
                        type="hidden"
                        id="reporte_institucion"
                        name="institucion_id"
                        value="<?= (int)($filtrosReporte['institucion_id'] ?? 0) ?>"
                        data-report-institution-input>
                    <button
                        type="button"
                        class="form-select report-institution-trigger"
                        id="reporte_institucion_selector"
                        data-report-institution-picker
                        <?= $estadoIdActual > 0 ? '' : 'disabled' ?>>
                        <span
                            class="report-institution-trigger-label"
                            data-report-institution-label>
                            <?= trim((string)($filtrosReporte['institucion'] ?? '')) !== ''
                                ? $texto($filtrosReporte['institucion'])
                                : 'Seleccionar institución' ?>
                        </span>
                    </button>
                    <div class="form-text report-institution-hint" data-report-institution-hint>
                        <?= $estadoIdActual > 0
                            ? 'Puedes acotar por municipio o elegir una institución del estado seleccionado.'
                            : 'Selecciona primero un estado para consultar las instituciones disponibles.' ?>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
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
                <label class="form-label" for="reporte_estatus"><?= $tipoReporteActual === 'cartera' ? 'Etapa actual' : 'Etapa / Estatus' ?></label>
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
                    <label class="form-label" for="reporte_actividad"><?= $tipoReporteActual === 'actividad' ? 'Tipo de interacción' : 'Último canal de contacto' ?></label>
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
                    <div class="form-text"><?= $tipoReporteActual === 'actividad'
                            ? 'Filtra por el tipo de interacción que realizaste durante el periodo.'
                            : 'Filtra por el canal de la última interacción humana registrada; los eventos automáticos no se consideran.' ?></div>
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
            <?php if ($tipoReporteActual === 'actividad'): ?>
                Consulta las actividades que realizaste durante el periodo y, si lo necesitas, acótalas por territorio o tipo de interacción.
            <?php elseif ($tipoReporteActual === 'institucion'): ?>
                Selecciona una institución para consultar su expediente ejecutivo de seguimiento.
            <?php else: ?>
                Consulta el estado actual de tu cartera y acota por territorio, etapa, canal o inactividad.
            <?php endif; ?>
        </div>

        <?php if ($modoModalReporte): ?>
            </div>
        <?php endif; ?>

        <div class="<?= $modoModalReporte ? 'modal-footer' : 'd-flex flex-wrap justify-content-end gap-2 mt-3' ?>">
            <?php if ($modoSeguimiento === 'analista'): ?>
                <a
                    class="btn <?= $modoModalReporte ? 'btn-system-cancel' : 'btn-secondary' ?>"
                    href="<?= $texto($urlLimpiar) ?>"
                    data-report-clear-filters>
                    <i class="bi bi-arrow-counterclockwise me-2"></i>
                    Limpiar filtros
                </a>
            <?php else: ?>
                <a
                    class="btn <?= $modoModalReporte ? 'btn-system-cancel' : 'btn-secondary' ?>"
                    href="<?= $texto($urlLimpiar) ?>">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>
                    Limpiar filtros
                </a>
            <?php endif; ?>
            <button
                class="btn <?= $modoModalReporte ? 'btn-system-save' : 'btn-system-primary' ?>"
                type="submit">
                <i class="bi bi-bar-chart me-2"></i>
                Generar reporte
            </button>
        </div>
    </form>

    <?php if ($modoSeguimiento === 'analista'): ?>
        <div class="report-institution-picker-backdrop d-none" data-report-institution-dialog aria-hidden="true">
            <section
                class="report-institution-picker-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="report-institution-picker-title">
                <div class="report-institution-picker-head">
                    <div>
                        <span class="report-eyebrow">UNA INSTITUCIÓN</span>
                        <h3 class="panel-title mb-1" id="report-institution-picker-title">Seleccionar institución</h3>
                        <p class="page-subtitle mb-0" data-report-institution-context>
                            Elige una institución del territorio seleccionado.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="report-institution-picker-close"
                        data-report-institution-close
                        aria-label="Cerrar selector">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="report-institution-picker-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        class="form-control"
                        placeholder="Buscar dentro de las instituciones mostradas"
                        autocomplete="off"
                        data-report-institution-search>
                </div>

                <div class="report-institution-picker-status" data-report-institution-status>
                    Selecciona un estado para consultar instituciones.
                </div>

                <div class="report-institution-picker-list" data-report-institution-list></div>

                <div class="report-institution-picker-empty d-none" data-report-institution-empty>
                    <span><i class="bi bi-building"></i></span>
                    <strong>No encontramos instituciones con esos criterios.</strong>
                    <small>Prueba con otro nombre o cambia el municipio seleccionado.</small>
                </div>

                <div class="report-institution-picker-footer">
                    <span data-report-institution-page-summary></span>
                    <div class="d-flex align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-system-light btn-sm"
                            data-report-institution-prev
                            disabled>
                            <i class="bi bi-chevron-left"></i>
                            Anterior
                        </button>
                        <button
                            type="button"
                            class="btn btn-system-light btn-sm"
                            data-report-institution-next
                            disabled>
                            Siguiente
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
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
                <?php if ($modoSeguimiento === 'analista'): ?>
                    <button
                        type="button"
                        class="btn btn-system-light linkage-action-button"
                        data-edit-report-filters>
                        <i class="bi bi-sliders"></i>
                        Editar filtros
                    </button>
                <?php endif; ?>
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

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
            <?php
            $filtrosActividadResumen = [
                'Periodo' => (string)($resumenFiltros['Periodo'] ?? '—'),
                'Territorio' => (string)($resumenFiltros['Estado'] ?? 'Todos'),
                'Tipo de interacción' => (string)($resumenFiltros['Tipo de interacción'] ?? 'Todos')
            ];
            ?>
            <section class="dashboard-panel analyst-activity-context mb-3" aria-label="Contexto del reporte">
                <div class="analyst-activity-section-heading">
                    <div>
                        <span class="report-eyebrow">CONTEXTO DEL REPORTE</span>
                        <h3 class="panel-title mb-1">Actividad analizada</h3>
                        <p class="page-subtitle mb-0">El reporte considera únicamente las interacciones realizadas por el Analista dentro del periodo.</p>
                    </div>
                </div>
                <div class="analyst-activity-context-grid">
                    <?php foreach ($filtrosActividadResumen as $nombreFiltro => $valorFiltro): ?>
                        <div>
                            <span><?= $texto($nombreFiltro) ?></span>
                            <strong><?= $texto($valorFiltro) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'cartera'): ?>
            <?php
            $territorioCartera = (string)($resumenFiltros['Estado'] ?? 'Todos');
            $municipioCartera = (string)($resumenFiltros['Municipio'] ?? 'Todos');
            if ($municipioCartera !== 'Todos') {
                $territorioCartera .= ' · ' . $municipioCartera;
            }
            $filtrosCarteraResumen = [
                'Territorio' => $territorioCartera,
                'Etapa actual' => (string)($resumenFiltros['Etapa'] ?? 'Todos'),
                'Último canal humano' => (string)($resumenFiltros['Último canal de contacto'] ?? 'Todos'),
                'Inactividad' => (string)($resumenFiltros['Días sin actividad'] ?? 'Todos')
            ];
            ?>
            <section class="dashboard-panel analyst-portfolio-context mb-3" aria-label="Contexto de la cartera">
                <div class="analyst-portfolio-section-heading">
                    <div>
                        <span class="report-eyebrow">CONTEXTO DEL REPORTE</span>
                        <h3 class="panel-title mb-1">Cartera analizada</h3>
                        <p class="page-subtitle mb-0">Fotografía actual de los seguimientos del Analista. La actividad y el canal consideran únicamente interacciones humanas.</p>
                    </div>
                </div>
                <div class="analyst-portfolio-context-grid">
                    <?php foreach ($filtrosCarteraResumen as $nombreFiltro => $valorFiltro): ?>
                        <div>
                            <span><?= $texto($nombreFiltro) ?></span>
                            <strong><?= $texto($valorFiltro) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="dashboard-panel mb-4 report-filter-summary" aria-label="Filtros utilizados">
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
        <?php endif; ?>

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
        <section class="analyst-activity-kpis mb-3" aria-label="Resumen ejecutivo de actividad">
            <article class="analyst-activity-kpi">
                <span class="analyst-activity-kpi-icon"><i class="bi bi-activity"></i></span>
                <div>
                    <strong><?= (int)($analiticaReporte['interacciones'] ?? 0) ?></strong>
                    <span>Actividades realizadas</span>
                    <small>Interacciones humanas del periodo</small>
                </div>
            </article>
            <article class="analyst-activity-kpi">
                <span class="analyst-activity-kpi-icon"><i class="bi bi-buildings"></i></span>
                <div>
                    <strong><?= (int)($analiticaReporte['seguimientos_con_actividad'] ?? 0) ?></strong>
                    <span>Instituciones trabajadas</span>
                    <small>Seguimientos con actividad real</small>
                </div>
            </article>
            <article class="analyst-activity-kpi">
                <span class="analyst-activity-kpi-icon"><i class="bi bi-telephone"></i></span>
                <div>
                    <strong><?= (int)($llamadasReporte['total'] ?? 0) ?></strong>
                    <span>Llamadas realizadas</span>
                    <small>Intentos telefónicos registrados</small>
                </div>
            </article>
            <article class="analyst-activity-kpi">
                <span class="analyst-activity-kpi-icon"><i class="bi bi-person-check"></i></span>
                <div>
                    <strong><?= (int)($llamadasReporte['contactadas'] ?? 0) ?></strong>
                    <span>Con contacto</span>
                    <small><?= number_format((float)($llamadasReporte['tasa_contacto'] ?? 0), 1) ?>% de las llamadas</small>
                </div>
            </article>
            <article class="analyst-activity-kpi analyst-activity-kpi--effective">
                <span class="analyst-activity-kpi-icon"><i class="bi bi-patch-check"></i></span>
                <div>
                    <strong><?= (int)($llamadasReporte['verificaciones_efectivas'] ?? 0) ?></strong>
                    <span>Llamadas efectivas</span>
                    <small>Una efectiva contabilizada por institución y día</small>
                </div>
            </article>
        </section>

        <div class="row g-3 mb-3">
            <div class="<?= $mostrarRendimientoTelefonico ? 'col-xl-8' : 'col-12' ?>">
                <section class="dashboard-panel analyst-activity-phone" aria-labelledby="rendimiento-telefonico-actividad">
                    <div class="analyst-activity-section-heading">
                        <div>
                            <span class="report-eyebrow">RENDIMIENTO TELEFÓNICO</span>
                            <h3 class="panel-title mb-1" id="rendimiento-telefonico-actividad">
                                <?= $mostrarRendimientoTelefonico
                                    ? 'Rendimiento telefónico por ' . $etiquetaGranularidadTelefonica
                                    : 'Actividad filtrada por canal' ?>
                            </h3>
                            <p class="page-subtitle mb-0">
                                <?php if ($mostrarRendimientoTelefonico): ?>
                                    <?php if ($rendimientoTelefonicoGranularidad === 'dia'): ?>
                                        Seguimiento diario de llamadas, contacto y efectivas contabilizadas. La referencia operativa es de <?= $metaDiariaEfectivas ?> efectivas por día.
                                    <?php elseif ($rendimientoTelefonicoGranularidad === 'semana'): ?>
                                        Resumen semanal de llamadas y contacto. Las efectivas se contabilizan una vez por institución y día y aquí se suman por semana; no se extrapola la meta diaria a una meta semanal.
                                    <?php else: ?>
                                        Resumen mensual de llamadas y contacto. Las efectivas se contabilizan una vez por institución y día y aquí se suman por mes; la meta diaria se conserva únicamente como referencia operativa.
                                    <?php endif; ?>
                                <?php else: ?>
                                    El filtro actual no corresponde a llamadas; el rendimiento telefónico no se mezcla con este resultado.
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($mostrarRendimientoTelefonico): ?>
                            <span class="analyst-activity-today-pill">
                                <?php if ($hoyIncluidoEnPeriodo): ?>
                                    Hoy: <strong><?= $efectivasHoyReporte ?></strong>/<?= $metaDiariaEfectivas ?>
                                <?php else: ?>
                                    Meta diaria: <strong><?= $metaDiariaEfectivas ?></strong> efectivas
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($mostrarRendimientoTelefonico && !empty($rendimientoTelefonicoPeriodos)): ?>
                        <div class="analyst-activity-phone-head">
                            <span><?= $texto(ucfirst($etiquetaGranularidadTelefonica)) ?></span>
                            <span>Llamadas</span>
                            <span>Contacto</span>
                            <span>Efectivas</span>
                            <span><?= $rendimientoTelefonicoGranularidad === 'dia' ? 'Avance diario' : 'Tasa contacto' ?></span>
                        </div>
                        <div class="analyst-activity-phone-days">
                            <?php foreach ($rendimientoTelefonicoPeriodos as $periodoTelefonico): ?>
                                <?php
                                $fechaInicioPeriodoTelefonico = trim((string)($periodoTelefonico['fecha_inicio'] ?? ''));
                                $fechaFinPeriodoTelefonico = trim((string)($periodoTelefonico['fecha_fin'] ?? ''));
                                $incluyeHoyTelefonico =
                                    $fechaInicioPeriodoTelefonico !== '' &&
                                    $fechaFinPeriodoTelefonico !== '' &&
                                    $fechaHoyReporte >= $fechaInicioPeriodoTelefonico &&
                                    $fechaHoyReporte <= $fechaFinPeriodoTelefonico;
                                $efectivasPeriodo = max(0, (int)($periodoTelefonico['efectivas'] ?? 0));
                                $cumplimientoPeriodo = max(0, min(100, (float)($periodoTelefonico['cumplimiento_pct'] ?? 0)));
                                ?>
                                <div class="analyst-activity-phone-day<?= $incluyeHoyTelefonico ? ' is-today' : '' ?>">
                                    <div class="analyst-activity-phone-date">
                                        <strong><?= $texto($periodoTelefonico['etiqueta'] ?? '—') ?></strong>
                                        <?php if (trim((string)($periodoTelefonico['subetiqueta'] ?? '')) !== ''): ?>
                                            <span><?= $texto($periodoTelefonico['subetiqueta']) ?></span>
                                        <?php elseif ($incluyeHoyTelefonico && $rendimientoTelefonicoGranularidad === 'dia'): ?>
                                            <span>Hoy</span>
                                        <?php endif; ?>
                                    </div>
                                    <strong><?= (int)($periodoTelefonico['llamadas'] ?? 0) ?></strong>
                                    <strong><?= (int)($periodoTelefonico['con_contacto'] ?? 0) ?></strong>
                                    <strong class="analyst-activity-effective-value"><?= $efectivasPeriodo ?></strong>
                                    <?php if ($rendimientoTelefonicoGranularidad === 'dia'): ?>
                                        <div class="analyst-activity-goal">
                                            <div>
                                                <span style="width: <?= number_format($cumplimientoPeriodo, 1, '.', '') ?>%"></span>
                                            </div>
                                            <small><?= $efectivasPeriodo ?>/<?= $metaDiariaEfectivas ?></small>
                                        </div>
                                    <?php else: ?>
                                        <div class="analyst-activity-contact-rate">
                                            <strong><?= number_format((float)($periodoTelefonico['tasa_contacto'] ?? 0), 1) ?>%</strong>
                                            <small>contacto</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($mostrarRendimientoTelefonico): ?>
                        <div class="analyst-activity-empty">
                            <i class="bi bi-telephone-x"></i>
                            <span>No hay llamadas registradas dentro del periodo seleccionado.</span>
                        </div>
                    <?php else: ?>
                        <div class="analyst-activity-channel-focus">
                            <span class="analyst-activity-channel-focus-icon"><i class="bi bi-funnel"></i></span>
                            <div>
                                <strong>Vista enfocada en <?= $texto($resumenFiltros['Tipo de interacción'] ?? 'el canal seleccionado') ?></strong>
                                <p>Las métricas y el historial muestran únicamente las interacciones de este tipo.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php if ($mostrarRendimientoTelefonico): ?>
            <div class="col-xl-4">
                <section class="dashboard-panel analyst-activity-composition" aria-labelledby="composicion-actividad">
                    <div class="analyst-activity-section-heading">
                        <div>
                            <span class="report-eyebrow">COMPOSICIÓN DEL TRABAJO</span>
                            <h3 class="panel-title mb-1" id="composicion-actividad">Actividad medible</h3>
                            <p class="page-subtitle mb-0">Llamadas y correos registrados, más los principales resultados telefónicos.</p>
                        </div>
                    </div>
                    <div class="analyst-activity-channel-grid analyst-activity-channel-grid--measurable">
                        <div><strong><?= (int)($canalesReporte['llamadas'] ?? 0) ?></strong><span>Llamadas</span></div>
                        <div><strong><?= (int)($canalesReporte['correos'] ?? 0) ?></strong><span>Correos</span></div>
                    </div>
                    <div class="analyst-activity-result-list">
                        <div><span>Sin respuesta</span><strong><?= (int)($llamadasReporte['sin_respuesta'] ?? 0) ?></strong></div>
                        <div><span>Número incorrecto</span><strong><?= (int)($llamadasReporte['numero_incorrecto'] ?? 0) ?></strong></div>
                        <div><span>Solicitaron llamar después</span><strong><?= (int)($llamadasReporte['volver_llamar'] ?? 0) ?></strong></div>
                        <div><span>Tasa de contacto</span><strong><?= number_format((float)($llamadasReporte['tasa_contacto'] ?? 0), 1) ?>%</strong></div>
                    </div>
                </section>
            </div>
            <?php endif; ?>
        </div>
    <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'institucion'): ?>
        <?php
        $contactoInstitucion = is_array($detalleInstitucionReporte['contacto'] ?? null)
            ? $detalleInstitucionReporte['contacto']
            : [];
        $seguimientoInstitucion = is_array($detalleInstitucionReporte['seguimiento'] ?? null)
            ? $detalleInstitucionReporte['seguimiento']
            : ($seguimientosReporte[0] ?? []);
        $flujoInstitucion = is_array($detalleInstitucionReporte['flujo'] ?? null)
            ? $detalleInstitucionReporte['flujo']
            : [];
        $hitosInstitucion = is_array($detalleInstitucionReporte['hitos'] ?? null)
            ? $detalleInstitucionReporte['hitos']
            : [];
        $oficiosInstitucion = is_array($detalleInstitucionReporte['oficios'] ?? null)
            ? $detalleInstitucionReporte['oficios']
            : [];
        $correosInstitucion = is_array($detalleInstitucionReporte['correos_recientes'] ?? null)
            ? $detalleInstitucionReporte['correos_recientes']
            : [];
        $postEnvioInstitucion = is_array($detalleInstitucionReporte['post_envio'] ?? null)
            ? $detalleInstitucionReporte['post_envio']
            : [];
        $ultimaReunionInstitucion = is_array($detalleInstitucionReporte['ultima_reunion'] ?? null)
            ? $detalleInstitucionReporte['ultima_reunion']
            : [];
        $observacionesInstitucion = is_array($detalleInstitucionReporte['observaciones'] ?? null)
            ? $detalleInstitucionReporte['observaciones']
            : [];
        $ultimaInteraccionInstitucion = is_array($detalleInstitucionReporte['ultima_interaccion_humana'] ?? null)
            ? $detalleInstitucionReporte['ultima_interaccion_humana']
            : [];

        $estadoCodigoInstitucion = strtoupper(trim((string)($seguimientoInstitucion['estado_seguimiento'] ?? '')));
        $estadoLabelInstitucion = trim((string)($seguimientoInstitucion['estado_label'] ?? ''));
        if ($estadoLabelInstitucion === '') {
            $estadoLabelInstitucion = $estadosSeguimiento[$estadoCodigoInstitucion]
                ?? ($estadoCodigoInstitucion !== ''
                    ? ucfirst(strtolower(str_replace('_', ' ', $estadoCodigoInstitucion)))
                    : 'Seguimiento');
        }
        $responsableInstitucion = trim((string)($seguimientoInstitucion['responsable_nombre'] ?? ''));
        if ($responsableInstitucion === '') {
            $responsableInstitucion = trim(
                (string)($seguimientoInstitucion['analista_nombre'] ?? '') . ' ' .
                (string)($seguimientoInstitucion['analista_apellidos'] ?? '')
            );
        }
        $tipoEntidadCodigoInstitucion = strtoupper(trim((string)($seguimientoInstitucion['tipo_entidad'] ?? '')));
        $tipoEntidadLabelsInstitucion = [
            'EMPRESA' => 'Empresa',
            'ORGANIZACION' => 'Organización',
            'INSTITUCION' => 'Institución',
            'SECRETARIA' => 'Secretaría',
            'MUNICIPIO' => 'Municipio',
            'OTRO' => 'Otro'
        ];
        $tipoEntidadInstitucion = $tipoEntidadLabelsInstitucion[$tipoEntidadCodigoInstitucion]
            ?? ($tipoEntidadCodigoInstitucion !== ''
                ? ucfirst(strtolower(str_replace('_', ' ', $tipoEntidadCodigoInstitucion)))
                : '—');

        $pasoActualInstitucion = (int)($flujoInstitucion['paso_actual'] ?? 0);
        $totalPasosInstitucion = max(1, (int)($flujoInstitucion['total_pasos'] ?? 13));
        $porcentajeRutaInstitucion = max(
            0,
            min(100, (int)($flujoInstitucion['porcentaje'] ?? round(($pasoActualInstitucion / $totalPasosInstitucion) * 100)))
        );
        $etapaRutaInstitucion = trim((string)($flujoInstitucion['ventana']['actual']['titulo'] ?? ''));
        if ($etapaRutaInstitucion === '') {
            $etapaRutaInstitucion = $estadoLabelInstitucion;
        }

        $accionEjecutivaInstitucion = trim((string)($flujoInstitucion['accion_principal']['etiqueta'] ?? ''));
        $fechaReunionInstitucion = trim((string)($ultimaReunionInstitucion['fecha_propuesta'] ?? ''));
        $estadoReunionInstitucion = strtoupper(trim((string)($ultimaReunionInstitucion['estado'] ?? '')));
        $reunionRealizadaInstitucion = trim((string)($ultimaReunionInstitucion['realizada_at'] ?? ''));
        $reunionVencidaInstitucion =
            $fechaReunionInstitucion !== '' &&
            $reunionRealizadaInstitucion === '' &&
            !in_array($estadoReunionInstitucion, ['CANCELADA', 'CAMBIO_SOLICITADO'], true) &&
            strtotime($fechaReunionInstitucion) !== false &&
            strtotime($fechaReunionInstitucion) < time();

        if ($reunionVencidaInstitucion) {
            $accionEjecutivaInstitucion = 'Registrar resultado de reunión';
        } elseif ($accionEjecutivaInstitucion === '') {
            $accionEjecutivaInstitucion = trim((string)($seguimientoInstitucion['proxima_accion_label'] ?? ''));
        }
        if ($accionEjecutivaInstitucion === '') {
            $accionEjecutivaInstitucion = 'Sin acción pendiente registrada';
        }

        $ultimaActividadInstitucion = trim((string)($ultimaInteraccionInstitucion['fecha_inicio'] ?? ''));
        if ($ultimaActividadInstitucion === '') {
            $ultimaActividadInstitucion = trim((string)($seguimientoInstitucion['ultima_interaccion_at'] ?? ''));
        }

        $ultimoOficioInstitucion = $oficiosInstitucion[0] ?? [];
        $respuestaTipoInstitucion = strtoupper(trim((string)($postEnvioInstitucion['respuesta_tipo'] ?? '')));
        $respuestaTipoLabels = [
            'INTERESADO' => 'Interesado',
            'MAS_INFORMACION' => 'Solicitó más información',
            'QUIERE_REUNION' => 'Solicitó reunión',
            'CONTACTAR_DESPUES' => 'Solicitó retomar contacto',
            'NO_INTERESADO' => 'No interesado'
        ];
        $resultadoReunionInstitucion = strtoupper(trim((string)(
            $ultimaReunionInstitucion['reunion_resultado']
                ?? $postEnvioInstitucion['reunion_resultado']
                ?? ''
        )));
        $resultadoReunionLabels = [
            'AVANZAR_CONVENIO' => 'Avanzar a convenio',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado'
        ];
        $modalidadReunionLabels = [
            'VIRTUAL' => 'Virtual',
            'PRESENCIAL' => 'Presencial',
            'HIBRIDA' => 'Híbrida'
        ];
        $estadoHitoLabels = [
            'COMPLETADO' => 'Completado',
            'EN_PROCESO' => 'En proceso',
            'PENDIENTE' => 'Pendiente'
        ];
        ?>

        <section class="dashboard-panel analyst-institution-current mb-3" aria-labelledby="situacion-actual-institucion">
            <div class="analyst-institution-section-heading">
                <div>
                    <span class="report-eyebrow">SITUACIÓN ACTUAL</span>
                    <h3 class="panel-title mb-1" id="situacion-actual-institucion"><?= $texto($etapaRutaInstitucion) ?></h3>
                    <p class="page-subtitle mb-0"><?= $texto(trim((string)($flujoInstitucion['descripcion'] ?? 'Estado operativo actual del seguimiento.'))) ?></p>
                </div>
                <?php if ($pasoActualInstitucion > 0): ?>
                    <span class="analyst-institution-step-pill">
                        Paso <?= $pasoActualInstitucion ?> de <?= $totalPasosInstitucion ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="analyst-institution-current-grid">
                <div>
                    <span>Última actividad</span>
                    <strong><?= $texto($fechaHoraReporte($ultimaActividadInstitucion)) ?></strong>
                </div>
                <div class="<?= $reunionVencidaInstitucion ? 'is-alert' : '' ?>">
                    <span>Próxima acción</span>
                    <strong><?= $texto($accionEjecutivaInstitucion) ?></strong>
                    <?php if ($reunionVencidaInstitucion): ?>
                        <small>Reunión pendiente de registrar · <?= $texto($fechaHoraReporte($fechaReunionInstitucion)) ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Responsable</span>
                    <strong><?= $texto($responsableInstitucion !== '' ? $responsableInstitucion : '—') ?></strong>
                </div>
                <div>
                    <span>Estado del contacto</span>
                    <strong><?= !empty($contactoInstitucion['datos_verificados']) ? 'Datos verificados' : 'Datos por validar' ?></strong>
                </div>
            </div>
        </section>

        <section class="dashboard-panel analyst-institution-route mb-3" aria-labelledby="ruta-institucion-reporte">
            <div class="analyst-institution-section-heading">
                <div>
                    <span class="report-eyebrow">RUTA DE VINCULACIÓN</span>
                    <h3 class="panel-title mb-1" id="ruta-institucion-reporte">Avance del proceso</h3>
                    <p class="page-subtitle mb-0"><?= $pasoActualInstitucion > 0 ? $pasoActualInstitucion . ' de ' . $totalPasosInstitucion . ' etapas alcanzadas en la ruta operativa.' : 'Seguimiento de hitos relevantes del proceso.' ?></p>
                </div>
                <strong class="analyst-institution-route-percent"><?= $porcentajeRutaInstitucion ?>%</strong>
            </div>
            <div class="analyst-institution-route-track" aria-hidden="true">
                <span style="width: <?= $porcentajeRutaInstitucion ?>%"></span>
            </div>
            <div class="analyst-institution-milestones">
                <?php foreach ($hitosInstitucion as $hito): ?>
                    <?php
                    $estadoHito = strtoupper(trim((string)($hito['estado'] ?? 'PENDIENTE')));
                    $claseHito = strtolower(str_replace('_', '-', $estadoHito));
                    ?>
                    <article class="analyst-institution-milestone is-<?= $texto($claseHito) ?>">
                        <span class="analyst-institution-milestone-icon">
                            <i class="bi <?= $estadoHito === 'COMPLETADO' ? 'bi-check2' : ($estadoHito === 'EN_PROCESO' ? 'bi-arrow-right' : 'bi-dot') ?>"></i>
                        </span>
                        <div>
                            <strong><?= $texto($hito['titulo'] ?? 'Hito') ?></strong>
                            <span><?= $texto($estadoHitoLabels[$estadoHito] ?? 'Pendiente') ?></span>
                            <?php if (trim((string)($hito['detalle'] ?? '')) !== ''): ?>
                                <small><?= $texto($hito['detalle']) ?></small>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-institution-profile" aria-labelledby="ficha-institucion-reporte">
                    <div class="analyst-institution-section-heading">
                        <div>
                            <span class="report-eyebrow">FICHA INSTITUCIONAL</span>
                            <h3 class="panel-title mb-1" id="ficha-institucion-reporte"><?= $texto($seguimientoInstitucion['nombre_entidad'] ?? 'Institución') ?></h3>
                            <p class="page-subtitle mb-0">
                                <?= $texto(trim((string)($contactoInstitucion['actividad_giro'] ?? '')) !== '' ? $contactoInstitucion['actividad_giro'] : 'Información institucional y de contacto disponible.') ?>
                            </p>
                        </div>
                        <span class="status-pill status-pill-active"><?= $texto($etapaRutaInstitucion) ?></span>
                    </div>

                    <div class="analyst-institution-profile-grid">
                        <div><span>Tipo</span><strong><?= $texto($tipoEntidadInstitucion) ?></strong></div>
                        <div><span>Ubicación</span><strong><?= $texto(trim((string)($seguimientoInstitucion['municipio'] ?? '')) !== '' ? ($seguimientoInstitucion['municipio'] . ', ' . ($seguimientoInstitucion['estado_nombre'] ?? '')) : ($seguimientoInstitucion['estado_nombre'] ?? '—')) ?></strong></div>
                        <div><span>Contacto</span><strong><?= $texto(trim((string)($contactoInstitucion['nombre'] ?? '')) !== '' ? $contactoInstitucion['nombre'] : '—') ?></strong></div>
                        <div><span>Cargo / área</span><strong><?= $texto(trim((string)($contactoInstitucion['cargo'] ?? '')) !== '' ? $contactoInstitucion['cargo'] : '—') ?></strong></div>
                        <div><span>Teléfono</span><strong><?= $texto(trim((string)($contactoInstitucion['telefono'] ?? '')) !== '' ? $contactoInstitucion['telefono'] : '—') ?></strong></div>
                        <div><span>Correo</span><strong><?= $texto(trim((string)($contactoInstitucion['correo'] ?? '')) !== '' ? $contactoInstitucion['correo'] : '—') ?></strong></div>
                        <div><span>WhatsApp</span><strong><?= $texto(trim((string)($contactoInstitucion['whatsapp'] ?? '')) !== '' ? $contactoInstitucion['whatsapp'] : '—') ?></strong></div>
                        <div><span>Sitio web</span><strong><?= $texto(trim((string)($contactoInstitucion['sitio_web'] ?? '')) !== '' ? $contactoInstitucion['sitio_web'] : '—') ?></strong></div>
                        <div><span>Dirección</span><strong><?= $texto(trim((string)($contactoInstitucion['direccion'] ?? '')) !== '' ? $contactoInstitucion['direccion'] : '—') ?></strong></div>
                        <div><span>Origen</span><strong><?= $texto(trim((string)($seguimientoInstitucion['origen'] ?? '')) !== '' ? $seguimientoInstitucion['origen'] : '—') ?></strong></div>
                    </div>
                    <?php if (trim((string)($seguimientoInstitucion['observaciones'] ?? '')) !== ''): ?>
                        <div class="analyst-institution-general-note">
                            <span>Observaciones generales</span>
                            <p><?= $texto($seguimientoInstitucion['observaciones']) ?></p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-institution-contact-summary" aria-labelledby="actividad-contacto-institucion">
                    <div class="analyst-institution-section-heading">
                        <div>
                            <span class="report-eyebrow">ACTIVIDAD Y CONTACTO</span>
                            <h3 class="panel-title mb-1" id="actividad-contacto-institucion">Resumen de interacción</h3>
                            <p class="page-subtitle mb-0">Actividad humana registrada con esta institución.</p>
                        </div>
                    </div>
                    <div class="analyst-institution-kpis">
                        <div><strong><?= (int)($analiticaReporte['interacciones'] ?? 0) ?></strong><span>Interacciones</span></div>
                        <div><strong><?= (int)($llamadasReporte['total'] ?? 0) ?></strong><span>Llamadas</span></div>
                        <div><strong><?= (int)($llamadasReporte['contactadas'] ?? 0) ?></strong><span>Con contacto</span></div>
                        <div><strong><?= (int)($canalesReporte['correos'] ?? 0) ?></strong><span>Correos</span></div>
                    </div>
                    <div class="analyst-institution-call-breakdown">
                        <div><span>Sin respuesta</span><strong><?= (int)($llamadasReporte['sin_respuesta'] ?? 0) ?></strong></div>
                        <div><span>Solicitaron llamar después</span><strong><?= (int)($llamadasReporte['volver_llamar'] ?? 0) ?></strong></div>
                        <div><span>Tasa de contacto</span><strong><?= number_format((float)($llamadasReporte['tasa_contacto'] ?? 0), 1) ?>%</strong></div>
                        <div><span>Verificaciones efectivas</span><strong><?= (int)($llamadasReporte['verificaciones_efectivas'] ?? 0) ?></strong></div>
                    </div>
                    <div class="analyst-institution-last-activity">
                        <div>
                            <span>Última interacción</span>
                            <strong><?= $texto($fechaHoraReporte($ultimaActividadInstitucion)) ?></strong>
                        </div>
                        <div>
                            <span>Último correo</span>
                            <strong><?= !empty($correosInstitucion) ? $texto($fechaHoraReporte($correosInstitucion[0]['fecha_inicio'] ?? '')) : '—' ?></strong>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <section class="dashboard-panel analyst-institution-formal" aria-labelledby="comunicacion-formal-institucion">
                    <div class="analyst-institution-section-heading">
                        <div>
                            <span class="report-eyebrow">COMUNICACIÓN FORMAL</span>
                            <h3 class="panel-title mb-1" id="comunicacion-formal-institucion">Oficio y respuesta</h3>
                        </div>
                    </div>

                    <?php if (!empty($ultimoOficioInstitucion)): ?>
                        <div class="analyst-institution-formal-row">
                            <span>Oficio</span>
                            <strong><?= $texto(trim((string)($ultimoOficioInstitucion['folio'] ?? '')) !== '' ? $ultimoOficioInstitucion['folio'] : 'Folio pendiente') ?></strong>
                            <small>
                                <?= $texto(trim((string)($ultimoOficioInstitucion['estado_oficio'] ?? '')) !== '' ? ucfirst(strtolower((string)$ultimoOficioInstitucion['estado_oficio'])) : 'Registrado') ?>
                                <?php if (trim((string)($ultimoOficioInstitucion['fecha_envio'] ?? '')) !== ''): ?>
                                    · enviado <?= $texto($fechaHoraReporte($ultimoOficioInstitucion['fecha_envio'])) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="analyst-institution-formal-row is-empty">
                            <span>Oficio</span>
                            <strong>Aún no registrado</strong>
                            <small>La ruta todavía no cuenta con un oficio asociado.</small>
                        </div>
                    <?php endif; ?>

                    <div class="analyst-institution-formal-row <?= trim((string)($postEnvioInstitucion['respuesta_at'] ?? '')) === '' ? 'is-empty' : '' ?>">
                        <span>Respuesta de la institución</span>
                        <strong>
                            <?= trim((string)($postEnvioInstitucion['respuesta_at'] ?? '')) !== ''
                                ? $texto($respuestaTipoLabels[$respuestaTipoInstitucion] ?? 'Respuesta registrada')
                                : 'Pendiente de respuesta' ?>
                        </strong>
                        <small>
                            <?php if (trim((string)($postEnvioInstitucion['respuesta_texto'] ?? '')) !== ''): ?>
                                <?= $texto($postEnvioInstitucion['respuesta_texto']) ?>
                            <?php else: ?>
                                Sin respuesta documentada todavía.
                            <?php endif; ?>
                        </small>
                    </div>

                    <?php if (!empty($correosInstitucion)): ?>
                        <div class="analyst-institution-formal-row analyst-institution-mail-list">
                            <span>Correos enviados</span>
                            <strong>
                                Mostrando los últimos <?= min(4, count($correosInstitucion)) ?>
                                <?php if ((int)($canalesReporte['correos'] ?? 0) > 0): ?>
                                    de <?= (int)$canalesReporte['correos'] ?> correos registrados
                                <?php else: ?>
                                    correos registrados
                                <?php endif; ?>
                            </strong>
                            <div class="analyst-institution-mail-items">
                                <?php foreach (array_slice($correosInstitucion, 0, 4) as $correoInstitucion): ?>
                                    <?php
                                    $presentacionCorreo = is_array($correoInstitucion['presentacion'] ?? null)
                                        ? $correoInstitucion['presentacion']
                                        : [];
                                    $resumenCorreo = trim((string)($presentacionCorreo['resumen'] ?? ''));
                                    if (stripos($resumenCorreo, 'Asunto: ') === 0) {
                                        $resumenCorreo = trim(substr($resumenCorreo, 8));
                                    }
                                    if ($resumenCorreo === '') {
                                        $resumenCorreo = 'Correo enviado';
                                    }
                                    ?>
                                    <div class="analyst-institution-mail-item">
                                        <span><?= $texto($fechaHoraReporte($correoInstitucion['fecha_inicio'] ?? '')) ?></span>
                                        <strong><?= $texto($resumenCorreo) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif (trim((string)($postEnvioInstitucion['seguimiento_correo_at'] ?? '')) !== ''): ?>
                        <div class="analyst-institution-formal-row">
                            <span>Seguimiento por correo</span>
                            <strong><?= $texto($fechaHoraReporte($postEnvioInstitucion['seguimiento_correo_at'])) ?></strong>
                            <small>Seguimiento por correo registrado.</small>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-6">
                <section class="dashboard-panel analyst-institution-formal" aria-labelledby="reunion-convenio-institucion">
                    <div class="analyst-institution-section-heading">
                        <div>
                            <span class="report-eyebrow">REUNIÓN Y FORMALIZACIÓN</span>
                            <h3 class="panel-title mb-1" id="reunion-convenio-institucion">Avance de acuerdos</h3>
                        </div>
                    </div>

                    <?php if (!empty($ultimaReunionInstitucion)): ?>
                        <div class="analyst-institution-formal-row <?= $reunionVencidaInstitucion ? 'is-alert' : '' ?>">
                            <span>Reunión más reciente</span>
                            <strong><?= $texto($fechaHoraReporte($fechaReunionInstitucion)) ?> · <?= $texto($modalidadReunionLabels[strtoupper((string)($ultimaReunionInstitucion['modalidad'] ?? ''))] ?? ucfirst(strtolower((string)($ultimaReunionInstitucion['modalidad'] ?? 'Reunión')))) ?></strong>
                            <small>
                                <?= trim((string)($ultimaReunionInstitucion['objetivo'] ?? '')) !== ''
                                    ? $texto($ultimaReunionInstitucion['objetivo'])
                                    : $texto(ucfirst(strtolower(str_replace('_', ' ', $estadoReunionInstitucion)))) ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="analyst-institution-formal-row is-empty">
                            <span>Reunión</span>
                            <strong>Aún no programada</strong>
                            <small>No hay reunión registrada para este seguimiento.</small>
                        </div>
                    <?php endif; ?>

                    <div class="analyst-institution-formal-row <?= $resultadoReunionInstitucion === '' ? 'is-empty' : '' ?>">
                        <span>Resultado / convenio</span>
                        <strong><?= $resultadoReunionInstitucion !== '' ? $texto($resultadoReunionLabels[$resultadoReunionInstitucion] ?? 'Resultado registrado') : 'Sin resultado de reunión' ?></strong>
                        <small>
                            <?php
                            $notasResultadoReunion = trim((string)(
                                $ultimaReunionInstitucion['reunion_resultado_notas']
                                    ?? $postEnvioInstitucion['reunion_resultado_notas']
                                    ?? ''
                            ));
                            ?>
                            <?php if ($notasResultadoReunion !== ''): ?>
                                <?= $texto($notasResultadoReunion) ?>
                            <?php elseif (trim((string)($postEnvioInstitucion['convenio_formalizado_at'] ?? '')) !== ''): ?>
                                Convenio formalizado <?= $texto($fechaHoraReporte($postEnvioInstitucion['convenio_formalizado_at'])) ?>.
                            <?php elseif ($resultadoReunionInstitucion === 'AVANZAR_CONVENIO'): ?>
                                La relación está lista para continuar con la formalización.
                            <?php else: ?>
                                La formalización depende del avance y resultado de la reunión.
                            <?php endif; ?>
                        </small>
                    </div>
                </section>
            </div>
        </div>

        <?php if (!empty($observacionesInstitucion)): ?>
            <section class="dashboard-panel analyst-institution-observations mb-3" aria-labelledby="observaciones-institucion-reporte">
                <div class="analyst-institution-section-heading">
                    <div>
                        <span class="report-eyebrow">OBSERVACIONES INTERNAS</span>
                        <h3 class="panel-title mb-1" id="observaciones-institucion-reporte">Notas recientes de seguimiento</h3>
                    </div>
                </div>
                <div class="analyst-institution-observation-list">
                    <?php foreach (array_slice($observacionesInstitucion, 0, 3) as $observacion): ?>
                        <article>
                            <div>
                                <strong><?= $texto(trim((string)($observacion['nombre'] ?? '') . ' ' . (string)($observacion['apellidos'] ?? '')) ?: 'Equipo de seguimiento') ?></strong>
                                <span><?= $texto($fechaHoraReporte($observacion['created_at'] ?? '')) ?></span>
                            </div>
                            <p><?= $texto($observacion['observacion'] ?? 'Sin detalle adicional.') ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php elseif ($modoSeguimiento === 'analista' && $tipoReporteActual === 'cartera'): ?>
        <section class="analyst-portfolio-kpis mb-3" aria-label="Panorama de la cartera">
            <article class="analyst-portfolio-kpi">
                <span class="analyst-portfolio-kpi-icon"><i class="bi bi-kanban"></i></span>
                <div>
                    <strong><?= (int)($resumenReporte['total'] ?? 0) ?></strong>
                    <span>Seguimientos en cartera</span>
                    <small>Instituciones incluidas en la consulta</small>
                </div>
            </article>
            <article class="analyst-portfolio-kpi">
                <span class="analyst-portfolio-kpi-icon"><i class="bi bi-arrow-repeat"></i></span>
                <div>
                    <strong><?= (int)($resumenReporte['en_gestion'] ?? 0) ?></strong>
                    <span>En gestión</span>
                    <small>Seguimientos que aún requieren trabajo</small>
                </div>
            </article>
            <article class="analyst-portfolio-kpi analyst-portfolio-kpi--attention">
                <span class="analyst-portfolio-kpi-icon"><i class="bi bi-exclamation-circle"></i></span>
                <div>
                    <strong><?= (int)($resumenReporte['requieren_atencion'] ?? 0) ?></strong>
                    <span>Requieren atención</span>
                    <small>Vencidos, sin actividad o con inactividad prolongada</small>
                </div>
            </article>
            <article class="analyst-portfolio-kpi analyst-portfolio-kpi--success">
                <span class="analyst-portfolio-kpi-icon"><i class="bi bi-patch-check"></i></span>
                <div>
                    <strong><?= (int)($resumenReporte['formalizados'] ?? 0) ?></strong>
                    <span>Convenios formalizados</span>
                    <small>Ruta del Analista concluida</small>
                </div>
            </article>
            <article class="analyst-portfolio-kpi analyst-portfolio-kpi--muted">
                <span class="analyst-portfolio-kpi-icon"><i class="bi bi-slash-circle"></i></span>
                <div>
                    <strong><?= (int)($resumenReporte['descartados'] ?? 0) ?></strong>
                    <span>Descartados</span>
                    <small>Seguimientos cerrados sin continuidad</small>
                </div>
            </article>
        </section>

        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-portfolio-attention">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">ATENCIÓN OPERATIVA</span>
                            <h3 class="panel-title mb-1">Seguimientos que conviene revisar</h3>
                            <p class="page-subtitle mb-0">Prioriza acciones vencidas, instituciones nunca trabajadas y seguimientos con más de 7 días sin interacción humana.</p>
                        </div>
                        <span class="analyst-portfolio-attention-count">
                            <?= (int)($resumenReporte['requieren_atencion'] ?? 0) ?> por revisar
                        </span>
                    </div>

                    <?php if (!empty($resumenReporte['prioritarios'])): ?>
                        <div class="analyst-portfolio-priority-list">
                            <?php foreach ($resumenReporte['prioritarios'] as $prioritario): ?>
                                <?php
                                $diasPrioritario = $prioritario['dias_sin_actividad'] ?? null;
                                $codigoAtencion = (string)($prioritario['atencion_codigo'] ?? 'EN_SEGUIMIENTO');
                                ?>
                                <article>
                                    <div class="analyst-portfolio-priority-main">
                                        <strong><?= $texto($prioritario['nombre_entidad'] ?? 'Institución') ?></strong>
                                        <span>
                                            <?= $texto(trim((string)($prioritario['municipio'] ?? '')) !== ''
                                                ? $prioritario['municipio']
                                                : ($prioritario['estado_nombre'] ?? 'Ubicación no disponible')) ?>
                                            · <?= $texto($prioritario['etapa_operativa_label'] ?? 'Sin etapa') ?>
                                        </span>
                                    </div>
                                    <span class="analyst-portfolio-priority-status is-<?= strtolower($texto($codigoAtencion)) ?>">
                                        <?= $texto($prioritario['atencion_label'] ?? 'En seguimiento') ?>
                                    </span>
                                    <div class="analyst-portfolio-priority-meta">
                                        <span>
                                            <i class="bi bi-clock-history"></i>
                                            <?= $diasPrioritario === null
                                                ? 'Sin actividad humana registrada'
                                                : ((int)$diasPrioritario . ' días sin actividad') ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-arrow-right-circle"></i>
                                            <?= $texto(trim((string)($prioritario['proxima_accion_label'] ?? '')) !== ''
                                                ? $prioritario['proxima_accion_label']
                                                : 'Sin acción programada') ?>
                                        </span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="analyst-portfolio-empty">
                            <i class="bi bi-check-circle"></i>
                            <div>
                                <strong>No hay seguimientos con atención prioritaria.</strong>
                                <span>La cartera filtrada no presenta acciones vencidas ni inactividad mayor a 7 días.</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-portfolio-health">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">SALUD DE CARTERA</span>
                            <h3 class="panel-title mb-1">Estado operativo</h3>
                            <p class="page-subtitle mb-0">Señales que ayudan a distinguir seguimiento activo de cartera detenida.</p>
                        </div>
                    </div>
                    <div class="analyst-portfolio-health-grid">
                        <div>
                            <span>Acciones vencidas</span>
                            <strong><?= (int)($resumenReporte['acciones_vencidas'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Sin actividad registrada</span>
                            <strong><?= (int)($resumenReporte['sin_actividad'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Más de 7 días inactivos</span>
                            <strong><?= (int)($resumenReporte['mas_7_dias'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>En seguimiento normal</span>
                            <strong><?= max(
                                0,
                                (int)($resumenReporte['en_gestion'] ?? 0) -
                                (int)($resumenReporte['requieren_atencion'] ?? 0)
                            ) ?></strong>
                        </div>
                    </div>
                    <div class="analyst-portfolio-health-note">
                        <i class="bi bi-info-circle"></i>
                        <span>“Más de 7 días” solo contempla instituciones que sí tuvieron actividad humana previamente; las nunca trabajadas se muestran aparte.</span>
                    </div>
                </section>
            </div>
        </div>
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
                <div class="metric-icon metric-icon-muted"><i class="bi bi-clock-history"></i></div>
                <div>
                    <p class="metric-value"><?= (int)$resumenReporte['sin_actividad'] ?></p>
                    <p class="metric-label">Sin actividad registrada</p>
                </div>
            </article>
            <article class="metric-card linkage-summary-card">
                <div class="metric-icon metric-icon-muted"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <p class="metric-value"><?= (int)$resumenReporte['mas_7_dias'] ?></p>
                    <p class="metric-label">Más de 7 días sin actividad</p>
                </div>
            </article>
            <?php foreach ($resumenReporte['por_estatus'] as $codigo => $totalEstatus): ?>
                <article class="metric-card linkage-summary-card">
                    <div class="metric-icon"><i class="bi bi-record-circle"></i></div>
                    <div>
                        <p class="metric-value"><?= (int)$totalEstatus ?></p>
                        <p class="metric-label"><?= $texto($etiquetaEstatus($codigo)) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'cartera'): ?>
        <?php
        $maxEtapaCartera = !empty($resumenReporte['por_etapa'])
            ? max(1, max($resumenReporte['por_etapa']))
            : 1;
        $municipiosCartera = array_slice($resumenReporte['por_municipio'] ?? [], 0, 8, true);
        $maxMunicipioCartera = !empty($municipiosCartera)
            ? max(1, max($municipiosCartera))
            : 1;
        ?>
        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-portfolio-stage">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">AVANCE DE LA CARTERA</span>
                            <h3 class="panel-title mb-1">Distribución por etapa actual</h3>
                            <p class="page-subtitle mb-0">La etapa se obtiene del avance operativo real de la ruta, no únicamente del estatus técnico almacenado.</p>
                        </div>
                    </div>

                    <?php if (!empty($resumenReporte['por_etapa'])): ?>
                        <div class="analyst-portfolio-stage-list">
                            <?php foreach ($resumenReporte['por_etapa'] as $codigoEtapa => $totalEtapa): ?>
                                <?php
                                $porcentajeEtapa = ((int)$totalEtapa / max(1, (int)$resumenReporte['total'])) * 100;
                                $etiquetaEtapa = $estadosSeguimiento[$codigoEtapa]
                                    ?? [
                                        'DATOS_CONTACTO' => 'Datos de contacto',
                                        'OFICIO_INSTITUCIONAL' => 'Oficio institucional',
                                        'RESPUESTA_INSTITUCION' => 'Respuesta de la institución',
                                        'REUNION' => 'Reunión',
                                        'CONVENIO_FORMALIZACION' => 'Convenio / formalización',
                                        'DESCARTADO' => 'Descartado'
                                    ][$codigoEtapa]
                                    ?? $codigoEtapa;
                                ?>
                                <div class="analyst-portfolio-stage-item">
                                    <div>
                                        <strong><?= $texto($etiquetaEtapa) ?></strong>
                                        <span><?= (int)$totalEtapa ?> seguimiento<?= (int)$totalEtapa === 1 ? '' : 's' ?></span>
                                    </div>
                                    <div class="analyst-portfolio-stage-progress">
                                        <span style="width: <?= number_format($porcentajeEtapa, 1, '.', '') ?>%"></span>
                                    </div>
                                    <b><?= number_format($porcentajeEtapa, 0) ?>%</b>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="analyst-portfolio-empty">
                            <i class="bi bi-kanban"></i>
                            <div><strong>No hay etapas para mostrar.</strong><span>Modifica los filtros para ampliar la consulta.</span></div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-portfolio-territory">
                    <div class="analyst-portfolio-section-heading">
                        <div>
                            <span class="report-eyebrow">COBERTURA TERRITORIAL</span>
                            <h3 class="panel-title mb-1">Seguimientos por municipio</h3>
                            <p class="page-subtitle mb-0">Municipios donde se concentra la cartera incluida en el reporte.</p>
                        </div>
                    </div>

                    <?php if (!empty($municipiosCartera)): ?>
                        <div class="analyst-portfolio-territory-list">
                            <?php foreach ($municipiosCartera as $municipioNombre => $totalMunicipio): ?>
                                <?php $porcentajeMunicipio = ((int)$totalMunicipio / max(1, (int)$resumenReporte['total'])) * 100; ?>
                                <div>
                                    <div class="analyst-portfolio-territory-copy">
                                        <strong><?= $texto($municipioNombre) ?></strong>
                                        <span><?= (int)$totalMunicipio ?> · <?= number_format($porcentajeMunicipio, 0) ?>%</span>
                                    </div>
                                    <div class="analyst-portfolio-territory-progress">
                                        <span style="width: <?= number_format($porcentajeMunicipio, 1, '.', '') ?>%"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="analyst-portfolio-empty">
                            <i class="bi bi-geo-alt"></i>
                            <div><strong>No hay distribución municipal disponible.</strong><span>Los seguimientos filtrados no tienen municipio registrado.</span></div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php elseif ($modoSeguimiento !== 'analista'): ?>
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
                                        <div class="progress-bar" style="width: <?= number_format($porcentaje, 2, '.', '') ?>%"></div>
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
                                        <div class="progress-bar" style="width: <?= number_format($porcentaje, 2, '.', '') ?>%"></div>
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

        <?php if ($modoSeguimiento !== 'analista'): ?>
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
                                <p class="metric-label">
                                    <?= (int)($evolucionActividad['total'] ?? 0) ?> vs.
                                    <?= (int)($evolucionActividad['total_anterior'] ?? 0) ?> ·
                                    <?= $texto($comparacionActividadRango) ?>
                                </p>
                            <?php elseif (!empty($evolucionActividad['comparacion_periodo_disponible'])): ?>
                                <p class="metric-value" style="font-size: 16px; line-height: 1.2;">0 anteriores</p>
                                <p class="metric-label"><?= $texto($comparacionActividadRango) ?> · sin % calculable</p>
                            <?php else: ?>
                                <p class="metric-value" style="font-size: 16px; line-height: 1.2;">Sin comparación disponible</p>
                                <p class="metric-label"><?= $texto($evolucionActividad['comparacion_motivo'] ?? 'Sin periodo comparable') ?></p>
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
        <?php
        $periodosActividadAnalista = is_array($evolucionActividad['periodos'] ?? null)
            ? $evolucionActividad['periodos']
            : [];
        ?>
        <div class="row g-3 mb-3">
            <div class="col-xl-7">
                <section class="dashboard-panel analyst-activity-evolution" aria-labelledby="evolucion-actividad-analista">
                    <div class="analyst-activity-section-heading">
                        <div>
                            <span class="report-eyebrow">EVOLUCIÓN DEL PERIODO</span>
                            <h3 class="panel-title mb-1" id="evolucion-actividad-analista">Actividad por <?= $texto($etiquetaGranularidadEvolucion) ?></h3>
                            <p class="page-subtitle mb-0">Volumen de interacciones realizadas por el Analista durante el periodo seleccionado.</p>
                        </div>
                        <?php if (!empty($evolucionActividad['comparacion_periodo_disponible'])): ?>
                            <?php
                            $variacionActividadAnalista = (float)($evolucionActividad['variacion'] ?? 0);
                            $comparacionCalculable = !empty($evolucionActividad['comparacion_disponible']);
                            ?>
                            <span class="analyst-activity-variation<?= $comparacionCalculable && $variacionActividadAnalista < 0 ? ' is-negative' : (!$comparacionCalculable ? ' is-neutral' : '') ?>">
                                <?php if ($comparacionCalculable): ?>
                                    <?= $variacionActividadAnalista > 0 ? '+' : '' ?><?= number_format($variacionActividadAnalista, 1) ?>%
                                <?php else: ?>
                                    Sin %
                                <?php endif; ?>
                                <small><?= (int)($evolucionActividad['total_anterior'] ?? 0) ?> actividades anteriores</small>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="analyst-activity-evolution-summary">
                        <div>
                            <span>Actividades</span>
                            <strong><?= (int)($evolucionActividad['total'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Mayor actividad</span>
                            <strong><?= $texto($evolucionActividad['mayor']['etiqueta'] ?? '—') ?></strong>
                            <small><?= (int)($evolucionActividad['mayor']['total'] ?? 0) ?> actividades</small>
                        </div>
                        <div>
                            <span>Menor actividad</span>
                            <strong><?= $texto($evolucionActividad['menor']['etiqueta'] ?? '—') ?></strong>
                            <small><?= (int)($evolucionActividad['menor']['total'] ?? 0) ?> actividades</small>
                        </div>
                        <div>
                            <span>Comparación</span>
                            <?php if (!empty($evolucionActividad['comparacion_periodo_disponible'])): ?>
                                <?php if (!empty($evolucionActividad['comparacion_disponible'])): ?>
                                    <?php $variacionResumen = (float)($evolucionActividad['variacion'] ?? 0); ?>
                                    <strong><?= $variacionResumen > 0 ? '+' : '' ?><?= number_format($variacionResumen, 1) ?>%</strong>
                                    <small>
                                        <?= (int)($evolucionActividad['total'] ?? 0) ?> vs.
                                        <?= (int)($evolucionActividad['total_anterior'] ?? 0) ?> ·
                                        <?= $texto($comparacionActividadRango) ?>
                                    </small>
                                <?php else: ?>
                                    <strong>0 actividades anteriores</strong>
                                    <small><?= $texto($comparacionActividadRango) ?> · sin % calculable</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <strong>—</strong>
                                <small><?= $texto($evolucionActividad['comparacion_motivo'] ?? 'Sin periodo comparable') ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($periodosActividadAnalista)): ?>
                        <?php
                        $svgAnchoActividad = 760;
                        $svgAltoActividad = 220;
                        $margenIzquierdoActividad = 42;
                        $margenDerechoActividad = 42;
                        $margenSuperiorActividad = 18;
                        $margenInferiorActividad = 42;
                        $anchoAreaActividad = $svgAnchoActividad - $margenIzquierdoActividad - $margenDerechoActividad;
                        $altoAreaActividad = $svgAltoActividad - $margenSuperiorActividad - $margenInferiorActividad;
                        $maxActividadAnalista = max(1, max(array_map(static function ($periodo) {
                            return (int)($periodo['total'] ?? 0);
                        }, $periodosActividadAnalista)));
                        $cantidadPeriodosActividad = count($periodosActividadAnalista);
                        $pasoXActividad = $cantidadPeriodosActividad > 1
                            ? $anchoAreaActividad / ($cantidadPeriodosActividad - 1)
                            : 0;
                        $puntosLineaActividad = [];
                        $puntosActividad = [];

                        foreach ($periodosActividadAnalista as $indicePeriodo => $periodoActividad) {
                            $x = $cantidadPeriodosActividad > 1
                                ? $margenIzquierdoActividad + ($pasoXActividad * $indicePeriodo)
                                : $margenIzquierdoActividad + ($anchoAreaActividad / 2);
                            $totalPeriodo = (int)($periodoActividad['total'] ?? 0);
                            $y = $margenSuperiorActividad +
                                $altoAreaActividad -
                                (($totalPeriodo / $maxActividadAnalista) * $altoAreaActividad);
                            $puntosLineaActividad[] =
                                number_format($x, 2, '.', '') . ',' .
                                number_format($y, 2, '.', '');
                            $puntosActividad[] = [
                                'x' => $x,
                                'y' => $y,
                                'total' => $totalPeriodo,
                                'etiqueta' => (string)($periodoActividad['etiqueta'] ?? ''),
                                'tooltip' => (string)($periodoActividad['tooltip'] ?? ''),
                                'anchor' => $indicePeriodo === 0
                                    ? 'start'
                                    : ($indicePeriodo === $cantidadPeriodosActividad - 1 ? 'end' : 'middle')
                            ];
                        }
                        $saltoEtiquetasActividad = max(1, (int)ceil($cantidadPeriodosActividad / 6));
                        ?>
                        <div class="analyst-activity-chart">
                            <svg viewBox="0 0 <?= $svgAnchoActividad ?> <?= $svgAltoActividad ?>" width="100%" role="img" aria-label="Evolución de actividad">
                                <?php for ($nivel = 0; $nivel <= 3; $nivel++): ?>
                                    <?php
                                    $valorNivel = (int)round($maxActividadAnalista * (1 - ($nivel / 3)));
                                    $yNivel = $margenSuperiorActividad + (($altoAreaActividad / 3) * $nivel);
                                    ?>
                                    <line
                                        x1="<?= $margenIzquierdoActividad ?>"
                                        y1="<?= number_format($yNivel, 2, '.', '') ?>"
                                        x2="<?= $svgAnchoActividad - $margenDerechoActividad ?>"
                                        y2="<?= number_format($yNivel, 2, '.', '') ?>"
                                        class="analyst-activity-chart-grid" />
                                    <text
                                        x="<?= $margenIzquierdoActividad - 8 ?>"
                                        y="<?= number_format($yNivel + 4, 2, '.', '') ?>"
                                        text-anchor="end"
                                        class="analyst-activity-chart-axis">
                                        <?= $valorNivel ?>
                                    </text>
                                <?php endfor; ?>
                                <polyline
                                    points="<?= $texto(implode(' ', $puntosLineaActividad)) ?>"
                                    fill="none"
                                    class="analyst-activity-chart-line" />
                                <?php foreach ($puntosActividad as $indicePunto => $punto): ?>
                                    <circle
                                        cx="<?= number_format($punto['x'], 2, '.', '') ?>"
                                        cy="<?= number_format($punto['y'], 2, '.', '') ?>"
                                        r="4.5"
                                        class="analyst-activity-chart-point">
                                        <title><?= $texto($punto['tooltip']) ?> · <?= (int)$punto['total'] ?> actividades</title>
                                    </circle>
                                    <?php if ($indicePunto % $saltoEtiquetasActividad === 0 || $indicePunto === $cantidadPeriodosActividad - 1): ?>
                                        <text
                                            x="<?= number_format($punto['x'], 2, '.', '') ?>"
                                            y="<?= $svgAltoActividad - 14 ?>"
                                            text-anchor="<?= $texto($punto['anchor'] ?? 'middle') ?>"
                                            class="analyst-activity-chart-axis">
                                            <?= $texto($punto['etiqueta']) ?>
                                        </text>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </svg>
                        </div>
                    <?php else: ?>
                        <div class="analyst-activity-empty">
                            <i class="bi bi-activity"></i>
                            <span>No hay actividad registrada durante el periodo seleccionado.</span>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="dashboard-panel analyst-activity-institutions" aria-labelledby="instituciones-actividad-analista">
                    <div class="analyst-activity-section-heading">
                        <div>
                            <span class="report-eyebrow">COBERTURA DE TRABAJO</span>
                            <h3 class="panel-title mb-1" id="instituciones-actividad-analista">Instituciones con mayor actividad</h3>
                            <p class="page-subtitle mb-0">
                                <?php if ($totalInstitucionesActividadReporte > count($institucionesActividadReporte)): ?>
                                    Principales <?= count($institucionesActividadReporte) ?> de <?= $totalInstitucionesActividadReporte ?> instituciones trabajadas en el periodo.
                                <?php else: ?>
                                    Instituciones con actividad registrada durante el periodo.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($institucionesActividadReporte)): ?>
                        <div class="analyst-activity-institution-list">
                            <?php foreach ($institucionesActividadReporte as $institucionActividad): ?>
                                <article>
                                    <div class="analyst-activity-institution-main">
                                        <strong><?= $texto($institucionActividad['nombre_entidad'] ?? 'Institución') ?></strong>
                                        <span><?= $texto(trim((string)($institucionActividad['municipio'] ?? '')) !== '' ? $institucionActividad['municipio'] : 'Ubicación no disponible') ?></span>
                                    </div>
                                    <div class="analyst-activity-institution-total">
                                        <strong><?= (int)($institucionActividad['interacciones'] ?? 0) ?></strong>
                                        <span>interacciones</span>
                                    </div>
                                    <div class="analyst-activity-institution-detail">
                                        <span><b><?= (int)($institucionActividad['llamadas'] ?? 0) ?></b> llamadas</span>
                                        <span><b><?= (int)($institucionActividad['con_contacto'] ?? 0) ?></b> contacto</span>
                                        <?php if ((int)($institucionActividad['efectivas'] ?? 0) > 0): ?>
                                            <span class="is-effective"><b><?= (int)$institucionActividad['efectivas'] ?></b> efectivas contabilizadas</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="analyst-activity-empty">
                            <i class="bi bi-buildings"></i>
                            <span>No hay instituciones con actividad dentro del periodo.</span>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($modoSeguimiento === 'analista' && $tipoReporteActual === 'actividad'): ?>
        <section class="dashboard-panel p-0 overflow-hidden analyst-activity-history">
            <div class="table-panel-header">
                <div>
                    <span class="report-eyebrow">DETALLE DE ACTIVIDAD</span>
                    <h3 class="panel-title mb-0">Interacciones del periodo</h3>
                    <p class="page-subtitle mb-0 mt-1">Hasta 60 registros, de la actividad más reciente a la más antigua.</p>
                </div>
                <span class="analyst-activity-history-count">
                    <?= count($actividadRecienteReporte) ?> mostradas
                </span>
            </div>
            <?php if (!empty($actividadRecienteReporte)): ?>
                <div class="table-responsive">
                    <table class="table users-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Institución</th>
                                <th>Interacción</th>
                                <th>Resultado</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($actividadRecienteReporte as $actividad): ?>
                            <?php
                            $canalActividad = strtoupper(trim((string)($actividad['canal'] ?? '')));
                            $resultadoActividad = strtoupper(trim((string)($actividad['resultado'] ?? '')));
                            $notasActividad = (string)($actividad['notas'] ?? '');
                            $duracionActividad = max(0, (int)($actividad['duracion_segundos'] ?? 0));
                            $esLlamadaActividad = in_array($canalActividad, ['LLAMADA', 'LLAMADA_IP'], true);
                            $esEfectivaActividad =
                                $esLlamadaActividad &&
                                strpos($notasActividad, '[VERIFICACION_EFECTIVA]') !== false &&
                                trim((string)($actividad['proveedor_externo'] ?? '')) !== '' &&
                                trim((string)($actividad['id_externo'] ?? '')) !== '' &&
                                $duracionActividad > 0;
                            $contactoActividad =
                                $esLlamadaActividad &&
                                strpos($notasActividad, '[SIN_CONTACTO_EFECTIVO]') === false &&
                                (
                                    strpos($notasActividad, '[CONTACTO_EFECTIVO]') !== false ||
                                    in_array(
                                        $resultadoActividad,
                                        [
                                            'CONTACTADO',
                                            'CONTACTO_CORRECTO',
                                            'CONTACTO_REFERIDO',
                                            'SOLICITO_INFORMACION',
                                            'SOLICITO_LLAMAR_DESPUES',
                                            'NO_INTERESADO'
                                        ],
                                        true
                                    )
                                );
                            $iconoCanalActividad = [
                                'LLAMADA' => 'bi-telephone',
                                'LLAMADA_IP' => 'bi-telephone',
                                'CORREO' => 'bi-envelope',
                                'WHATSAPP' => 'bi-chat-dots',
                                'NOTA' => 'bi-journal-text'
                            ][$canalActividad] ?? 'bi-activity';

                            $resultadoLabelActividad = $etiquetaResultadoReporte($resultadoActividad);
                            if ($esEfectivaActividad) {
                                $resultadoLabelActividad = 'Verificación válida';
                            } elseif ($contactoActividad) {
                                $resultadoLabelActividad = 'Con contacto';
                            } elseif ($resultadoActividad === 'BUZON_VOZ') {
                                $resultadoLabelActividad = 'Buzón de voz';
                            } elseif ($resultadoActividad === 'FUERA_SERVICIO') {
                                $resultadoLabelActividad = 'Fuera de servicio';
                            }

                            $detalleActividad = '';
                            if ($esLlamadaActividad) {
                                $partesDetalle = [];
                                if ($duracionActividad > 0) {
                                    $minutos = intdiv($duracionActividad, 60);
                                    $segundos = $duracionActividad % 60;
                                    $partesDetalle[] = $minutos > 0
                                        ? $minutos . ' min ' . str_pad((string)$segundos, 2, '0', STR_PAD_LEFT) . ' s'
                                        : $segundos . ' s';
                                }
                                if ($esEfectivaActividad) {
                                    $partesDetalle[] = 'evidencia válida; la efectiva se contabiliza una vez por institución y día';
                                } elseif ($contactoActividad) {
                                    $partesDetalle[] = 'contacto registrado';
                                } else {
                                    $partesDetalle[] = 'intento telefónico';
                                }
                                $detalleActividad = implode(' · ', $partesDetalle);
                            } elseif ($canalActividad === 'CORREO') {
                                $detalleActividad = 'Correo registrado en el seguimiento';
                            } elseif ($canalActividad === 'WHATSAPP') {
                                $detalleActividad = 'Interacción por WhatsApp';
                            } else {
                                $detalleActividad = 'Actividad registrada';
                            }
                            ?>
                            <tr>
                                <td class="analyst-activity-history-date">
                                    <?= $texto($fechaHoraReporte($actividad['fecha_inicio'] ?? '')) ?>
                                </td>
                                <td>
                                    <strong class="analyst-activity-history-institution"><?= $texto($actividad['nombre_entidad'] ?? '—') ?></strong>
                                </td>
                                <td>
                                    <span class="analyst-activity-channel">
                                        <i class="bi <?= $texto($iconoCanalActividad) ?>"></i>
                                        <?= $texto($etiquetaCanalReporte($canalActividad)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="analyst-activity-result<?= $esEfectivaActividad ? ' is-effective' : ($contactoActividad ? ' is-contact' : '') ?>">
                                        <?= $texto($resultadoLabelActividad) ?>
                                    </span>
                                </td>
                                <td class="analyst-activity-history-detail"><?= $texto($detalleActividad) ?></td>
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
                                $esLlamadaActividad = strcasecmp($tituloActividad, 'Llamada') === 0;
                                $notasActividad = (string)($actividad['notas'] ?? '');
                                $resultadoCodigoActividad = strtoupper(trim((string)($actividad['resultado'] ?? '')));
                                $duracionActividad = max(0, (int)($actividad['duracion_segundos'] ?? 0));
                                $telefonoActividad = trim((string)($actividad['telefono_destino'] ?? ''));
                                $detallesActividad = is_array($presentacionActividad['detalles'] ?? null)
                                    ? $presentacionActividad['detalles']
                                    : [];

                                if ($esLlamadaActividad) {
                                    $contactoEfectivo = strpos($notasActividad, '[CONTACTO_EFECTIVO]') !== false
                                        && strpos($notasActividad, '[SIN_CONTACTO_EFECTIVO]') === false;
                                    $sinContacto = strpos($notasActividad, '[SIN_CONTACTO_EFECTIVO]') !== false;
                                    $buzonVoz = $resultadoCodigoActividad === 'BUZON_VOZ'
                                        || strpos($notasActividad, '[BUZON_VOZ]') !== false;
                                    $fueraServicio = $resultadoCodigoActividad === 'FUERA_SERVICIO'
                                        || strpos($notasActividad, '[FUERA_SERVICIO]') !== false;

                                    $personaActividad = '';
                                    foreach ($detallesActividad as $detalleActividad) {
                                        if (!is_array($detalleActividad)) {
                                            continue;
                                        }
                                        $etiquetaDetalle = mb_strtolower(trim((string)($detalleActividad['etiqueta'] ?? '')), 'UTF-8');
                                        if (in_array($etiquetaDetalle, ['persona atendió', 'contacto'], true)) {
                                            $personaActividad = trim((string)($detalleActividad['valor'] ?? ''));
                                            if ($personaActividad !== '') {
                                                break;
                                            }
                                        }
                                    }

                                    if ($contactoEfectivo) {
                                        $resultadoActividad = 'Contacto efectivo';
                                    } elseif ($sinContacto) {
                                        $resultadoActividad = 'Sin contacto';
                                    } elseif ($buzonVoz) {
                                        $resultadoActividad = 'Buzón de voz';
                                    } elseif ($fueraServicio) {
                                        $resultadoActividad = 'Fuera de servicio';
                                    } elseif ($personaActividad !== '') {
                                        $resultadoActividad = 'Contacto registrado';
                                    } elseif (
                                        $resultadoActividad === '' ||
                                        strcasecmp($resultadoActividad, 'Otro') === 0
                                    ) {
                                        $resultadoActividad = 'Resultado no clasificado';
                                    }

                                    if (
                                        $resumenActividad === '' ||
                                        strcasecmp($resumenActividad, 'Otro') === 0 ||
                                        strcasecmp($resumenActividad, 'Llamada') === 0
                                    ) {
                                        $partesResumenLlamada = [];

                                        if ($personaActividad !== '') {
                                            $partesResumenLlamada[] = 'Atendió ' . $personaActividad;
                                        } elseif ($contactoEfectivo) {
                                            $partesResumenLlamada[] = 'Se logró contacto';
                                        } elseif ($sinContacto) {
                                            $partesResumenLlamada[] = 'No se logró contacto';
                                        } elseif ($buzonVoz) {
                                            $partesResumenLlamada[] = 'La llamada llegó a buzón de voz';
                                        } elseif ($fueraServicio) {
                                            $partesResumenLlamada[] = 'La línea se registró fuera de servicio';
                                        } else {
                                            $partesResumenLlamada[] = 'Llamada registrada';
                                        }

                                        if ($telefonoActividad !== '') {
                                            $partesResumenLlamada[] = 'al ' . $telefonoActividad;
                                        }

                                        if ($duracionActividad > 0) {
                                            $minutosDuracion = intdiv($duracionActividad, 60);
                                            $segundosDuracion = $duracionActividad % 60;
                                            $partesResumenLlamada[] = 'duración ' .
                                                ($minutosDuracion > 0
                                                    ? $minutosDuracion . ' min ' . str_pad((string)$segundosDuracion, 2, '0', STR_PAD_LEFT) . ' s'
                                                    : $segundosDuracion . ' s');
                                        }

                                        $resumenActividad = implode(' · ', $partesResumenLlamada);
                                    }
                                }
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
