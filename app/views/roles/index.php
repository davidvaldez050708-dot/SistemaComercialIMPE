<?php

$roles = $roles ?? [];
$rolSeleccionado = $rolSeleccionado ?? null;
$rolSeleccionadoId = (int)($rolSeleccionadoId ?? 0);
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';

$puedeCrearRoles = tienePermiso('roles.crear');
$puedeEditarRoles = tienePermiso('roles.editar');
$puedeCambiarEstadoRoles = tienePermiso('roles.cambiar_estado');

$datosCrear = $modalAbierto === 'crear' ? $datosFormulario : [];
$datosEditar = $modalAbierto === 'editar' ? $datosFormulario : [];
$erroresCrear = $modalAbierto === 'crear' ? $erroresFormulario : [];
$erroresEditar = $modalAbierto === 'editar' ? $erroresFormulario : [];

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$seleccionado = function ($actual, $valor) {
    return (string)$actual === (string)$valor ? 'selected' : '';
};

?>

<section class="roles-module">
    <div class="roles-layout">
        <aside class="dashboard-panel roles-list-panel">
            <div class="roles-list-header">
                <div>
                    <h2 class="panel-title mb-1">
                        Roles registrados
                    </h2>

                    <p class="roles-list-subtitle">
                        Perfiles de acceso del sistema
                    </p>
                </div>

                <?php if ($puedeCrearRoles): ?>

                    <button
                        type="button"
                        class="btn btn-new-user roles-new-button"
                        data-bs-toggle="modal"
                        data-bs-target="#modalCrearRol">
                        <i class="bi bi-plus-lg me-2"></i>
                        Nuevo rol
                    </button>

                <?php endif; ?>
            </div>

            <div class="roles-list">
                <?php foreach ($roles as $rol): ?>

                    <?php
                    $rolId = (int)$rol['id'];
                    $rolActivo = (int)$rol['estado'] === 1;
                    $rolProtegido = $rolId === 1;
                    ?>

                    <div
                        class="role-card <?= $rolId === $rolSeleccionadoId ? 'active' : '' ?>"
                        data-role-card>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=rol&action=index&rol_id=<?= $rolId ?>"
                            class="role-card-link"
                            data-role-link
                            data-role-id="<?= $rolId ?>"
                            data-role-url="<?= BASE_URL ?>index.php?controller=rol&action=panel&rol_id=<?= $rolId ?>">
                            <span class="role-card-icon">
                                <i class="bi bi-shield-lock"></i>
                            </span>

                            <span class="role-card-content">
                                <strong><?= $texto($rol['nombre']) ?></strong>
                                <small>
                                    <?= $texto($rol['descripcion'] ?: 'Sin descripción') ?>
                                </small>

                                <span class="role-card-meta">
                                    <span class="status-pill <?= $rolActivo
                                        ? 'status-pill-active'
                                        : 'status-pill-inactive' ?>">
                                        <?= $rolActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>

                                    <?php if ($rolProtegido): ?>
                                        <span class="role-system-pill">
                                            Sistema
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </a>

                        <span class="role-card-actions">
                            <?php if ($puedeEditarRoles): ?>

                                <button
                                    type="button"
                                    class="table-action-button"
                                    aria-label="Editar rol"
                                    data-role-edit
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEditarRol"
                                    data-id="<?= $rolId ?>"
                                    data-nombre="<?= $texto($rol['nombre']) ?>"
                                    data-descripcion="<?= $texto($rol['descripcion']) ?>"
                                    data-estado="<?= (int)$rol['estado'] ?>"
                                    <?= $rolProtegido ? 'disabled' : '' ?>>
                                    <i class="bi bi-pencil"></i>
                                </button>

                            <?php endif; ?>

                            <?php if ($puedeCambiarEstadoRoles): ?>

                                <form
                                    action="<?= BASE_URL ?>index.php?controller=rol&action=cambiarEstado"
                                    method="POST">
                                    <input type="hidden" name="id" value="<?= $rolId ?>">
                                    <input
                                        type="hidden"
                                        name="estado"
                                        value="<?= $rolActivo ? 0 : 1 ?>">

                                    <button
                                        type="submit"
                                        class="table-action-button <?= $rolActivo
                                            ? 'table-action-warning'
                                            : 'table-action-success' ?>"
                                        aria-label="<?= $rolActivo ? 'Desactivar rol' : 'Activar rol' ?>"
                                        <?= $rolProtegido ? 'disabled' : '' ?>>
                                        <i class="bi <?= $rolActivo ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>

                            <?php endif; ?>
                        </span>
                    </div>

                <?php endforeach; ?>
            </div>
        </aside>

        <section
            class="dashboard-panel roles-permissions-panel"
            id="rolesPermisosPanel">
            <?php require __DIR__ . '/panel_permisos.php'; ?>
        </section>
    </div>
