<?php

$convocatorias = $convocatorias ?? [];
$estados = $estados ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';
$buscar = $buscar ?? '';
$estadoFiltro = $estadoFiltro ?? 0;
$estatusFiltro = $estatusFiltro ?? '';
$territorioSeleccionado = $territorioSeleccionado ?? null;

$puedeCrear = tienePermiso('convocatorias.crear');
$puedeEditar = tienePermiso('convocatorias.editar');
$puedeDescargar = tienePermiso('convocatorias.descargar');
$puedeCambiarEstado = tienePermiso('convocatorias.cambiar_estado');

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fechaLegible = static function ($fecha) {
    if (!$fecha) {
        return '—';
    }

    try {
        return (new DateTime($fecha))->format('d/m/Y');
    } catch (Exception $error) {
        return (string)$fecha;
    }
};

$datosCrear = $modalAbierto === 'crear' ? $datosFormulario : [];
$datosEditar = $modalAbierto === 'editar' ? $datosFormulario : [];
?>

<?php if ($mensajeExito !== ''): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div
            class="toast system-toast"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-bs-delay="3200"
            data-convocatoria-success-toast>
            <div class="toast-body">
                <i class="bi bi-check2-circle"></i>
                <span><?= $texto($mensajeExito) ?></span>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const toastElement = document.querySelector('[data-convocatoria-success-toast]');

        if (toastElement && window.bootstrap) {
            bootstrap.Toast.getOrCreateInstance(toastElement).show();
        }
    });
    </script>
<?php endif; ?>

<?php if ($mensajeError !== ''): ?>
    <div class="alert alert-danger login-alert" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <div><?= $texto($mensajeError) ?></div>
    </div>
<?php endif; ?>

<?php if (!$territorioSeleccionado): ?>
<section class="data-territorial-module convocatoria-territory-selector">
    <section class="dashboard-panel data-territorial-selector">
        <div class="data-selector-heading">
            <div class="data-territorial-selector-copy">
                <h2 class="panel-title mb-1">Seleccionar territorio</h2>
                <p>Busca o selecciona un Estado para consultar sus convocatorias.</p>
            </div>
        </div>

        <div class="data-territorial-toolbar convocatoria-territory-toolbar">
            <div class="data-filter-field">
                <label for="buscar_territorio_convocatoria">Buscar territorio</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="buscar_territorio_convocatoria"
                        placeholder="Buscar territorio..."
                        aria-label="Buscar territorio"
                        data-convocatoria-territory-search>
                </div>
            </div>
        </div>
    </section>

    <section class="data-territorial-cards" data-convocatoria-territory-cards>
        <?php foreach ($estados as $estado): ?>
            <?php
            $nombreEstado = trim((string)($estado['nombre'] ?? ''));
            $slugEstado = strtolower($nombreEstado);
            $slugEstado = iconv('UTF-8', 'ASCII//TRANSLIT', $slugEstado);
            $slugEstado = preg_replace('/[^a-z0-9]+/', '-', (string)$slugEstado);
            $slugEstado = trim((string)$slugEstado, '-');

            if ($nombreEstado === 'Ciudad de México') {
                $slugEstado = 'ciudad-de-mexico';
            } elseif ($nombreEstado === 'Estado de México') {
                $slugEstado = 'estado-de-mexico';
            } elseif ($nombreEstado === 'Michoacán') {
                $slugEstado = 'michoacán';
            } elseif ($nombreEstado === 'Nuevo León') {
                $slugEstado = 'nuevo-leon';
            } elseif ($nombreEstado === 'Querétaro') {
                $slugEstado = 'queretaro';
            } elseif ($nombreEstado === 'San Luis Potosí') {
                $slugEstado = 'san-luis-potosi';
            } elseif ($nombreEstado === 'Yucatán') {
                $slugEstado = 'yucatan';
            }

            $imagenEstado = BASE_URL . 'public/img/estados/' . $slugEstado . '.png';
            ?>
            <article
                class="dashboard-panel data-territorial-card convocatoria-territory-card"
                data-convocatoria-territory-card
                data-territory-name="<?= $texto(mb_strtolower($nombreEstado, 'UTF-8')) ?>">
                <div class="data-card-heading">
                    <h3><?= $texto($nombreEstado) ?></h3>
                </div>

                <div class="convocatoria-territory-map">
                    <img
                        src="<?= $texto($imagenEstado) ?>"
                        alt="Mapa de <?= $texto($nombreEstado) ?>">
                </div>

                <a
                    class="btn btn-system-light"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$estado['id'] ?>">
                    Ver convocatorias
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <section
        class="dashboard-panel data-empty-state d-none"
        data-convocatoria-territory-empty>
        <span><i class="bi bi-map"></i></span>
        <strong>No se encontraron territorios.</strong>
        <p>Prueba con otro nombre de estado.</p>
    </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.querySelector('[data-convocatoria-territory-search]');
    const cards = Array.from(document.querySelectorAll('[data-convocatoria-territory-card]'));
    const empty = document.querySelector('[data-convocatoria-territory-empty]');
    let timer = null;

    const normalizar = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const aplicarFiltro = function () {
        const term = normalizar(search?.value || '');
        let visibles = 0;

        cards.forEach(function (card) {
            const name = normalizar(card.dataset.territoryName || '');
            const mostrar = term === '' || name.includes(term);
            card.classList.toggle('d-none', !mostrar);

            if (mostrar) {
                visibles++;
            }
        });

        empty?.classList.toggle('d-none', visibles > 0);
    };

    search?.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(aplicarFiltro, 300);
    });
});
</script>
<?php return; ?>
<?php endif; ?>

