<?php
$rolesReporteAdministrador = is_array($rolesReporteAdministrador ?? null)
    ? $rolesReporteAdministrador
    : [];
$reporteCargado = is_array($reporteAdministrador ?? null);
$reporteAdministrador = $reporteCargado
    ? $reporteAdministrador
    : [];
$rolesSeleccionados = is_array($rolesSeleccionados ?? null)
    ? array_values(array_map('intval', $rolesSeleccionados))
    : null;

$resumen = is_array($reporteAdministrador['resumen'] ?? null)
    ? $reporteAdministrador['resumen']
    : [];
$usuarios = is_array($reporteAdministrador['usuarios'] ?? null)
    ? $reporteAdministrador['usuarios']
    : [];
$usuariosPorRol = is_array($reporteAdministrador['usuarios_por_rol'] ?? null)
    ? $reporteAdministrador['usuarios_por_rol']
    : [];
$estadoUsuarios = is_array($reporteAdministrador['estado_usuarios'] ?? null)
    ? $reporteAdministrador['estado_usuarios']
    : [];
$hallazgos = is_array($reporteAdministrador['hallazgos'] ?? null)
    ? $reporteAdministrador['hallazgos']
    : [];

$todosRoles = $rolesSeleccionados === null;
$urlExportarPdf = (string)($urlExportarPdf ?? '');

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$fechaHora = static function ($valor, $vacio) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return (string)$vacio;
    }

    try {
        return (new DateTimeImmutable($valor))->format('d/m/Y H:i');
    } catch (Exception $error) {
        return (string)$vacio;
    }
};

$maxUsuariosRol = 0;
foreach ($usuariosPorRol as $datoRol) {
    $maxUsuariosRol = max($maxUsuariosRol, (int)($datoRol['valor'] ?? 0));
}

$maxEstadoUsuarios = 0;
foreach ($estadoUsuarios as $datoEstado) {
    $maxEstadoUsuarios = max($maxEstadoUsuarios, (int)($datoEstado['valor'] ?? 0));
}
?>

<style>
#formReporteUsuarios .form-check-label {
    font-family: inherit;
    font-size: 13px;
    line-height: 1.2;
}

.report-users .analyst-portfolio-kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.report-users-charts {
    display: grid;
    grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
    align-items: start;
    gap: 1rem;
}

.report-users-summary .report-export-action {
    margin-inline-start: auto;
}

.report-users .analyst-portfolio-table {
    table-layout: auto;
    min-width: 36rem;
}

.report-users .analyst-portfolio-table :is(th, td) {
    width: auto;
}

.report-users .report-users-status-active {
    color: #087966;
    background: rgba(10, 143, 122, .07);
    border-color: rgba(10, 143, 122, .18);
}

.report-users .report-users-finding + .report-users-finding {
    border-top: 1px solid var(--color-border);
}

.report-users .report-users-finding .report-insight-item {
    padding: 13px 18px;
}

