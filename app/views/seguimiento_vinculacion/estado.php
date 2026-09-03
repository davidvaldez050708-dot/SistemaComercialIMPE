<?php

$estado = $estado ?? [];
$resumen = $resumen ?? [
    'en_seguimiento' => 0,
    'contactando' => 0,
    'datos_verificados' => 0,
    'esperando_respuesta' => 0
];
$seguimientos = $seguimientos ?? [];
$analistasFiltro = $analistasFiltro ?? [];
$municipiosCandidatos = $municipiosCandidatos ?? [];
$filtrosSeguimiento = $filtrosSeguimiento ?? [];
$modoSeguimiento = $modoSeguimiento ?? 'analista';
$mostrarColumnaAnalista = in_array($modoSeguimiento, ['supervisor', 'administrador'], true);
$puedeCrearSeguimiento = $puedeCrearSeguimiento ?? false;
$totalSeguimientosReales = (int)($totalSeguimientosReales ?? count($seguimientos));
$totalResultadosFiltrados = (int)($totalResultadosFiltrados ?? count($seguimientos));

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$formatearFecha = function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    try {
        $meses = [
            'Jan' => 'ene',
            'Feb' => 'feb',
            'Mar' => 'mar',
            'Apr' => 'abr',
            'May' => 'may',
            'Jun' => 'jun',
            'Jul' => 'jul',
            'Aug' => 'ago',
            'Sep' => 'sep',
            'Oct' => 'oct',
            'Nov' => 'nov',
            'Dec' => 'dic'
        ];
        $fechaObjeto = new DateTime($fecha);
        $formato = $fechaObjeto->format('d M Y · H:i');

        return strtr($formato, $meses);
    } catch (Exception $error) {
        return '—';
    }
};

$etiquetaEstado = function ($estadoSeguimiento) {
    $estadoSeguimiento = (string)$estadoSeguimiento;
    $etiquetas = [
        'NUEVO' => 'Nuevo',
        'CONTACTANDO' => 'Contactando',
        'DATOS_VERIFICADOS' => 'Datos verificados',
        'NO_LOCALIZADO' => 'No localizado',
        'DESCARTADO' => 'Descartado',
        'OFICIO_PREPARADO' => 'Oficio preparado',
        'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
    ];

    return $etiquetas[$estadoSeguimiento] ?? 'Sin estado';
};

$etiquetaTipo = function ($tipoEntidad) {
    $tipoEntidad = (string)$tipoEntidad;
    $etiquetas = [
        'EMPRESA' => 'Empresa',
        'ORGANIZACION' => 'Organización',
        'INSTITUCION' => 'Institución',
        'SECRETARIA' => 'Secretaría',
        'MUNICIPIO' => 'Municipio',
        'OTRO' => 'Otro'
    ];

    return $etiquetas[$tipoEntidad] ?? '—';
};

$etiquetaCanal = function ($canal) {
    $canal = (string)$canal;
    $etiquetas = [
        'LLAMADA_IP' => 'Llamada',
        'WHATSAPP' => 'WhatsApp',
        'CORREO' => 'Correo',
        'NOTA' => 'Nota',
        'SISTEMA' => 'Sistema'
    ];

    return $etiquetas[$canal] ?? '—';
};

$formatearProximaAccion = function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    try {
        $fechaObjeto = new DateTime($fecha);
        $hoy = new DateTime('today');
        $manana = (clone $hoy)->modify('+1 day');
        $fechaDia = (clone $fechaObjeto)->setTime(0, 0, 0);

        if ($fechaDia < $hoy) {
            return 'Vencida';
        }

        if ($fechaDia == $hoy) {
            return 'Hoy';
        }

        if ($fechaDia == $manana) {
            return 'Mañana';
        }

        $meses = [
            'Jan' => 'ene',
            'Feb' => 'feb',
            'Mar' => 'mar',
            'Apr' => 'abr',
            'May' => 'may',
            'Jun' => 'jun',
            'Jul' => 'jul',
            'Aug' => 'ago',
            'Sep' => 'sep',
            'Oct' => 'oct',
            'Nov' => 'nov',
            'Dec' => 'dic'
        ];

        return strtr($fechaObjeto->format('d M Y'), $meses);
    } catch (Exception $error) {
        return '—';
    }
};

$proximaAccionBandeja = function ($seguimiento) use ($formatearProximaAccion) {
    $fecha = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
    $estado = (string)($seguimiento['estado_seguimiento'] ?? '');

    if ($fecha === '' && $estado === 'NUEVO') {
        return 'Completar investigación';
    }

    return $formatearProximaAccion($fecha);
};

$nombreAnalista = function ($seguimiento) use ($texto) {
    return $texto(trim(
        ($seguimiento['analista_nombre'] ?? '') . ' ' .
        ($seguimiento['analista_apellidos'] ?? '')
    ));
};

$seleccionado = function ($actual, $valor) {
    return (string)$actual === (string)$valor ? 'selected' : '';
};

$buscarActual = $filtrosSeguimiento['buscar'] ?? '';
$analistaActual = (string)($filtrosSeguimiento['analista_id'] ?? 0);
$estadoSeguimientoActual = (string)($filtrosSeguimiento['estado_seguimiento'] ?? '');
$hayFiltros =
    trim((string)$buscarActual) !== '' ||
    (int)$analistaActual > 0 ||
    $estadoSeguimientoActual !== '';

?>

<div class="linkage-state-nav">
    <a
        class="linkage-back-link"
        href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a territorios
    </a>

    <div class="linkage-state-identity">
        <strong><?= $texto($estado['nombre'] ?? '') ?></strong>
        <span>Seguimiento territorial</span>
    </div>
</div>

<section class="metric-grid linkage-summary-grid" aria-label="Resumen de seguimiento">
    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-kanban"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['en_seguimiento'] ?></p>
            <p class="metric-label">En seguimiento</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-telephone"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['contactando'] ?></p>
            <p class="metric-label">Contactando</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-patch-check"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['datos_verificados'] ?></p>
            <p class="metric-label">Datos verificados</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['esperando_respuesta'] ?></p>
            <p class="metric-label">Esperando respuesta</p>
        </div>
    </article>
</section>

<section class="dashboard-panel linkage-filters-panel">
    <form
        class="linkage-tools-form"
        action="<?= BASE_URL ?>index.php"
        method="GET"
        data-linkage-state-filters>
        <input type="hidden" name="controller" value="seguimientoVinculacion">
        <input type="hidden" name="action" value="estado">
        <input type="hidden" name="estado_id" value="<?= (int)($estado['id'] ?? 0) ?>">

        <div class="data-filter-field linkage-search-field">
            <label for="buscar_institucion_seguimiento">Buscar institución</label>
            <div class="module-search">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    class="form-control"
                    id="buscar_institucion_seguimiento"
                    name="buscar"
                    value="<?= $texto($buscarActual) ?>"
                    placeholder="Buscar institución, organización o Secretaría..."
                    aria-label="Buscar institución"
                    data-linkage-search-input>
            </div>
        </div>

        <div class="data-filter-field">
            <label for="estado_seguimiento_filtro">Estado</label>
            <select
                class="form-select"
                id="estado_seguimiento_filtro"
                name="estado_seguimiento"
                aria-label="Filtrar por estado de seguimiento"
                data-linkage-stage-filter>
                <option value="">Todos</option>
                <option value="NUEVO" <?= $seleccionado($estadoSeguimientoActual, 'NUEVO') ?>>Nuevo</option>
                <option value="CONTACTANDO" <?= $seleccionado($estadoSeguimientoActual, 'CONTACTANDO') ?>>Contactando</option>
                <option value="DATOS_VERIFICADOS" <?= $seleccionado($estadoSeguimientoActual, 'DATOS_VERIFICADOS') ?>>Datos verificados</option>
                <option value="NO_LOCALIZADO" <?= $seleccionado($estadoSeguimientoActual, 'NO_LOCALIZADO') ?>>No localizado</option>
                <option value="DESCARTADO" <?= $seleccionado($estadoSeguimientoActual, 'DESCARTADO') ?>>Descartado</option>
                <option value="OFICIO_PREPARADO" <?= $seleccionado($estadoSeguimientoActual, 'OFICIO_PREPARADO') ?>>Oficio preparado</option>
                <option value="ESPERANDO_RESPUESTA" <?= $seleccionado($estadoSeguimientoActual, 'ESPERANDO_RESPUESTA') ?>>Esperando respuesta</option>
            </select>
        </div>

        <?php if ($mostrarColumnaAnalista): ?>
            <div class="data-filter-field">
                <label for="analista_seguimiento_filtro">Analista</label>
                <select
                    class="form-select"
                    id="analista_seguimiento_filtro"
                    name="analista_id"
                    aria-label="Filtrar por Analista"
                    data-linkage-analyst-filter>
                    <option value="">
                        <?= $modoSeguimiento === 'administrador'
                            ? 'Todos los analistas'
                            : 'Mis analistas' ?>
                    </option>
                    <?php foreach ($analistasFiltro as $analista): ?>
                        <?php
                        $nombreFiltro = trim(
                            ($analista['nombre'] ?? '') . ' ' .
                            ($analista['apellidos'] ?? '')
                        );
                        ?>
                        <option
                            value="<?= (int)$analista['id'] ?>"
                            <?= $seleccionado($analistaActual, $analista['id']) ?>>
                            <?= $texto($nombreFiltro) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="linkage-filter-clear-slot">
            <a
                class="filter-clear-link <?= $hayFiltros ? '' : 'd-none' ?>"
                href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=estado&estado_id=<?= (int)($estado['id'] ?? 0) ?>"
                data-linkage-clear-filters>
                Limpiar filtros
            </a>
        </div>

        <div class="linkage-tools-actions">
            <a
                href="#"
                class="btn btn-system-save linkage-action-button"
                <?= $puedeCrearSeguimiento ? 'data-bs-toggle="modal" data-bs-target="#modalBuscarCandidato"' : 'aria-disabled="true" data-linkage-disabled-action' ?>>
                <i class="bi bi-plus-lg"></i>
                Buscar candidato
            </a>
            <a
                href="#"
                class="btn btn-system-light linkage-action-button"
                aria-disabled="true"
                data-linkage-disabled-action>
                <i class="bi bi-plus-lg"></i>
                Agregar manualmente
            </a>
        </div>
    </form>
