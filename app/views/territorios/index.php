<?php

$estados = $estados ?? [];
$resumenTerritorial = $resumenTerritorial ?? [];
$cuentasClaveFiltro = $cuentasClaveFiltro ?? [];
$analistasFiltro = $analistasFiltro ?? [];
$filtros = $filtros ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';

$puedeEditarTerritorio = tienePermiso('territorios.actualizar_ficha');
$puedeAsignarTerritorio = tienePermiso('territorios.asignar');

$buscarActual = $filtros['buscar'] ?? '';
$cuentaClaveActual = (string)(
    $filtros['cuenta_clave_filtro'] ?? $filtros['cuenta_clave'] ?? ''
);
$analistaActual = (string)(
    $filtros['analista_filtro'] ?? $filtros['analista'] ?? ''
);
$estadoAsignacionActual = (string)($filtros['estado_asignacion'] ?? '');
$hayFiltros =
    $buscarActual !== '' ||
    $cuentaClaveActual !== '' ||
    $analistaActual !== '' ||
    $estadoAsignacionActual !== '';

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$seleccionado = function ($actual, $valor) {
    return (string)$actual === (string)$valor ? 'selected' : '';
};

$nombreUsuario = function ($usuario) use ($texto) {
    return $texto(trim($usuario['nombre'] . ' ' . $usuario['apellidos']));
};

$valorForm = function ($campo) use ($datosFormulario, $texto) {
    return $texto($datosFormulario[$campo] ?? '');
};

?>

<section class="territory-summary-grid" id="territoriosResumen">
    <article class="metric-card territory-summary-card">
        <span
            class="metric-icon"
            title="Total de estados activos registrados en el sistema."
            aria-label="Total de estados activos registrados en el sistema."
            tabindex="0"
            data-bs-toggle="tooltip">
            <i class="bi bi-map"></i>
        </span>

        <div>
            <p class="metric-value" data-summary-field="estados_registrados">
                <?= (int)($resumenTerritorial['estados_registrados'] ?? 0) ?>
            </p>
            <p class="metric-label">Estados registrados</p>
        </div>
    </article>

    <article class="metric-card territory-summary-card">
        <span
            class="metric-icon"
            title="Estados con al menos una Cuenta Clave activa."
            aria-label="Estados con al menos una Cuenta Clave activa."
            tabindex="0"
            data-bs-toggle="tooltip">
            <i class="bi bi-person-badge"></i>
        </span>

        <div>
            <p class="metric-value" data-summary-field="con_cuenta_clave">
                <?= (int)($resumenTerritorial['con_cuenta_clave'] ?? 0) ?>
            </p>
            <p class="metric-label">Con Cuenta Clave</p>
        </div>
    </article>

    <article class="metric-card territory-summary-card">
        <span
            class="metric-icon metric-icon-success"
            title="Estados con al menos un Analista de Datos activo."
            aria-label="Estados con al menos un Analista de Datos activo."
            tabindex="0"
            data-bs-toggle="tooltip">
            <i class="bi bi-person-check"></i>
        </span>

        <div>
            <p class="metric-value" data-summary-field="con_analista">
                <?= (int)($resumenTerritorial['con_analista'] ?? 0) ?>
            </p>
            <p class="metric-label">Con Analista</p>
        </div>
    </article>

    <article class="metric-card territory-summary-card">
        <span
            class="metric-icon metric-icon-muted"
            title="Estados que todavía no tienen una Cuenta Clave activa."
            aria-label="Estados que todavía no tienen una Cuenta Clave activa."
            tabindex="0"
            data-bs-toggle="tooltip">
            <i class="bi bi-geo"></i>
        </span>

        <div>
            <p class="metric-value" data-summary-field="sin_cuenta_clave">
                <?= (int)($resumenTerritorial['sin_cuenta_clave'] ?? 0) ?>
            </p>
            <p class="metric-label">Sin Cuenta Clave</p>
        </div>
    </article>
</section>

<section class="dashboard-panel users-module-panel territory-toolbar-panel mt-4">
    <form
        id="territoriosFiltrosForm"
        class="module-toolbar territory-toolbar"
        action="<?= BASE_URL ?>index.php"
        method="GET">

        <input type="hidden" name="controller" value="territorio">
        <input type="hidden" name="action" value="index">

        <div class="module-search">
            <i class="bi bi-search"></i>

            <input
                type="search"
                class="form-control"
                name="buscar"
                value="<?= $texto($buscarActual) ?>"
                placeholder="Buscar estado..."
                aria-label="Buscar estado">
        </div>

        <div class="module-filters territory-filters">
            <select
                class="form-select"
                name="cuenta_clave"
                aria-label="Filtrar por Cuenta Clave">
                <option value="">Cuenta Clave</option>
                <option
                    value="con_cuenta_clave"
                    <?= $seleccionado($cuentaClaveActual, 'con_cuenta_clave') ?>>
                    Con Cuenta Clave
                </option>
                <option
                    value="sin_cuenta_clave"
                    <?= $seleccionado($cuentaClaveActual, 'sin_cuenta_clave') ?>>
                    Sin Cuenta Clave
                </option>

                <?php foreach ($cuentasClaveFiltro as $usuario): ?>
                    <option
                        value="<?= (int)$usuario['id'] ?>"
                        <?= $seleccionado($cuentaClaveActual, $usuario['id']) ?>>
                        <?= $nombreUsuario($usuario) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                class="form-select"
                name="analista"
                aria-label="Filtrar por Analista">
                <option value="">Analista</option>
                <option
                    value="con_analista"
                    <?= $seleccionado($analistaActual, 'con_analista') ?>>
                    Con Analista
                </option>
                <option
                    value="sin_analista"
                    <?= $seleccionado($analistaActual, 'sin_analista') ?>>
                    Sin Analista
                </option>

                <?php foreach ($analistasFiltro as $usuario): ?>
                    <option
                        value="<?= (int)$usuario['id'] ?>"
                        <?= $seleccionado($analistaActual, $usuario['id']) ?>>
                        <?= $nombreUsuario($usuario) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                class="form-select"
                name="estado_asignacion"
                aria-label="Filtrar por estado de asignación">
                <option value="">Todos</option>
                <option
                    value="con_cuenta_clave"
                    <?= $seleccionado($estadoAsignacionActual, 'con_cuenta_clave') ?>>
                    Con Cuenta Clave
                </option>
                <option
                    value="sin_cuenta_clave"
                    <?= $seleccionado($estadoAsignacionActual, 'sin_cuenta_clave') ?>>
                    Sin Cuenta Clave
                </option>
                <option
                    value="con_analista"
                    <?= $seleccionado($estadoAsignacionActual, 'con_analista') ?>>
                    Con Analista
                </option>
                <option
                    value="sin_analista"
                    <?= $seleccionado($estadoAsignacionActual, 'sin_analista') ?>>
                    Sin Analista
                </option>
                <option
                    value="varias_cuenta_clave"
                    <?= $seleccionado($estadoAsignacionActual, 'varias_cuenta_clave') ?>>
                    Más de una Cuenta Clave
                </option>
            </select>
        </div>

        <div class="module-toolbar-actions">
            <a
                class="filter-clear-link <?= $hayFiltros ? '' : 'd-none' ?>"
                id="limpiarFiltrosTerritorios"
                href="<?= BASE_URL ?>index.php?controller=territorio&action=index">
                Limpiar filtros
            </a>

            <noscript>
                <button
                    type="submit"
                    class="btn btn-filter-submit">
                    <i class="bi bi-funnel me-2"></i>
                    Aplicar
                </button>
            </noscript>
        </div>
    </form>