<div class="convocatoria-territory-context">
    <a
        class="data-back-link"
        href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index">
        <i class="bi bi-arrow-left"></i>
        Cambiar territorio
    </a>
    <span><?= $texto($territorioSeleccionado['nombre'] ?? '') ?></span>
</div>

<section class="dashboard-panel users-module-panel">
    <div class="module-toolbar convocatoria-toolbar">
        <div>
            <h2 class="panel-title mb-1">Convocatorias registradas</h2>
            <p class="panel-subtitle mb-0">Consulta, actualiza y administra publicaciones por estado.</p>
        </div>

        <div class="module-toolbar-actions">
            <?php if ($puedeCrear): ?>
                <button
                    type="button"
                    class="btn btn-system-save"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCrearConvocatoria">
                    <i class="bi bi-plus-circle me-2"></i>
                    Nueva convocatoria
                </button>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="dashboard-panel mt-4 convocatoria-filter-panel">
    <form
        method="GET"
        action="<?= BASE_URL ?>index.php"
        class="convocatoria-filter-bar">
        <input type="hidden" name="controller" value="convocatoria">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">

        <div class="convocatoria-filter-field convocatoria-filter-search">
            <label class="form-label login-label" for="filtro_convocatoria_buscar">Buscar convocatoria</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input
                    type="search"
                    class="form-control system-form-control"
                    id="filtro_convocatoria_buscar"
                    name="buscar"
                    placeholder="Buscar convocatoria..."
                    value="<?= $texto($buscar) ?>">
            </div>
        </div>

        <div class="convocatoria-filter-field">
            <label class="form-label login-label" for="filtro_convocatoria_estatus">Estatus</label>
            <select
                class="form-select system-form-control"
                id="filtro_convocatoria_estatus"
                name="estatus">
                <option value="" <?= $estatusFiltro === '' ? 'selected' : '' ?>>Todos</option>
                <option value="1" <?= $estatusFiltro === '1' ? 'selected' : '' ?>>Activas</option>
                <option value="0" <?= $estatusFiltro === '0' ? 'selected' : '' ?>>Inactivas</option>
            </select>
        </div>

        <div class="convocatoria-filter-actions">
            <a
                class="filter-clear-link <?= ($buscar === '' && (int)$estadoFiltro === 0 && $estatusFiltro === '') ? 'd-none' : '' ?>"
                href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index"
                data-convocatoria-clear-filters>
                Limpiar filtros
            </a>
        </div>
    </form>