</section>

<section class="dashboard-panel users-list-panel linkage-table-panel">
    <div class="users-list-header">
        <div>
            <h2>Seguimientos registrados</h2>
            <p>Consulta y gestiona los procesos de vinculación del territorio.</p>
        </div>
        <span class="linkage-results-count" data-linkage-results-count>
            <?= $totalResultadosFiltrados ?> <?= $totalResultadosFiltrados === 1 ? 'resultado' : 'resultados' ?>
        </span>
    </div>

    <div class="table-responsive <?= empty($seguimientos) ? 'd-none' : '' ?>" data-linkage-table-wrapper>
        <table class="table users-table align-middle linkage-table">
            <thead>
                <tr>
                    <th>Institución</th>
                    <th>Tipo</th>
                    <?php if ($mostrarColumnaAnalista): ?>
                        <th>Analista</th>
                    <?php endif; ?>
                    <th>Municipio</th>
                    <th>Última actividad</th>
                    <th>Etapa</th>
                    <th>Próxima acción</th>
                    <th>Folio</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seguimientos as $seguimiento): ?>
                    <?php
                    $textoBusquedaFila = trim(implode(' ', [
                        $seguimiento['nombre_entidad'] ?? '',
                        $etiquetaTipo($seguimiento['tipo_entidad'] ?? ''),
                        $seguimiento['municipio'] ?? '',
                        $seguimiento['folio'] ?? '',
                        $seguimiento['analista_nombre'] ?? '',
                        $seguimiento['analista_apellidos'] ?? ''
                    ]));
                    ?>
                    <tr
                        data-linkage-follow-row
                        data-search="<?= $texto($textoBusquedaFila) ?>"
                        data-stage="<?= $texto($seguimiento['estado_seguimiento'] ?? '') ?>"
                        data-analyst="<?= (int)($seguimiento['analista_id'] ?? 0) ?>">
                        <td>
                            <strong><?= $texto($seguimiento['nombre_entidad'] ?? '') ?></strong>
                        </td>
                        <td>
                            <span class="linkage-type-badge">
                                <?= $texto($etiquetaTipo($seguimiento['tipo_entidad'] ?? '')) ?>
                            </span>
                        </td>
                        <?php if ($mostrarColumnaAnalista): ?>
                            <td><?= $nombreAnalista($seguimiento) ?></td>
                        <?php endif; ?>
                        <td>
                            <?= trim((string)($seguimiento['municipio'] ?? '')) !== ''
                                ? $texto($seguimiento['municipio'])
                                : '—' ?>
                        </td>
                        <td>
                            <span class="linkage-activity-date" data-row-last-activity>
                                <?= $texto($formatearFecha($seguimiento['ultima_interaccion_at'] ?? '')) ?>
                            </span>
                            <small
                                class="<?= trim((string)($seguimiento['ultimo_canal'] ?? '')) !== '' ? '' : 'd-none' ?>"
                                data-row-last-channel>
                                <?= $texto($etiquetaCanal($seguimiento['ultimo_canal'] ?? '')) ?>
                            </small>
                        </td>
                        <td>
                            <span class="linkage-stage-badge" data-row-stage-label>
                                <?= $texto($etiquetaEstado($seguimiento['estado_seguimiento'] ?? '')) ?>
                            </span>
                        </td>
                        <td data-row-next-action><?= $texto($proximaAccionBandeja($seguimiento)) ?></td>
                        <td>
                            <?= trim((string)($seguimiento['folio'] ?? '')) !== ''
                                ? $texto($seguimiento['folio'])
                                : '—' ?>
                        </td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                                <a
                                    href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=<?= (int)$seguimiento['id'] ?>"
                                    class="btn btn-system-light linkage-manage-button"
                                    title="Trabajar seguimiento"
                                    aria-label="Trabajar seguimiento"
                                    data-work-follow
                                    data-work-follow-id="<?= (int)$seguimiento['id'] ?>">
                                    <i class="bi bi-kanban"></i>
                                    <span>Trabajar</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div
        class="empty-table-message linkage-empty-message <?= ($totalSeguimientosReales === 0 && empty($seguimientos)) ? '' : 'd-none' ?>"
        data-linkage-empty-real>
            <strong>
                <?php if ($modoSeguimiento === 'supervisor' && empty($analistasFiltro)): ?>
                    No tienes Analistas asociados en este territorio.
                <?php elseif ($modoSeguimiento === 'supervisor'): ?>
                    Tus Analistas aún no tienen seguimientos en este territorio.
                <?php elseif ($modoSeguimiento === 'administrador'): ?>
                    No hay seguimientos registrados en <?= $texto($estado['nombre'] ?? '') ?>.
                <?php else: ?>
                    Aún no tienes seguimientos en <?= $texto($estado['nombre'] ?? '') ?>.
                <?php endif; ?>
            </strong>
            <span>
                Busca una institución u organización para comenzar.
            </span>
            <div class="linkage-empty-actions">
                <a
                    href="#"
                    class="btn btn-system-save linkage-action-button"
                    <?= $puedeCrearSeguimiento ? 'data-bs-toggle="modal" data-bs-target="#modalBuscarCandidato"' : 'aria-disabled="true" data-linkage-disabled-action' ?>>
                    <i class="bi bi-plus-lg"></i>
                    Buscar candidato
                </a>
                <a
                    href="#"
                    class="btn btn-system-light linkage-action-button"
                    aria-disabled="true"
                    data-linkage-disabled-action>
                    Agregar manualmente
                </a>
            </div>
    </div>

    <div
        class="empty-table-message linkage-empty-message <?= ($totalSeguimientosReales > 0 && empty($seguimientos)) ? '' : 'd-none' ?>"
        data-linkage-empty-filtered>
        <strong>No se encontraron seguimientos con los filtros seleccionados.</strong>
        <span>Prueba cambiando la búsqueda o el estado.</span>
    </div>
</section>

