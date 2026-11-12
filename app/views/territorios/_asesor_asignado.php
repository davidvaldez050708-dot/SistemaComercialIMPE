<?php
$nombreAsesor = trim(($asesor['nombre'] ?? '') . ' ' . ($asesor['apellidos'] ?? ''));
?>
<div class="territory-team-advisor-item">
    <div class="territory-analyst-row">
        <div class="territory-team-person territory-team-person-compact">
            <?= renderAvatarUsuario(
                $asesor['nombre'] ?? '',
                $asesor['apellidos'] ?? '',
                $asesor['rol'] ?? 'Asesor de Ventas',
                $asesor['foto_perfil'] ?? '',
                'sm',
                'analista'
            ) ?>

            <div>
                <strong><?= $texto($nombreAsesor) ?></strong>
                <span>Desde <?= $fecha($asesor['fecha_inicio'] ?? '') ?></span>
            </div>
        </div>

        <button
            type="button"
            class="btn btn-territory-finalize"
            aria-label="Eliminar asignación del Asesor"
            data-finalize-assignment
            data-assignment-type="ASESOR"
            data-id="<?= (int)$asesor['id'] ?>"
            data-name="<?= $texto($nombreAsesor) ?>"
            data-has-analysts="0">
            <i class="bi bi-stop-circle me-2"></i>
            Eliminar asignación
        </button>
    </div>
    <?php if ($tieneCuentasClave): ?>
        <button type="button" class="btn btn-territory-expand"
            data-team-toggle
            data-target="formAsesorCuenta<?= (int)$asesor['id'] ?>"
            aria-controls="formAsesorCuenta<?= (int)$asesor['id'] ?>"
            aria-expanded="false">
            <i class="bi bi-people me-2" aria-hidden="true"></i>
            <?= !empty($asesor['cuenta_clave_asignacion_id'])
                ? 'Cambiar Cuenta Clave del Asesor'
                : 'Vincular Cuenta Clave al Asesor' ?>
        </button>
        <div class="territory-expandable-form"
            id="formAsesorCuenta<?= (int)$asesor['id'] ?>" hidden>
            <form class="territory-inline-form" data-team-form
                method="POST"
                action="<?= BASE_URL ?>index.php?controller=territorio&action=vincularAsesorCuentaClave">
                <input type="hidden" name="csrf_token"
                    value="<?= $texto($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="asignacion_asesor_id"
                    value="<?= (int)$asesor['id'] ?>">
                <div>
                    <label class="form-label"
                        for="asesorCuentaClave<?= (int)$asesor['id'] ?>">
                        Cuenta Clave correspondiente
                    </label>
                    <select class="form-select"
                        id="asesorCuentaClave<?= (int)$asesor['id'] ?>"
                        name="cuenta_clave_asignacion_id" required>
                        <option value="">Selecciona Cuenta Clave</option>
                        <?php foreach ($equipoTerritorial as $cuentaAsignacion): ?>
                            <option value="<?= (int)$cuentaAsignacion['id'] ?>"
                                <?= (int)($asesor['cuenta_clave_asignacion_id'] ?? 0) ===
                                    (int)$cuentaAsignacion['id'] ? 'selected' : '' ?>>
                                <?= $nombreUsuario($cuentaAsignacion) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="territory-inline-actions">
                    <button type="button" class="btn btn-system-cancel" data-team-cancel>
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-territory-analyst">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
