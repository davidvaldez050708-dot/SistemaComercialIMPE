<?php
$periodosReporte = [
    'historico' => 'Histórico completo',
    'ultimos_7' => 'Últimos 7 días',
    'ultimos_30' => 'Últimos 30 días',
    'este_mes' => 'Este mes',
    'mes_anterior' => 'Mes anterior',
    'personalizado' => 'Periodo personalizado'
];

$periodoSeleccionado = (string)(
    $filtrosReporte['periodo'] ?? 'historico'
);
$fechaDesdeSeleccionada = (string)(
    $filtrosReporte['fecha_desde'] ?? ''
);
$fechaHastaSeleccionada = (string)(
    $filtrosReporte['fecha_hasta'] ?? ''
);
?>

<form method="GET" class="aliados-report-filter-form">
    <input type="hidden" name="controller" value="aliadoReporte">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="generar" value="1">

    <div class="report-filter-field">
        <label class="form-label" for="reporte_aliados_estado">Estado</label>
        <select
            class="form-select"
            id="reporte_aliados_estado"
            name="estado_id">
            <option value="0">Todos mis territorios</option>
            <?php foreach ($territorios as $territorio): ?>
                <option
                    value="<?= (int)($territorio['id'] ?? 0) ?>"
                    <?= (int)($filtrosReporte['estado_id'] ?? 0) === (int)($territorio['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= $texto($territorio['nombre'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="report-filter-field">
        <label class="form-label" for="reporte_aliados_municipio">Municipio</label>
        <select
            class="form-select"
            id="reporte_aliados_municipio"
            name="municipio_id">
            <option value="0">Todos</option>
            <?php foreach ($municipios as $municipio): ?>
                <option
                    value="<?= (int)($municipio['id'] ?? 0) ?>"
                    data-estado-id="<?= (int)($municipio['estado_id'] ?? 0) ?>"
                    <?= (int)($filtrosReporte['municipio_id'] ?? 0) === (int)($municipio['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= $texto(
                        ($municipio['nombre'] ?? '') .
                        ' · ' .
                        ($municipio['estado_nombre'] ?? '')
                    ) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="report-filter-field">
        <label class="form-label" for="reporte_aliados_situacion">Situación</label>
        <select
            class="form-select"
            id="reporte_aliados_situacion"
            name="situacion">
            <?php foreach ($situaciones as $valor => $label): ?>
                <option
                    value="<?= $texto($valor) ?>"
                    <?= $situacionSeleccionada === $valor ? 'selected' : '' ?>>
                    <?= $texto($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="report-filter-field">
        <label class="form-label" for="reporte_aliados_periodo">Periodo</label>
        <select
            class="form-select"
            id="reporte_aliados_periodo"
            name="periodo">
            <?php foreach ($periodosReporte as $valor => $label): ?>
                <option
                    value="<?= $texto($valor) ?>"
                    <?= $periodoSeleccionado === $valor ? 'selected' : '' ?>>
                    <?= $texto($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="aliados-report-filter-actions">
        <a
            class="btn btn-system-light"
            href="<?= BASE_URL ?>index.php?controller=aliadoReporte&action=index">
            <i class="bi bi-arrow-counterclockwise"></i>
            Limpiar
        </a>
        <button class="btn btn-system-save" type="submit">
            <i class="bi bi-bar-chart"></i>
            Generar reporte
        </button>
    </div>

    <div
        class="aliados-report-custom-period <?= $periodoSeleccionado === 'personalizado' ? '' : 'd-none' ?>"
        data-aliados-custom-period>
        <div class="report-filter-field">
            <label class="form-label" for="reporte_aliados_fecha_desde">
                Desde
            </label>
            <input
                class="form-control"
                type="date"
                id="reporte_aliados_fecha_desde"
                name="fecha_desde"
                max="<?= $texto(date('Y-m-d')) ?>"
                value="<?= $texto($fechaDesdeSeleccionada) ?>">
        </div>

        <div class="report-filter-field">
            <label class="form-label" for="reporte_aliados_fecha_hasta">
                Hasta
            </label>
            <input
                class="form-control"
                type="date"
                id="reporte_aliados_fecha_hasta"
                name="fecha_hasta"
                max="<?= $texto(date('Y-m-d')) ?>"
                value="<?= $texto($fechaHastaSeleccionada) ?>">
        </div>

        <p>
            El periodo limita la actividad histórica; la red actual y los pendientes
            conservan su estado vigente.
        </p>
    </div>
</form>
