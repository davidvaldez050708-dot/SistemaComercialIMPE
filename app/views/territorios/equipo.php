<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$estado = $estado ?? [];
$equipoTerritorial = $equipoTerritorial ?? [];
$analistasSinCuentaClave = $analistasSinCuentaClave ?? [];
$usuariosCuentaClave = $usuariosCuentaClave ?? [];
$usuariosAnalistas = $usuariosAnalistas ?? [];
$asesoresTerritorio = $asesoresTerritorio ?? [];
$usuariosAsesores = $usuariosAsesores ?? [];
$fechaHoy = date('Y-m-d');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$tieneCuentasClave = !empty($equipoTerritorial);
$idsCuentasClave = array_fill_keys(array_map(
    static function ($cuenta) { return (int)$cuenta['id']; },
    $equipoTerritorial
), true);
$asesoresPorCuenta = [];
$asesoresSinCuenta = [];
foreach ($asesoresTerritorio as $asesorTerritorio) {
    $cuentaId = (int)($asesorTerritorio['cuenta_clave_asignacion_id'] ?? 0);
    if (isset($idsCuentasClave[$cuentaId])) {
        $asesoresPorCuenta[$cuentaId][] = $asesorTerritorio;
    } else {
        $asesoresSinCuenta[] = $asesorTerritorio;
    }
}

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