</section>

<section class="dashboard-panel users-list-panel mt-4">
    <div class="table-responsive">
        <table class="table users-table align-middle">
            <thead>
                <tr>
                    <th>Imagen</th>
                    <th>Título</th>
                    <th>Periodo</th>
                    <th>Estados</th>
                    <th>Estatus</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>

            <tbody data-convocatorias-listado>
                <?php if (!empty($convocatorias)): ?>
                    <?php foreach ($convocatorias as $convocatoria): ?>
                        <tr>
                            <td>
                                <?php if (!empty($convocatoria['imagen'])): ?>
                                    <img
                                        src="<?= BASE_URL . $texto($convocatoria['imagen']) ?>"
                                        alt="<?= $texto($convocatoria['titulo']) ?>"
                                        class="convocatoria-thumb">
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td><?= $texto($convocatoria['titulo']) ?></td>

                            <td>
                                <?= $texto($fechaLegible($convocatoria['fecha_inicio'])) ?>
                                —
                                <?= $texto($fechaLegible($convocatoria['fecha_termino'])) ?>
                            </td>

                            <td><?= $texto($convocatoria['estados'] ?: 'Sin estados') ?></td>

                            <td>
                                <span class="status-pill <?= (int)$convocatoria['estado'] === 1 ? 'status-pill-active' : 'status-pill-inactive' ?>">
                                    <?= (int)$convocatoria['estado'] === 1 ? 'Activa' : 'Inactiva' ?>
                                </span>
                            </td>

                            <td class="text-end">
                                <div class="table-actions">
                                    <button
                                        type="button"
                                        class="table-action-button btn-ver-convocatoria"
                                        data-id="<?= (int)$convocatoria['id'] ?>"
                                        aria-label="Ver convocatoria">
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <?php if ($puedeEditar): ?>
                                        <button
                                            type="button"
                                            class="table-action-button btn-editar-convocatoria"
                                            data-id="<?= (int)$convocatoria['id'] ?>"
                                            aria-label="Editar convocatoria">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($puedeDescargar): ?>
                                        <a
                                            class="table-action-button"
                                            href="<?= BASE_URL ?>index.php?controller=convocatoria&action=descargarImagen&id=<?= (int)$convocatoria['id'] ?>"
                                            aria-label="Descargar imagen">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($puedeCambiarEstado): ?>
                                        <button
                                            type="button"
                                            class="table-action-button <?= (int)$convocatoria['estado'] === 1 ? 'table-action-warning' : 'table-action-success' ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEstadoConvocatoria"
                                            data-id="<?= (int)$convocatoria['id'] ?>"
                                            data-titulo="<?= $texto($convocatoria['titulo']) ?>"
                                            data-estado-nuevo="<?= (int)$convocatoria['estado'] === 1 ? 0 : 1 ?>"
                                            aria-label="<?= (int)$convocatoria['estado'] === 1 ? 'Desactivar convocatoria' : 'Activar convocatoria' ?>">
                                            <i class="bi <?= (int)$convocatoria['estado'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-table-message">
                                No se encontraron convocatorias con los filtros seleccionados.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div
    class="offcanvas offcanvas-end user-detail-panel"
    tabindex="-1"
    id="offcanvasDetalleConvocatoria"
    aria-labelledby="offcanvasDetalleConvocatoriaTitulo">

    <div class="offcanvas-header user-detail-header">
        <div>
            <h5 class="user-detail-title" id="offcanvasDetalleConvocatoriaTitulo">Detalle de la convocatoria</h5>
            <p class="user-detail-subtitle">Información de la publicación</p>
        </div>

        <button
            type="button"
            class="btn-close user-detail-close"
            data-bs-dismiss="offcanvas"
            aria-label="Cerrar">
        </button>
    </div>

    <div class="offcanvas-body user-detail-body" id="convocatoriaDetalleContenido">
        <div class="empty-table-message">Selecciona una convocatoria para consultar su detalle.</div>
    </div>