</section>

<div
    class="modal fade"
    id="modalCrearRol"
    tabindex="-1"
    aria-labelledby="modalCrearRolTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCrearRolTitulo">
                        Nuevo rol
                    </h5>

                    <p class="system-form-modal-subtitle">
                        Registra un perfil de acceso para el sistema
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
                action="<?= BASE_URL ?>index.php?controller=rol&action=guardar"
                method="POST"
                novalidate>

                <div class="modal-body">
                    <?php if (!empty($erroresCrear)): ?>

                        <div class="alert alert-danger login-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>

                            <div>
                                <?php foreach ($erroresCrear as $error): ?>
                                    <div><?= $texto($error) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="crear_rol_nombre" class="form-label">
                            Nombre del rol
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="crear_rol_nombre"
                            name="nombre"
                            value="<?= $texto($datosCrear['nombre'] ?? '') ?>"
                            required>
                    </div>

                    <div class="mb-3">
                        <label for="crear_rol_descripcion" class="form-label">
                            Descripción
                        </label>
                        <textarea
                            class="form-control"
                            id="crear_rol_descripcion"
                            name="descripcion"
                            rows="3"><?= $texto($datosCrear['descripcion'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="crear_rol_estado" class="form-label">
                            Estado
                        </label>
                        <select
                            class="form-select"
                            id="crear_rol_estado"
                            name="estado"
                            required>
                            <option
                                value="1"
                                <?= $seleccionado($datosCrear['estado'] ?? '1', '1') ?>>
                                Activo
                            </option>
                            <option
                                value="0"
                                <?= $seleccionado($datosCrear['estado'] ?? '', '0') ?>>
                                Inactivo
                            </option>
                        </select>
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
                        Guardar rol
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEditarRol"
    tabindex="-1"
    aria-labelledby="modalEditarRolTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalEditarRolTitulo">
                        Editar rol
                    </h5>

                    <p class="system-form-modal-subtitle">
                        Actualiza los datos generales del perfil
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
                action="<?= BASE_URL ?>index.php?controller=rol&action=actualizar"
                method="POST"
                novalidate>

                <input
                    type="hidden"
                    id="editar_rol_id"
                    name="id"
                    value="<?= (int)($datosEditar['id'] ?? 0) ?>">

                <div class="modal-body">
                    <?php if (!empty($erroresEditar)): ?>

                        <div class="alert alert-danger login-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>

                            <div>
                                <?php foreach ($erroresEditar as $error): ?>
                                    <div><?= $texto($error) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="editar_rol_nombre" class="form-label">
                            Nombre del rol
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="editar_rol_nombre"
                            name="nombre"
                            value="<?= $texto($datosEditar['nombre'] ?? '') ?>"
                            required>
                    </div>

                    <div class="mb-3">
                        <label for="editar_rol_descripcion" class="form-label">
                            Descripción
                        </label>
                        <textarea
                            class="form-control"
                            id="editar_rol_descripcion"
                            name="descripcion"
                            rows="3"><?= $texto($datosEditar['descripcion'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="editar_rol_estado" class="form-label">
                            Estado
                        </label>
                        <select
                            class="form-select"
                            id="editar_rol_estado"
                            name="estado"
                            <?= $puedeCambiarEstadoRoles ? '' : 'disabled' ?>
                            required>
                            <option
                                value="1"
                                <?= $seleccionado($datosEditar['estado'] ?? '1', '1') ?>>
                                Activo
                            </option>
                            <option
                                value="0"
                                <?= $seleccionado($datosEditar['estado'] ?? '', '0') ?>>
                                Inactivo
                            </option>
                        </select>

                        <?php if (!$puedeCambiarEstadoRoles): ?>
                            <input
                                type="hidden"
                                id="editar_rol_estado_oculto"
                                name="estado"
                                value="<?= $texto($datosEditar['estado'] ?? '1') ?>">
                        <?php endif; ?>
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
                        Actualizar rol
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
    const panel = document.getElementById('rolesPermisosPanel');
    const modalAbierto = '<?= $texto($modalAbierto) ?>';
    const modalEditarRol = document.getElementById('modalEditarRol');

    const inicializarPanelPermisos = function () {
        const formulario = document.querySelector('[data-permissions-form]');

        if (!formulario) {
            return;
        }

        const esAdministrador = formulario.dataset.admin === '1';
        const contador = formulario.querySelector('[data-permissions-count]') ||
            document.querySelector('[data-permissions-count]');
        const estado = formulario.querySelector('[data-permissions-status]');
        const botonGuardar = formulario.querySelector('[data-permissions-save]');
        const buscador = formulario.querySelector('[data-permission-search]');
        const checkboxes = Array.from(
            formulario.querySelectorAll('input[type="checkbox"][name="permisos[]"]')
        );
        const estadosIniciales = checkboxes.map(function (checkbox) {
            return checkbox.checked;
        });

        const normalizar = function (valor) {
            return (valor || '')
                .toString()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');
        };

        const actualizarContador = function () {
            if (!contador) {
                return;
            }

            if (esAdministrador) {
                contador.textContent = 'Acceso completo';
                return;
            }

            const total = parseInt(contador.dataset.total || checkboxes.length, 10);
            const marcados = checkboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            contador.textContent =
                marcados + ' de ' + total + ' permisos habilitados';
        };

        const hayCambios = function () {
            return checkboxes.some(function (checkbox, indice) {
                return checkbox.checked !== estadosIniciales[indice];
            });
        };

        const actualizarEstado = function () {
            const modificado = hayCambios();

            actualizarContador();

            if (estado) {
                estado.textContent = modificado
                    ? 'Cambios sin guardar'
                    : (esAdministrador ? 'Acceso completo' : 'Sin cambios pendientes');
                estado.classList.toggle('has-changes', modificado);
            }

            if (botonGuardar) {
                botonGuardar.disabled = esAdministrador || !modificado;
            }
        };

        const actualizarBotonesModulo = function () {
            formulario
                .querySelectorAll('[data-permission-group]')
                .forEach(function (grupo) {
                    const permisosGrupo = Array.from(
                        grupo.querySelectorAll('input[type="checkbox"][name="permisos[]"]')
                    );
                    const boton = grupo.querySelector('[data-permission-group-toggle]');

                    if (!boton || permisosGrupo.length === 0) {
                        return;
                    }

                    const todosMarcados = permisosGrupo.every(function (checkbox) {
                        return checkbox.checked;
                    });

                    boton.textContent = todosMarcados
                        ? 'Desmarcar todos'
                        : 'Marcar todos';
                });
        };

        const filtrarPermisos = function () {
            const termino = normalizar(buscador ? buscador.value : '');

            formulario
                .querySelectorAll('[data-permission-group]')
                .forEach(function (grupo) {
                    let visibles = 0;

                    grupo
                        .querySelectorAll('[data-permission-row]')
                        .forEach(function (fila) {
                            const coincide = termino === '' ||
                                normalizar(fila.dataset.search).includes(termino);

                            fila.classList.toggle('d-none', !coincide);

                            if (coincide) {
                                visibles++;
                            }
                        });

                    grupo.classList.toggle('d-none', visibles === 0);
                });
        };

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                actualizarBotonesModulo();
                actualizarEstado();
            });
        });

        formulario
            .querySelectorAll('[data-permission-group-toggle]')
            .forEach(function (boton) {
                boton.addEventListener('click', function () {
                    const grupo = boton.closest('[data-permission-group]');

                    if (!grupo) {
                        return;
                    }

                    const permisosGrupo = Array.from(
                        grupo.querySelectorAll('input[type="checkbox"][name="permisos[]"]')
                    ).filter(function (checkbox) {
                        return !checkbox.disabled;
                    });

                    const todosMarcados = permisosGrupo.length > 0 &&
                        permisosGrupo.every(function (checkbox) {
                            return checkbox.checked;
                        });

                    permisosGrupo.forEach(function (checkbox) {
                        checkbox.checked = !todosMarcados;
                    });

                    actualizarBotonesModulo();
                    actualizarEstado();
                });
            });

        if (buscador) {
            buscador.addEventListener('input', filtrarPermisos);
        }

        actualizarBotonesModulo();
        actualizarEstado();
        filtrarPermisos();
    };

    document.querySelectorAll('[data-role-link]').forEach(function (link) {
        link.addEventListener('click', async function (event) {
            if (!panel) {
                return;
            }

            event.preventDefault();

            document
                .querySelectorAll('[data-role-card]')
                .forEach(function (item) {
                    item.classList.remove('active');
                });

            const card = link.closest('[data-role-card]');

            if (card) {
                card.classList.add('active');
            }

            panel.classList.add('roles-panel-loading');

            try {
                const respuesta = await fetch(link.dataset.roleUrl, {
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });

                if (!respuesta.ok) {
                    throw new Error('No fue posible consultar permisos.');
                }

                panel.innerHTML = await respuesta.text();
                inicializarPanelPermisos();
                window.history.replaceState(null, '', link.href);
            } catch (error) {
                panel.innerHTML =
                    '<div class="roles-empty-panel">' +
                    '<div class="roles-empty-icon">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '</div>' +
                    '<h2>No fue posible cargar los permisos</h2>' +
                    '<p>Intenta seleccionar el rol nuevamente.</p>' +
                    '</div>';
            } finally {
                panel.classList.remove('roles-panel-loading');
            }
        });
    });

    if (modalEditarRol) {
        modalEditarRol.addEventListener('show.bs.modal', function (event) {
            const boton = event.relatedTarget;

            if (!boton) {
                return;
            }

            document.getElementById('editar_rol_id').value =
                boton.getAttribute('data-id') || '';
            document.getElementById('editar_rol_nombre').value =
                boton.getAttribute('data-nombre') || '';
            document.getElementById('editar_rol_descripcion').value =
                boton.getAttribute('data-descripcion') || '';
            document.getElementById('editar_rol_estado').value =
                boton.getAttribute('data-estado') || '1';

            const estadoOculto =
                document.getElementById('editar_rol_estado_oculto');

            if (estadoOculto) {
                estadoOculto.value = boton.getAttribute('data-estado') || '1';
            }
        });
    }

    if (modalAbierto === 'crear') {
        const modalCrear = document.getElementById('modalCrearRol');

        if (modalCrear) {
            new bootstrap.Modal(modalCrear).show();
        }
    }

    if (modalAbierto === 'editar' && modalEditarRol) {
        new bootstrap.Modal(modalEditarRol).show();
    }

    document.querySelectorAll('.system-toast').forEach(function (toast) {
        new bootstrap.Toast(toast).show();
    });

    inicializarPanelPermisos();
});
</script>
