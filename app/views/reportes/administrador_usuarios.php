<?php
$rolesReporteAdministrador = is_array($rolesReporteAdministrador ?? null)
    ? $rolesReporteAdministrador
    : [];
$reporteAdministrador = is_array($reporteAdministrador ?? null)
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
$pendientes = is_array($reporteAdministrador['pendientes'] ?? null)
    ? $reporteAdministrador['pendientes']
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
</style>

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

            <input type="hidden" name="controller" value="reporte">
            <input type="hidden" name="action" value="usuarios">
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
                <a
                    class="btn btn-system-primary"
                    href="<?= $texto($urlExportarPdf) ?>">
                    <i class="bi bi-bar-chart me-2"></i>
                    Generar reporte
                </a>
            </div>
        </form>
    </section>

    <div class="territorial-section-title">
        <h2>RESUMEN EJECUTIVO</h2>
        <p>Indicadores administrativos calculados con el mismo alcance utilizado por el reporte PDF.</p>
    </div>

    <section class="metric-grid report-summary-grid mb-4" aria-label="Resumen ejecutivo administrativo">
        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-people"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['usuarios_registrados'] ?? 0) ?></p>
                <p class="metric-label">Usuarios</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon metric-icon-success"><i class="bi bi-person-check"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['usuarios_activos'] ?? 0) ?></p>
                <p class="metric-label">Activos</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon metric-icon-muted"><i class="bi bi-person-dash"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['usuarios_inactivos'] ?? 0) ?></p>
                <p class="metric-label">Inactivos</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-shield-check"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['roles_registrados'] ?? 0) ?></p>
                <p class="metric-label">Roles</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-list-check"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['total_seguimientos'] ?? 0) ?></p>
                <p class="metric-label">Seguimientos</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-clock-history"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['acciones_pendientes'] ?? 0) ?></p>
                <p class="metric-label">Pendientes</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-exclamation-circle"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['acciones_vencidas'] ?? 0) ?></p>
                <p class="metric-label">Vencidas</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-exclamation-diamond"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['requieren_atencion'] ?? 0) ?></p>
                <p class="metric-label">Requieren atención</p>
            </div>
        </article>
    </section>

    <div class="territorial-section-title">
        <h2>PERSONAS Y ACCESO</h2>
        <p>Distribución y detalle de los usuarios incluidos en el alcance seleccionado.</p>
    </div>

    <section class="report-two-column mb-4">
        <article class="dashboard-panel report-section">
            <div class="report-section-heading">
                <div>
                    <h3>Usuarios por rol</h3>
                </div>
            </div>

            <?php if (empty($usuariosPorRol)): ?>
                <p class="data-empty-text mb-0">
                    No se encontraron usuarios para los roles seleccionados.
                </p>
            <?php else: ?>
                <div class="territorial-bar-chart" aria-label="Usuarios por rol">
                    <?php foreach ($usuariosPorRol as $datoRol): ?>
                        <?php
                        $valorRol = (int)($datoRol['valor'] ?? 0);
                        $anchoRol = $maxUsuariosRol > 0
                            ? max(3, ($valorRol / $maxUsuariosRol) * 100)
                            : 0;
                        ?>
                        <div class="territorial-bar-row">
                            <div class="territorial-bar-label">
                                <span><?= $texto($datoRol['etiqueta'] ?? 'Sin rol') ?></span>
                                <strong><?= $valorRol ?></strong>
                            </div>
                            <div class="territorial-bar-track">
                                <span style="width: <?= number_format($anchoRol, 2, '.', '') ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel report-section">
            <div class="report-section-heading">
                <div>
                    <h3>Estado de usuarios</h3>
                </div>
            </div>

            <div class="territorial-bar-chart" aria-label="Estado de usuarios">
                <?php foreach ($estadoUsuarios as $datoEstado): ?>
                    <?php
                    $valorEstado = (int)($datoEstado['valor'] ?? 0);
                    $anchoEstado = $maxEstadoUsuarios > 0
                        ? max(3, ($valorEstado / $maxEstadoUsuarios) * 100)
                        : 0;
                    ?>
                    <div class="territorial-bar-row">
                        <div class="territorial-bar-label">
                            <span><?= $texto($datoEstado['etiqueta'] ?? '') ?></span>
                            <strong><?= $valorEstado ?></strong>
                        </div>
                        <div class="territorial-bar-track">
                            <span style="width: <?= number_format($anchoEstado, 2, '.', '') ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="dashboard-panel report-section mb-4">
        <div class="report-section-heading">
            <div>
                <h3>Usuarios del sistema</h3>
            </div>
        </div>

        <?php if (empty($usuarios)): ?>
            <p class="data-empty-text mb-0">
                No se encontraron usuarios para los roles seleccionados.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table users-table data-table align-middle report-table">
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
                                <td><?= (int)($usuario['estado'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?></td>
                                <td><?= $texto($fechaHora($usuario['ultimo_acceso'] ?? null, 'Sin acceso registrado')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="territorial-section-title">
        <h2>ATENCIÓN REQUERIDA</h2>
        <p>Acciones y seguimientos que requieren revisión dentro del alcance seleccionado.</p>
    </div>

    <section class="report-two-column mb-4" aria-label="Indicadores de atención requerida">
        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-calendar-x"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['acciones_vencidas'] ?? 0) ?></p>
                <p class="metric-label">Acciones vencidas</p>
            </div>
        </article>

        <article class="metric-card">
            <div class="metric-icon"><i class="bi bi-exclamation-diamond"></i></div>
            <div>
                <p class="metric-value"><?= (int)($resumen['requieren_atencion'] ?? 0) ?></p>
                <p class="metric-label">Requieren atención</p>
            </div>
        </article>
    </section>

    <section class="dashboard-panel report-section mb-4">
        <div class="report-section-heading">
            <div>
                <h3>Pendientes y seguimientos que requieren atención</h3>
            </div>
        </div>

        <?php if (empty($pendientes)): ?>
            <p class="data-empty-text mb-0">
                No se encontraron seguimientos con acciones programadas, sin actividad
                o con más de 7 días sin movimiento.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table users-table data-table align-middle report-table">
                    <thead>
                        <tr>
                            <th>Responsable</th>
                            <th>Institución</th>
                            <th>Estado</th>
                            <th>Estatus</th>
                            <th>Última actividad</th>
                            <th>Días</th>
                            <th>Próxima acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $pendiente): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= $texto(trim(
                                            (string)($pendiente['responsable_nombre'] ?? '') . ' ' .
                                            (string)($pendiente['responsable_apellidos'] ?? '')
                                        )) ?>
                                    </strong>
                                </td>
                                <td><?= $texto($pendiente['nombre_entidad'] ?? '—') ?></td>
                                <td><?= $texto($pendiente['estado_nombre'] ?? '—') ?></td>
                                <td><?= $texto($pendiente['estado_label'] ?? $pendiente['estado_seguimiento'] ?? '—') ?></td>
                                <td><?= $texto($fechaHora($pendiente['ultima_interaccion_at'] ?? null, 'Sin actividad')) ?></td>
                                <td>
                                    <?= $pendiente['dias_sin_actividad'] === null
                                        ? '—'
                                        : (int)$pendiente['dias_sin_actividad'] ?>
                                </td>
                                <td><?= $texto($fechaHora($pendiente['proxima_accion_at'] ?? null, 'Sin fecha')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="territorial-section-title">
        <h2>HALLAZGOS ADMINISTRATIVOS</h2>
        <p>Lecturas dinámicas construidas con los mismos resultados utilizados en el PDF.</p>
    </div>

    <section class="dashboard-panel report-section mb-4">
        <div class="report-insight-list">
            <?php foreach ($hallazgos as $hallazgo): ?>
                <div class="report-insight-item">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <p><?= $texto($hallazgo) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
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

    function mostrarError() {
        if (error) {
            error.classList.remove('d-none');
        }
    }

    function enviarFiltros() {
        formulario.submit();
    }

    function aplicarEstadoTodos(enviar) {
        const usarTodos = todos.checked;

        roles.forEach(function (checkbox) {
            checkbox.disabled = usarTodos;

            if (usarTodos) {
                checkbox.checked = false;
            }
        });

        if (usarTodos) {
            ocultarError();

            if (enviar) {
                enviarFiltros();
            }
        } else {
            const hayRolSeleccionado = roles.some(function (checkbox) {
                return checkbox.checked;
            });

            if (!hayRolSeleccionado) {
                mostrarError();
            }
        }
    }

    todos.addEventListener('change', function () {
        aplicarEstadoTodos(true);
    });

    roles.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const hayRolSeleccionado = roles.some(function (rolCheckbox) {
                return rolCheckbox.checked;
            });

            if (!hayRolSeleccionado) {
                mostrarError();
                return;
            }

            ocultarError();
            enviarFiltros();
        });
    });

    aplicarEstadoTodos(false);
});
</script>
