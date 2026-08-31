<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$estado = $estado ?? [];
$equipoTerritorial = $equipoTerritorial ?? [];
$usuariosCuentaClave = $usuariosCuentaClave ?? [];
$usuariosAnalistas = $usuariosAnalistas ?? [];
$fechaHoy = date('Y-m-d');
$tieneEquipo = !empty($equipoTerritorial);

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$nombreUsuario = function ($usuario) use ($texto) {
    return $texto(trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellidos'] ?? '')));
};

$fecha = function ($valorFecha) use ($texto) {
    if (!$valorFecha) {
        return 'No registrado';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    $meses = [
        '01' => 'ene',
        '02' => 'feb',
        '03' => 'mar',
        '04' => 'abr',
        '05' => 'may',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'ago',
        '09' => 'sep',
        '10' => 'oct',
        '11' => 'nov',
        '12' => 'dic'
    ];

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y');
};

?>

<div class="territory-team-content">
    <section class="territory-team-section">
        <h4 class="territory-team-section-title">Equipo actual</h4>

        <?php if (!empty($equipoTerritorial)): ?>

            <div class="territory-team-list">
                <?php foreach ($equipoTerritorial as $cuentaClave): ?>

                    <?php
                    $nombreCuenta = trim(
                        ($cuentaClave['nombre'] ?? '') . ' ' .
                        ($cuentaClave['apellidos'] ?? '')
                    );
                    $analistas = $cuentaClave['analistas'] ?? [];
                    ?>

                    <article class="territory-team-card">
                        <div class="territory-team-card-header">
                            <div class="territory-team-person">
                                <?= renderAvatarUsuario(
                                    $cuentaClave['nombre'] ?? '',
                                    $cuentaClave['apellidos'] ?? '',
                                    $cuentaClave['rol'] ?? 'Cuenta Clave',
                                    $cuentaClave['foto_perfil'] ?? '',
                                    'md',
                                    'cuenta-clave'
                                ) ?>

                                <div>
                                    <span class="territory-team-label">Cuenta Clave</span>
                                    <strong><?= $texto($nombreCuenta) ?></strong>
                                    <small>Desde <?= $fecha($cuentaClave['fecha_inicio'] ?? '') ?></small>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="btn btn-territory-finalize"
                                title="Finalizar Cuenta Clave"
                                aria-label="Finalizar Cuenta Clave"
                                data-bs-toggle="tooltip"
                                data-finalize-assignment
                                data-id="<?= (int)$cuentaClave['id'] ?>"
                                data-name="<?= $texto($nombreCuenta) ?>"
                                data-has-analysts="<?= !empty($analistas) ? '1' : '0' ?>"
                                data-analyst-count="<?= count($analistas) ?>">
                                <i class="bi bi-stop-circle me-2"></i>
                                Finalizar Cuenta Clave
                            </button>
                        </div>

                        <div class="territory-analyst-list">
                            <div class="territory-analyst-label">
                                <span></span>
                                Analistas
                            </div>

                            <?php if (!empty($analistas)): ?>

                                <?php foreach ($analistas as $analista): ?>

                                    <?php
                                    $nombreAnalista = trim(
                                        ($analista['nombre'] ?? '') . ' ' .
                                        ($analista['apellidos'] ?? '')
                                    );
                                    ?>

                                    <div class="territory-analyst-row">
                                        <div class="territory-team-person territory-team-person-compact">
                                            <?= renderAvatarUsuario(
                                                $analista['nombre'] ?? '',
                                                $analista['apellidos'] ?? '',
                                                $analista['rol'] ?? 'Analista de Datos',
                                                $analista['foto_perfil'] ?? '',
                                                'sm',
                                                'analista'
                                            ) ?>

                                            <div>
                                                <strong><?= $texto($nombreAnalista) ?></strong>
                                                <span>Desde <?= $fecha($analista['fecha_inicio'] ?? '') ?></span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="table-action-button table-action-warning"
                                            title="Finalizar asignación del Analista"
                                            aria-label="Finalizar asignación del Analista"
                                            data-bs-toggle="tooltip"
                                            data-finalize-assignment
                                            data-id="<?= (int)$analista['id'] ?>"
                                            data-name="<?= $texto($nombreAnalista) ?>"
                                            data-has-analysts="0">
                                            <i class="bi bi-stop-circle"></i>
                                        </button>
                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <p class="territory-empty-text">
                                    Sin analistas asignados.
                                </p>

                            <?php endif; ?>
                        </div>

                        <button
                            type="button"
                            class="btn btn-territory-expand"
                            data-team-toggle
                            data-target="formAnalista<?= (int)$cuentaClave['id'] ?>"
                            aria-expanded="false"
                            aria-controls="formAnalista<?= (int)$cuentaClave['id'] ?>">
                            <i class="bi bi-plus-circle me-2"></i>
                            Agregar analista
                        </button>

                        <div
                            class="territory-expandable-form"
                            id="formAnalista<?= (int)$cuentaClave['id'] ?>"
                            hidden>
                            <form
                                class="territory-inline-form"
                                data-team-form
                                action="<?= BASE_URL ?>index.php?controller=territorio&action=guardarAsignacion"
                                method="POST">
                                <input type="hidden" name="estado_id" value="<?= (int)$estado['id'] ?>">
                                <input type="hidden" name="tipo_asignacion" value="ANALISTA_DATOS">
                                <input
                                    type="hidden"
                                    name="cuenta_clave_asignacion_id"
                                    value="<?= (int)$cuentaClave['id'] ?>">

                                <div>
                                    <label
                                        class="form-label"
                                        for="analista_usuario_<?= (int)$cuentaClave['id'] ?>">
                                        Analista
                                    </label>
                                    <select
                                        class="form-select"
                                        id="analista_usuario_<?= (int)$cuentaClave['id'] ?>"
                                        name="usuario_id"
                                        <?= empty($usuariosAnalistas) ? 'disabled' : '' ?>
                                        required>
                                        <option value="">
                                            <?= empty($usuariosAnalistas)
                                                ? 'No hay Analistas de Datos activos disponibles'
                                                : 'Seleccionar usuario' ?>
                                        </option>

                                        <?php foreach ($usuariosAnalistas as $usuario): ?>
                                            <option value="<?= (int)$usuario['id'] ?>">
                                                <?= $nombreUsuario($usuario) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback d-block" data-field-error="usuario_id"></div>
                                    <div
                                        class="invalid-feedback d-block"
                                        data-field-error="cuenta_clave_asignacion_id"></div>
                                </div>

                                <div>
                                    <label
                                        class="form-label"
                                        for="analista_fecha_<?= (int)$cuentaClave['id'] ?>">
                                        Fecha de inicio
                                    </label>
                                    <input
                                        type="date"
                                        class="form-control"
                                        id="analista_fecha_<?= (int)$cuentaClave['id'] ?>"
                                        name="fecha_inicio"
                                        value="<?= $fechaHoy ?>">
                                    <div class="invalid-feedback d-block" data-field-error="fecha_inicio"></div>
                                </div>

                                <div class="territory-inline-actions">
                                    <button
                                        type="button"
                                        class="btn btn-system-cancel"
                                        data-team-cancel>
                                        Cancelar
                                    </button>

                                    <button
                                        type="submit"
                                        class="btn btn-territory-analyst"
                                        <?= empty($usuariosAnalistas) ? 'disabled' : '' ?>>
                                        <i class="bi bi-plus-circle me-2"></i>
                                        Agregar analista
                                    </button>
                                </div>
                            </form>
                        </div>
                    </article>

                <?php endforeach; ?>
            </div>

        <?php else: ?>

            <div class="territory-team-empty">
                <span>
                    <i class="bi bi-people"></i>
                </span>
                <strong>Sin equipo territorial</strong>
                <p>
                    Este territorio aún no tiene una Cuenta Clave asignada.
                </p>
            </div>

        <?php endif; ?>
    </section>

    <section class="territory-team-section territory-team-add">
        <div>
            <h4 class="territory-team-section-title">Agregar Cuenta Clave</h4>
            <p>Selecciona un usuario activo con rol Cuenta Clave.</p>
        </div>

        <?php if ($tieneEquipo): ?>

            <button
                type="button"
                class="btn btn-territory-expand"
                data-team-toggle
                data-target="formCuentaClave"
                aria-expanded="false"
                aria-controls="formCuentaClave">
                <i class="bi bi-plus-circle me-2"></i>
                Agregar otra Cuenta Clave
            </button>

        <?php endif; ?>

        <div
            class="territory-expandable-form"
            id="formCuentaClave"
            <?= $tieneEquipo ? 'hidden' : '' ?>>
            <form
                class="territory-inline-form"
                data-team-form
                action="<?= BASE_URL ?>index.php?controller=territorio&action=guardarAsignacion"
                method="POST">
                <input type="hidden" name="estado_id" value="<?= (int)$estado['id'] ?>">
                <input type="hidden" name="tipo_asignacion" value="CUENTA_CLAVE">

                <div>
                    <label class="form-label" for="cuenta_clave_usuario">
                        Cuenta Clave
                    </label>
                    <select
                        class="form-select"
                        id="cuenta_clave_usuario"
                        name="usuario_id"
                        <?= empty($usuariosCuentaClave) ? 'disabled' : '' ?>
                        required>
                        <option value="">
                            <?= empty($usuariosCuentaClave)
                                ? 'No hay usuarios activos con rol Cuenta Clave'
                                : 'Seleccionar usuario' ?>
                        </option>

                        <?php foreach ($usuariosCuentaClave as $usuario): ?>
                            <option value="<?= (int)$usuario['id'] ?>">
                                <?= $nombreUsuario($usuario) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback d-block" data-field-error="usuario_id"></div>
                </div>

                <div>
                    <label class="form-label" for="cuenta_clave_fecha">
                        Fecha de inicio
                    </label>
                    <input
                        type="date"
                        class="form-control"
                        id="cuenta_clave_fecha"
                        name="fecha_inicio"
                        value="<?= $fechaHoy ?>">
                    <div class="invalid-feedback d-block" data-field-error="fecha_inicio"></div>
                </div>

                <div class="territory-inline-actions">
                    <?php if ($tieneEquipo): ?>

                        <button
                            type="button"
                            class="btn btn-system-cancel"
                            data-team-cancel>
                            Cancelar
                        </button>

                    <?php endif; ?>

                    <button
                        type="submit"
                        class="btn btn-system-save"
                        <?= empty($usuariosCuentaClave) ? 'disabled' : '' ?>>
                        <i class="bi bi-plus-circle me-2"></i>
                        Agregar Cuenta Clave
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>