</div>

<div
    class="modal fade"
    id="modalCrearConvocatoria"
    tabindex="-1"
    aria-labelledby="modalCrearConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCrearConvocatoriaTitulo">Nueva convocatoria</h5>
                    <p class="system-form-modal-subtitle">Registra una publicación y su cobertura territorial</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=guardar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">

                <div class="modal-body">
                    <?php if ($modalAbierto === 'crear' && !empty($erroresFormulario)): ?>
                        <div class="alert alert-danger login-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <div>
                                <?php foreach ($erroresFormulario as $error): ?>
                                    <div><?= $texto($error) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="system-form-grid">
                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_titulo">Título</label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_convocatoria_titulo"
                                name="titulo"
                                value="<?= $texto($datosCrear['titulo'] ?? '') ?>"
                                required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_inicio"
                                name="fecha_inicio"
                                value="<?= $texto($datosCrear['fecha_inicio'] ?? '') ?>"
                                required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_termino">Fecha de término</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_termino"
                                name="fecha_termino"
                                value="<?= $texto($datosCrear['fecha_termino'] ?? '') ?>"
                                required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_estado">Disponibilidad</label>
                            <select class="form-select system-form-control" id="crear_convocatoria_estado" name="estado">
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_imagen">Imagen</label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="crear_convocatoria_imagen"
                                name="imagen"
                                accept="image/jpeg,image/png,image/webp"
                                required>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_estados">Estados</label>
                            <div class="convocatoria-state-picker" data-state-picker>
                                <input
                                    type="search"
                                    class="form-control system-form-control convocatoria-state-search"
                                    placeholder="Buscar estado..."
                                    data-state-search>

                                <div class="convocatoria-state-list">
                                    <label class="convocatoria-state-option convocatoria-state-option-all">
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            data-state-select-all>
                                        <span>Seleccionar todos</span>
                                    </label>

                                    <?php foreach ($estados as $estado): ?>
                                        <?php $estadoIdActual = (int)$estado['id']; ?>
                                        <label
                                            class="convocatoria-state-option"
                                            data-state-option
                                            data-state-name="<?= $texto(mb_strtolower((string)$estado['nombre'], 'UTF-8')) ?>">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="estados[]"
                                                value="<?= $estadoIdActual ?>"
                                                <?= in_array($estadoIdActual, array_map('intval', $datosCrear['estados_ids'] ?? []), true) ? 'checked' : '' ?>>
                                            <span><?= $texto($estado['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="form-text">Puedes seleccionar uno o varios estados.</small>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar convocatoria
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEditarConvocatoria"
    tabindex="-1"
    aria-labelledby="modalEditarConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalEditarConvocatoriaTitulo">Editar convocatoria</h5>
                    <p class="system-form-modal-subtitle">Actualiza la publicación sin perder su imagen actual</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                id="formEditarConvocatoria"
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=actualizar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">

                <input type="hidden" name="id" id="editar_convocatoria_id">

                <div class="modal-body">
                    <div class="system-form-grid">
                        <div class="system-form-full">
                            <label class="form-label login-label" for="editar_convocatoria_titulo">Título</label>
                            <input type="text" class="form-control system-form-control" id="editar_convocatoria_titulo" name="titulo" required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input type="date" class="form-control system-form-control" id="editar_convocatoria_fecha_inicio" name="fecha_inicio" required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_fecha_termino">Fecha de término</label>
                            <input type="date" class="form-control system-form-control" id="editar_convocatoria_fecha_termino" name="fecha_termino" required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_estado">Disponibilidad</label>
                            <select class="form-select system-form-control" id="editar_convocatoria_estado" name="estado">
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_imagen">Reemplazar imagen</label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="editar_convocatoria_imagen"
                                name="imagen"
                                accept="image/jpeg,image/png,image/webp">
                            <small class="form-text">Si no eliges un archivo, se conservará la imagen actual.</small>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label" for="editar_convocatoria_estados">Estados</label>
                            <div class="convocatoria-state-picker" data-state-picker>
                                <input
                                    type="search"
                                    class="form-control system-form-control convocatoria-state-search"
                                    placeholder="Buscar estado..."
                                    data-state-search>

                                <div class="convocatoria-state-list">
                                    <label class="convocatoria-state-option convocatoria-state-option-all">
                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            data-state-select-all>
                                        <span>Seleccionar todos</span>
                                    </label>

                                    <?php foreach ($estados as $estado): ?>
                                        <?php $estadoIdActual = (int)$estado['id']; ?>
                                        <label
                                            class="convocatoria-state-option"
                                            data-state-option
                                            data-state-name="<?= $texto(mb_strtolower((string)$estado['nombre'], 'UTF-8')) ?>">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="estados[]"
                                                value="<?= $estadoIdActual ?>"
                                                >
                                            <span><?= $texto($estado['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEstadoConvocatoria"
    tabindex="-1"
    aria-labelledby="modalEstadoConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalEstadoConvocatoriaTitulo">Cambiar estado</h5>
                    <p class="system-form-modal-subtitle">La convocatoria conservará su información y relaciones</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form action="<?= BASE_URL ?>index.php?controller=convocatoria&action=cambiarEstado" method="POST">
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input type="hidden" name="id" id="estado_convocatoria_id">
                <input type="hidden" name="estado" id="estado_convocatoria_nuevo">

                <div class="modal-body">
                    <p class="confirm-text" id="estado_convocatoria_mensaje">
                        ¿Deseas cambiar el estado de esta convocatoria?
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const detallePanel = document.getElementById('offcanvasDetalleConvocatoria');
    const detalleContenido = document.getElementById('convocatoriaDetalleContenido');
    const detalleOffcanvas = detallePanel ? new bootstrap.Offcanvas(detallePanel) : null;

    const cargarConvocatoria = async function (id) {
        const respuesta = await fetch(
            <?= json_encode(BASE_URL . 'index.php?controller=convocatoria&action=detalle&id=') ?> + encodeURIComponent(id)
        );

        if (!respuesta.ok) {
            throw new Error('No fue posible consultar la convocatoria.');
        }

        const datos = await respuesta.json();

        if (!datos.ok || !datos.convocatoria) {
            throw new Error('No fue posible consultar la convocatoria.');
        }

        return datos.convocatoria;
    };

    const manejarVerConvocatoria = async function () {
            try {
                const convocatoria = await cargarConvocatoria(this.dataset.id);
                const imagen = convocatoria.imagen
                    ? '<img class="convocatoria-detail-image" src="' +
                        <?= json_encode(BASE_URL) ?> +
                        convocatoria.imagen.replace(/^\/+/, '') +
                        '" alt="">'
                    : '';

                detalleContenido.innerHTML =
                    imagen +
                    '<div class="user-detail-tab-content active">' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-card-heading"></i></div>' +
                    '<div class="user-detail-info-label">Título</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml(convocatoria.titulo || '—') +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-calendar3"></i></div>' +
                    '<div class="user-detail-info-label">Periodo</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml((convocatoria.fecha_inicio || '—') + ' — ' + (convocatoria.fecha_termino || '—')) +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-geo-alt"></i></div>' +
                    '<div class="user-detail-info-label">Estados</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml((convocatoria.estados || []).join(', ') || '—') +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-toggle-on"></i></div>' +
                    '<div class="user-detail-info-label">Estatus</div>' +
                    '<div class="user-detail-info-value">' +
                    ((Number(convocatoria.estado) === 1) ? 'Activa' : 'Inactiva') +
                    '</div></div></div>';

                detalleOffcanvas.show();
            } catch (error) {
                detalleContenido.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
                detalleOffcanvas.show();
            }
    };

    const manejarEditarConvocatoria = async function () {
            try {
                const convocatoria = await cargarConvocatoria(this.dataset.id);
                document.getElementById('editar_convocatoria_id').value = convocatoria.id || '';
                document.getElementById('editar_convocatoria_titulo').value = convocatoria.titulo || '';
                document.getElementById('editar_convocatoria_fecha_inicio').value = convocatoria.fecha_inicio || '';
                document.getElementById('editar_convocatoria_fecha_termino').value = convocatoria.fecha_termino || '';
                document.getElementById('editar_convocatoria_estado').value = String(convocatoria.estado ?? 1);

                const seleccion = new Set((convocatoria.estados_ids || []).map(String));
                const modalEditar = document.getElementById('modalEditarConvocatoria');

                modalEditar.querySelectorAll('[data-state-option] input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = seleccion.has(String(checkbox.value));
                });

                actualizarSeleccionTodos(modalEditar.querySelector('[data-state-picker]'));

                new bootstrap.Modal(document.getElementById('modalEditarConvocatoria')).show();
            } catch (error) {
                window.alert(error.message);
            }
    };

    vincularAccionesConvocatorias();

    document.querySelectorAll('[data-state-picker]').forEach(function (picker) {
        const search = picker.querySelector('[data-state-search]');
        const selectAll = picker.querySelector('[data-state-select-all]');
        const options = Array.from(picker.querySelectorAll('[data-state-option]'));

        const filterStates = function () {
            const term = normalizarTexto(search.value);

            options.forEach(function (option) {
                const name = normalizarTexto(option.dataset.stateName || '');
                option.hidden = term !== '' && !name.includes(term);
            });

            actualizarSeleccionTodos(picker);
        };

        search.addEventListener('input', filterStates);

        selectAll.addEventListener('change', function () {
            options.forEach(function (option) {
                if (!option.hidden) {
                    option.querySelector('input[type="checkbox"]').checked = selectAll.checked;
                }
            });

            actualizarSeleccionTodos(picker);
        });

        options.forEach(function (option) {
            option.querySelector('input[type="checkbox"]').addEventListener('change', function () {
                actualizarSeleccionTodos(picker);
            });
        });

        actualizarSeleccionTodos(picker);
    });

    function actualizarSeleccionTodos(picker) {
        if (!picker) {
            return;
        }

        const visibles = Array.from(picker.querySelectorAll('[data-state-option]'))
            .filter(function (option) {
                return !option.hidden;
            })
            .map(function (option) {
                return option.querySelector('input[type="checkbox"]');
            });

        const selectAll = picker.querySelector('[data-state-select-all]');
        const marcados = visibles.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        selectAll.checked = visibles.length > 0 && marcados === visibles.length;
        selectAll.indeterminate = marcados > 0 && marcados < visibles.length;
    }

    function normalizarTexto(valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    const filtroBuscar = document.getElementById('filtro_convocatoria_buscar');
    const filtroEstatus = document.getElementById('filtro_convocatoria_estatus');
    const limpiarFiltros = document.querySelector('[data-convocatoria-clear-filters]');
    const listadoConvocatorias = document.querySelector('[data-convocatorias-listado]');
    let temporizadorFiltro = null;
    let controladorFiltro = null;
    let secuenciaFiltro = 0;

    const actualizarVisibilidadLimpiar = function () {
        const hayFiltros =
            String(filtroBuscar?.value || '').trim() !== '' ||
            String(filtroEstatus?.value || '') !== '';

        limpiarFiltros?.classList.toggle('d-none', !hayFiltros);
    };

    const renderConvocatorias = function (convocatorias) {
        if (!listadoConvocatorias) {
            return;
        }

        if (!Array.isArray(convocatorias) || convocatorias.length === 0) {
            listadoConvocatorias.innerHTML =
                '<tr><td colspan="6"><div class="empty-table-message">' +
                'No se encontraron convocatorias con los filtros seleccionados.' +
                '</div></td></tr>';
            return;
        }

        listadoConvocatorias.innerHTML = convocatorias.map(function (convocatoria) {
            const estadoActivo = Number(convocatoria.estado) === 1;
            const imagen = convocatoria.imagen
                ? '<img src="' + <?= json_encode(BASE_URL) ?> +
                    escapeHtml(String(convocatoria.imagen).replace(/^\/+/, '')) +
                    '" alt="' + escapeHtml(convocatoria.titulo || '') +
                    '" class="convocatoria-thumb">'
                : '—';

            const acciones = [
                '<button type="button" class="table-action-button btn-ver-convocatoria" ' +
                    'data-id="' + Number(convocatoria.id) + '" aria-label="Ver convocatoria">' +
                    '<i class="bi bi-eye"></i></button>'
            ];

            <?php if ($puedeEditar): ?>
            acciones.push(
                '<button type="button" class="table-action-button btn-editar-convocatoria" ' +
                'data-id="' + Number(convocatoria.id) + '" aria-label="Editar convocatoria">' +
                '<i class="bi bi-pencil"></i></button>'
            );
            <?php endif; ?>

            <?php if ($puedeDescargar): ?>
            acciones.push(
                '<a class="table-action-button" href="' +
                <?= json_encode(BASE_URL . 'index.php?controller=convocatoria&action=descargarImagen&id=') ?> +
                Number(convocatoria.id) +
                '" aria-label="Descargar imagen"><i class="bi bi-download"></i></a>'
            );
            <?php endif; ?>

            <?php if ($puedeCambiarEstado): ?>
            acciones.push(
                '<button type="button" class="table-action-button ' +
                (estadoActivo ? 'table-action-warning' : 'table-action-success') +
                '" data-bs-toggle="modal" data-bs-target="#modalEstadoConvocatoria" ' +
                'data-id="' + Number(convocatoria.id) + '" ' +
                'data-titulo="' + escapeHtml(convocatoria.titulo || '') + '" ' +
                'data-estado-nuevo="' + (estadoActivo ? '0' : '1') + '" ' +
                'aria-label="' + (estadoActivo ? 'Desactivar convocatoria' : 'Activar convocatoria') + '">' +
                '<i class="bi ' + (estadoActivo ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>'
            );
            <?php endif; ?>

            return '<tr>' +
                '<td>' + imagen + '</td>' +
                '<td>' + escapeHtml(convocatoria.titulo || '') + '</td>' +
                '<td>' + escapeHtml(formatearFecha(convocatoria.fecha_inicio)) +
                    ' — ' + escapeHtml(formatearFecha(convocatoria.fecha_termino)) + '</td>' +
                '<td>' + escapeHtml(convocatoria.estados || 'Sin estados') + '</td>' +
                '<td><span class="status-pill ' +
                    (estadoActivo ? 'status-pill-active' : 'status-pill-inactive') + '">' +
                    (estadoActivo ? 'Activa' : 'Inactiva') +
                '</span></td>' +
                '<td class="text-end"><div class="table-actions">' +
                    acciones.join('') +
                '</div></td>' +
            '</tr>';
        }).join('');

        vincularAccionesConvocatorias();
    };

    const formatearFecha = function (fecha) {
        if (!fecha) {
            return '—';
        }

        const partes = String(fecha).split('-');
        return partes.length === 3
            ? partes[2] + '/' + partes[1] + '/' + partes[0]
            : String(fecha);
    };

    const cargarListadoFiltrado = async function () {
        if (!listadoConvocatorias) {
            return;
        }

        if (controladorFiltro) {
            controladorFiltro.abort();
        }

        const secuenciaActual = ++secuenciaFiltro;
        controladorFiltro = new AbortController();
        listadoConvocatorias.classList.add('opacity-50');

        const params = new URLSearchParams({
            controller: 'convocatoria',
            action: 'listadoFiltrado',
            buscar: String(filtroBuscar?.value || '').trim(),
            territorio_id: <?= json_encode((string)(int)$territorioSeleccionado['id']) ?>,
            estatus: String(filtroEstatus?.value || '')
        });

        try {
            const respuesta = await fetch(
                <?= json_encode(BASE_URL . 'index.php?') ?> + params.toString(),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    signal: controladorFiltro.signal
                }
            );

            if (!respuesta.ok) {
                throw new Error('No fue posible actualizar las convocatorias.');
            }

            const datos = await respuesta.json();

            if (secuenciaActual !== secuenciaFiltro) {
                return;
            }

            renderConvocatorias(datos.convocatorias || []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                listadoConvocatorias.innerHTML =
                    '<tr><td colspan="6"><div class="alert alert-danger mb-0">' +
                    escapeHtml(error.message) +
                    '</div></td></tr>';
            }
        } finally {
            if (secuenciaActual === secuenciaFiltro) {
                listadoConvocatorias.classList.remove('opacity-50');
            }
        }
    };

    const programarBusqueda = function () {
        window.clearTimeout(temporizadorFiltro);
        actualizarVisibilidadLimpiar();

        temporizadorFiltro = window.setTimeout(function () {
            cargarListadoFiltrado();
        }, 300);
    };

    filtroBuscar?.addEventListener('input', programarBusqueda);

    [filtroEstatus].forEach(function (filtro) {
        filtro?.addEventListener('change', function () {
            actualizarVisibilidadLimpiar();
            cargarListadoFiltrado();
        });
    });

    limpiarFiltros?.addEventListener('click', function (event) {
        event.preventDefault();
        window.clearTimeout(temporizadorFiltro);

        if (filtroBuscar) {
            filtroBuscar.value = '';
        }

        if (filtroEstatus) {
            filtroEstatus.value = '';
        }

        actualizarVisibilidadLimpiar();
        cargarListadoFiltrado();
    });

    function vincularAccionesConvocatorias() {
        document.querySelectorAll('.btn-ver-convocatoria').forEach(function (boton) {
            if (boton.dataset.listenerVinculado === '1') {
                return;
            }

            boton.dataset.listenerVinculado = '1';
            boton.addEventListener('click', manejarVerConvocatoria);
        });

        document.querySelectorAll('.btn-editar-convocatoria').forEach(function (boton) {
            if (boton.dataset.listenerVinculado === '1') {
                return;
            }

            boton.dataset.listenerVinculado = '1';
            boton.addEventListener('click', manejarEditarConvocatoria);
        });
    }

    const modalEstado = document.getElementById('modalEstadoConvocatoria');
    if (modalEstado) {
        modalEstado.addEventListener('show.bs.modal', function (event) {
            const boton = event.relatedTarget;
            const nuevoEstado = Number(boton.dataset.estadoNuevo);
            document.getElementById('estado_convocatoria_id').value = boton.dataset.id || '';
            document.getElementById('estado_convocatoria_nuevo').value = String(nuevoEstado);
            document.getElementById('estado_convocatoria_mensaje').textContent =
                (nuevoEstado === 1 ? '¿Deseas activar "' : '¿Deseas desactivar "') +
                (boton.dataset.titulo || 'esta convocatoria') +
                '"?';
        });
    }

    function escapeHtml(valor) {
        return String(valor)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    <?php if ($modalAbierto === 'crear'): ?>
        new bootstrap.Modal(document.getElementById('modalCrearConvocatoria')).show();
    <?php endif; ?>
});
</script>