</section>

<section class="dashboard-panel users-list-panel territories-list-panel mt-4">
    <div class="table-panel-header">
        <h2 class="panel-title mb-0">
            Estados registrados
        </h2>

        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php if ($puedeAsignarTerritorio): ?>
                <button type="button" class="btn btn-system-save territory-bulk-launch" data-bs-toggle="modal"
                    data-bs-target="#modalAsignacionMasiva">
                    <i class="bi bi-person-plus me-2"></i>Asignar
                </button>
            <?php endif; ?>
            <span class="territory-count"><?= count($estados) ?> resultados</span>
        </div>
    </div>

    <div id="territoriosTablaContenido">
        <?php require __DIR__ . '/tabla.php'; ?>
    </div>
</section>

<div
    class="offcanvas offcanvas-end user-detail-panel territory-detail-panel"
    tabindex="-1"
    id="offcanvasDetalleTerritorio"
    aria-labelledby="offcanvasDetalleTerritorioTitulo">

    <div class="offcanvas-header user-detail-header">
        <div>
            <h5 class="user-detail-title" id="offcanvasDetalleTerritorioTitulo">
                Detalle del territorio
            </h5>

            <p class="user-detail-subtitle">
                Equipo territorial e información general
            </p>
        </div>

        <button
            type="button"
            class="btn-close user-detail-close"
            data-bs-dismiss="offcanvas"
            aria-label="Cerrar">
        </button>
    </div>

    <div class="offcanvas-body user-detail-body" id="territorioDetalleContenido">
        <div class="territory-loading">
            Consultando territorio...
        </div>
    </div>
</div>

