<?php

$usuarios = $usuarios ?? [];
$usuarioActualId = (int)($usuarioActualId ?? ($_SESSION['usuario_id'] ?? 0));
$administradoresActivos = (int)($administradoresActivos ?? 0);

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fechaLegible = function ($fecha) {
    if (!$fecha) {
        return 'Sin registro';
    }

    try {
        $fechaObjeto = new DateTime($fecha);
    } catch (Exception $error) {
        return htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8');
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
        $fechaObjeto->format('Y') . ' · ' .
        $fechaObjeto->format('H:i');
};

?>

<?php if (!empty($usuarios)): ?>

    <div class="table-responsive">
        <table class="table users-table align-middle">
            <thead>
                <tr>
                    <th>Nombre completo</th>
                    <th>Usuario</th>
                    <th>Correo</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Último acceso</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($usuarios as $usuario): ?>

                    <?php
                    $nombreCompleto = trim(
                        $usuario['nombre'] . ' ' .
                        $usuario['apellidos']
                    );
                    $esCuentaActual =
                        (int)$usuario['id'] === $usuarioActualId;
                    $esUltimoAdministradorActivo =
                        (int)$usuario['rol_id'] === 1 &&
                        (int)$usuario['estado'] === 1 &&
                        $administradoresActivos <= 1;
                    $estadoTexto =
                        (int)$usuario['estado'] === 1 ? 'Activo' : 'Inactivo';
                    $telefonoTexto =
                        trim((string)($usuario['telefono'] ?? '')) !== ''
                            ? $usuario['telefono']
                            : 'No registrado';
                    $seguridadTexto =
                        !empty($usuario['requiere_cambio_password'])
                            ? 'Cambio de contraseña pendiente'
                            : 'Acceso normal';
                    $seguridadTipo =
                        !empty($usuario['requiere_cambio_password'])
                            ? 'pendiente'
                            : 'normal';
                    $iniciales =
                        strtoupper(substr($usuario['nombre'], 0, 1)) .
                        strtoupper(substr($usuario['apellidos'], 0, 1));
                    $fotoPerfil = trim((string)($usuario['foto_perfil'] ?? ''));
                    $fotoPerfilUrl = $fotoPerfil !== ''
                        ? BASE_URL . ltrim($fotoPerfil, '/')
                        : '';
                    $ultimoAccesoTexto =
                        $fechaLegible($usuario['ultimo_acceso'] ?? null);
                    $fechaCreacionTexto =
                        $fechaLegible($usuario['created_at'] ?? null);
                    $fechaActualizacionTexto =
                        $fechaLegible($usuario['updated_at'] ?? null);
                    ?>

                    <tr>
                        <td>
                            <?= htmlspecialchars($nombreCompleto) ?>
                        </td>

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
                            <?= $ultimoAccesoTexto ?>
                        </td>

                        <td class="text-end">
                            <div class="table-actions">
                                <button
                                    type="button"
                                    class="table-action-button btn-ver-usuario"
                                    aria-label="Ver detalle"
                                    data-bs-toggle="offcanvas"
                                    data-bs-target="#offcanvasDetalleUsuario"
                                    data-iniciales="<?= $texto($iniciales) ?>"
                                    data-foto-perfil="<?= $texto($fotoPerfilUrl) ?>"
                                    data-nombre-completo="<?= $texto($nombreCompleto) ?>"
                                    data-usuario="<?= $texto($usuario['usuario']) ?>"
                                    data-correo="<?= $texto($usuario['correo']) ?>"
                                    data-telefono="<?= $texto($telefonoTexto) ?>"
                                    data-rol="<?= $texto($usuario['rol']) ?>"
                                    data-estado="<?= (int)$usuario['estado'] ?>"
                                    data-estado-texto="<?= $texto($estadoTexto) ?>"
                                    data-ultimo-acceso="<?= $texto($ultimoAccesoTexto) ?>"
                                    data-created-at="<?= $texto($fechaCreacionTexto) ?>"
                                    data-updated-at="<?= $texto($fechaActualizacionTexto) ?>"
                                    data-seguridad="<?= $texto($seguridadTexto) ?>"
                                    data-seguridad-tipo="<?= $texto($seguridadTipo) ?>">
                                    <i class="bi bi-eye"></i>
                                </button>

                                <button
                                    type="button"
                                    class="table-action-button"
                                    aria-label="Editar usuario"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEditarUsuario"
                                    data-id="<?= (int)$usuario['id'] ?>"
                                    data-es-cuenta-actual="<?= $esCuentaActual ? '1' : '0' ?>"
                                    data-nombre="<?= $texto($usuario['nombre']) ?>"
                                    data-apellidos="<?= $texto($usuario['apellidos']) ?>"
                                    data-telefono="<?= $texto($usuario['telefono'] ?? '') ?>"
                                    data-foto-perfil="<?= $texto($fotoPerfilUrl) ?>"
                                    data-correo="<?= $texto($usuario['correo']) ?>"
                                    data-usuario="<?= $texto($usuario['usuario']) ?>"
                                    data-rol-id="<?= (int)$usuario['rol_id'] ?>"
                                    data-estado="<?= (int)$usuario['estado'] ?>"
                                    data-ultimo-acceso="<?= $texto($ultimoAccesoTexto) ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <button
                                    type="button"
                                    class="table-action-button <?= (int)$usuario['estado'] === 1
                                    ? 'table-action-warning'
                                    : 'table-action-success' ?>"
                                    aria-label="<?= (int)$usuario['estado'] === 1 ? 'Desactivar usuario' : 'Activar usuario' ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEstadoUsuario"
                                    data-id="<?= (int)$usuario['id'] ?>"
                                    data-nombre="<?= $texto($nombreCompleto) ?>"
                                    data-estado-actual="<?= (int)$usuario['estado'] ?>"
                                    data-estado-nuevo="<?= (int)$usuario['estado'] === 1 ? 0 : 1 ?>"
                                    <?= ($esCuentaActual || $esUltimoAdministradorActivo) ? 'disabled' : '' ?>>
                                    <i class="bi <?= (int)$usuario['estado'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                </button>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="empty-table-message">
        No se encontraron usuarios con los criterios seleccionados.
    </div>

<?php endif; ?>
