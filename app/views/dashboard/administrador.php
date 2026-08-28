<?php

$usuariosRegistrados = (int)($conteoUsuarios['registrados'] ?? 0);
$usuariosActivos = (int)($conteoUsuarios['activos'] ?? 0);
$usuariosInactivos = (int)($conteoUsuarios['inactivos'] ?? 0);
$totalRoles = (int)($totalRoles ?? 0);
$usuariosPorRol = $usuariosPorRol ?? [];
$usuariosRecientes = $usuariosRecientes ?? [];

$maxUsuariosPorRol = 0;

foreach ($usuariosPorRol as $rol) {
    $maxUsuariosPorRol = max(
        $maxUsuariosPorRol,
        (int)$rol['total']
    );
}

$porcentajeActivos = $usuariosRegistrados > 0
    ? round(($usuariosActivos / $usuariosRegistrados) * 100)
    : 0;

$usuariosUrl =
    BASE_URL . 'index.php?controller=usuario&action=index';

$rolesUrl =
    BASE_URL . 'index.php?controller=home&action=index&vista=roles';

?>

<div class="metric-grid mb-4">

    <article class="metric-card">
        <div class="metric-icon">
            <i class="bi bi-people"></i>
        </div>

        <div>
            <p class="metric-value">
                <?= $usuariosRegistrados ?>
            </p>

            <p class="metric-label">
                Usuarios registrados
            </p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-success">
            <i class="bi bi-person-check"></i>
        </div>

        <div>
            <p class="metric-value">
                <?= $usuariosActivos ?>
            </p>

            <p class="metric-label">
                Usuarios activos
            </p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-muted">
            <i class="bi bi-person-dash"></i>
        </div>

        <div>
            <p class="metric-value">
                <?= $usuariosInactivos ?>
            </p>

            <p class="metric-label">
                Usuarios inactivos
            </p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon">
            <i class="bi bi-shield-lock"></i>
        </div>

        <div>
            <p class="metric-value">
                <?= $totalRoles ?>
            </p>

            <p class="metric-label">
                Roles registrados
            </p>
        </div>
    </article>

</div>

<div class="row g-4 mb-4">

    <div class="col-12 col-xl-7">
        <section class="dashboard-panel h-100">
            <h2 class="panel-title">
                Usuarios por rol
            </h2>

            <div class="role-bars">
                <?php foreach ($usuariosPorRol as $rol): ?>

                    <?php
                    $totalRol = (int)$rol['total'];
                    $ancho = $maxUsuariosPorRol > 0
                        ? round(($totalRol / $maxUsuariosPorRol) * 100)
                        : 0;
                    ?>

                    <div class="role-bar-row">
                        <span class="role-name">
                            <?= htmlspecialchars($rol['nombre']) ?>
                        </span>

                        <div
                            class="role-track"
                            aria-label="<?= htmlspecialchars($rol['nombre']) ?>">
                            <div
                                class="role-fill"
                                style="width: <?= $ancho ?>%;">
                            </div>
                        </div>

                        <span class="role-count">
                            <?= $totalRol ?>
                        </span>
                    </div>

                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-5">
        <section class="dashboard-panel h-100">
            <h2 class="panel-title">
                Estado de usuarios
            </h2>

            <div class="status-panel-body">
                <div
                    class="donut-chart"
                    style="--active-percent: <?= $porcentajeActivos ?>%;">
                    <span class="donut-label">
                        <?= $porcentajeActivos ?>%
                    </span>
                </div>

                <div class="chart-legend">
                    <div class="legend-item">
                        <span class="legend-dot legend-dot-active"></span>
                        Activos
                        <strong><?= $usuariosActivos ?></strong>
                    </div>

                    <div class="legend-item">
                        <span class="legend-dot legend-dot-inactive"></span>
                        Inactivos
                        <strong><?= $usuariosInactivos ?></strong>
                    </div>
                </div>
            </div>
        </section>
    </div>

</div>

<div class="row g-4">

    <div class="col-12 col-xl-9">
        <section class="dashboard-panel table-panel">
            <div class="table-panel-header">
                <h2 class="panel-title mb-0">
                    Usuarios recientes
                </h2>
            </div>

            <?php if (!empty($usuariosRecientes)): ?>

                <div class="table-responsive">
                    <table class="table users-table align-middle">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Último acceso</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($usuariosRecientes as $usuario): ?>

                                <tr>
                                    <td>
                                        <?= htmlspecialchars($usuario['usuario']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($usuario['correo']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($usuario['rol']) ?>
                                    </td>

                                    <td>
                                        <?php if ((int)$usuario['estado'] === 1): ?>

                                            <span class="status-pill status-pill-active">
                                                Activo
                                            </span>

                                        <?php else: ?>

                                            <span class="status-pill status-pill-inactive">
                                                Inactivo
                                            </span>

                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= $usuario['ultimo_acceso']
                                            ? htmlspecialchars($usuario['ultimo_acceso'])
                                            : 'Sin acceso registrado' ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty-table-message">
                    No hay usuarios registrados.
                </div>

            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-xl-3">
        <aside class="dashboard-panel h-100">
            <h2 class="panel-title">
                Acciones rápidas
            </h2>

            <div class="quick-actions">
                <a
                    href="<?= $usuariosUrl ?>"
                    class="quick-action">
                    <i class="bi bi-person-plus"></i>
                    Crear usuario
                </a>

                <a
                    href="<?= $usuariosUrl ?>"
                    class="quick-action">
                    <i class="bi bi-people"></i>
                    Gestionar usuarios
                </a>

                <a
                    href="<?= $rolesUrl ?>"
                    class="quick-action">
                    <i class="bi bi-shield-lock"></i>
                    Roles y permisos
                </a>
            </div>
        </aside>
    </div>

</div>