@media (max-width: 1199.98px) {
    .report-users-charts {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 991.98px) {
    .report-users .analyst-portfolio-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .report-users .analyst-portfolio-kpis {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="report-module report-territorial-module report-users">
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

            <input type="hidden" name="controller" value="reporte">
            <input type="hidden" name="action" value="usuarios">
            <input type="hidden" name="filtrar_roles" value="1">
            <input type="hidden" name="generar" value="1">

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
                            <?= $todosRoles ? 'checked' : '' ?>>

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
                            $rolSeleccionado = !$todosRoles && in_array($rolId, $rolesSeleccionados, true);
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
                                            <?= $rolSeleccionado ? 'checked' : '' ?>
                                            <?= $todosRoles ? 'disabled' : '' ?>>

                                        <label
                                            class="form-check-label"
                                            for="reporteRol<?= $rolId ?>">
                                            <?= $texto($rolNombre) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="text-muted small mt-3 mb-1">
                    Los usuarios incluidos en la vista y en el PDF dependen de los roles seleccionados.
                </p>

                <div
                    class="text-danger small d-none"
                    id="reporteUsuariosRolesError"
                    role="alert">
                    Selecciona al menos un rol o la opción “Todos los roles”.
                </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button
                    class="btn btn-system-primary"
                    type="submit">
                    <i class="bi bi-bar-chart me-2"></i>
                    Generar reporte
                </button>
            </div>
        </form>
    </section>

    <?php if (!$reporteCargado): ?>
        <section class="dashboard-panel data-empty-state report-empty-state">
            <span><i class="bi bi-file-earmark-bar-graph"></i></span>
            <strong>Selecciona los roles y genera el reporte para consultar la información.</strong>
            <p>La información administrativa aparecerá aquí antes de que decidas exportarla a PDF.</p>
        </section>
    <?php else: ?>
    <div class="report-users-summary d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
            <h2 class="report-eyebrow mb-1">Resumen ejecutivo</h2>
            <p class="page-subtitle mb-0">Indicadores administrativos calculados con el mismo alcance utilizado por el reporte PDF.</p>
        </div>
        <a
            class="btn btn-system-save report-export-action"
            href="<?= $texto($urlExportarPdf) ?>">
            <i class="bi bi-file-earmark-pdf"></i>
            Exportar PDF
        </a>
    </div>

    <section class="analyst-portfolio-kpis mb-3" aria-label="Resumen ejecutivo administrativo">
        <article class="analyst-portfolio-kpi">
            <span class="analyst-portfolio-kpi-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
            <div>
                <strong><?= (int)($resumen['usuarios_registrados'] ?? 0) ?></strong>
                <span>Usuarios</span>
            </div>
        </article>

        <article class="analyst-portfolio-kpi analyst-portfolio-kpi--success">
            <span class="analyst-portfolio-kpi-icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
            <div>
                <strong><?= (int)($resumen['usuarios_activos'] ?? 0) ?></strong>
                <span>Activos</span>
            </div>
        </article>

        <article class="analyst-portfolio-kpi analyst-portfolio-kpi--muted">
            <span class="analyst-portfolio-kpi-icon" aria-hidden="true"><i class="bi bi-person-dash"></i></span>
            <div>
                <strong><?= (int)($resumen['usuarios_inactivos'] ?? 0) ?></strong>
                <span>Inactivos</span>
            </div>
        </article>

        <article class="analyst-portfolio-kpi">
            <span class="analyst-portfolio-kpi-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
            <div>
                <strong><?= (int)($resumen['roles_registrados'] ?? 0) ?></strong>
                <span>Roles</span>
            </div>
        </article>
    </section>

    <section class="report-users-charts mb-3" aria-label="Personas y acceso">
        <article class="dashboard-panel analyst-portfolio-stage">
            <div class="analyst-portfolio-section-heading">
                <div>
                    <span class="report-eyebrow">PERSONAS Y ACCESO</span>
                    <h3 class="panel-title mb-1">Usuarios por rol</h3>
                    <p class="page-subtitle mb-0">Distribución de los usuarios incluidos en el alcance seleccionado.</p>
                </div>
            </div>

            <?php if (empty($usuariosPorRol)): ?>
                <p class="data-empty-text mb-0">
                    No se encontraron usuarios para los roles seleccionados.
                </p>
            <?php else: ?>
                <div class="analyst-portfolio-stage-list">
                    <?php foreach ($usuariosPorRol as $datoRol): ?>
                        <?php
                        $valorRol = (int)($datoRol['valor'] ?? 0);
                        $anchoRol = $maxUsuariosRol > 0
                            ? max(3, ($valorRol / $maxUsuariosRol) * 100)
                            : 0;
                        ?>
                        <div class="analyst-portfolio-stage-item">
                            <div>
                                <strong><?= $texto($datoRol['etiqueta'] ?? 'Sin rol') ?></strong>
                            </div>
                            <div class="analyst-portfolio-stage-progress" aria-hidden="true">
                                <span style="width: <?= number_format($anchoRol, 2, '.', '') ?>%"></span>
                            </div>
                            <b><?= $valorRol ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel analyst-portfolio-territory">
            <div class="analyst-portfolio-section-heading">
                <div>
                    <span class="report-eyebrow">CUENTAS DEL SISTEMA</span>
                    <h3 class="panel-title mb-1">Estado de usuarios</h3>
                    <p class="page-subtitle mb-0">Usuarios activos e inactivos de los roles seleccionados.</p>
                </div>
            </div>

            <div class="analyst-portfolio-territory-list">
                <?php foreach ($estadoUsuarios as $datoEstado): ?>
                    <?php
                    $valorEstado = (int)($datoEstado['valor'] ?? 0);
                    $anchoEstado = $maxEstadoUsuarios > 0
                        ? max(3, ($valorEstado / $maxEstadoUsuarios) * 100)
                        : 0;
                    ?>
                    <div>
                        <div class="analyst-portfolio-territory-copy">
                            <strong><?= $texto($datoEstado['etiqueta'] ?? '') ?></strong>
                            <span><?= $valorEstado ?></span>
                        </div>
                        <div class="analyst-portfolio-territory-progress" aria-hidden="true">
                            <span style="width: <?= number_format($anchoEstado, 2, '.', '') ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="dashboard-panel p-0 overflow-hidden analyst-portfolio-detail mb-3">
        <div class="table-panel-header">
            <div>
                <span class="report-eyebrow">DETALLE DE USUARIOS</span>
                <h3 class="panel-title mb-0">Usuarios del sistema</h3>
            </div>
            <span class="analyst-portfolio-detail-count"><?= count($usuarios) ?> usuarios</span>
        </div>

        <?php if (empty($usuarios)): ?>
            <p class="data-empty-text px-3 pb-3 mb-0">
                No se encontraron usuarios para los roles seleccionados.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table users-table align-middle mb-0 analyst-portfolio-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Último acceso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><strong><?= $texto($usuario['usuario'] ?? '—') ?></strong></td>
                                <td>
                                    <?= $texto(trim(
                                        (string)($usuario['nombre'] ?? '') . ' ' .
                                        (string)($usuario['apellidos'] ?? '')
                                    )) ?>
                                </td>
                                <td><?= $texto($usuario['rol'] ?? '—') ?></td>
                                <td>
                                    <span class="analyst-portfolio-stage-pill<?= (int)($usuario['estado'] ?? 0) === 1 ? ' report-users-status-active' : '' ?>">
                                        <?= (int)($usuario['estado'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td><?= $texto($fechaHora($usuario['ultimo_acceso'] ?? null, 'Sin acceso registrado')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel p-0 overflow-hidden analyst-portfolio-detail mb-4">
        <div class="table-panel-header">
            <div>
                <span class="report-eyebrow">REVISIÓN ADMINISTRATIVA</span>
                <h3 class="panel-title mb-0">Hallazgos administrativos</h3>
                <p class="page-subtitle mb-0 mt-1">Lecturas dinámicas construidas con los mismos resultados utilizados en el PDF.</p>
            </div>
        </div>
        <?php foreach ($hallazgos as $hallazgo): ?>
            <div class="report-users-finding">
                <div class="report-insight-item">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <p><?= $texto($hallazgo['texto']) ?></p>
                </div>
                <?php if (!empty($hallazgo['filas'])): ?>
                    <div class="table-responsive">
                        <table class="table users-table align-middle mb-0 analyst-portfolio-table">
                            <thead>
                                <tr>
                                    <?php foreach ($hallazgo['columnas'] as $columna): ?>
                                        <th scope="col"><?= $texto($columna) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hallazgo['filas'] as $fila): ?>
                                    <tr>
                                        <?php foreach ($fila as $valor): ?>
                                            <td><?= $texto($valor) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php endif; ?>
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

    function mostrarError() {
        if (error) {
            error.classList.remove('d-none');
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

        if (usarTodos) {
            ocultarError();
            return;
        }

        const hayRolSeleccionado = roles.some(function (checkbox) {
            return checkbox.checked;
        });

        if (hayRolSeleccionado) {
            ocultarError();
        } else {
            mostrarError();
        }
    }

    todos.addEventListener('change', aplicarEstadoTodos);

    roles.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const hayRolSeleccionado = roles.some(function (rolCheckbox) {
                return rolCheckbox.checked;
            });

            if (hayRolSeleccionado) {
                ocultarError();
            } else {
                mostrarError();
            }
        });
    });

    formulario.addEventListener('submit', function (event) {
        const hayRolSeleccionado = roles.some(function (checkbox) {
            return checkbox.checked && !checkbox.disabled;
        });

        if (!todos.checked && !hayRolSeleccionado) {
            event.preventDefault();
            mostrarError();
        }
    });

    aplicarEstadoTodos();
});
</script>
