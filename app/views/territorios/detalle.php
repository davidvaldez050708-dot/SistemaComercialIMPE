<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$estado = $estado ?? [];
$equipoTerritorial = $equipoTerritorial ?? [];
$historialAsignaciones = $historialAsignaciones ?? [];
$puedeEditarTerritorio = tienePermiso('territorios.actualizar_ficha');
$puedeAsignarTerritorio = tienePermiso('territorios.asignar');

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = function ($valor) use ($texto) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return $texto($valor);
};

$numero = function ($valor) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return number_format((float)$valor, 0, '.', ',');
};

$fecha = function ($valorFecha) use ($texto) {
    if (!$valorFecha) {
        return '<span class="detail-muted">No registrado</span>';
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

$fechaInput = function ($valorFecha) {
    if (!$valorFecha) {
        return '';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return '';
    }

    return $fechaObjeto->format('Y-m-d\TH:i');
};

$tipoTexto = function ($tipo) {
    $mapa = [
        'CUENTA_CLAVE' => 'Cuenta Clave',
        'ANALISTA_DATOS' => 'Analista de Datos'
    ];

    return $mapa[$tipo] ?? $tipo;
};

$nombreCortoVisible = function ($nombre, $nombreCorto) use ($texto) {
    $nombreCorto = trim((string)$nombreCorto);

    if ($nombreCorto === '') {
        return '';
    }

    $normalizar = function ($valor) {
        return strtolower(preg_replace('/\s+/', '', trim((string)$valor)));
    };

    if ($normalizar($nombre) === $normalizar($nombreCorto)) {
        return '';
    }

    return $texto($nombreCorto);
};

$totalTexto = function ($total, $cargados) {
    if ($total === null || trim((string)$total) === '') {
        return (int)$cargados . ' cargados';
    }

    return (int)$cargados . ' de ' . (int)$total . ' cargados';
};

?>

<div class="territory-detail-content">
    <div class="territory-detail-scroll">
        <div class="territory-detail-heading">
            <span class="territory-detail-icon">
                <i class="bi bi-geo-alt"></i>
            </span>

            <div>
                <h3><?= $texto($estado['nombre'] ?? '') ?></h3>
                <p>
                    <?= $nombreCortoVisible(
                        $estado['nombre'] ?? '',
                        $estado['nombre_corto'] ?? ''
                    ) ?: 'Territorio registrado' ?>
                </p>
            </div>
        </div>

        <section class="territory-detail-section">
            <h4>Equipo territorial actual</h4>

            <?php if (!empty($equipoTerritorial)): ?>

                <div class="territory-detail-team-list">
                    <?php foreach ($equipoTerritorial as $cuentaClave): ?>

                        <?php
                        $nombreCuenta = trim(
                            ($cuentaClave['nombre'] ?? '') . ' ' .
                            ($cuentaClave['apellidos'] ?? '')
                        );
                        $analistas = $cuentaClave['analistas'] ?? [];
                        ?>

                        <article class="territory-detail-team-card">
                            <div class="territory-detail-team-header">
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
                                        <span class="assignment-role assignment-role-account">
                                            Cuenta Clave
                                        </span>
                                        <strong><?= $texto($nombreCuenta) ?></strong>
                                        <small>Desde <?= $fecha($cuentaClave['fecha_inicio'] ?? '') ?></small>
                                    </div>
                                </div>
                            </div>

                            <div class="territory-detail-analysts">
                                <?php if (!empty($analistas)): ?>

                                    <?php foreach ($analistas as $analista): ?>

                                        <?php
                                        $nombreAnalista = trim(
                                            ($analista['nombre'] ?? '') . ' ' .
                                            ($analista['apellidos'] ?? '')
                                        );
                                        ?>

                                        <div class="territory-person">
                                            <?= renderAvatarUsuario(
                                                $analista['nombre'] ?? '',
                                                $analista['apellidos'] ?? '',
                                                $analista['rol'] ?? 'Analista de Datos',
                                                $analista['foto_perfil'] ?? '',
                                                'xs',
                                                'analista'
                                            ) ?>
                                            <span class="territory-person-name">
                                                <?= $texto($nombreAnalista) ?>
                                            </span>
                                        </div>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <span class="detail-muted">Sin analistas asignados</span>

                                <?php endif; ?>
                            </div>
                        </article>

                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <p class="territory-empty-text">
                    Este territorio aún no tiene Cuenta Clave asignada.
                </p>

            <?php endif; ?>
        </section>

        <section class="territory-detail-section">
            <h4>Información territorial</h4>

            <div class="detail-table">
                <div class="detail-row">
                    <span>Capital</span>
                    <strong><?= $valor($estado['capital'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Población</span>
                    <strong><?= $numero($estado['poblacion'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Municipios</span>
                    <strong>
                        <?= $texto($totalTexto(
                            $estado['total_municipios'] ?? null,
                            $estado['municipios_registrados'] ?? 0
                        )) ?>
                    </strong>
                </div>

                <div class="detail-row">
                    <span>Secretarías</span>
                    <strong>
                        <?= $texto($totalTexto(
                            $estado['total_secretarias'] ?? null,
                            $estado['secretarias_registradas'] ?? 0
                        )) ?>
                    </strong>
                </div>

                <div class="detail-row">
                    <span>Titular gobierno</span>
                    <strong><?= $valor($estado['titular_gobierno'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Cargo</span>
                    <strong><?= $valor($estado['cargo_titular'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Partido político</span>
                    <strong><?= $valor($estado['partido_politico'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Periodo de gobierno</span>
                    <strong><?= $valor($estado['periodo_gobierno'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Teléfono</span>
                    <strong><?= $valor($estado['telefono'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Redes sociales</span>
                    <strong><?= $valor($estado['redes_sociales'] ?? null) ?></strong>
                </div>

                <div class="detail-row">
                    <span>Fecha actualización</span>
                    <strong><?= $fecha($estado['fecha_actualizacion'] ?? null) ?></strong>
                </div>
            </div>
        </section>

        <section class="territory-detail-section">
            <h4>Historial</h4>

            <?php if (!empty($historialAsignaciones)): ?>

                <div class="territory-history-list">
                    <?php foreach ($historialAsignaciones as $asignacion): ?>

                        <?php
                        $nombre = trim(
                            ($asignacion['nombre'] ?? '') . ' ' .
                            ($asignacion['apellidos'] ?? '')
                        );
                        ?>

                        <div class="territory-history-item">
                            <div>
                                <strong><?= $texto($nombre) ?></strong>
                                <span><?= $texto($tipoTexto($asignacion['tipo_asignacion'])) ?></span>
                                <small>
                                    <?= $fecha($asignacion['fecha_inicio']) ?>
                                    -
                                    <?= $fecha($asignacion['fecha_fin']) ?>
                                </small>
                            </div>

                            <span class="status-pill status-pill-inactive">
                                Finalizada
                            </span>
                        </div>

                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <p class="territory-empty-text">
                    No hay historial de asignaciones.
                </p>

            <?php endif; ?>
        </section>
    </div>

    <div class="territory-detail-footer">
        <button
            type="button"
            class="btn btn-system-cancel"
            data-bs-dismiss="offcanvas">
            Cerrar
        </button>

        <?php if ($puedeEditarTerritorio): ?>

            <button
                type="button"
                class="btn btn-system-light"
                data-edit-territory
                data-bs-toggle="modal"
                data-bs-target="#modalEditarTerritorio"
                data-id="<?= (int)$estado['id'] ?>"
                data-clave-inegi="<?= $texto($estado['clave_inegi'] ?? '') ?>"
                data-nombre="<?= $texto($estado['nombre'] ?? '') ?>"
                data-nombre-corto="<?= $texto($estado['nombre_corto'] ?? '') ?>"
                data-capital="<?= $texto($estado['capital'] ?? '') ?>"
                data-titular-gobierno="<?= $texto($estado['titular_gobierno'] ?? '') ?>"
                data-cargo-titular="<?= $texto($estado['cargo_titular'] ?? '') ?>"
                data-partido-politico="<?= $texto($estado['partido_politico'] ?? '') ?>"
                data-poblacion="<?= $texto($estado['poblacion'] ?? '') ?>"
                data-total-municipios="<?= $texto($estado['total_municipios'] ?? '') ?>"
                data-total-secretarias="<?= $texto($estado['total_secretarias'] ?? '') ?>"
                data-periodo-gobierno="<?= $texto($estado['periodo_gobierno'] ?? '') ?>"
                data-telefono="<?= $texto($estado['telefono'] ?? '') ?>"
                data-redes-sociales="<?= $texto($estado['redes_sociales'] ?? '') ?>"
                data-fuente="<?= $texto($estado['fuente'] ?? '') ?>"
                data-fecha-actualizacion="<?= $texto($fechaInput($estado['fecha_actualizacion'] ?? null)) ?>">
                <i class="bi bi-pencil me-2"></i>
                Editar ficha territorial
            </button>

        <?php endif; ?>

        <?php if ($puedeAsignarTerritorio): ?>

            <button
                type="button"
                class="btn btn-system-save"
                data-open-team-manager
                data-estado-id="<?= (int)$estado['id'] ?>"
                data-estado-nombre="<?= $texto($estado['nombre'] ?? '') ?>">
                <i class="bi bi-people me-2"></i>
                Gestionar equipo
            </button>

        <?php endif; ?>
    </div>
</div>
