<?php

$rolSeleccionado = $rolSeleccionado ?? null;
$permisosAgrupados = $permisosAgrupados ?? [];
$permisosRol = $permisosRol ?? [];
$puedeAsignarPermisos = tienePermiso('roles.asignar_permisos');

$textoRol = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$rolEsAdministrador = $rolSeleccionado &&
    (int)$rolSeleccionado['id'] === 1;
$panelBloqueado = !$puedeAsignarPermisos || $rolEsAdministrador;
$totalPermisos = 0;

foreach ($permisosAgrupados as $permisos) {
    $totalPermisos += count($permisos);
}

$permisosHabilitados = $rolEsAdministrador
    ? $totalPermisos
    : count($permisosRol);

?>

<?php if (!$rolSeleccionado): ?>

    <div class="roles-empty-panel">
        <div class="roles-empty-icon">
            <i class="bi bi-shield-lock"></i>
        </div>

        <h2>Selecciona un rol</h2>
        <p>Elige un perfil para consultar sus permisos.</p>
    </div>

<?php else: ?>

    <div class="roles-permissions-header">
        <div>
            <h2 class="panel-title mb-1">
                Permisos del rol
            </h2>

            <p class="roles-permissions-subtitle">
                <?= $textoRol($rolSeleccionado['nombre']) ?>
            </p>
        </div>

        <div class="roles-permissions-summary">
            <span
                class="permissions-count"
                data-permissions-count
                data-total="<?= (int)$totalPermisos ?>">
                <?= $rolEsAdministrador
                    ? 'Acceso completo'
                    : (int)$permisosHabilitados . ' de ' .
                        (int)$totalPermisos . ' permisos habilitados' ?>
            </span>

            <span class="status-pill <?= (int)$rolSeleccionado['estado'] === 1
                ? 'status-pill-active'
                : 'status-pill-inactive' ?>">
                <?= (int)$rolSeleccionado['estado'] === 1 ? 'Activo' : 'Inactivo' ?>
            </span>
        </div>
    </div>

    <?php if ($rolEsAdministrador): ?>

        <div class="roles-note">
            <i class="bi bi-shield-check"></i>
            El rol Administrador conserva siempre acceso completo al sistema.
        </div>

    <?php elseif (!$puedeAsignarPermisos): ?>

        <div class="roles-note">
            <i class="bi bi-info-circle"></i>
            Puedes consultar estos permisos, pero no modificarlos.
        </div>

    <?php endif; ?>

    <form
        class="roles-permissions-form"
        action="<?= BASE_URL ?>index.php?controller=rol&action=guardarPermisos"
        method="POST"
        data-permissions-form
        data-admin="<?= $rolEsAdministrador ? '1' : '0' ?>">

        <input
            type="hidden"
            name="rol_id"
            value="<?= (int)$rolSeleccionado['id'] ?>">

        <div class="roles-permissions-tools">
            <div class="module-search permission-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    class="form-control"
                    placeholder="Buscar permiso..."
                    aria-label="Buscar permiso"
                    data-permission-search>
            </div>
        </div>

        <div class="permissions-grid">
            <?php foreach ($permisosAgrupados as $modulo => $permisos): ?>

                <section
                    class="permission-group"
                    data-permission-group>
                    <div class="permission-group-header">
                        <h3>
                            <?= $textoRol($modulo) ?>
                        </h3>

                        <button
                            type="button"
                            class="permission-group-toggle"
                            data-permission-group-toggle
                            <?= $panelBloqueado ? 'disabled' : '' ?>>
                            Marcar todos
                        </button>
                    </div>

                    <div class="permission-list">
                        <?php foreach ($permisos as $permiso): ?>

                            <?php
                            $permisoActivo = $rolEsAdministrador ||
                                isset($permisosRol[(int)$permiso['id']]);
                            $checkboxId =
                                'permiso_' .
                                (int)$rolSeleccionado['id'] .
                                '_' .
                                (int)$permiso['id'];
                            ?>

                            <label
                                class="permission-option"
                                for="<?= $checkboxId ?>"
                                data-permission-row
                                data-search="<?= $textoRol(
                                    $modulo . ' ' .
                                    $permiso['nombre'] . ' ' .
                                    $permiso['descripcion'] . ' ' .
                                    $permiso['codigo']
                                ) ?>">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="<?= $checkboxId ?>"
                                    name="permisos[]"
                                    value="<?= (int)$permiso['id'] ?>"
                                    <?= $permisoActivo ? 'checked' : '' ?>
                                    <?= $panelBloqueado ? 'disabled' : '' ?>>

                                <span>
                                    <strong><?= $textoRol($permiso['nombre']) ?></strong>
                                    <small><?= $textoRol($permiso['descripcion']) ?></small>
                                </span>
                            </label>

                        <?php endforeach; ?>
                    </div>
                </section>

            <?php endforeach; ?>
        </div>

        <div class="roles-permissions-footer">
            <span
                class="permissions-changes-status"
                data-permissions-status>
                <?= $rolEsAdministrador ? 'Acceso completo' : 'Sin cambios pendientes' ?>
            </span>

            <button
                type="submit"
                class="btn btn-system-save"
                data-permissions-save
                disabled>
                <i class="bi bi-check2-circle me-2"></i>
                Guardar cambios
            </button>
        </div>
    </form>

<?php endif; ?>
