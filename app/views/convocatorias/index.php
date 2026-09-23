<?php

$convocatorias = $convocatorias ?? [];
$estados = $estados ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';

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
    <div class="alert alert-success login-alert" role="alert">
        <i class="bi bi-check-circle"></i>
        <div><?= $texto($mensajeExito) ?></div>
    </div>
<?php endif; ?>

<?php if ($mensajeError !== ''): ?>
    <div class="alert alert-danger login-alert" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <div><?= $texto($mensajeError) ?></div>
    </div>
<?php endif; ?>

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
                    class="btn btn-new-user"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCrearConvocatoria">
                    <i class="bi bi-plus-circle me-2"></i>
                    Nueva convocatoria
                </button>
            <?php endif; ?>
        </div>
    </div>
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

            <tbody>
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
                                No hay convocatorias registradas.
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
                            <select
                                class="form-select system-form-control convocatoria-estados-select"
                                id="crear_convocatoria_estados"
                                name="estados[]"
                                multiple
                                size="8"
                                required>
                                <?php foreach ($estados as $estado): ?>
                                    <option value="<?= (int)$estado['id'] ?>">
                                        <?= $texto($estado['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                            <select
                                class="form-select system-form-control convocatoria-estados-select"
                                id="editar_convocatoria_estados"
                                name="estados[]"
                                multiple
                                size="8"
                                required>
                                <?php foreach ($estados as $estado): ?>
                                    <option value="<?= (int)$estado['id'] ?>">
                                        <?= $texto($estado['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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

    document.querySelectorAll('.btn-ver-convocatoria').forEach(function (boton) {
        boton.addEventListener('click', async function () {
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
        });
    });

    document.querySelectorAll('.btn-editar-convocatoria').forEach(function (boton) {
        boton.addEventListener('click', async function () {
            try {
                const convocatoria = await cargarConvocatoria(this.dataset.id);
                document.getElementById('editar_convocatoria_id').value = convocatoria.id || '';
                document.getElementById('editar_convocatoria_titulo').value = convocatoria.titulo || '';
                document.getElementById('editar_convocatoria_fecha_inicio').value = convocatoria.fecha_inicio || '';
                document.getElementById('editar_convocatoria_fecha_termino').value = convocatoria.fecha_termino || '';
                document.getElementById('editar_convocatoria_estado').value = String(convocatoria.estado ?? 1);

                const seleccion = new Set((convocatoria.estados_ids || []).map(String));
                document.querySelectorAll('#editar_convocatoria_estados option').forEach(function (opcion) {
                    opcion.selected = seleccion.has(String(opcion.value));
                });

                new bootstrap.Modal(document.getElementById('modalEditarConvocatoria')).show();
            } catch (error) {
                window.alert(error.message);
            }
        });
    });

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