<?php if ($puedeAsignarTerritorio): ?>

    <div class="modal fade" id="modalAsignacionMasiva" tabindex="-1"
        aria-labelledby="modalAsignacionMasivaTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable system-form-dialog territory-bulk-dialog">
            <div class="modal-content system-form-modal territory-bulk-content">
                <form id="formAsignacionMasiva" class="territory-bulk-form"
                    action="<?= BASE_URL ?>index.php?controller=territorio&amp;action=guardarAsignacionMasiva" method="post">
                    <div class="modal-header system-form-modal-header territory-bulk-header">
                        <div>
                            <h5 class="system-form-modal-title" id="modalAsignacionMasivaTitulo">Asignar a varios estados</h5>
                            <p class="system-form-modal-subtitle">Elige los estados y agrega solo las personas necesarias.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body territory-bulk-body">
                        <input type="hidden" name="csrf_token" value="<?= $texto($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="territory-bulk-states">
                            <div class="territory-bulk-states-copy">
                                <h6>Estados</h6>
                                <p>Selecciona uno o varios estados para esta asignación.</p>
                            </div>
                            <div class="territory-bulk-states-picker">
                                <button type="button" class="form-select text-start" id="masivaAbrirEstados"
                                    aria-expanded="false" aria-controls="masivaListaEstados">Seleccionar estados</button>
                                <div id="masivaListaEstados" class="territory-bulk-options" hidden>
                                    <button type="button" class="btn btn-territory-expand territory-bulk-select-all" id="masivaSeleccionarTodos">Seleccionar todos</button>
                                    <div id="masivaEstados" class="territory-bulk-options-list">
                                        <span class="text-muted">Cargando estados...</span>
                                    </div>
                                </div>
                                <div id="masivaEstadosElegidos" class="territory-bulk-chips" aria-live="polite"></div>
                            </div>
                        </div>
                        <?php foreach (['CUENTA_CLAVE' => 'Cuenta Clave', 'ANALISTA_DATOS' => 'Analista', 'ASESOR' => 'Asesor'] as $tipoMasivo => $nombreMasivo): ?>
                            <section class="territory-bulk-section" data-masiva-seccion="<?= $tipoMasivo ?>">
                                <h6 class="territory-bulk-section-title"><?= $tipoMasivo === 'CUENTA_CLAVE' ? 'Cuenta clave' : ($tipoMasivo === 'ANALISTA_DATOS' ? 'Analistas' : 'Asesores') ?></h6>
                                <div data-masiva-personas></div>
                                <button type="button" class="btn btn-territory-expand" data-masiva-agregar>
                                    <i class="bi bi-plus-circle me-2"></i>Agregar <?= $nombreMasivo ?>
                                </button>
                            </section>
                        <?php endforeach; ?>
                        <section id="masivaCuentas" class="territory-bulk-section d-none"></section>
                        <div id="masivaError" class="text-danger small mt-2" role="alert"></div>
                    </div>
                    <div class="modal-footer territory-bulk-footer">
                        <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-system-save">Guardar asignación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="modalEquipoTerritorial"
        tabindex="-1"
        aria-labelledby="modalEquipoTerritorialTitulo"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable system-form-dialog territory-team-dialog">
            <div class="modal-content system-form-modal">
                <div class="modal-header system-form-modal-header">
                    <div>
                        <h5 class="system-form-modal-title" id="modalEquipoTerritorialTitulo">
                            Gestionar equipo territorial
                        </h5>

                        <p class="system-form-modal-subtitle" id="equipoTerritorioNombre">
                            Selecciona un territorio
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Cerrar">
                    </button>
                </div>

                <div class="modal-body territory-team-modal-body" id="equipoTerritorialContenido">
                    <div class="territory-loading">
                        Consultando equipo territorial...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="modalFinalizarAsignacion"
        tabindex="-1"
        aria-labelledby="modalFinalizarAsignacionTitulo"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
            <div class="modal-content system-form-modal">
                <div class="modal-header system-form-modal-header">
                    <div>
                        <h5 class="system-form-modal-title" id="modalFinalizarAsignacionTitulo">
                            Finalizar asignación
                        </h5>

                        <p class="system-form-modal-subtitle">
                            Conserva el registro en historial territorial
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Cerrar">
                    </button>
                </div>

                <form
                    id="finalizarAsignacionForm"
                    action="<?= BASE_URL ?>index.php?controller=territorio&action=finalizarAsignacion"
                    method="POST">

                    <input type="hidden" id="finalizar_asignacion_id" name="asignacion_id">

                    <div class="modal-body">
                        <p class="confirm-text">
                            <span id="finalizar_asignacion_accion">¿Deseas finalizar la asignación de</span>
                            <strong id="finalizar_asignacion_nombre">este usuario</strong>?
                        </p>

                        <div
                            class="territory-confirm-team d-none"
                            id="finalizar_equipo_confirmacion">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="finalizar_equipo"
                                    name="finalizar_equipo"
                                    value="1">
                                <label class="form-check-label" for="finalizar_equipo">
                                    Finalizar también al analista activo.
                                </label>
                                <div
                                    class="invalid-feedback d-block"
                                    data-field-error="finalizar_equipo"></div>
                            </div>
                            <p id="finalizar_equipo_mensaje">
                                Si no marcas esta opción, el analista permanecerá activo
                                y solo quedará sin Cuenta Clave asociada.
                            </p>
                        </div>

                        <div class="mt-3">
                            <label for="finalizar_fecha_fin" class="form-label">
                                Fecha de finalización
                            </label>
                            <input
                                type="date"
                                class="form-control"
                                id="finalizar_fecha_fin"
                                name="fecha_fin"
                                value="<?= date('Y-m-d') ?>">
                            <div class="invalid-feedback d-block" data-field-error="fecha_fin"></div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-system-cancel"
                            data-bs-dismiss="modal">
                            Cancelar
                        </button>

                        <button type="submit" class="btn btn-system-save" id="finalizar_asignacion_boton">
                            <i class="bi bi-check2-circle me-2"></i>
                            Finalizar asignación
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php if ($puedeEditarTerritorio): ?>

    <div
        class="modal fade"
        id="modalEditarTerritorio"
        tabindex="-1"
        aria-labelledby="modalEditarTerritorioTitulo"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
            <div class="modal-content system-form-modal">
                <div class="modal-header system-form-modal-header">
                    <div>
                        <h5 class="system-form-modal-title" id="modalEditarTerritorioTitulo">
                            Editar ficha territorial
                        </h5>

                        <p class="system-form-modal-subtitle">
                            Actualiza información de investigación del territorio
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Cerrar">
                    </button>
                </div>

                <form
                    action="<?= BASE_URL ?>index.php?controller=territorio&action=actualizarFichaTerritorial"
                    method="POST"
                    novalidate>

                    <input
                        type="hidden"
                        id="editar_estado_id"
                        name="id"
                        value="<?= $texto($datosFormulario['id'] ?? '') ?>">

                    <div class="modal-body">
                        <?php if ($modalAbierto === 'estado' && !empty($erroresFormulario)): ?>

                            <div class="alert alert-danger login-alert" role="alert">
                                <i class="bi bi-exclamation-circle"></i>

                                <div>
                                    <?php foreach ($erroresFormulario as $error): ?>
                                        <div><?= $texto($error) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php endif; ?>

                        <div class="territory-identity-box">
                            <div>
                                <span>Estado</span>
                                <strong id="editar_nombre_estado">
                                    <?= $valorForm('nombre') ?: 'No registrado' ?>
                                </strong>
                            </div>

                            <div>
                                <span>Clave INEGI</span>
                                <strong id="editar_clave_inegi">
                                    <?= $valorForm('clave_inegi') ?: 'No registrado' ?>
                                </strong>
                            </div>

                            <div>
                                <span>Nombre corto</span>
                                <strong id="editar_nombre_corto">
                                    <?= $valorForm('nombre_corto') ?: 'No registrado' ?>
                                </strong>
                            </div>
                        </div>

                        <div class="system-form-grid mt-3">

                            <div>
                                <label for="editar_capital" class="form-label">
                                    Capital
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_capital"
                                    name="capital"
                                    value="<?= $valorForm('capital') ?>">
                            </div>

                            <div>
                                <label for="editar_titular_gobierno" class="form-label">
                                    Titular del gobierno
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_titular_gobierno"
                                    name="titular_gobierno"
                                    value="<?= $valorForm('titular_gobierno') ?>">
                            </div>

                            <div>
                                <label for="editar_cargo_titular" class="form-label">
                                    Cargo
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_cargo_titular"
                                    name="cargo_titular"
                                    value="<?= $valorForm('cargo_titular') ?>">
                            </div>

                            <div>
                                <label for="editar_partido_politico" class="form-label">
                                    Partido político
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_partido_politico"
                                    name="partido_politico"
                                    value="<?= $valorForm('partido_politico') ?>">
                            </div>

                            <div>
                                <label for="editar_periodo_gobierno" class="form-label">
                                    Periodo de gobierno
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_periodo_gobierno"
                                    name="periodo_gobierno"
                                    value="<?= $valorForm('periodo_gobierno') ?>">
                            </div>

                            <div>
                                <label for="editar_poblacion" class="form-label">
                                    Población
                                </label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="editar_poblacion"
                                    name="poblacion"
                                    min="0"
                                    step="1"
                                    value="<?= $valorForm('poblacion') ?>">
                            </div>

                            <div>
                                <label for="editar_total_municipios" class="form-label">
                                    Total municipios
                                </label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="editar_total_municipios"
                                    name="total_municipios"
                                    min="0"
                                    step="1"
                                    value="<?= $valorForm('total_municipios') ?>">
                            </div>

                            <div>
                                <label for="editar_total_secretarias" class="form-label">
                                    Total secretarías
                                </label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="editar_total_secretarias"
                                    name="total_secretarias"
                                    min="0"
                                    step="1"
                                    value="<?= $valorForm('total_secretarias') ?>">
                            </div>

                            <div>
                                <label for="editar_telefono" class="form-label">
                                    Teléfono
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_telefono"
                                    name="telefono"
                                    value="<?= $valorForm('telefono') ?>">
                            </div>

                            <div>
                                <label for="editar_fuente" class="form-label">
                                    Fuente
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="editar_fuente"
                                    name="fuente"
                                    value="<?= $valorForm('fuente') ?>">
                            </div>

                            <div>
                                <label for="editar_fecha_actualizacion" class="form-label">
                                    Fecha de actualización
                                </label>
                                <input
                                    type="datetime-local"
                                    class="form-control"
                                    id="editar_fecha_actualizacion"
                                    name="fecha_actualizacion"
                                    value="<?= $valorForm('fecha_actualizacion') ?>">
                            </div>

                            <div class="detail-item-full">
                                <label for="editar_redes_sociales" class="form-label">
                                    Redes sociales
                                </label>
                                <textarea
                                    class="form-control"
                                    id="editar_redes_sociales"
                                    name="redes_sociales"
                                    rows="3"><?= $valorForm('redes_sociales') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-system-cancel"
                            data-bs-dismiss="modal">
                            Cancelar
                        </button>

                        <button type="submit" class="btn btn-system-save">
                            <i class="bi bi-check2-circle me-2"></i>
                            Guardar ficha
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if ($mensajeExito !== ''): ?>

        <div
            class="toast system-toast"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-bs-delay="3200">
            <div class="toast-body">
                <i class="bi bi-check2-circle"></i>
                <span><?= $texto($mensajeExito) ?></span>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($mensajeError !== ''): ?>

        <div
            class="toast system-toast system-toast-error"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-bs-delay="4200">
            <div class="toast-body">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= $texto($mensajeError) ?></span>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filtrosForm = document.getElementById('territoriosFiltrosForm');
    const tablaContenido = document.getElementById('territoriosTablaContenido');
    const resumenContenido = document.getElementById('territoriosResumen');
    const limpiarFiltros = document.getElementById('limpiarFiltrosTerritorios');
    const detallePanel = document.getElementById('offcanvasDetalleTerritorio');
    const detalleContenido = document.getElementById('territorioDetalleContenido');
    const modalEquipo = document.getElementById('modalEquipoTerritorial');
    const equipoContenido = document.getElementById('equipoTerritorialContenido');
    const modalFinalizar = document.getElementById('modalFinalizarAsignacion');
    const finalizarForm = document.getElementById('finalizarAsignacionForm');
    const modalEditar = document.getElementById('modalEditarTerritorio');
    const modalAbierto = '<?= $texto($modalAbierto) ?>';
    const baseUrl = '<?= BASE_URL ?>index.php';
    let temporizadorBusqueda = null;
    let consultaActual = null;
    let equipoEstadoId = '';
    let equipoEstadoNombre = '';
    let detalleEstadoId = '';

    const activarTooltips = function (contenedor) {
        const raiz = contenedor || document;

        raiz.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (elemento) {
            const existente = bootstrap.Tooltip.getInstance(elemento);

            if (existente) {
                existente.dispose();
            }

            new bootstrap.Tooltip(elemento);
        });
    };

    const crearParametros = function (action) {
        const parametros = new URLSearchParams(new FormData(filtrosForm));
        parametros.set('controller', 'territorio');
        parametros.set('action', action);

        return parametros;
    };

    const actualizarEnlaceLimpiar = function () {
        if (!filtrosForm || !limpiarFiltros) {
            return;
        }

        const datos = new FormData(filtrosForm);
        const hayFiltros =
            (datos.get('buscar') || '').trim() !== '' ||
            (datos.get('cuenta_clave') || '') !== '' ||
            (datos.get('analista') || '') !== '' ||
            (datos.get('estado_asignacion') || '') !== '';

        limpiarFiltros.classList.toggle('d-none', !hayFiltros);
    };

    const cargarTabla = async function () {
        if (!filtrosForm || !tablaContenido) {
            return;
        }

        if (consultaActual) {
            consultaActual.abort();
        }

        const controlador = new AbortController();
        consultaActual = controlador;
        tablaContenido.classList.add('table-loading');

        try {
            const parametros = crearParametros('tabla');
            const respuesta = await fetch(baseUrl + '?' + parametros.toString(), {
                headers: {
                    'X-Requested-With': 'fetch'
                },
                signal: controlador.signal
            });

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar territorios.');
            }

            tablaContenido.innerHTML = await respuesta.text();
            activarTooltips(tablaContenido);
            const contador = document.querySelector('.territory-count');

            if (contador) {
                contador.textContent =
                    tablaContenido.querySelectorAll('[data-territory-row]').length +
                    ' resultados';
            }

            const urlParametros = crearParametros('index');
            window.history.replaceState(
                null,
                '',
                baseUrl + '?' + urlParametros.toString()
            );
            actualizarEnlaceLimpiar();
        } catch (error) {
            if (error.name !== 'AbortError') {
                tablaContenido.innerHTML =
                    '<div class="empty-table-message">' +
                    'No fue posible actualizar el listado de territorios.' +
                    '</div>';
            }
        } finally {
            if (consultaActual === controlador) {
                tablaContenido.classList.remove('table-loading');
            }
        }
    };

    const cargarResumen = async function () {
        if (!resumenContenido) {
            return;
        }

        try {
            const parametros = new URLSearchParams();
            parametros.set('controller', 'territorio');
            parametros.set('action', 'resumen');

            const respuesta = await fetch(baseUrl + '?' + parametros.toString(), {
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar el resumen.');
            }

            const datos = await respuesta.json();

            if (!datos.ok || !datos.resumen) {
                return;
            }

            Object.keys(datos.resumen).forEach(function (campo) {
                const elemento = resumenContenido.querySelector(
                    '[data-summary-field="' + campo + '"]'
                );

                if (elemento) {
                    elemento.textContent = parseInt(datos.resumen[campo] || 0, 10);
                }
            });
        } catch (error) {
            return;
        }
    };

    const cargarDetalle = async function (estadoId) {
        if (!detallePanel || !detalleContenido) {
            return;
        }

        detalleEstadoId = estadoId;
        detalleContenido.innerHTML =
            '<div class="territory-loading">Consultando territorio...</div>';

        const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(detallePanel);
        offcanvas.show();

        try {
            const parametros = new URLSearchParams();
            parametros.set('controller', 'territorio');
            parametros.set('action', 'detalle');
            parametros.set('id', estadoId);

            const respuesta = await fetch(baseUrl + '?' + parametros.toString(), {
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar el detalle.');
            }

            detalleContenido.innerHTML = await respuesta.text();
            activarTooltips(detalleContenido);
        } catch (error) {
            detalleContenido.innerHTML =
                '<div class="territory-loading">' +
                'No fue posible consultar el territorio.' +
                '</div>';
        }
    };

    const cargarEquipoTerritorial = async function (estadoId, estadoNombre) {
        if (!modalEquipo || !equipoContenido) {
            return;
        }

        equipoEstadoId = estadoId;
        equipoEstadoNombre = estadoNombre || equipoEstadoNombre;

        document.getElementById('equipoTerritorioNombre').textContent =
            equipoEstadoNombre || 'Selecciona un territorio';
        equipoContenido.innerHTML =
            '<div class="territory-loading">Consultando equipo territorial...</div>';

        const modal = bootstrap.Modal.getOrCreateInstance(modalEquipo);
        modal.show();

        try {
            const parametros = new URLSearchParams();
            parametros.set('controller', 'territorio');
            parametros.set('action', 'equipo');
            parametros.set('id', estadoId);

            const respuesta = await fetch(baseUrl + '?' + parametros.toString(), {
                headers: {
                    'X-Requested-With': 'fetch'
                }
            });

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar el equipo.');
            }

            equipoContenido.innerHTML = await respuesta.text();
            activarTooltips(equipoContenido);
        } catch (error) {
            equipoContenido.innerHTML =
                '<div class="territory-loading">' +
                'No fue posible consultar el equipo territorial.' +
                '</div>';
        }
    };

    const mostrarToast = function (mensaje, esError) {
        const contenedor = document.querySelector('.toast-container');

        if (!contenedor) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'toast system-toast' +
            (esError ? ' system-toast-error' : '');
        toast.setAttribute('role', esError ? 'alert' : 'status');
        toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.dataset.bsDelay = esError ? '4200' : '3200';
        toast.innerHTML =
            '<div class="toast-body">' +
            '<i class="bi ' +
            (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') +
            '"></i>' +
            '<span></span>' +
            '</div>';
        toast.querySelector('span').textContent = mensaje;
        contenedor.appendChild(toast);

        const instancia = new bootstrap.Toast(toast);
        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });
        instancia.show();
    };

    const modalMasiva = document.getElementById('modalAsignacionMasiva');
    const formMasiva = document.getElementById('formAsignacionMasiva');
    if (modalMasiva && formMasiva) {
        const abrirEstados = document.getElementById('masivaAbrirEstados');
        const listaEstados = document.getElementById('masivaListaEstados');
        const estados = document.getElementById('masivaEstados');
        const chips = document.getElementById('masivaEstadosElegidos');
        const cuentas = document.getElementById('masivaCuentas');
        const error = document.getElementById('masivaError');
        const todos = document.getElementById('masivaSeleccionarTodos');
        const secciones = Array.from(formMasiva.querySelectorAll('[data-masiva-seccion]'));
        let opciones = null;
        const cuentasAbiertas = {ANALISTA_DATOS: true, ASESOR: true};

        const opcion = function (valor, etiqueta) {
            const elemento = document.createElement('option');
            elemento.value = valor;
            elemento.textContent = etiqueta;
            return elemento;
        };
        const elegidos = function () {
            return Array.from(estados.querySelectorAll('input[name="estados[]"]:checked'));
        };
        const usuariosElegidos = function (tipo) {
            const seccion = secciones.find(function (item) {
                return item.dataset.masivaSeccion === tipo;
            });
            return Array.from(seccion.querySelectorAll('[data-masiva-personas] select'))
                .filter(function (select) { return select.value !== ''; })
                .map(function (select) {
                    return {id: select.value, nombre: select.selectedOptions[0].textContent};
                });
        };
        const actualizarResumenEstados = function () {
            const seleccionados = elegidos();
            const total = seleccionados.length;
            abrirEstados.textContent = total
                ? total + (total === 1 ? ' estado seleccionado' : ' estados seleccionados')
                : 'Seleccionar estados';
            const cantidad = estados.querySelectorAll('input[name="estados[]"]').length;
            todos.textContent = total && total === cantidad
                ? 'Quitar selección' : 'Seleccionar todos';
            chips.replaceChildren();
            if (total > 0 && total === cantidad) return;
            seleccionados.forEach(function (entrada) {
                const etiqueta = document.createElement('button');
                etiqueta.type = 'button';
                etiqueta.className = 'territory-bulk-chip';
                etiqueta.setAttribute('aria-label', 'Quitar ' + entrada.dataset.nombre);
                etiqueta.append(document.createTextNode(entrada.dataset.nombre));
                const icono = document.createElement('i');
                icono.className = 'bi bi-x-lg';
                icono.setAttribute('aria-hidden', 'true');
                etiqueta.appendChild(icono);
                etiqueta.addEventListener('click', function () {
                    entrada.checked = false;
                    actualizarResumenEstados();
                    actualizarCuentas();
                });
                chips.appendChild(etiqueta);
            });
        };
        const actualizarCuentas = function () {
            const anteriores = {};
            cuentas.querySelectorAll('select').forEach(function (selector) {
                anteriores[selector.name] = selector.value;
            });
            cuentas.replaceChildren();
            const tipos = [
                ['ANALISTA_DATOS', 'Cuenta Clave correspondiente a los Analistas'],
                ['ASESOR', 'Cuenta Clave correspondiente a los Asesores']
            ];
            const activos = tipos.filter(function (item) {
                return usuariosElegidos(item[0]).length > 0;
            });
            cuentas.classList.toggle('d-none', !activos.length);
            if (!activos.length || !opciones) return;
            const nuevasCuentas = usuariosElegidos('CUENTA_CLAVE');
            if (nuevasCuentas.length === 1) {
                const aviso = document.createElement('p');
                aviso.className = 'territory-bulk-parent-auto';
                const perfiles = activos.map(function (item) {
                    return item[0] === 'ASESOR' ? 'asesores' : 'analistas';
                }).join(' y ');
                const total = elegidos().length;
                aviso.textContent = nuevasCuentas[0].nombre + ' · ' +
                    'vinculación automática de ' + perfiles + ' en ' + total +
                    (total === 1 ? ' estado seleccionado.' : ' estados seleccionados.');
                cuentas.appendChild(aviso);
                return;
            }
            activos.forEach(function (item) {
                const tipo = item[0];
                const grupo = document.createElement('details');
                grupo.className = 'territory-bulk-parent-group';
                grupo.open = cuentasAbiertas[tipo];
                grupo.addEventListener('toggle', function () {
                    cuentasAbiertas[tipo] = grupo.open;
                });
                const titulo = document.createElement('summary');
                titulo.className = 'territory-bulk-section-title';
                titulo.append(document.createTextNode(item[1]));
                const flecha = document.createElement('i');
                flecha.className = 'bi bi-chevron-down territory-bulk-parent-chevron';
                flecha.setAttribute('aria-hidden', 'true');
                titulo.appendChild(flecha);
                grupo.appendChild(titulo);
                elegidos().forEach(function (entrada) {
                    const disponibles = opciones.cuentas.filter(function (cuenta) {
                        return String(cuenta.estado_id) === entrada.value;
                    }).map(function (cuenta) {
                        return {id: String(cuenta.id), nombre: (cuenta.nombre + ' ' + cuenta.apellidos).trim()};
                    }).concat(usuariosElegidos('CUENTA_CLAVE').map(function (persona) {
                        return {id: 'nuevo:' + persona.id,
                            nombre: persona.nombre + ' (se asignará ahora)'};
                    }));
                    const tarjeta = document.createElement('div');
                    tarjeta.className = 'territory-bulk-parent-card';
                    const cabecera = document.createElement('div');
                    cabecera.className = 'territory-bulk-parent-card-title';
                    const iconoEstado = document.createElement('i');
                    iconoEstado.className = 'bi bi-geo-alt';
                    iconoEstado.setAttribute('aria-hidden', 'true');
                    const nombreEstado = document.createElement('strong');
                    nombreEstado.textContent = entrada.dataset.nombre;
                    cabecera.append(iconoEstado, nombreEstado);
                    const columnas = document.createElement('div');
                    columnas.className = 'territory-bulk-parent-columns';
                    const columnaPersona = document.createElement('span');
                    columnaPersona.textContent = tipo === 'ASESOR' ? 'Asesor' : 'Analista';
                    const columnaCuenta = document.createElement('span');
                    columnaCuenta.textContent = 'Cuenta Clave correspondiente';
                    columnas.append(columnaPersona, columnaCuenta);
                    tarjeta.append(cabecera, columnas);
                    usuariosElegidos(tipo).forEach(function (persona) {
                        const fila = document.createElement('div');
                        fila.className = 'territory-bulk-parent-row';
                        const etiqueta = document.createElement('label');
                        etiqueta.className = 'territory-bulk-parent-person';
                        const avatar = document.createElement('span');
                        avatar.className = 'territory-bulk-avatar';
                        const usuario = opciones.usuarios[tipo].find(function (item) {
                            return String(item.id) === persona.id;
                        });
                        const iniciales = document.createElement('span');
                        iniciales.textContent = usuario
                            ? ((usuario.nombre || '').charAt(0) +
                                (usuario.apellidos || '').charAt(0)).toUpperCase()
                            : persona.nombre.charAt(0).toUpperCase();
                        if (usuario?.foto_url) {
                            const imagen = document.createElement('img');
                            imagen.src = usuario.foto_url;
                            imagen.alt = '';
                            imagen.addEventListener('error', function () { imagen.remove(); });
                            avatar.appendChild(imagen);
                        }
                        avatar.appendChild(iniciales);
                        const nombrePersona = document.createElement('span');
                        nombrePersona.textContent = persona.nombre;
                        etiqueta.append(avatar, nombrePersona);
                        fila.appendChild(etiqueta);
                        if (disponibles.length === 1 ||
                            (!disponibles.length && tipo === 'ASESOR')) {
                            const aviso = document.createElement('span');
                            aviso.className = 'territory-bulk-parent-auto';
                            aviso.textContent = disponibles.length
                                ? disponibles[0].nombre + ' · asignación automática'
                                : 'Sin Cuenta Clave disponible';
                            fila.appendChild(aviso);
                        } else {
                            const selector = document.createElement('select');
                            selector.className = 'form-select form-select-sm';
                            selector.id = 'masiva-cuenta-' + tipo + '-' + entrada.value + '-' + persona.id;
                            etiqueta.htmlFor = selector.id;
                            selector.name = 'cuentas_por_estado[' + tipo + '][' +
                                entrada.value + '][' + persona.id + ']';
                            selector.required = true;
                            selector.appendChild(opcion('', disponibles.length
                                ? 'Seleccionar Cuenta Clave' : 'Sin Cuenta Clave vigente'));
                            disponibles.forEach(function (cuenta) {
                                selector.appendChild(opcion(cuenta.id, cuenta.nombre));
                            });
                            selector.value = anteriores[selector.name] || '';
                            fila.appendChild(selector);
                        }
                        tarjeta.appendChild(fila);
                    });
                    grupo.appendChild(tarjeta);
                });
                cuentas.appendChild(grupo);
            });
        };
        const actualizarUsuariosDisponibles = function (seccion) {
            if (!opciones) return;
            const tipo = seccion.dataset.masivaSeccion;
            const selectores = Array.from(seccion.querySelectorAll('[data-masiva-personas] select'));
            const elegidos = new Set(selectores.map(function (selector) {
                return selector.value;
            }).filter(Boolean));
            selectores.forEach(function (selector) {
                const actual = selector.value;
                selector.replaceChildren(opcion('', 'Seleccionar usuario'));
                opciones.usuarios[tipo].forEach(function (persona) {
                    if (String(persona.id) !== actual && elegidos.has(String(persona.id))) return;
                    selector.appendChild(opcion(persona.id,
                        (persona.nombre + ' ' + persona.apellidos).trim()));
                });
                selector.value = actual;
            });
            seccion.querySelector('[data-masiva-agregar]').disabled =
                elegidos.size >= opciones.usuarios[tipo].length;
        };
        const agregarPersona = function (seccion) {
            if (!opciones) return;
            const tipo = seccion.dataset.masivaSeccion;
            const fila = document.createElement('div');
            fila.className = 'territory-bulk-person-row';
            const selector = document.createElement('select');
            selector.className = 'form-select';
            selector.name = 'usuarios[' + tipo + '][]';
            selector.required = true;
            selector.appendChild(opcion('', 'Seleccionar usuario'));
            opciones.usuarios[tipo].forEach(function (persona) {
                selector.appendChild(opcion(persona.id,
                    (persona.nombre + ' ' + persona.apellidos).trim()));
            });
            const identidad = document.createElement('div');
            identidad.className = 'territory-bulk-person-identity';
            const avatar = document.createElement('span');
            avatar.className = 'territory-bulk-avatar';
            const nombre = document.createElement('strong');
            const actualizarIdentidad = function () {
                const persona = opciones.usuarios[tipo].find(function (item) {
                    return String(item.id) === selector.value;
                });
                avatar.replaceChildren();
                nombre.textContent = persona
                    ? (persona.nombre + ' ' + persona.apellidos).trim()
                    : 'Seleccionar ' + (tipo === 'CUENTA_CLAVE'
                        ? 'Cuenta Clave' : (tipo === 'ASESOR' ? 'asesor' : 'analista'));
                const iniciales = document.createElement('span');
                iniciales.textContent = persona
                    ? ((persona.nombre || '').charAt(0) + (persona.apellidos || '').charAt(0)).toUpperCase()
                    : '?';
                if (persona?.foto_url) {
                    const imagen = document.createElement('img');
                    imagen.src = persona.foto_url;
                    imagen.alt = '';
                    imagen.addEventListener('error', function () { imagen.remove(); });
                    avatar.appendChild(imagen);
                }
                avatar.appendChild(iniciales);
            };
            selector.addEventListener('change', function () {
                actualizarIdentidad();
                actualizarUsuariosDisponibles(seccion);
            });
            actualizarIdentidad();
            identidad.append(avatar, nombre);
            fila.appendChild(identidad);
            const quitar = document.createElement('button');
            quitar.type = 'button';
            quitar.className = 'btn btn-system-cancel territory-bulk-remove';
            quitar.setAttribute('aria-label', 'Quitar persona');
            quitar.innerHTML = '<i class="bi bi-x-lg"></i>';
            quitar.addEventListener('click', function () {
                fila.remove();
                actualizarUsuariosDisponibles(seccion);
                actualizarCuentas();
            });
            fila.append(selector, quitar);
            seccion.querySelector('[data-masiva-personas]').appendChild(fila);
            actualizarUsuariosDisponibles(seccion);
            selector.focus();
        };

        abrirEstados.addEventListener('click', function () {
            listaEstados.hidden = !listaEstados.hidden;
            abrirEstados.setAttribute('aria-expanded', String(!listaEstados.hidden));
        });
        modalMasiva.addEventListener('click', function (evento) {
            if (!evento.target.closest('.territory-bulk-states-picker')) {
                listaEstados.hidden = true;
                abrirEstados.setAttribute('aria-expanded', 'false');
            }
        });
        formMasiva.addEventListener('invalid', function (evento) {
            const grupo = evento.target.closest('.territory-bulk-parent-group');
            if (grupo) grupo.open = true;
        }, true);
        secciones.forEach(function (seccion) {
            seccion.querySelector('[data-masiva-agregar]').addEventListener('click', function () {
                agregarPersona(seccion);
            });
        });
        formMasiva.addEventListener('change', function (evento) {
            if (evento.target.name && evento.target.name.startsWith('usuarios[')) {
                actualizarCuentas();
            }
        });

        modalMasiva.addEventListener('show.bs.modal', async function () {
            formMasiva.reset();
            cuentasAbiertas.ANALISTA_DATOS = true;
            cuentasAbiertas.ASESOR = true;
            secciones.forEach(function (seccion) {
                seccion.querySelector('[data-masiva-personas]').replaceChildren();
                seccion.querySelector('[data-masiva-agregar]').disabled = false;
            });
            listaEstados.hidden = true;
            abrirEstados.setAttribute('aria-expanded', 'false');
            abrirEstados.textContent = 'Seleccionar estados';
            error.textContent = '';
            todos.textContent = 'Seleccionar todos';
            estados.textContent = 'Cargando estados...';
            opciones = null;
            actualizarCuentas();
            try {
                const respuesta = await fetch(baseUrl + '?controller=territorio&action=opcionesAsignacionMasiva',
                    {headers: {'X-Requested-With': 'fetch'}, cache: 'no-store'});
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.ok) throw new Error('No fue posible consultar las opciones.');
                opciones = datos.opciones;
                estados.replaceChildren();
                opciones.estados.forEach(function (estado) {
                    const fila = document.createElement('label');
                    fila.className = 'd-flex align-items-center gap-2 px-2 py-1';
                    const entrada = document.createElement('input');
                    entrada.type = 'checkbox';
                    entrada.name = 'estados[]';
                    entrada.value = estado.id;
                    entrada.dataset.nombre = estado.nombre;
                    entrada.className = 'form-check-input mt-0';
                    fila.append(entrada, document.createTextNode(estado.nombre));
                    estados.appendChild(fila);
                });
                actualizarResumenEstados();
            } catch (fallo) {
                estados.textContent = 'No fue posible cargar los estados.';
                error.textContent = fallo.message;
            }
        });
        estados.addEventListener('change', function () {
            actualizarResumenEstados();
            actualizarCuentas();
        });
        todos.addEventListener('click', function () {
            const entradas = estados.querySelectorAll('input[name="estados[]"]');
            if (!entradas.length) return;
            const seleccionar = elegidos().length !== entradas.length;
            entradas.forEach(function (entrada) { entrada.checked = seleccionar; });
            actualizarResumenEstados();
            actualizarCuentas();
            listaEstados.hidden = true;
            abrirEstados.setAttribute('aria-expanded', 'false');
        });
        formMasiva.addEventListener('submit', async function (evento) {
            evento.preventDefault();
            error.textContent = '';
            if (!elegidos().length) {
                error.textContent = 'Selecciona al menos un estado.';
                return;
            }
            if (!secciones.some(function (seccion) {
                return seccion.querySelector('[data-masiva-personas] select');
            })) {
                error.textContent = 'Agrega al menos una persona.';
                return;
            }
            const boton = formMasiva.querySelector('[type="submit"]');
            boton.disabled = true;
            try {
                const respuesta = await fetch(formMasiva.action, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'fetch'},
                    body: new FormData(formMasiva)
                });
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.ok) {
                    error.textContent = datos.mensaje || 'No fue posible guardar la asignación.';
                    mostrarToast(error.textContent, true);
                    return;
                }
                bootstrap.Modal.getInstance(modalMasiva).hide();
                mostrarToast(datos.mensaje, false);
                await cargarResumen();
                await cargarTabla();
            } catch (fallo) {
                error.textContent = 'No fue posible comunicar con el servidor.';
                mostrarToast(error.textContent, true);
            } finally {
                boton.disabled = false;
            }
        });
    }

    const limpiarErroresFormulario = function (formulario) {
        formulario.querySelectorAll('.is-invalid').forEach(function (campo) {
            campo.classList.remove('is-invalid');
        });

        formulario.querySelectorAll('[data-field-error]').forEach(function (error) {
            error.textContent = '';
        });
    };

    const mostrarErroresFormulario = function (formulario, errores) {
        limpiarErroresFormulario(formulario);

        Object.keys(errores || {}).forEach(function (campo) {
            const mensaje = errores[campo];
            const entrada = formulario.querySelector('[name="' + campo + '"]');
            const contenedor = formulario.querySelector(
                '[data-field-error="' + campo + '"]'
            );

            if (entrada) {
                entrada.classList.add('is-invalid');
            }

            if (contenedor) {
                contenedor.textContent = mensaje;
            }
        });
    };

    const refrescarTerritorioActual = async function () {
        await cargarResumen();
        await cargarTabla();

        if (equipoEstadoId) {
            await cargarEquipoTerritorial(equipoEstadoId, equipoEstadoNombre);
        }

        if (
            detalleEstadoId &&
            detallePanel &&
            detallePanel.classList.contains('show')
        ) {
            await cargarDetalle(detalleEstadoId);
        }
    };

    const enviarFormularioEquipo = async function (formulario) {
        limpiarErroresFormulario(formulario);

        const boton = formulario.querySelector('[type="submit"]');
        const textoOriginal = boton ? boton.innerHTML : '';

        if (boton) {
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>Guardando';
        }

        try {
            const respuesta = await fetch(formulario.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'fetch'
                },
                body: new FormData(formulario)
            });

            const datos = await respuesta.json();

            if (!respuesta.ok || !datos.ok) {
                mostrarErroresFormulario(formulario, datos.errores || {});
                mostrarToast(
                    datos.mensaje || 'No fue posible completar la operación.',
                    true
                );
                return;
            }

            mostrarToast(datos.mensaje || 'Operación realizada correctamente.', false);
            await refrescarTerritorioActual();
        } catch (error) {
            mostrarToast('No fue posible comunicar con el servidor.', true);
        } finally {
            if (boton) {
                boton.disabled = false;
                boton.innerHTML = textoOriginal;
            }
        }
    };

    if (filtrosForm) {
        const campoBusqueda = filtrosForm.querySelector('[name="buscar"]');
        const filtros = filtrosForm.querySelectorAll('select');

        filtrosForm.addEventListener('submit', function (event) {
            event.preventDefault();
            cargarTabla();
        });

        if (campoBusqueda) {
            campoBusqueda.addEventListener('input', function () {
                window.clearTimeout(temporizadorBusqueda);
                temporizadorBusqueda = window.setTimeout(cargarTabla, 300);
            });
        }

        filtros.forEach(function (filtro) {
            filtro.addEventListener('change', cargarTabla);
        });
    }

    if (limpiarFiltros && filtrosForm) {
        limpiarFiltros.addEventListener('click', function (event) {
            event.preventDefault();

            filtrosForm.querySelector('[name="buscar"]').value = '';
            filtrosForm.querySelector('[name="cuenta_clave"]').value = '';
            filtrosForm.querySelector('[name="analista"]').value = '';
            filtrosForm.querySelector('[name="estado_asignacion"]').value = '';

            cargarTabla();
        });
    }

    document.addEventListener('submit', function (event) {
        const formularioEquipo = event.target.closest('[data-team-form]');

        if (formularioEquipo) {
            event.preventDefault();
            enviarFormularioEquipo(formularioEquipo);
        }
    });

    if (finalizarForm) {
        finalizarForm.addEventListener('submit', async function (event) {
            if (!equipoEstadoId) {
                return;
            }

            event.preventDefault();
            limpiarErroresFormulario(finalizarForm);

            const boton = finalizarForm.querySelector('[type="submit"]');
            const textoOriginal = boton ? boton.innerHTML : '';

            if (boton) {
                boton.disabled = true;
                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>Finalizando';
            }

            try {
                const respuesta = await fetch(finalizarForm.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: new FormData(finalizarForm)
                });
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    mostrarErroresFormulario(finalizarForm, datos.errores || {});
                    mostrarToast(
                        datos.mensaje || 'No fue posible finalizar la asignación.',
                        true
                    );
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(modalFinalizar).hide();
                mostrarToast(datos.mensaje || 'Asignación finalizada correctamente.', false);
                await refrescarTerritorioActual();
            } catch (error) {
                mostrarToast('No fue posible comunicar con el servidor.', true);
            } finally {
                if (boton) {
                    boton.disabled = false;
                    boton.innerHTML = textoOriginal;
                }
            }
        });
    }

    document.addEventListener('click', function (event) {
        const botonDetalle = event.target.closest('[data-territory-detail]');
        const fila = event.target.closest('[data-territory-row]');
        const botonEquipo = event.target.closest('[data-open-team-manager]');
        const botonFiltroEquipo = event.target.closest('[data-team-filter]');
        const botonAccionCompartida = event.target.closest('[data-team-shared-action]');
        const botonExpandir = event.target.closest('[data-team-toggle]');
        const botonCancelarEquipo = event.target.closest('[data-team-cancel]');
        const botonFinalizar = event.target.closest('[data-finalize-assignment]');
        const botonEditar = event.target.closest('[data-edit-territory]');

        if (botonAccionCompartida && modalEquipo && modalEquipo.contains(botonAccionCompartida)) {
            const tarjeta = botonAccionCompartida.closest('.territory-team-card');
            const destino = botonAccionCompartida.dataset.teamSharedAction;
            const contenedor = equipoContenido.querySelector('#' + destino);
            const espacio = tarjeta && tarjeta.querySelector('[data-team-shared-slot="' + destino + '"]');
            if (contenedor && espacio) {
                const tarjetaAnterior = contenedor.closest('.territory-team-card');
                const botonAnterior = tarjetaAnterior && tarjetaAnterior.querySelector(
                    '[data-team-shared-action="' + destino + '"]'
                );
                if (botonAnterior) {
                    botonAnterior.hidden = false;
                    botonAnterior.setAttribute('aria-expanded', 'false');
                }
                espacio.appendChild(contenedor);
                contenedor.hidden = false;
                botonAccionCompartida.hidden = true;
                botonAccionCompartida.setAttribute('aria-expanded', 'true');
                const primerCampo = contenedor.querySelector('select, input');
                if (primerCampo && !primerCampo.disabled) {
                    primerCampo.focus();
                }
            }
            return;
        }

        if (botonFiltroEquipo && modalEquipo && modalEquipo.contains(botonFiltroEquipo)) {
            const tarjeta = botonFiltroEquipo.closest('.territory-team-card');
            if (tarjeta) {
                const filtro = botonFiltroEquipo.dataset.teamFilter;
                tarjeta.querySelectorAll('[data-team-filter]').forEach(function (boton) {
                    boton.setAttribute('aria-pressed', String(boton === botonFiltroEquipo));
                });
                tarjeta.querySelectorAll('[data-team-pane]').forEach(function (panel) {
                    panel.hidden = panel.dataset.teamPane !== filtro;
                });
            }
            return;
        }

        if (botonDetalle) {
            cargarDetalle(botonDetalle.dataset.id);
            return;
        }

        if (fila && !event.target.closest('button')) {
            cargarDetalle(fila.dataset.territoryId);
            return;
        }

        if (botonEquipo) {
            cargarEquipoTerritorial(
                botonEquipo.dataset.estadoId || '',
                botonEquipo.dataset.estadoNombre || ''
            );
            return;
        }

        if (botonExpandir) {
            const formulario = document.getElementById(
                botonExpandir.dataset.target || ''
            );

            if (formulario) {
                formulario.hidden = false;
                botonExpandir.hidden = true;
                botonExpandir.setAttribute('aria-expanded', 'true');

                const primerCampo = formulario.querySelector('select, input');

                if (primerCampo && !primerCampo.disabled) {
                    primerCampo.focus();
                }
            }
            return;
        }

        if (botonCancelarEquipo) {
            const contenedor = botonCancelarEquipo.closest('.territory-expandable-form');
            const formulario = botonCancelarEquipo.closest('form');

            if (formulario) {
                formulario.reset();
                limpiarErroresFormulario(formulario);
            }

            if (contenedor) {
                contenedor.hidden = true;

                const tarjetaFormulario = contenedor.closest('.territory-team-card');
                const botonToggle = (tarjetaFormulario && tarjetaFormulario.querySelector(
                    '[data-team-shared-action="' + contenedor.id + '"]'
                )) || document.querySelector(
                    '[data-team-toggle][data-target="' + contenedor.id + '"]'
                );

                if (botonToggle) {
                    botonToggle.hidden = false;
                    botonToggle.setAttribute('aria-expanded', 'false');
                    botonToggle.focus();
                }
            }
            return;
        }

        if (botonFinalizar) {
            document.getElementById('finalizar_asignacion_id').value =
                botonFinalizar.dataset.id || '';
            document.getElementById('finalizar_asignacion_nombre').textContent =
                botonFinalizar.dataset.name || 'este usuario';

            const confirmacionEquipo =
                document.getElementById('finalizar_equipo_confirmacion');
            const checkEquipo = document.getElementById('finalizar_equipo');
            const mensajeEquipo = document.getElementById('finalizar_equipo_mensaje');
            const botonFinalizarModal =
                document.getElementById('finalizar_asignacion_boton');
            const tituloFinalizarModal =
                document.getElementById('modalFinalizarAsignacionTitulo');
            const textoAccionFinalizar =
                document.getElementById('finalizar_asignacion_accion');
            const tieneAnalistas = botonFinalizar.dataset.hasAnalysts === '1';
            const esAsesor = botonFinalizar.dataset.assignmentType === 'ASESOR';
            const totalAnalistas = parseInt(
                botonFinalizar.dataset.analystCount || '0',
                10
            );

            if (confirmacionEquipo && checkEquipo) {
                confirmacionEquipo.classList.toggle('d-none', !tieneAnalistas);
                checkEquipo.checked = false;
            }

            if (mensajeEquipo) {
                mensajeEquipo.textContent =
                    'Esta Cuenta Clave tiene ' +
                    totalAnalistas +
                    ' Analistas activos. Si no marcas la casilla, permanecerán activos ' +
                    'y solo quedarán sin Cuenta Clave asociada.';
            }

            if (botonFinalizarModal) {
                botonFinalizarModal.innerHTML = esAsesor
                    ? '<i class="bi bi-check2-circle me-2"></i>Eliminar asignación'
                    : (tieneAnalistas
                        ? '<i class="bi bi-check2-circle me-2"></i>Finalizar Cuenta Clave'
                        : '<i class="bi bi-check2-circle me-2"></i>Finalizar asignación');
            }

            if (tituloFinalizarModal) {
                tituloFinalizarModal.textContent = esAsesor
                    ? 'Eliminar asignación'
                    : 'Finalizar asignación';
            }

            if (textoAccionFinalizar) {
                textoAccionFinalizar.textContent = esAsesor
                    ? '¿Deseas eliminar la asignación de'
                    : '¿Deseas finalizar la asignación de';
            }

            if (modalFinalizar) {
                bootstrap.Modal.getOrCreateInstance(modalFinalizar).show();
            }
            return;
        }

        if (botonEditar) {
            const campos = {
                'editar_estado_id': 'id',
                'editar_clave_inegi': 'claveInegi',
                'editar_nombre_estado': 'nombre',
                'editar_nombre_corto': 'nombreCorto',
                'editar_capital': 'capital',
                'editar_titular_gobierno': 'titularGobierno',
                'editar_cargo_titular': 'cargoTitular',
                'editar_partido_politico': 'partidoPolitico',
                'editar_poblacion': 'poblacion',
                'editar_total_municipios': 'totalMunicipios',
                'editar_total_secretarias': 'totalSecretarias',
                'editar_periodo_gobierno': 'periodoGobierno',
                'editar_telefono': 'telefono',
                'editar_fuente': 'fuente',
                'editar_fecha_actualizacion': 'fechaActualizacion',
                'editar_redes_sociales': 'redesSociales'
            };

            Object.keys(campos).forEach(function (id) {
                const campo = document.getElementById(id);

                if (campo) {
                    const valor = botonEditar.dataset[campos[id]] || '';

                    if ('value' in campo) {
                        campo.value = valor;
                    } else {
                        campo.textContent = valor || 'No registrado';
                    }
                }
            });
        }
    });

    if (modalAbierto === 'equipo' && modalEquipo) {
        cargarEquipoTerritorial(
            '<?= $texto($datosFormulario['estado_id'] ?? '') ?>',
            'Territorio seleccionado'
        );
    }

    if (modalAbierto === 'estado' && modalEditar) {
        bootstrap.Modal.getOrCreateInstance(modalEditar).show();
    }

    document.querySelectorAll('.system-toast').forEach(function (toast) {
        new bootstrap.Toast(toast).show();
    });

    activarTooltips(document);
});
</script>
