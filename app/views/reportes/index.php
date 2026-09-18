<?php
$puedeReporteTerritorial = $puedeReporteTerritorial ?? false;
$puedeReporteSeguimiento = $puedeReporteSeguimiento ?? false;
$puedeReporteAdministrador = $puedeReporteAdministrador ?? false;
$rolesReporteAdministrador = is_array($rolesReporteAdministrador ?? null)
    ? $rolesReporteAdministrador
    : [];
?>

<section class="report-module">
    <section class="dashboard-panel report-intro-panel">
        <div>
            <span class="report-eyebrow">CENTRO DE REPORTES</span>
            <h2 class="panel-title mb-1">Selecciona la información que deseas analizar</h2>
            <p class="page-subtitle mb-0">
                Cada reporte conserva el alcance de información permitido para tu usuario y utiliza los datos registrados en su módulo de origen.
            </p>
        </div>
        <span class="metric-icon report-intro-icon" aria-hidden="true">
            <i class="bi bi-file-earmark-bar-graph"></i>
        </span>
    </section>

    <div class="report-catalog-grid">
        <?php if ($puedeReporteTerritorial): ?>
            <article class="dashboard-panel report-catalog-card report-catalog-card--territorial">
                <div class="report-card-heading">
                    <span class="metric-icon report-card-icon">
                        <i class="bi bi-map"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">INFORMACIÓN TERRITORIAL</span>
                        <h3>Reporte territorial</h3>
                    </div>
                </div>

                <p class="report-card-description">
                    Integra datos generales del Estado, actividad económica, poder adquisitivo,
                    educación, priorización municipal, cálculos derivados y fuentes oficiales.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-check2-circle"></i> Datos oficiales y registrados</span>
                    <span><i class="bi bi-calculator"></i> Indicadores y comparaciones</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Exportación a PDF</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="<?= BASE_URL ?>index.php?controller=dataTerritorialReporte&action=index">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Generar reporte</span>
                </a>
                <span class="report-card-decoration report-card-decoration--territorial" aria-hidden="true">
                    <i class="bi bi-map-fill"></i>
                </span>
            </article>
        <?php endif; ?>

        <?php if ($puedeReporteSeguimiento): ?>
            <article class="dashboard-panel report-catalog-card report-catalog-card--seguimiento">
                <div class="report-card-heading">
                    <span class="metric-icon report-card-icon">
                        <i class="bi bi-kanban"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">SEGUIMIENTO DE VINCULACIÓN</span>
                        <h3>Reporte de seguimiento</h3>
                    </div>
                </div>

                <p class="report-card-description">
                    Consulta el generador de reportes de Seguimiento de vinculación con sus filtros,
                    indicadores y exportación actualmente implementados.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-funnel"></i> Filtros de seguimiento</span>
                    <span><i class="bi bi-activity"></i> Actividad e indicadores</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Consulta y exportación</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacionReporte&action=index&origen=reportes">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Abrir reporte</span>
                </a>
                <span class="report-card-decoration report-card-decoration--seguimiento" aria-hidden="true">
                    <i class="bi bi-bar-chart-fill"></i>
                </span>
            </article>
        <?php endif; ?>

        <?php if ($puedeReporteAdministrador): ?>
            <article class="dashboard-panel report-catalog-card report-catalog-card--usuarios">
                <div class="report-card-heading">
                    <span class="metric-icon report-card-icon">
                        <i class="bi bi-people"></i>
                    </span>
                    <div>
                        <span class="report-card-kicker">ADMINISTRACIÓN</span>
                        <h3>Reporte de usuarios</h3>
                    </div>
                </div>

                <p class="report-card-description">
                    Consolida la información de usuarios del sistema, su estado, último acceso,
                    carga de seguimientos, acciones programadas y casos que requieren atención.
                </p>

                <div class="report-card-meta">
                    <span><i class="bi bi-person-check"></i> Usuarios, roles y estado</span>
                    <span><i class="bi bi-list-check"></i> Seguimientos y pendientes</span>
                    <span><i class="bi bi-file-earmark-pdf"></i> Exportación a PDF</span>
                </div>

                <a
                    class="btn btn-system-save report-card-action"
                    href="#modalReporteUsuarios"
                    data-bs-toggle="modal"
                    data-bs-target="#modalReporteUsuarios">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                    <span>Generar reporte</span>
                </a>
                <span class="report-card-decoration report-card-decoration--usuarios" aria-hidden="true">
                    <i class="bi bi-people-fill"></i>
                </span>
            </article>
        <?php endif; ?>
    </div>

    <?php if ($puedeReporteAdministrador): ?>
        <div
            class="modal fade"
            id="modalReporteUsuarios"
            tabindex="-1"
            aria-labelledby="modalReporteUsuariosTitulo"
            aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0">
                        <div class="d-flex align-items-start gap-3">
                            <span class="metric-icon report-card-icon" aria-hidden="true">
                                <i class="bi bi-people"></i>
                            </span>

                            <div>
                                <span class="report-card-kicker">FILTROS DEL REPORTE</span>
                                <h2
                                    class="modal-title fs-5 mt-1"
                                    id="modalReporteUsuariosTitulo">
                                    Generar reporte de usuarios
                                </h2>
                                <p class="text-muted small mb-0">
                                    Selecciona los roles que deseas incluir en el reporte.
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Cerrar">
                        </button>
                    </div>

                    <form
                        id="formReporteUsuarios"
                        action="<?= BASE_URL ?>index.php"
                        method="GET">

                        <input type="hidden" name="controller" value="reporteAdministrador">
                        <input type="hidden" name="action" value="exportarPdf">
                        <input type="hidden" name="filtrar_roles" value="1">

                        <div class="modal-body">
                            <label class="form-label fw-semibold mb-2">
                                Roles de usuario
                            </label>

                            <div class="border rounded-3 p-3">
                                <div class="form-check pb-2 mb-2 border-bottom">
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

                                <?php foreach ($rolesReporteAdministrador as $rol): ?>
                                    <?php
                                    $rolId = (int)($rol['id'] ?? 0);
                                    $rolNombre = trim((string)($rol['nombre'] ?? ''));
                                    ?>
                                    <?php if ($rolId > 0 && $rolNombre !== ''): ?>
                                        <div class="form-check mb-2">
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
                                    <?php endif; ?>
                                <?php endforeach; ?>
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

                        <div class="modal-footer border-0 pt-0">
                            <button
                                type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal">
                                Cancelar
                            </button>

                            <button
                                type="submit"
                                class="btn btn-system-save">
                                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                                <span>Generar reporte</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('modalReporteUsuarios');
            const formulario = document.getElementById('formReporteUsuarios');
            const todos = document.getElementById('reporteUsuariosTodosRoles');
            const roles = Array.from(document.querySelectorAll('.js-reporte-rol'));
            const error = document.getElementById('reporteUsuariosRolesError');

            if (!modal || !formulario || !todos) {
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

            modal.addEventListener('show.bs.modal', function () {
                todos.checked = true;
                roles.forEach(function (checkbox) {
                    checkbox.checked = false;
                });
                aplicarEstadoTodos();
            });

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
        });
        </script>
    <?php endif; ?>
</section>
