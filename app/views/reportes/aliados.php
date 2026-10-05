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

$maxMunicipios = 10;
$municipiosVisibles = array_slice($porMunicipio, 0, $maxMunicipios);
$maxAliadosMunicipio = 1;
foreach ($municipiosVisibles as $filaMunicipio) {
    $maxAliadosMunicipio = max(
        $maxAliadosMunicipio,
        (int)($filaMunicipio['aliados'] ?? 0)
    );
}
?>

<section class="report-module aliados-report-module">
    <div class="aliados-report-toolbar">
        <a
            class="linkage-back-link territorial-back-link"
            href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver a Reportes
        </a>

        <?php if ($generarReporte): ?>
            <span class="aliados-report-generated">
                <i class="bi bi-check2-circle"></i>
                Reporte actualizado
            </span>
        <?php endif; ?>
    </div>

    <section class="dashboard-panel report-intro-panel mb-4">
        <div>
            <span class="report-eyebrow">ALIADOS · PANORAMA EJECUTIVO</span>
            <h2 class="panel-title mb-1">Red institucional y atención operativa</h2>
            <p class="page-subtitle mb-0">
                Analiza cobertura, difusión de convocatorias, estado del seguimiento
                y aliados que requieren atención dentro de tus territorios autorizados.
            </p>
        </div>

        <span class="metric-icon report-intro-icon" aria-hidden="true">
            <i class="bi bi-diagram-3"></i>
        </span>
    </section>

    <section class="dashboard-panel aliados-report-filter-panel mb-4">
        <div class="aliados-report-filter-heading">
            <div>
                <span class="report-card-kicker">ALCANCE DEL REPORTE</span>
                <h3>Selecciona la red que deseas analizar</h3>
                <p>
                    El reporte respeta automáticamente los territorios y aliados
                    autorizados para tu usuario.
                </p>
            </div>
            <i class="bi bi-sliders"></i>
        </div>

        <form method="GET" class="aliados-report-filter-form">
            <input type="hidden" name="controller" value="aliadoReporte">
            <input type="hidden" name="action" value="index">
            <input type="hidden" name="generar" value="1">

            <div>
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

            <div>
                <label class="form-label" for="reporte_aliados_municipio">Municipio</label>
                <select
                    class="form-select"
                    id="reporte_aliados_municipio"
                    name="municipio_id">
                    <option value="0">Todos</option>
                    <?php foreach ($municipios as $municipio): ?>
                        <option
                            value="<?= (int)($municipio['id'] ?? 0) ?>"
                            <?= (int)($filtrosReporte['municipio_id'] ?? 0) === (int)($municipio['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= (int)($filtrosReporte['estado_id'] ?? 0) > 0
                                ? $texto($municipio['nombre'] ?? '')
                                : $texto(
                                    ($municipio['nombre'] ?? '') .
                                    ' · ' .
                                    ($municipio['estado_nombre'] ?? '')
                                ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
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
        </form>
    </section>

    <?php if ($generarReporte): ?>
        <section class="dashboard-panel aliados-report-context mb-4">
            <div class="aliados-report-context-heading">
                <div>
                    <span class="report-card-kicker">CONTEXTO DEL REPORTE</span>
                    <h3>Red analizada</h3>
                </div>
                <span>
                    <?= (int)($resumen['total'] ?? 0) ?>
                    <?= (int)($resumen['total'] ?? 0) === 1 ? 'aliado' : 'aliados' ?>
                </span>
            </div>

            <div class="aliados-report-context-grid">
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
                    <span>Generado</span>
                    <strong><?= $texto(date('d/m/Y · H:i')) ?></strong>
                </div>
            </div>
        </section>

        <div class="territorial-section-title">
            <h2>RESUMEN EJECUTIVO</h2>
            <p>Indicadores para dimensionar la red y detectar necesidades de atención.</p>
        </div>

        <section class="metric-grid report-summary-grid aliados-report-kpi-grid mb-4">
            <article class="metric-card">
                <div class="metric-icon">
                    <i class="bi bi-buildings"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)($resumen['total'] ?? 0) ?></p>
                    <p class="metric-label">Aliados analizados</p>
                    <small><?= (int)($resumen['municipios'] ?? 0) ?> municipios</small>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-icon metric-icon-success">
                    <i class="bi bi-megaphone"></i>
                </div>
                <div>
                    <p class="metric-value"><?= $texto(number_format((float)($resumen['cobertura_difusion'] ?? 0), 1)) ?>%</p>
                    <p class="metric-label">Cobertura de difusión</p>
                    <small><?= (int)($resumen['con_difusion'] ?? 0) ?> con difusión</small>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-icon">
                    <i class="bi bi-whatsapp"></i>
                </div>
                <div>
                    <p class="metric-value"><?= $texto(number_format((float)($resumen['cobertura_whatsapp'] ?? 0), 1)) ?>%</p>
                    <p class="metric-label">WhatsApp disponible</p>
                    <small><?= (int)($resumen['con_whatsapp'] ?? 0) ?> aliados</small>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-icon aliados-report-icon-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)($resumen['pendientes'] ?? 0) ?></p>
                    <p class="metric-label">Seguimientos pendientes</p>
                    <small>Acciones todavía abiertas</small>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-icon aliados-report-icon-danger">
                    <i class="bi bi-exclamation-octagon"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)($resumen['vencidos'] ?? 0) ?></p>
                    <p class="metric-label">Seguimientos vencidos</p>
                    <small>Requieren atención prioritaria</small>
                </div>
            </article>

            <article class="metric-card">
                <div class="metric-icon metric-icon-success">
                    <i class="bi bi-patch-check"></i>
                </div>
                <div>
                    <p class="metric-value"><?= (int)($resumen['difusion_confirmada'] ?? 0) ?></p>
                    <p class="metric-label">Difusión confirmada</p>
                    <small><?= $texto(number_format((float)($resumen['tasa_confirmacion'] ?? 0), 1)) ?>% sobre aliados con difusión</small>
                </div>
            </article>
        </section>

        <section class="aliados-report-decision-grid mb-4">
            <article class="dashboard-panel aliados-report-analysis-card">
                <div class="aliados-report-section-heading">
                    <div>
                        <span class="report-card-kicker">LECTURA EJECUTIVA</span>
                        <h3>Hallazgos para toma de decisiones</h3>
                        <p>
                            Síntesis automática del alcance, cobertura y pendientes
                            detectados en la red seleccionada.
                        </p>
                    </div>
                    <i class="bi bi-lightbulb"></i>
                </div>

                <ul class="aliados-report-findings">
                    <?php foreach ($hallazgos as $hallazgo): ?>
                        <li>
                            <i class="bi bi-check2-circle"></i>
                            <span><?= $texto($hallazgo) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>

            <article class="dashboard-panel aliados-report-analysis-card">
                <div class="aliados-report-section-heading">
                    <div>
                        <span class="report-card-kicker">ESTADO DE RESPUESTA</span>
                        <h3>Situación del seguimiento</h3>
                        <p>Distribución actual del último seguimiento de convocatoria.</p>
                    </div>
                    <i class="bi bi-chat-square-text"></i>
                </div>

                <div class="aliados-report-status-grid">
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
                </div>
            </article>
        </section>

        <div class="territorial-section-title">
            <h2>PRIORIDADES DE ATENCIÓN</h2>
            <p>Aliados que requieren una acción concreta antes que el resto de la cartera.</p>
        </div>

        <section class="dashboard-panel aliados-report-table-panel mb-4">
            <div class="table-responsive">
                <table class="table users-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Aliado</th>
                            <th>Territorio</th>
                            <th>Situación</th>
                            <th>Convocatoria</th>
                            <th>Próxima acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($atencion)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-table-message">
                                        No se detectaron aliados que requieran atención prioritaria con estos filtros.
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($atencion as $fila): ?>
                                <tr>
                                    <td>
                                        <strong><?= $texto($fila['institucion'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <?= $texto($fila['municipio'] ?? '') ?>
                                        <small class="d-block text-muted">
                                            <?= $texto($fila['estado'] ?? '') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="aliados-report-priority is-<?= $texto($fila['tipo'] ?? '') ?>">
                                            <?= $texto($fila['etiqueta'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td><?= $texto($fila['convocatoria'] ?: '—') ?></td>
                                    <td>
                                        <span><?= $texto($fila['accion'] ?? '') ?></span>
                                        <?php if (trim((string)($fila['proximo_seguimiento_at'] ?? '')) !== ''): ?>
                                            <small class="d-block text-muted">
                                                <?= $texto($fecha($fila['proximo_seguimiento_at'], true)) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if (count($porEstado) > 1): ?>
            <div class="territorial-section-title">
                <h2>COBERTURA POR ESTADO</h2>
                <p>Comparativo de tamaño de red, difusión y atención pendiente.</p>
            </div>

            <section class="dashboard-panel aliados-report-table-panel mb-4">
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

        <div class="territorial-section-title">
            <h2>CONCENTRACIÓN MUNICIPAL</h2>
            <p>Municipios con mayor número de aliados dentro del alcance seleccionado.</p>
        </div>

        <section class="dashboard-panel aliados-report-analysis-card mb-4">
            <?php if (empty($municipiosVisibles)): ?>
                <div class="empty-table-message">
                    No hay municipios disponibles para el alcance seleccionado.
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
                                    <strong><?= $aliadosMunicipio ?></strong>
                                    <span><?= $texto(number_format((float)($fila['cobertura_difusion'] ?? 0), 1)) ?>% con difusión</span>
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

        <div class="territorial-section-title">
            <h2>DETALLE DE ALIADOS</h2>
            <p>Listado utilizado para construir los indicadores y hallazgos del reporte.</p>
        </div>

        <section class="dashboard-panel aliados-report-table-panel">
            <div class="table-responsive">
                <table class="table users-table align-middle mb-0">
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