<div class="territory-team-content<?= $tieneCuentasClave ? ' territory-team-has-accounts' : '' ?>">
    <section class="territory-team-section">
        <h4 class="territory-team-section-title">Equipo actual</h4>

        <?php if ($tieneCuentasClave): ?>

            <div class="territory-team-list">
                <?php foreach ($equipoTerritorial as $cuentaClave): ?>

                    <?php
                    $nombreCuenta = trim(
                        ($cuentaClave['nombre'] ?? '') . ' ' .
                        ($cuentaClave['apellidos'] ?? '')
                    );
                    $analistas = $cuentaClave['analistas'] ?? [];
                    $asesoresCuenta = $asesoresPorCuenta[(int)$cuentaClave['id']] ?? [];
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

                        <div class="territory-team-summary" aria-label="Resumen de asignaciones">
                            <span class="territory-team-active"><i class="bi bi-circle-fill" aria-hidden="true"></i> Activa</span>
                            <button type="button" data-team-filter="analistas" aria-pressed="true" aria-label="Mostrar analistas de <?= $texto($nombreCuenta) ?>">
                                <i class="bi bi-people" aria-hidden="true"></i> <?= count($analistas) ?> <?= count($analistas) === 1 ? 'analista' : 'analistas' ?>
                            </button>
                            <button type="button" data-team-filter="asesores" aria-pressed="false" aria-label="Mostrar asesores de <?= $texto($nombreCuenta) ?>">
                                <i class="bi bi-people" aria-hidden="true"></i> <?= count($asesoresCuenta) ?> <?= count($asesoresCuenta) === 1 ? 'asesor' : 'asesores' ?>
                            </button>
                            <button type="button" class="btn btn-territory-expand territory-team-shared-button"
                                data-team-shared-action="formCuentaClave" aria-controls="formCuentaClave" aria-expanded="false">
                                <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Agregar otra Cuenta Clave
                            </button>
                        </div>
                        <div data-team-shared-slot="formCuentaClave"></div>

                        <div class="territory-team-pane" data-team-pane="analistas">
                        <div class="territory-analyst-list">
                            <div class="territory-analyst-label">
                                <span></span>
                                Analistas asignados (<?= count($analistas) ?>)
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
                        </div>

                        <div class="territory-team-pane" data-team-pane="asesores" hidden>
                            <div class="territory-analyst-list territory-team-advisors">
                                <div class="territory-analyst-label"><span></span>Asesores asignados (<?= count($asesoresCuenta) ?>)</div>
                                <?php foreach ($asesoresCuenta as $asesor): ?>
                                    <?php require __DIR__ . '/_asesor_asignado.php'; ?>
                                <?php endforeach; ?>
                                <?php if (!$asesoresCuenta): ?>
                                    <p class="territory-empty-text">Sin asesores asignados.</p>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-territory-expand"
                                data-team-shared-action="formAsesor" aria-controls="formAsesor" aria-expanded="false">
                                <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Agregar asesor
                            </button>
                            <div data-team-shared-slot="formAsesor"></div>
                        </div>
                    </article>

                <?php endforeach; ?>
            </div>

        <?php elseif (!empty($analistasSinCuentaClave)): ?>

            <article class="territory-team-card territory-unassigned-account-card">
                <div class="territory-team-card-header">
                    <div class="territory-team-person">
                        <?= renderAvatarUsuario(
                            'Sin',
                            'asignar',
                            'Cuenta Clave',
                            '',
                            'md',
                            'cuenta-clave',
                            '',
                            false
                        ) ?>

                        <div>
                            <span class="territory-team-label">Cuenta Clave</span>
                            <strong>Sin asignar</strong>
                            <small>Analistas activos en el territorio</small>
                        </div>
                    </div>
                </div>
            </article>

        <?php else: ?>

            <div class="territory-team-empty">
                <span>
                    <i class="bi bi-people"></i>
                </span>
                <strong>Sin equipo territorial</strong>
                <p>
                    Este territorio aún no tiene Cuenta Clave ni Analistas activos.
                </p>
            </div>

        <?php endif; ?>
    </section>

    <?php if (!empty($analistasSinCuentaClave)): ?>

        <section class="territory-team-section">
            <h4 class="territory-team-section-title">Analistas sin Cuenta Clave</h4>

            <div class="territory-team-list">
                <?php foreach ($analistasSinCuentaClave as $analistaSinCuenta): ?>

                    <?php
                    $nombreAnalistaSinCuenta = trim(
                        ($analistaSinCuenta['nombre'] ?? '') . ' ' .
                        ($analistaSinCuenta['apellidos'] ?? '')
                    );
                    $formReasignarId =
                        'formReasignarAnalista' . (int)$analistaSinCuenta['id'];
                    ?>

                    <article class="territory-team-card territory-orphan-analyst-card">
                        <div class="territory-team-card-header">
                            <div class="territory-team-person">
                                <?= renderAvatarUsuario(
                                    $analistaSinCuenta['nombre'] ?? '',
                                    $analistaSinCuenta['apellidos'] ?? '',
                                    $analistaSinCuenta['rol'] ?? 'Analista de Datos',
                                    $analistaSinCuenta['foto_perfil'] ?? '',
                                    'sm',
                                    'analista'
                                ) ?>

                                <div>
                                    <span class="territory-team-label">Analista de Datos</span>
                                    <strong><?= $texto($nombreAnalistaSinCuenta) ?></strong>
                                    <small>Desde <?= $fecha($analistaSinCuenta['fecha_inicio'] ?? '') ?></small>
                                </div>
                            </div>

                            <?php if ($tieneCuentasClave): ?>

                                <button
                                    type="button"
                                    class="btn btn-territory-expand"
                                    data-team-toggle
                                    data-target="<?= $formReasignarId ?>"
                                    aria-expanded="false"
                                    aria-controls="<?= $formReasignarId ?>">
                                    <i class="bi bi-link-45deg me-2"></i>
                                    Asignar
                                </button>

                            <?php endif; ?>
                        </div>

                        <?php if ($tieneCuentasClave): ?>

                            <div
                                class="territory-expandable-form"
                                id="<?= $formReasignarId ?>"
                                hidden>
                                <form
                                    class="territory-inline-form"
                                    data-team-form
                                    action="<?= BASE_URL ?>index.php?controller=territorio&action=reasociarAnalistaCuentaClave"
                                    method="POST">
                                    <input
                                        type="hidden"
                                        name="asignacion_analista_id"
                                        value="<?= (int)$analistaSinCuenta['id'] ?>">

                                    <div>
                                        <label
                                            class="form-label"
                                            for="reasignar_cuenta_clave_<?= (int)$analistaSinCuenta['id'] ?>">
                                            Cuenta Clave
                                        </label>
                                        <select
                                            class="form-select"
                                            id="reasignar_cuenta_clave_<?= (int)$analistaSinCuenta['id'] ?>"
                                            name="cuenta_clave_asignacion_id"
                                            required>
                                            <option value="">Seleccionar Cuenta Clave</option>

                                            <?php foreach ($equipoTerritorial as $cuentaClave): ?>
                                                <?php
                                                $nombreCuentaAsignar = trim(
                                                    ($cuentaClave['nombre'] ?? '') . ' ' .
                                                    ($cuentaClave['apellidos'] ?? '')
                                                );
                                                ?>
                                                <option
                                                    value="<?= (int)$cuentaClave['id'] ?>"
                                                    <?= count($equipoTerritorial) === 1 ? 'selected' : '' ?>>
                                                    <?= $texto($nombreCuentaAsignar) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div
                                            class="invalid-feedback d-block"
                                            data-field-error="cuenta_clave_asignacion_id"></div>
                                        <div
                                            class="invalid-feedback d-block"
                                            data-field-error="asignacion_analista_id"></div>
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
                                            class="btn btn-territory-analyst">
                                            <i class="bi bi-check2-circle me-2"></i>
                                            Guardar
                                        </button>
                                    </div>
                                </form>
                            </div>

                        <?php else: ?>

                            <p class="territory-empty-text mt-2">
                                Sin Cuenta Clave activa para asociar.
                            </p>

                        <?php endif; ?>
                    </article>

                <?php endforeach; ?>
            </div>
        </section>

    <?php endif; ?>

    <section class="territory-team-section territory-team-global-advisors<?= $asesoresSinCuenta ? '' : ' territory-team-global-advisors-empty' ?>">
        <h4 class="territory-team-section-title"><?= $asesoresSinCuenta ? 'Asesores sin Cuenta Clave' : 'Agregar asesor' ?></h4>

        <article class="territory-team-card">
            <?php if ($asesoresSinCuenta): ?>
                <div class="territory-analyst-list">
                    <?php foreach ($asesoresSinCuenta as $asesor): ?>
                        <?php require __DIR__ . '/_asesor_asignado.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <button
                type="button"
                class="btn btn-territory-expand"
                data-team-toggle
                data-target="formAsesor"
                aria-expanded="false"
                aria-controls="formAsesor">
                <i class="bi bi-plus-circle me-2"></i>
                Agregar asesor
            </button>

            <div class="territory-expandable-form" id="formAsesor" hidden>
                <form
                    class="territory-inline-form"
                    data-team-form
                    action="<?= BASE_URL ?>index.php?controller=territorio&action=guardarAsignacion"
                    method="POST">
                    <input type="hidden" name="estado_id" value="<?= (int)$estado['id'] ?>">
                    <input type="hidden" name="tipo_asignacion" value="ASESOR">

                    <div>
                        <label class="form-label" for="asesor_usuario">Asesor</label>
                        <select
                            class="form-select"
                            id="asesor_usuario"
                            name="usuario_id"
                            <?= empty($usuariosAsesores) ? 'disabled' : '' ?>
                            required>
                            <option value="">
                                <?= empty($usuariosAsesores)
                                    ? 'No hay Asesores activos disponibles'
                                    : 'Seleccionar usuario' ?>
                            </option>
                            <?php foreach ($usuariosAsesores as $usuario): ?>
                                <option value="<?= (int)$usuario['id'] ?>">
                                    <?= $nombreUsuario($usuario) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback d-block" data-field-error="usuario_id"></div>
                    </div>

                    <div>
                        <label class="form-label" for="asesor_fecha">Fecha de inicio</label>
                        <input
                            type="date"
                            class="form-control"
                            id="asesor_fecha"
                            name="fecha_inicio"
                            value="<?= $fechaHoy ?>">
                        <div class="invalid-feedback d-block" data-field-error="fecha_inicio"></div>
                    </div>

                    <div class="territory-inline-actions">
                        <button type="button" class="btn btn-system-cancel" data-team-cancel>
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="btn btn-territory-analyst"
                            <?= empty($usuariosAsesores) ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-circle me-2"></i>
                            Agregar asesor
                        </button>
                    </div>
                </form>
            </div>
        </article>
    </section>

    <section class="territory-team-section territory-team-add">
        <div>
            <h4 class="territory-team-section-title">Agregar Cuenta Clave</h4>
            <p>Selecciona un usuario activo con rol Cuenta Clave.</p>
        </div>

        <?php if ($tieneCuentasClave): ?>

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
            <?= $tieneCuentasClave ? 'hidden' : '' ?>>
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
                    <?php if ($tieneCuentasClave): ?>

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
