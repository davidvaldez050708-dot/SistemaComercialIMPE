<?php
$rolesReporteAdministrador = is_array($rolesReporteAdministrador ?? null)
    ? $rolesReporteAdministrador
    : [];
?>

<section class="report-module report-territorial-module">
    <a
        class="linkage-back-link territorial-back-link"
        href="<?= BASE_URL ?>index.php?controller=reporte&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a Reportes
    </a>

    <section class="dashboard-panel report-filter-panel mb-4">
        <div class="report-filter-heading">
            <div>
                <span class="report-eyebrow">ADMINISTRACIÓN</span>
                <h2 class="panel-title mb-1">Generar reporte de usuarios</h2>
                <p class="page-subtitle mb-0">
                    Selecciona los roles que deseas incluir para generar el reporte administrativo de usuarios.
                </p>
            </div>

            <span class="metric-icon" aria-hidden="true">
                <i class="bi bi-people"></i>
            </span>
        </div>

        <form
            id="formReporteUsuarios"
            action="<?= BASE_URL ?>index.php"
            method="GET"
            class="mt-3">

            <input type="hidden" name="controller" value="reporteAdministrador">
            <input type="hidden" name="action" value="exportarPdf">
            <input type="hidden" name="filtrar_roles" value="1">

            <div class="report-filter-field">
                <label class="form-label fw-semibold mb-2">
                    Roles de usuario
                </label>

                <div class="border rounded-3 p-3 bg-white">
                    <div class="form-check pb-2 mb-3 border-bottom">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            value="1"
                            id="reporteUsuariosTodosRoles"
                            name="todos_roles"
                            checked>

                        <label
                            class="form-check-label fw-semibold"
                            for="reporteUsuariosTodosRoles">
                            Todos los roles
                        </label>
                    </div>

                    <div class="row row-cols-1 row-cols-md-2 g-2">
                        <?php foreach ($rolesReporteAdministrador as $rol): ?>
                            <?php
                            $rolId = (int)($rol['id'] ?? 0);
                            $rolNombre = trim((string)($rol['nombre'] ?? ''));
                            ?>

                            <?php if ($rolId > 0 && $rolNombre !== ''): ?>
                                <div class="col">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input js-reporte-rol"
                                            type="checkbox"
                                            name="roles[]"
                                            value="<?= $rolId ?>"
                                            id="reporteRol<?= $rolId ?>"
                                            disabled>

                                        <label
                                            class="form-check-label"
                                            for="reporteRol<?= $rolId ?>">
                                            <?= htmlspecialchars($rolNombre, ENT_QUOTES, 'UTF-8') ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="text-muted small mt-3 mb-1">
                    Los usuarios incluidos en el reporte dependerán de los roles seleccionados.
                </p>

                <div
                    class="text-danger small d-none"
                    id="reporteUsuariosRolesError"
                    role="alert">
                    Selecciona al menos un rol o la opción “Todos los roles”.
                </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button class="btn btn-system-primary" type="submit">
                    <i class="bi bi-bar-chart me-2"></i>
                    Generar reporte
                </button>
            </div>
        </form>
    </section>

    <section class="dashboard-panel data-empty-state report-empty-state">
        <span><i class="bi bi-people"></i></span>
        <strong>Selecciona los roles que deseas incluir en el reporte.</strong>
        <p>
            El reporte consolidará la información administrativa de los usuarios
            correspondientes a los roles seleccionados.
        </p>
    </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formulario = document.getElementById('formReporteUsuarios');
    const todos = document.getElementById('reporteUsuariosTodosRoles');
    const roles = Array.from(document.querySelectorAll('.js-reporte-rol'));
    const error = document.getElementById('reporteUsuariosRolesError');

    if (!formulario || !todos) {
        return;
    }

    function ocultarError() {
        if (error) {
            error.classList.add('d-none');
        }
    }

    function aplicarEstadoTodos() {
        const usarTodos = todos.checked;

        roles.forEach(function (checkbox) {
            checkbox.disabled = usarTodos;

            if (usarTodos) {
                checkbox.checked = false;
            }
        });

        ocultarError();
    }

    todos.addEventListener('change', aplicarEstadoTodos);

    roles.forEach(function (checkbox) {
        checkbox.addEventListener('change', ocultarError);
    });

    formulario.addEventListener('submit', function (event) {
        const hayRolSeleccionado = roles.some(function (checkbox) {
            return checkbox.checked && !checkbox.disabled;
        });

        if (!todos.checked && !hayRolSeleccionado) {
            event.preventDefault();

            if (error) {
                error.classList.remove('d-none');
            }
        }
    });

    aplicarEstadoTodos();
});
</script>