<div
    class="modal fade"
    id="modalBuscarCandidato"
    tabindex="-1"
    aria-labelledby="modalBuscarCandidatoTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl system-form-dialog linkage-candidate-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalBuscarCandidatoTitulo">
                        Buscar candidato
                    </h5>
                    <p class="system-form-modal-subtitle">
                        Encuentra instituciones y organizaciones para iniciar un seguimiento
                        en <?= $texto($estado['nombre'] ?? '') ?>.
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <form class="linkage-candidate-filters" data-candidate-search-form>
                    <div class="data-filter-field linkage-search-field">
                        <label for="candidate_search">Buscar</label>
                        <div class="module-search">
                            <i class="bi bi-search"></i>
                            <input
                                type="search"
                                class="form-control"
                                id="candidate_search"
                                placeholder="Buscar empresa, institución u organización..."
                                autocomplete="off"
                                data-candidate-search>
                        </div>
                    </div>

                    <div class="data-filter-field">
                        <label for="candidate_type">Tipo de candidato</label>
                        <select class="form-select" id="candidate_type" data-candidate-type>
                            <option value="TODOS">Todos</option>
                            <option value="EMPRESAS">Empresas y organizaciones</option>
                            <option value="INSTITUCIONES">Instituciones</option>
                            <option value="SECRETARIA">Secretarías estatales</option>
                        </select>
                    </div>

                    <div class="data-filter-field">
                        <label for="candidate_municipality">Municipio</label>
                        <select class="form-select" id="candidate_municipality" data-candidate-municipality>
                            <option value="0" data-municipality-default>Todos los municipios</option>
                            <?php foreach ($municipiosCandidatos as $municipio): ?>
                                <option value="<?= (int)$municipio['id'] ?>">
                                    <?= $texto($municipio['nombre'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="linkage-candidate-filter-actions">
                        <button
                            type="button"
                            class="btn btn-system-save linkage-candidate-auto"
                            data-candidate-auto-search>
                            <i class="bi bi-lightning-charge"></i>
                            Buscar candidatos automáticamente
                        </button>
                    </div>
                </form>

                <div class="linkage-candidate-alert d-none" data-candidate-alert></div>

                <div class="linkage-candidate-status" data-candidate-status>
                    Escribe al menos 3 caracteres o utiliza Buscar candidatos automáticamente.
                </div>

                <div class="linkage-candidate-results d-none" data-candidate-results></div>

                <div class="data-pagination linkage-candidate-pagination d-none" data-candidate-pagination>
                    <span data-candidate-page-label></span>
                    <div class="linkage-candidate-page-actions">
                        <a href="#" aria-label="Anterior" data-candidate-page-prev>‹</a>
                        <a href="#" aria-label="Siguiente" data-candidate-page-next>›</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalConfirmarSeguimiento"
    tabindex="-1"
    aria-labelledby="modalConfirmarSeguimientoTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalConfirmarSeguimientoTitulo">
                        Iniciar seguimiento
                    </h5>
                    <p class="system-form-modal-subtitle">
                        Revisa la información encontrada antes de crear el seguimiento.
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form data-candidate-confirm-form>
                <div class="modal-body">
                    <input type="hidden" name="estado_id" value="<?= (int)($estado['id'] ?? 0) ?>">
                    <input type="hidden" name="origen" data-confirm-origin>
                    <input type="hidden" name="clave_origen" data-confirm-key>

                    <div class="linkage-confirm-summary">
                        <div>
                            <span>Nombre</span>
                            <strong data-confirm-name>—</strong>
                        </div>
                        <div>
                            <span>Fuente</span>
                            <strong data-confirm-source>—</strong>
                        </div>
                        <div>
                            <span>Municipio</span>
                            <strong data-confirm-municipality>—</strong>
                        </div>
                        <div>
                            <span>Teléfono encontrado</span>
                            <strong data-confirm-phone>—</strong>
                        </div>
                        <div>
                            <span>Correo encontrado</span>
                            <strong data-confirm-email>—</strong>
                        </div>
                    </div>

                    <?php if ($mostrarColumnaAnalista): ?>
                        <div class="mt-3">
                            <label class="form-label" for="confirm_analyst">
                                Analista responsable
                            </label>
                            <select
                                class="form-select"
                                id="confirm_analyst"
                                name="analista_id"
                                required>
                                <option value="">Seleccionar Analista</option>
                                <?php foreach ($analistasFiltro as $analista): ?>
                                    <?php
                                    $nombreFiltro = trim(
                                        ($analista['nombre'] ?? '') . ' ' .
                                        ($analista['apellidos'] ?? '')
                                    );
                                    ?>
                                    <option value="<?= (int)$analista['id'] ?>">
                                        <?= $texto($nombreFiltro) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="linkage-candidate-alert d-none mt-3" data-confirm-alert></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-system-save" data-confirm-submit>
                        Confirmar e iniciar seguimiento
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="offcanvas offcanvas-end linkage-work-offcanvas"
    tabindex="-1"
    id="offcanvasSeguimientoTrabajo"
    aria-labelledby="offcanvasSeguimientoTrabajoTitulo">
    <div class="offcanvas-header linkage-work-header">
        <div>
            <span>Panel de trabajo</span>
            <h5 id="offcanvasSeguimientoTrabajoTitulo" data-work-title>Seguimiento</h5>
            <p data-work-subtitle>—</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>

    <div class="offcanvas-body linkage-work-body">
        <div class="linkage-candidate-alert d-none" data-work-alert></div>

        <section class="linkage-work-section linkage-work-next">
            <span>PRÓXIMA ACCIÓN</span>
            <strong data-work-next-action>—</strong>
            <button type="button" class="btn btn-system-save linkage-work-primary" data-work-primary-action>
                Completar / verificar contacto
            </button>
        </section>

        <section class="linkage-work-section">
            <div class="linkage-work-section-title">
                <h3>Contacto</h3>
                <button type="button" class="btn btn-system-light linkage-work-small-button" data-work-toggle-contact>
                    Editar / verificar datos
                </button>
            </div>

            <div class="linkage-work-contact-grid">
                <div>
                    <span>Contacto</span>
                    <strong data-work-contact-name>—</strong>
                </div>
                <div>
                    <span>Cargo / Área</span>
                    <strong data-work-contact-role>—</strong>
                </div>
                <div>
                    <span>Teléfono</span>
                    <strong data-work-phone>—</strong>
                </div>
                <div>
                    <span>WhatsApp</span>
                    <strong data-work-whatsapp>—</strong>
                </div>
                <div>
                    <span>Correo</span>
                    <strong data-work-email>—</strong>
                </div>
                <div>
                    <span>Estado</span>
                    <strong data-work-verified-status>Datos sin verificar</strong>
                </div>
            </div>

            <form class="linkage-work-form d-none" data-work-contact-form>
                <input type="hidden" name="seguimiento_id" data-work-contact-id>

                <div class="linkage-work-source-row">
                    <div>
                        <span>Teléfono encontrado</span>
                        <strong data-work-source-phone>—</strong>
                    </div>
                    <div>
                        <span>Correo encontrado</span>
                        <strong data-work-source-email>—</strong>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_telefono_verificado">Teléfono verificado</label>
                        <input class="form-control" id="work_telefono_verificado" name="telefono_verificado">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_whatsapp_verificado">WhatsApp</label>
                        <input class="form-control" id="work_whatsapp_verificado" name="whatsapp_verificado">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="work_correo_verificado">Correo verificado</label>
                        <input class="form-control" id="work_correo_verificado" name="correo_verificado" type="email">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_contacto_nombre">Persona de contacto</label>
                        <input class="form-control" id="work_contacto_nombre" name="contacto_nombre">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_contacto_cargo">Cargo / Área</label>
                        <input class="form-control" id="work_contacto_cargo" name="contacto_cargo">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="work_contacto_observaciones">Observaciones</label>
                        <textarea class="form-control" id="work_contacto_observaciones" name="observaciones" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="linkage-work-check">
                            <input type="checkbox" name="marcar_verificado" value="1">
                            <span>Marcar datos como verificados</span>
                        </label>
                    </div>
                </div>

                <div class="linkage-work-form-actions">
                    <button type="button" class="btn btn-system-cancel" data-work-cancel-contact>Cancelar</button>
                    <button type="submit" class="btn btn-system-save">Guardar datos</button>
                </div>
            </form>
        </section>

        <section class="linkage-work-section">
            <h3>Contactar</h3>
            <div class="linkage-work-quick-actions">
                <button type="button" class="btn btn-system-light" disabled title="Integración pendiente">
                    <i class="bi bi-telephone"></i>
                    Llamar
                </button>
                <button type="button" class="btn btn-system-light" disabled title="Integración pendiente">
                    <i class="bi bi-whatsapp"></i>
                    WhatsApp
                </button>
            </div>
        </section>

        <section class="linkage-work-section">
            <div class="linkage-work-section-title">
                <h3>Registrar interacción</h3>
                <button type="button" class="btn btn-system-light linkage-work-small-button" data-work-toggle-interaction>
                    <i class="bi bi-plus-lg"></i>
                    Registrar
                </button>
            </div>

            <form class="linkage-work-form d-none" data-work-interaction-form>
                <input type="hidden" name="seguimiento_id" data-work-interaction-id>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_interaction_channel">Canal</label>
                        <select class="form-select" id="work_interaction_channel" name="canal" required>
                            <option value="LLAMADA">Llamada</option>
                            <option value="WHATSAPP">WhatsApp</option>
                            <option value="CORREO">Correo</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_interaction_result">Resultado</label>
                        <select class="form-select" id="work_interaction_result" name="resultado" required>
                            <option value="SIN_RESPUESTA">Sin respuesta</option>
                            <option value="CONTACTADO">Contactado</option>
                            <option value="NO_CONTESTO">No contestó</option>
                            <option value="OCUPADO">Ocupado</option>
                            <option value="NUMERO_INCORRECTO">Número incorrecto</option>
                            <option value="SOLICITO_LLAMAR_DESPUES">Solicitó llamar después</option>
                            <option value="MENSAJE_ENVIADO">Mensaje enviado</option>
                            <option value="CORREO_ENVIADO">Correo enviado</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_interaction_person">Persona atendió</label>
                        <input class="form-control" id="work_interaction_person" name="persona_atendio">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_interaction_date">Fecha/hora</label>
                        <input class="form-control" id="work_interaction_date" name="fecha_inicio" type="datetime-local" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_next_action">Próxima acción</label>
                        <select class="form-select" id="work_next_action" name="proxima_accion">
                            <option value="">Sin próxima acción</option>
                            <option value="Volver a llamar">Volver a llamar</option>
                            <option value="Enviar WhatsApp">Enviar WhatsApp</option>
                            <option value="Confirmar contacto de RH">Confirmar contacto de RH</option>
                            <option value="Revisar correo">Revisar correo</option>
                            <option value="Preparar oficio">Preparar oficio</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="work_next_action_date">Fecha próxima acción</label>
                        <input class="form-control" id="work_next_action_date" name="proxima_accion_at" type="datetime-local">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="work_interaction_note">Observación</label>
                        <textarea class="form-control" id="work_interaction_note" name="observacion" rows="2"></textarea>
                    </div>
                </div>

                <div class="linkage-work-form-actions">
                    <button type="button" class="btn btn-system-cancel" data-work-cancel-interaction>Cancelar</button>
                    <button type="submit" class="btn btn-system-save">Guardar interacción</button>
                </div>
            </form>
        </section>

        <section class="linkage-work-section">
            <h3>Últimas actividades</h3>
            <div class="linkage-work-list" data-work-activity-list>
                <span class="linkage-work-empty">Sin interacciones registradas.</span>
            </div>
        </section>

        <section class="linkage-work-section">
            <div class="linkage-work-section-title">
                <h3>Observaciones Cuenta Clave</h3>
                <span class="linkage-work-new-badge d-none" data-work-observation-count></span>
            </div>
            <div class="linkage-work-list" data-work-observation-list>
                <span class="linkage-work-empty">Sin observaciones.</span>
            </div>
        </section>

        <a class="btn btn-system-light linkage-work-expedient" href="#" data-work-expedient>
            Ver expediente completo
        </a>
    </div>
</div>

<div class="linkage-toast d-none" role="status" aria-live="polite" data-linkage-toast></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formulario = document.querySelector('[data-linkage-state-filters]');
    let temporizadorFiltros = null;
    const campoFiltroSeguimiento = document.querySelector('[data-linkage-search-input]');
    const estadoFiltroSeguimiento = document.querySelector('[data-linkage-stage-filter]');
    const analistaFiltroSeguimiento = document.querySelector('[data-linkage-analyst-filter]');
    const filasSeguimiento = Array.from(document.querySelectorAll('[data-linkage-follow-row]'));
    const contadorSeguimientos = document.querySelector('[data-linkage-results-count]');
    const tablaSeguimientos = document.querySelector('[data-linkage-table-wrapper]');
    const emptyRealSeguimientos = document.querySelector('[data-linkage-empty-real]');
    const emptyFiltradoSeguimientos = document.querySelector('[data-linkage-empty-filtered]');
    const enlacesLimpiarSeguimiento = document.querySelectorAll('[data-linkage-clear-filters]');
    const totalSeguimientosReales = <?= (int)$totalSeguimientosReales ?>;
    const filtrosInicialesServidor = <?= $hayFiltros ? 'true' : 'false' ?>;
    const offcanvasTrabajoElemento = document.getElementById('offcanvasSeguimientoTrabajo');
    const offcanvasTrabajo = offcanvasTrabajoElemento && window.bootstrap
        ? new bootstrap.Offcanvas(offcanvasTrabajoElemento)
        : null;
    const urlPanelTrabajo = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=obtenerPanelTrabajo';
    const urlActualizarContactoTrabajo = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=actualizarContactoTrabajo';
    const urlRegistrarInteraccionTrabajo = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=registrarInteraccionTrabajo';
    let seguimientoTrabajoActual = null;

    const normalizarFiltroSeguimiento = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const filtrosSeguimientoActivos = function () {
        return normalizarFiltroSeguimiento(campoFiltroSeguimiento?.value || '') !== '' ||
            String(estadoFiltroSeguimiento?.value || '') !== '' ||
            String(analistaFiltroSeguimiento?.value || '') !== '';
    };

    const actualizarBandejaSeguimiento = function () {
        const busqueda = normalizarFiltroSeguimiento(campoFiltroSeguimiento?.value || '');
        const etapa = String(estadoFiltroSeguimiento?.value || '');
        const analista = String(analistaFiltroSeguimiento?.value || '');
        let visibles = 0;

        filasSeguimiento.forEach(function (fila) {
            const coincideBusqueda = busqueda === '' ||
                normalizarFiltroSeguimiento(fila.dataset.search || '').includes(busqueda);
            const coincideEtapa = etapa === '' || fila.dataset.stage === etapa;
            const coincideAnalista = analista === '' || fila.dataset.analyst === analista;
            const visible = coincideBusqueda && coincideEtapa && coincideAnalista;

            fila.classList.toggle('d-none', !visible);

            if (visible) {
                visibles += 1;
            }
        });

        if (contadorSeguimientos) {
            contadorSeguimientos.textContent = visibles + ' ' +
                (visibles === 1 ? 'resultado' : 'resultados');
        }

        tablaSeguimientos?.classList.toggle('d-none', visibles === 0);
        emptyRealSeguimientos?.classList.toggle('d-none', !(totalSeguimientosReales === 0 && visibles === 0));
        emptyFiltradoSeguimientos?.classList.toggle('d-none', !(totalSeguimientosReales > 0 && visibles === 0));

        enlacesLimpiarSeguimiento.forEach(function (enlace) {
            enlace.classList.toggle('d-none', !filtrosSeguimientoActivos());
        });
    };

    formulario?.addEventListener('submit', function (event) {
        event.preventDefault();
        actualizarBandejaSeguimiento();
    });

    campoFiltroSeguimiento?.addEventListener('input', function () {
        window.clearTimeout(temporizadorFiltros);
        temporizadorFiltros = window.setTimeout(actualizarBandejaSeguimiento, 320);
    });

    [estadoFiltroSeguimiento, analistaFiltroSeguimiento].forEach(function (selector) {
        selector?.addEventListener('change', actualizarBandejaSeguimiento);
    });

    enlacesLimpiarSeguimiento.forEach(function (enlace) {
        enlace.addEventListener('click', function (event) {
            if (filtrosInicialesServidor && totalSeguimientosReales > 0) {
                return;
            }

            event.preventDefault();

            if (campoFiltroSeguimiento) {
                campoFiltroSeguimiento.value = '';
            }

            if (estadoFiltroSeguimiento) {
                estadoFiltroSeguimiento.value = '';
            }

            if (analistaFiltroSeguimiento) {
                analistaFiltroSeguimiento.value = '';
            }

            actualizarBandejaSeguimiento();
            campoFiltroSeguimiento?.focus();
        });
    });

    actualizarBandejaSeguimiento();

    const valorTrabajo = function (valor, reserva = '—') {
        const texto = String(valor || '').trim();
        return texto === '' ? reserva : texto;
    };

    const escaparTrabajo = function (valor) {
        return String(valorTrabajo(valor))
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const asignarTextoTrabajo = function (selector, valor, reserva = '—') {
        const elemento = document.querySelector(selector);

        if (elemento) {
            elemento.textContent = valorTrabajo(valor, reserva);
        }
    };

    const mostrarAlertaTrabajo = function (mensaje, tipo = 'info') {
        const alerta = document.querySelector('[data-work-alert]');

        if (!alerta) {
            return;
        }

        if (!mensaje) {
            alerta.classList.add('d-none');
            alerta.textContent = '';
            return;
        }

        alerta.classList.remove('d-none');
        alerta.classList.toggle('linkage-candidate-alert-error', tipo === 'error');
        alerta.textContent = mensaje;
    };

    const fechaLocalInput = function (fecha = new Date()) {
        const local = new Date(fecha.getTime() - (fecha.getTimezoneOffset() * 60000));
        return local.toISOString().slice(0, 16);
    };

    const establecerOperacionHabilitada = function (habilitada) {
        document
            .querySelectorAll('[data-work-toggle-contact], [data-work-toggle-interaction], [data-work-primary-action]')
            .forEach(function (boton) {
                boton.disabled = !habilitada;
                boton.title = habilitada ? '' : 'Acción disponible para el Analista responsable';
            });
    };

    const actualizarFilaSeguimiento = function (seguimiento, interaccionReciente = null) {
        const fila = document
            .querySelector('[data-work-follow-id="' + seguimiento.id + '"]')
            ?.closest('[data-linkage-follow-row]');

        if (!fila) {
            return;
        }

        fila.dataset.stage = seguimiento.estado_seguimiento || '';
        const etapa = fila.querySelector('[data-row-stage-label]');
        const ultimaActividad = fila.querySelector('[data-row-last-activity]');
        const ultimoCanal = fila.querySelector('[data-row-last-channel]');
        const proximaAccion = fila.querySelector('[data-row-next-action]');

        if (etapa) {
            etapa.textContent = seguimiento.estado_label || 'Sin estado';
        }

        if (ultimaActividad) {
            ultimaActividad.textContent = seguimiento.ultima_interaccion_label || '—';
        }

        if (ultimoCanal && interaccionReciente) {
            ultimoCanal.textContent = interaccionReciente.canal_label || '';
            ultimoCanal.classList.toggle('d-none', !interaccionReciente.canal_label);
        }

        if (proximaAccion) {
            proximaAccion.textContent = seguimiento.proxima_accion_label || '—';
        }

        actualizarBandejaSeguimiento();
    };

    const renderizarResumenTrabajo = function (seguimiento, puedeOperar = true) {
        seguimientoTrabajoActual = seguimiento;
        asignarTextoTrabajo('[data-work-title]', seguimiento.nombre_entidad);
        asignarTextoTrabajo(
            '[data-work-subtitle]',
            valorTrabajo(seguimiento.tipo_entidad_label) + ' · ' + valorTrabajo(seguimiento.municipio)
        );
        asignarTextoTrabajo('[data-work-next-action]', seguimiento.proxima_accion_label);
        asignarTextoTrabajo('[data-work-contact-name]', seguimiento.contacto_nombre);
        asignarTextoTrabajo('[data-work-contact-role]', seguimiento.contacto_cargo);
        asignarTextoTrabajo('[data-work-phone]', seguimiento.telefono_verificado || seguimiento.telefono_fuente);
        asignarTextoTrabajo('[data-work-whatsapp]', seguimiento.whatsapp_verificado);
        asignarTextoTrabajo('[data-work-email]', seguimiento.correo_verificado || seguimiento.correo_fuente);
        asignarTextoTrabajo('[data-work-verified-status]', seguimiento.datos_verificados_label);
        asignarTextoTrabajo('[data-work-source-phone]', seguimiento.telefono_fuente);
        asignarTextoTrabajo('[data-work-source-email]', seguimiento.correo_fuente);

        const botonPrincipal = document.querySelector('[data-work-primary-action]');
        if (botonPrincipal) {
            botonPrincipal.textContent = seguimiento.accion_principal || 'Revisar seguimiento';
        }

        const expediente = document.querySelector('[data-work-expedient]');
        if (expediente) {
            expediente.href = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=' +
                seguimiento.id;
        }

        const formContacto = document.querySelector('[data-work-contact-form]');
        const formInteraccion = document.querySelector('[data-work-interaction-form]');

        if (formContacto) {
            formContacto.querySelector('[data-work-contact-id]').value = seguimiento.id;
            formContacto.querySelector('[name="telefono_verificado"]').value = seguimiento.telefono_verificado || '';
            formContacto.querySelector('[name="whatsapp_verificado"]').value = seguimiento.whatsapp_verificado || '';
            formContacto.querySelector('[name="correo_verificado"]').value = seguimiento.correo_verificado || '';
            formContacto.querySelector('[name="contacto_nombre"]').value = seguimiento.contacto_nombre || '';
            formContacto.querySelector('[name="contacto_cargo"]').value = seguimiento.contacto_cargo || '';
            formContacto.querySelector('[name="observaciones"]').value = seguimiento.observaciones || '';
            formContacto.querySelector('[name="marcar_verificado"]').checked = false;
        }

        if (formInteraccion) {
            formInteraccion.querySelector('[data-work-interaction-id]').value = seguimiento.id;
            formInteraccion.reset();
            formInteraccion.querySelector('[data-work-interaction-id]').value = seguimiento.id;
            formInteraccion.querySelector('[name="fecha_inicio"]').value = fechaLocalInput();
        }

        establecerOperacionHabilitada(puedeOperar);
        actualizarFilaSeguimiento(seguimiento);
    };

    const renderizarInteraccionesTrabajo = function (interacciones) {
        const lista = document.querySelector('[data-work-activity-list]');

        if (!lista) {
            return;
        }

        if (!Array.isArray(interacciones) || interacciones.length === 0) {
            lista.innerHTML = '<span class="linkage-work-empty">Sin interacciones registradas.</span>';
            return;
        }

        lista.innerHTML = interacciones.map(function (interaccion) {
            return '<article>' +
                '<strong>' + escaparTrabajo(interaccion.fecha_label) + '</strong>' +
                '<span>' + escaparTrabajo(interaccion.canal_label) + ' · ' +
                    escaparTrabajo(interaccion.resultado_label) + '</span>' +
                (interaccion.notas ? '<p>' + escaparTrabajo(interaccion.notas) + '</p>' : '') +
            '</article>';
        }).join('');
    };

    const renderizarObservacionesTrabajo = function (observaciones, nuevas) {
        const lista = document.querySelector('[data-work-observation-list]');
        const contador = document.querySelector('[data-work-observation-count]');

        if (contador) {
            contador.classList.toggle('d-none', !nuevas);
            contador.textContent = nuevas ? nuevas + ' nuevas' : '';
        }

        if (!lista) {
            return;
        }

        if (!Array.isArray(observaciones) || observaciones.length === 0) {
            lista.innerHTML = '<span class="linkage-work-empty">Sin observaciones.</span>';
            return;
        }

        lista.innerHTML = observaciones.map(function (observacion) {
            return '<article>' +
                '<strong>' + escaparTrabajo(observacion.autor) + '</strong>' +
                '<span>' + escaparTrabajo(observacion.fecha_label) + '</span>' +
                '<p>' + escaparTrabajo(observacion.observacion) + '</p>' +
            '</article>';
        }).join('');
    };

    const cargarPanelTrabajo = async function (seguimientoId) {
        mostrarAlertaTrabajo('');
        asignarTextoTrabajo('[data-work-title]', 'Cargando seguimiento...');
        asignarTextoTrabajo('[data-work-subtitle]', '—');
        offcanvasTrabajo?.show();

        const respuesta = await fetch(urlPanelTrabajo + '&id=' + encodeURIComponent(seguimientoId), {
            headers: {
                'X-Requested-With': 'fetch'
            }
        });
        const datos = await respuesta.json();

        if (!datos.ok) {
            throw new Error(datos.mensaje || 'No fue posible cargar el seguimiento.');
        }

        renderizarResumenTrabajo(datos.seguimiento, Boolean(datos.puede_operar));
        renderizarInteraccionesTrabajo(datos.interacciones);
        renderizarObservacionesTrabajo(datos.observaciones, Number(datos.observaciones_nuevas) || 0);
    };

    document.addEventListener('click', function (event) {
        const botonTrabajo = event.target.closest('[data-work-follow]');

        if (!botonTrabajo || !offcanvasTrabajo) {
            return;
        }

        event.preventDefault();
        cargarPanelTrabajo(botonTrabajo.dataset.workFollowId).catch(function (error) {
            mostrarAlertaTrabajo(error.message, 'error');
        });
    });

    document.querySelector('[data-work-primary-action]')?.addEventListener('click', function () {
        document.querySelector('[data-work-contact-form]')?.classList.remove('d-none');
    });

    document.querySelector('[data-work-toggle-contact]')?.addEventListener('click', function () {
        document.querySelector('[data-work-contact-form]')?.classList.toggle('d-none');
    });

    document.querySelector('[data-work-cancel-contact]')?.addEventListener('click', function () {
        document.querySelector('[data-work-contact-form]')?.classList.add('d-none');
    });

    document.querySelector('[data-work-toggle-interaction]')?.addEventListener('click', function () {
        const form = document.querySelector('[data-work-interaction-form]');
        form?.classList.toggle('d-none');

        if (form && !form.classList.contains('d-none')) {
            form.querySelector('[name="fecha_inicio"]').value = fechaLocalInput();
        }
    });

    document.querySelector('[data-work-cancel-interaction]')?.addEventListener('click', function () {
        document.querySelector('[data-work-interaction-form]')?.classList.add('d-none');
    });

    document.querySelector('[data-work-contact-form]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        mostrarAlertaTrabajo('');

        try {
            const respuesta = await fetch(urlActualizarContactoTrabajo, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'fetch'
                },
                body: new FormData(event.currentTarget)
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                throw new Error(datos.mensaje || 'No fue posible guardar los datos.');
            }

            renderizarResumenTrabajo(datos.seguimiento, true);
            event.currentTarget.classList.add('d-none');
            mostrarAlertaTrabajo(datos.mensaje || 'Datos actualizados.');
        } catch (error) {
            mostrarAlertaTrabajo(error.message, 'error');
        }
    });

    document.querySelector('[data-work-interaction-form]')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        mostrarAlertaTrabajo('');

        try {
            const respuesta = await fetch(urlRegistrarInteraccionTrabajo, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'fetch'
                },
                body: new FormData(event.currentTarget)
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                throw new Error(datos.mensaje || 'No fue posible guardar la interacción.');
            }

            renderizarResumenTrabajo(datos.seguimiento, true);
            renderizarInteraccionesTrabajo(datos.interacciones);
            actualizarFilaSeguimiento(datos.seguimiento, (datos.interacciones || [])[0] || null);
            event.currentTarget.classList.add('d-none');
            mostrarAlertaTrabajo(datos.mensaje || 'Interacción registrada.');
        } catch (error) {
            mostrarAlertaTrabajo(error.message, 'error');
        }
    });

    document
        .querySelectorAll('[data-linkage-disabled-action]')
        .forEach(function (accion) {
            accion.addEventListener('click', function (event) {
                event.preventDefault();
            });
        });

    const modalBuscar = document.getElementById('modalBuscarCandidato');
    const modalConfirmar = document.getElementById('modalConfirmarSeguimiento');
    const formularioBusqueda = document.querySelector('[data-candidate-search-form]');
    const campoBusqueda = document.querySelector('[data-candidate-search]');
    const selectorTipo = document.querySelector('[data-candidate-type]');
    const selectorMunicipio = document.querySelector('[data-candidate-municipality]');
    const opcionMunicipioDefault = document.querySelector('[data-municipality-default]');
    const botonBusquedaAutomatica = document.querySelector('[data-candidate-auto-search]');
    const contenedorResultados = document.querySelector('[data-candidate-results]');
    const estadoBusqueda = document.querySelector('[data-candidate-status]');
    const alertaBusqueda = document.querySelector('[data-candidate-alert]');
    const paginacion = document.querySelector('[data-candidate-pagination]');
    const etiquetaPagina = document.querySelector('[data-candidate-page-label]');
    const botonAnterior = document.querySelector('[data-candidate-page-prev]');
    const botonSiguiente = document.querySelector('[data-candidate-page-next]');
    const formularioConfirmar = document.querySelector('[data-candidate-confirm-form]');
    const alertaConfirmar = document.querySelector('[data-confirm-alert]');
    const botonConfirmar = document.querySelector('[data-confirm-submit]');
    const toast = document.querySelector('[data-linkage-toast]');
    const estadoId = '<?= (int)($estado['id'] ?? 0) ?>';
    const urlBuscar = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=buscarCandidatos';
    const urlCrear = '<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=crearSeguimientoDesdeCandidato';
    let temporizadorCandidatos = null;
    let paginaCandidatos = 1;
    let candidatosActuales = [];
    let hayMasCandidatos = false;
    let totalResultadosCandidatos = 0;
    let resultadosPorPaginaCandidatos = 10;
    let busquedaAutomaticaActiva = false;
    let mensajeResultadosVacios = 'No se encontraron candidatos con los criterios seleccionados.';
    const cacheCandidatosAutomaticos = {};
    let secuenciaBusqueda = 0;
    let controladorBusqueda = null;

    const limpiar = function (valor) {
        return String(valor || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const textoPlano = function (valor, reserva = '—') {
        const texto = String(valor || '').trim();
        return texto === '' ? reserva : texto;
    };

    const etiquetaTipoCandidato = function (tipo) {
        const tipos = {
            EMPRESA: 'Empresa',
            ORGANIZACION: 'Organización',
            INSTITUCION: 'Institución',
            SECRETARIA: 'Secretaría',
            OTRO: 'Otro'
        };

        return tipos[tipo] || limpiar(tipo || '—');
    };

    const etiquetaTipoVisual = function (candidato) {
        return candidato.tipo_entidad_etiqueta ||
            etiquetaTipoCandidato(candidato.tipo_entidad);
    };

    const etiquetaBadgeCandidato = function (candidato) {
        if (candidato.origen === 'DENUE') {
            return 'DENUE';
        }

        if (candidato.origen === 'SECRETARIA') {
            return 'Secretaría estatal';
        }

        return etiquetaTipoVisual(candidato);
    };

    const actualizarEstadoFiltrosCandidato = function () {
        if (!selectorTipo || !selectorMunicipio) {
            return;
        }

        const esSecretaria = selectorTipo.value === 'SECRETARIA';
        const mostrarBusquedaAutomatica = ['TODOS', 'EMPRESAS', 'INSTITUCIONES'].includes(selectorTipo.value);

        if (esSecretaria) {
            selectorMunicipio.value = '0';
        }

        selectorMunicipio.disabled = esSecretaria;

        if (opcionMunicipioDefault) {
            opcionMunicipioDefault.textContent = esSecretaria
                ? 'No aplica - cobertura estatal'
                : 'Todos los municipios';
        }

        botonBusquedaAutomatica?.classList.toggle('d-none', !mostrarBusquedaAutomatica);
    };

    const claseOportunidad = function (nivel) {
        const normalizado = String(nivel || '').toLowerCase();

        if (normalizado === 'alta') {
            return 'linkage-opportunity-high';
        }

        if (normalizado === 'media') {
            return 'linkage-opportunity-medium';
        }

        return 'linkage-opportunity-review';
    };

    const mostrarAlerta = function (elemento, mensaje, tipo, reintentar = false) {
        if (!elemento) {
            return;
        }

        if (!mensaje) {
            elemento.classList.add('d-none');
            elemento.textContent = '';
            return;
        }

        elemento.classList.remove('d-none');
        elemento.classList.toggle('linkage-candidate-alert-error', tipo === 'error');
        elemento.innerHTML = reintentar
            ? '<span>' + limpiar(mensaje) + '</span>' +
                '<button type="button" class="btn linkage-candidate-retry" data-candidate-retry-denue>' +
                    'Reintentar' +
                '</button>'
            : limpiar(mensaje);
    };

    const mostrarEstadoBusqueda = function (mensaje) {
        if (!estadoBusqueda || !contenedorResultados) {
            return;
        }

        estadoBusqueda.textContent = mensaje;
        estadoBusqueda.classList.remove('d-none');
        contenedorResultados.classList.add('d-none');
        contenedorResultados.innerHTML = '';
    };

    const limpiarResultadosCandidatos = function () {
        candidatosActuales = [];
        hayMasCandidatos = false;
        totalResultadosCandidatos = 0;
        resultadosPorPaginaCandidatos = 10;
        busquedaAutomaticaActiva = false;
        mostrarAlerta(alertaBusqueda, '', '');
        actualizarPaginacion();
        mostrarEstadoBusqueda(
            'Escribe al menos 3 caracteres o utiliza Buscar candidatos automáticamente.'
        );
        resetearScrollResultados();
    };

    const resetearScrollResultados = function (suave = false) {
        if (!contenedorResultados) {
            return;
        }

        if (typeof contenedorResultados.scrollTo === 'function') {
            contenedorResultados.scrollTo({
                top: 0,
                behavior: suave ? 'smooth' : 'auto'
            });
            return;
        }

        contenedorResultados.scrollTop = 0;
    };

    const requiereTextoDenue = function (automatico) {
        return !automatico &&
            ['TODOS', 'EMPRESAS', 'INSTITUCIONES'].includes(selectorTipo?.value || '') &&
            (campoBusqueda?.value.trim().length || 0) < 3;
    };

    const obtenerClaveCacheCandidatos = function () {
        return estadoId + '|' + (selectorMunicipio?.value || '0');
    };

    const normalizarBusquedaCandidato = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const guardarCacheCandidatos = function (candidatos) {
        if (!Array.isArray(candidatos) || candidatos.length === 0) {
            return;
        }

        const claveCache = obtenerClaveCacheCandidatos();
        const mapaCandidatos = {};

        (cacheCandidatosAutomaticos[claveCache] || []).concat(candidatos).forEach(function (candidato) {
            const clave = candidato.clave_origen || candidato.id_origen || candidato.nombre;

            if (clave) {
                mapaCandidatos[clave] = candidato;
            }
        });

        cacheCandidatosAutomaticos[claveCache] = Object.values(mapaCandidatos);
    };

    const esCandidatoCacheInstitucional = function (candidato) {
        const tipo = String(candidato.tipo_entidad || '').toUpperCase();
        const sector = String(candidato.sector || '');
        const texto = normalizarBusquedaCandidato([
            candidato.nombre || '',
            candidato.actividad || ''
        ].join(' '));
        const palabrasInstitucionales = [
            'institut',
            'universidad',
            'colegio',
            'escuela',
            'educacion',
            'hospital',
            'clinica',
            'salud',
            'asistencia',
            'gobierno',
            'publica',
            'secretaria',
            'asociacion',
            'camara',
            'fundacion'
        ];

        if (tipo === 'INSTITUCION' || ['61', '62', '81', '93'].includes(sector)) {
            return true;
        }

        return palabrasInstitucionales.some(function (palabra) {
            return texto.includes(palabra);
        });
    };

    const filtrarCachePorTipo = function (candidatos) {
        const tipoActual = selectorTipo?.value || 'TODOS';

        if (tipoActual === 'INSTITUCIONES') {
            return candidatos.filter(esCandidatoCacheInstitucional);
        }

        if (tipoActual === 'EMPRESAS') {
            return candidatos.filter(function (candidato) {
                return !esCandidatoCacheInstitucional(candidato);
            });
        }

        return candidatos;
    };

    const obtenerCoincidenciasCache = function (termino) {
        const terminoNormalizado = normalizarBusquedaCandidato(termino);
        const candidatos = filtrarCachePorTipo(
            cacheCandidatosAutomaticos[obtenerClaveCacheCandidatos()] || []
        );

        if (terminoNormalizado.length < 3 || candidatos.length === 0) {
            return [];
        }

        return candidatos.filter(function (candidato) {
            const textoBusqueda = normalizarBusquedaCandidato([
                candidato.nombre || '',
                candidato.razon_social || '',
                candidato.actividad || ''
            ].join(' '));

            return textoBusqueda.includes(terminoNormalizado);
        });
    };

    const renderizarCoincidenciasCache = function (coincidencias) {
        const porPagina = Math.max(1, resultadosPorPaginaCandidatos || 10);
        const inicio = (paginaCandidatos - 1) * porPagina;

        candidatosActuales = coincidencias.slice(inicio, inicio + porPagina);
        hayMasCandidatos = coincidencias.length > (inicio + porPagina);
        totalResultadosCandidatos = coincidencias.length;
        renderizarCandidatos();
        actualizarPaginacion();
        resetearScrollResultados();
    };

    const contactoDisponible = function (candidato) {
        const datos = [];

        if (candidato.telefono) {
            datos.push('Teléfono');
        }

        if (candidato.correo) {
            datos.push('Correo');
        }

        if (candidato.sitio_web) {
            datos.push('Sitio web');
        }

        return datos.length > 0 ? datos.join(' · ') : 'Sin contacto';
    };

    const separarContextoSecretaria = function (contexto) {
        const partes = String(contexto || '').split(' · ');

        return {
            titular: textoPlano(partes[0] || '', 'No registrado'),
            cargo: textoPlano(partes.slice(1).join(' · '), 'Titular de la dependencia')
        };
    };

    const construirTarjetaSecretaria = function (candidato, boton, oportunidad) {
        const contexto = separarContextoSecretaria(candidato.contexto);

        return '<div class="linkage-candidate-main">' +
                '<div class="linkage-candidate-badges">' +
                    '<span class="linkage-source-badge">Secretaría estatal</span>' +
                    oportunidad +
                '</div>' +
                '<strong>' + limpiar(candidato.nombre) + '</strong>' +
                '<p>' + limpiar(candidato.actividad || 'Administración pública estatal') + '</p>' +
                '<small>Cobertura estatal</small>' +
            '</div>' +
            '<div class="linkage-candidate-secretary-holder">' +
                '<span>Titular</span>' +
                '<strong>' + limpiar(contexto.titular) + '</strong>' +
                '<small>' + limpiar(contexto.cargo) + '</small>' +
            '</div>' +
            '<div class="linkage-candidate-secretary-actions">' +
                '<span>Contacto</span>' +
                '<strong>' + limpiar(contactoDisponible(candidato)) + '</strong>' +
                boton +
            '</div>';
    };

    const construirMetaCandidato = function (candidato) {
        const tipo = '<div><span>Tipo</span><strong>' +
            limpiar(etiquetaTipoVisual(candidato)) +
            '</strong></div>';
        const contacto = '<div><span>Contacto</span><strong>' +
            limpiar(contactoDisponible(candidato)) +
            '</strong></div>';

        if (candidato.origen === 'SECRETARIA') {
            return tipo +
                '<div><span>Cobertura</span><strong>Estatal</strong></div>' +
                '<div><span>Titular</span><strong>' +
                    limpiar(candidato.contexto || 'No registrado') +
                '</strong></div>' +
                contacto;
        }

        return tipo +
            '<div><span>Municipio</span><strong>' +
                limpiar(candidato.municipio_nombre || 'Estatal') +
            '</strong></div>' +
            '<div><span>Estrato</span><strong>' +
                limpiar(candidato.estrato_etiqueta || 'No disponible') +
            '</strong></div>' +
            contacto;
    };

    const renderizarCandidatos = function () {
        if (!contenedorResultados || !estadoBusqueda) {
            return;
        }

        if (candidatosActuales.length === 0) {
            mostrarEstadoBusqueda(mensajeResultadosVacios);
            resetearScrollResultados();
            return;
        }

        estadoBusqueda.classList.add('d-none');
        contenedorResultados.classList.remove('d-none');
        contenedorResultados.innerHTML = '';

        candidatosActuales.forEach(function (candidato, indice) {
            const tarjeta = document.createElement('article');
            tarjeta.className = 'linkage-candidate-card';
            tarjeta.classList.toggle('linkage-candidate-card-secretary', candidato.origen === 'SECRETARIA');

            const boton = candidato.seguimiento_existente
                ? '<a class="btn btn-system-light linkage-candidate-start" href="' +
                    limpiar(candidato.seguimiento_url || '#') +
                    '">' +
                    (candidato.seguimiento_activo ? 'Ver seguimiento' : 'Ya registrado') +
                    '</a>'
                : '<button type="button" class="btn btn-system-save linkage-candidate-start" data-candidate-index="' +
                    indice +
                    '">Iniciar seguimiento</button>';
            const oportunidad = candidato.oportunidad_etiqueta
                ? '<span class="linkage-opportunity-badge ' +
                    claseOportunidad(candidato.oportunidad_nivel) +
                    '">' +
                    limpiar(candidato.oportunidad_etiqueta) +
                    '</span>'
                : '';

            if (candidato.origen === 'SECRETARIA') {
                tarjeta.innerHTML = construirTarjetaSecretaria(candidato, boton, oportunidad);
                contenedorResultados.appendChild(tarjeta);
                return;
            }

            tarjeta.innerHTML =
                '<div class="linkage-candidate-main">' +
                    '<div class="linkage-candidate-badges">' +
                        '<span class="linkage-source-badge">' + limpiar(etiquetaBadgeCandidato(candidato)) + '</span>' +
                        oportunidad +
                    '</div>' +
                    '<strong>' + limpiar(candidato.nombre) + '</strong>' +
                    '<p>' + limpiar(candidato.actividad || candidato.contexto || 'Información por verificar') + '</p>' +
                    (candidato.contexto
                        ? '<small>' + limpiar(candidato.contexto) + '</small>'
                        : '') +
                    (candidato.recomendacion
                        ? '<em class="linkage-candidate-reason">' + limpiar(candidato.recomendacion) + '</em>'
                        : '') +
                '</div>' +
                '<div class="linkage-candidate-meta">' +
                    construirMetaCandidato(candidato) +
                '</div>' +
                '<div class="linkage-candidate-card-action">' +
                    boton +
                '</div>';

            contenedorResultados.appendChild(tarjeta);
        });
    };

    const actualizarPaginacion = function () {
        if (!paginacion || !etiquetaPagina || !botonAnterior || !botonSiguiente) {
            return;
        }

        const totalResultados = Math.max(0, Number(totalResultadosCandidatos) || 0);
        const porPagina = Math.max(1, Number(resultadosPorPaginaCandidatos) || 10);
        const totalPaginas = Math.max(1, Math.ceil(totalResultados / porPagina));
        const paginaActual = Math.min(Math.max(1, paginaCandidatos), totalPaginas);
        const inicio = totalResultados === 0 ? 0 : ((paginaActual - 1) * porPagina) + 1;
        const fin = Math.min(paginaActual * porPagina, totalResultados);

        paginacion.classList.toggle('d-none', totalResultados === 0);
        etiquetaPagina.textContent = totalResultados + ' resultados · Mostrando ' +
            inicio + '–' + fin + ' · Página ' + paginaActual + ' de ' + totalPaginas;
        botonAnterior.classList.toggle('disabled', paginaCandidatos === 1);
        botonSiguiente.classList.toggle('disabled', paginaActual >= totalPaginas || !hayMasCandidatos);
    };

    const buscarCandidatos = async function (automatico = false, suave = false) {
        if (!campoBusqueda || !selectorTipo || !selectorMunicipio) {
            return;
        }

        busquedaAutomaticaActiva = automatico;
        const termino = campoBusqueda.value.trim();
        const coincidenciasCache = (!automatico && selectorTipo.value !== 'SECRETARIA')
            ? obtenerCoincidenciasCache(termino)
            : [];
        const hayCoincidenciasCache = coincidenciasCache.length > 0;

        mostrarAlerta(alertaBusqueda, '', '');

        if (requiereTextoDenue(automatico)) {
            candidatosActuales = [];
            hayMasCandidatos = false;
            totalResultadosCandidatos = 0;
            actualizarPaginacion();
            mostrarEstadoBusqueda('Escribe al menos 3 caracteres o usa la búsqueda automática.');
            resetearScrollResultados();
            return;
        }

        if (hayCoincidenciasCache) {
            renderizarCoincidenciasCache(coincidenciasCache);
        }

        if (controladorBusqueda) {
            controladorBusqueda.abort();
        }

        controladorBusqueda = new AbortController();
        secuenciaBusqueda += 1;
        const secuenciaActual = secuenciaBusqueda;

        if (!hayCoincidenciasCache) {
            mostrarEstadoBusqueda(
                automatico
                    ? 'Buscando candidatos recomendados...'
                    : 'Buscando candidatos...'
            );
            totalResultadosCandidatos = 0;
            hayMasCandidatos = false;
            actualizarPaginacion();
            resetearScrollResultados();
        }

        const parametros = new URLSearchParams({
            estado_id: estadoId,
            buscar: termino,
            tipo_candidato: selectorTipo.value,
            municipio_id: selectorMunicipio.value,
            pagina: String(paginaCandidatos),
            automatico: automatico ? '1' : '0'
        });

        try {
            const respuesta = await fetch(urlBuscar + '&' + parametros.toString(), {
                headers: {
                    'X-Requested-With': 'fetch'
                },
                signal: controladorBusqueda.signal
            });
            const datos = await respuesta.json();

            if (secuenciaActual !== secuenciaBusqueda) {
                return;
            }

            if (!datos.ok) {
                throw new Error(datos.mensaje || 'No fue posible buscar candidatos.');
            }

            const candidatosRespuesta = Array.isArray(datos.candidatos) ? datos.candidatos : [];

            if (hayCoincidenciasCache && candidatosRespuesta.length === 0) {
                if (Array.isArray(datos.advertencias) && datos.advertencias.length > 0) {
                    mostrarAlerta(alertaBusqueda, datos.advertencias.join(' '), 'warning');
                }
                return;
            }

            candidatosActuales = candidatosRespuesta;
            hayMasCandidatos = Boolean(datos.hay_mas);
            paginaCandidatos = Math.max(1, Number(datos.pagina) || paginaCandidatos);
            totalResultadosCandidatos = Number(datos.total_resultados) || 0;
            resultadosPorPaginaCandidatos = Number(datos.resultados_por_pagina) || 10;
            mensajeResultadosVacios = datos.mensaje_vacio ||
                'No se encontraron candidatos con los criterios seleccionados.';

            if (automatico) {
                guardarCacheCandidatos(datos.cache_candidatos || datos.candidatos);
            }

            if (Array.isArray(datos.advertencias) && datos.advertencias.length > 0) {
                mostrarAlerta(
                    alertaBusqueda,
                    datos.advertencias.join(' '),
                    'warning',
                    automatico && Boolean(datos.denue_solicitado)
                );
            }

            renderizarCandidatos();
            actualizarPaginacion();
            resetearScrollResultados(suave);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            if (secuenciaActual !== secuenciaBusqueda) {
                return;
            }

            if (hayCoincidenciasCache) {
                mostrarAlerta(
                    alertaBusqueda,
                    'No se pudieron obtener resultados adicionales de DENUE.',
                    'warning'
                );
                return;
            }

            candidatosActuales = [];
            hayMasCandidatos = false;
            totalResultadosCandidatos = 0;
            actualizarPaginacion();
            mostrarEstadoBusqueda('No fue posible consultar candidatos en este momento.');
            mostrarAlerta(alertaBusqueda, error.message, 'error');
            resetearScrollResultados();
        }
    };

    const programarBusqueda = function () {
        window.clearTimeout(temporizadorCandidatos);
        temporizadorCandidatos = window.setTimeout(function () {
            paginaCandidatos = 1;
            buscarCandidatos(false);
        }, 550);
    };

    if (formularioBusqueda) {
        formularioBusqueda.addEventListener('submit', function (event) {
            event.preventDefault();
            paginaCandidatos = 1;
            buscarCandidatos(false);
        });
    }

    campoBusqueda?.addEventListener('input', programarBusqueda);

    [selectorTipo, selectorMunicipio].forEach(function (selector) {
        selector?.addEventListener('change', function () {
            actualizarEstadoFiltrosCandidato();
            paginaCandidatos = 1;

            if (selectorTipo?.value === 'SECRETARIA') {
                buscarCandidatos(false);
                return;
            }

            limpiarResultadosCandidatos();
        });
    });

    botonBusquedaAutomatica?.addEventListener('click', function () {
        actualizarEstadoFiltrosCandidato();
        paginaCandidatos = 1;
        buscarCandidatos(true);
    });

    alertaBusqueda?.addEventListener('click', function (event) {
        if (!event.target.closest('[data-candidate-retry-denue]')) {
            return;
        }

        paginaCandidatos = 1;
        buscarCandidatos(true);
    });

    actualizarEstadoFiltrosCandidato();

    botonAnterior?.addEventListener('click', function (event) {
        event.preventDefault();

        if (paginaCandidatos <= 1) {
            return;
        }

        paginaCandidatos -= 1;
        buscarCandidatos(busquedaAutomaticaActiva, true);
    });

    botonSiguiente?.addEventListener('click', function (event) {
        event.preventDefault();

        if (!hayMasCandidatos) {
            return;
        }

        paginaCandidatos += 1;
        buscarCandidatos(busquedaAutomaticaActiva, true);
    });

    contenedorResultados?.addEventListener('click', function (event) {
        const boton = event.target.closest('[data-candidate-index]');

        if (!boton || !modalConfirmar) {
            return;
        }

        const candidato = candidatosActuales[parseInt(boton.dataset.candidateIndex || '0', 10)];

        if (!candidato) {
            return;
        }

        mostrarAlerta(alertaConfirmar, '', '');
        formularioConfirmar?.reset();
        const campoOrigen = formularioConfirmar?.querySelector('[data-confirm-origin]');
        const campoClave = formularioConfirmar?.querySelector('[data-confirm-key]');

        if (campoOrigen) {
            campoOrigen.value = candidato.origen;
        }

        if (campoClave) {
            campoClave.value = candidato.clave_origen;
        }
        document.querySelector('[data-confirm-name]').textContent = textoPlano(candidato.nombre);
        document.querySelector('[data-confirm-source]').textContent =
            textoPlano(etiquetaBadgeCandidato(candidato));
        document.querySelector('[data-confirm-municipality]').textContent =
            textoPlano(candidato.municipio_nombre || 'Estatal');
        document.querySelector('[data-confirm-phone]').textContent =
            textoPlano(candidato.telefono || 'Sin teléfono');
        document.querySelector('[data-confirm-email]').textContent =
            textoPlano(candidato.correo || 'Sin correo');

        bootstrap.Modal.getOrCreateInstance(modalConfirmar).show();
    });

    formularioConfirmar?.addEventListener('submit', async function (event) {
        event.preventDefault();
        mostrarAlerta(alertaConfirmar, '', '');

        if (botonConfirmar) {
            botonConfirmar.disabled = true;
            botonConfirmar.textContent = 'Iniciando...';
        }

        try {
            const respuesta = await fetch(urlCrear, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'fetch'
                },
                body: new FormData(formularioConfirmar)
            });
            const datos = await respuesta.json();

            if (!datos.ok) {
                if (datos.duplicado && datos.url) {
                    mostrarAlerta(
                        alertaConfirmar,
                        datos.mensaje + ' Puedes abrir el seguimiento existente.',
                        'error'
                    );
                    return;
                }

                throw new Error(datos.mensaje || 'No fue posible iniciar el seguimiento.');
            }

            bootstrap.Modal.getInstance(modalConfirmar)?.hide();
            bootstrap.Modal.getInstance(modalBuscar)?.hide();

            if (toast) {
                toast.textContent = datos.mensaje || 'Seguimiento iniciado correctamente.';
                toast.classList.remove('d-none');
            }

            window.setTimeout(function () {
                window.location.reload();
            }, 900);
        } catch (error) {
            mostrarAlerta(alertaConfirmar, error.message, 'error');
        } finally {
            if (botonConfirmar) {
                botonConfirmar.disabled = false;
                botonConfirmar.textContent = 'Confirmar e iniciar seguimiento';
            }
        }
    });
});
</script>
