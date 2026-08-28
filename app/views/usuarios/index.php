<?php

    $usuarios = $usuarios ?? [];
    $roles = $roles ?? [];
    $filtros = $filtros ?? [];
    $mensajeExito = $mensajeExito ?? '';
    $mensajeError = $mensajeError ?? '';
    $erroresFormulario = $erroresFormulario ?? [];
    $datosFormulario = $datosFormulario ?? [];
    $modalAbierto = $modalAbierto ?? '';

    $buscarActual = $filtros['buscar'] ?? '';
    $rolActual = (string)($filtros['rol'] ?? '');
    $estadoActual = (string)($filtros['estado'] ?? '');
    $hayFiltros =
        $buscarActual !== '' ||
        $rolActual !== '' ||
        $estadoActual !== '';

    $datosCrear = $modalAbierto === 'crear' ? $datosFormulario : [];
    $datosEditar = $modalAbierto === 'editar' ? $datosFormulario : [];
    $erroresCrear = $modalAbierto === 'crear' ? $erroresFormulario : [];
    $erroresEditar = $modalAbierto === 'editar' ? $erroresFormulario : [];
    $administradoresActivos = $administradoresActivos ?? 0;
    $usuarioActualId = (int)($_SESSION['usuario_id'] ?? 0);
    $edicionCuentaActual =
        $modalAbierto === 'editar' &&
        (int)($datosEditar['id'] ?? 0) === $usuarioActualId;

$valor = function ($datos, $campo) {
    return htmlspecialchars((string)($datos[$campo] ?? ''), ENT_QUOTES, 'UTF-8');
};

$fechaPresentacion = function ($fecha) {
    if (!$fecha) {
        return 'Sin registro';
    }

    try {
        $fechaObjeto = new DateTime($fecha);
    } catch (Exception $error) {
        return (string)$fecha;
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

$seleccionado = function ($actual, $valor) {
    return (string)$actual === (string)$valor ? 'selected' : '';
};

?>

<section class="dashboard-panel users-module-panel">

    <form
        id="usuariosFiltrosForm"
        class="module-toolbar"
        action="<?= BASE_URL ?>index.php"
        method="GET">

        <input type="hidden" name="controller" value="usuario">
        <input type="hidden" name="action" value="index">

        <div class="module-search">
            <i class="bi bi-search"></i>

            <input
                type="search"
                class="form-control"
                name="buscar"
                value="<?= htmlspecialchars($buscarActual, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="Buscar usuario..."
                aria-label="Buscar usuario">
        </div>

        <div class="module-filters">
            <select
                class="form-select"
                name="rol"
                aria-label="Filtrar por rol">
                <option value="">Todos los roles</option>

                <?php foreach ($roles as $rol): ?>

                    <option
                        value="<?= (int)$rol['id'] ?>"
                        <?= $seleccionado($rolActual, $rol['id']) ?>>
                        <?= htmlspecialchars($rol['nombre']) ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <select
                class="form-select"
                name="estado"
                aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <option value="1" <?= $seleccionado($estadoActual, '1') ?>>
                    Activos
                </option>
                <option value="0" <?= $seleccionado($estadoActual, '0') ?>>
                    Inactivos
                </option>
            </select>
        </div>

        <div class="module-toolbar-actions">
            <a
                class="filter-clear-link <?= $hayFiltros ? '' : 'd-none' ?>"
                id="limpiarFiltrosUsuarios"
                href="<?= BASE_URL ?>index.php?controller=usuario&action=index">
                Limpiar filtros
            </a>

            <noscript>
                <button
                    type="submit"
                    class="btn btn-filter-submit">
                    <i class="bi bi-funnel me-2"></i>
                    Aplicar
                </button>
            </noscript>

            <button
                type="button"
                class="btn btn-new-user"
                data-bs-toggle="modal"
                data-bs-target="#modalCrearUsuario">
                <i class="bi bi-person-plus me-2"></i>
                Nuevo usuario
            </button>
        </div>

    </form>

</section>

<section class="dashboard-panel users-list-panel mt-4">

    <div class="table-panel-header">
        <h2 class="panel-title mb-0">
            Usuarios registrados
        </h2>
    </div>

    <div id="usuariosTablaContenido">
        <?php require __DIR__ . '/tabla.php'; ?>
    </div>

</section>

<!-- =========================================================
     DETALLE DEL USUARIO
     Panel lateral derecho
     ========================================================= -->

<div
    class="offcanvas offcanvas-end user-detail-panel"
    tabindex="-1"
    id="offcanvasDetalleUsuario"
    aria-labelledby="offcanvasDetalleUsuarioTitulo">

    <!-- Encabezado -->
    <div class="offcanvas-header user-detail-header">

        <div>

            <h5
                class="user-detail-title"
                id="offcanvasDetalleUsuarioTitulo">

                Detalle del usuario

            </h5>

            <p class="user-detail-subtitle">
                Información general de la cuenta
            </p>

        </div>

        <button
            type="button"
            class="btn-close user-detail-close"
            data-bs-dismiss="offcanvas"
            aria-label="Cerrar">
        </button>

    </div>


    <div class="offcanvas-body user-detail-body">

        <!-- Perfil -->
        <div class="user-detail-profile">

            <div class="user-detail-avatar-wrapper">

                <img
                    id="detalleFoto"
                    class="user-detail-avatar-image d-none"
                    src=""
                    alt="Foto de perfil">

                <div
                    id="detalleIniciales"
                    class="user-detail-avatar">

                    US

                </div>

            </div>


            <div class="user-detail-profile-info">

                <div class="d-flex align-items-center gap-2 flex-wrap">

                    <h3
                        id="detalleNombre"
                        class="user-detail-name">

                        Usuario

                    </h3>

                    <span
                        id="detalleEstadoBadge"
                        class="user-detail-status">

                        Activo

                    </span>

                </div>

                <p
                    id="detalleRolPrincipal"
                    class="user-detail-role">

                    Rol

                </p>

                <p
                    id="detalleCorreoPrincipal"
                    class="user-detail-email">

                    correo@ejemplo.com

                </p>

            </div>

        </div>


        <!-- Tabs -->
        <div class="user-detail-tabs">

            <button
                type="button"
                class="user-detail-tab active"
                data-user-tab="cuenta">

                Información de la cuenta

            </button>

            <button
                type="button"
                class="user-detail-tab"
                data-user-tab="seguridad">

                Acceso y seguridad

            </button>

        </div>


        <!-- Información de la cuenta -->
        <div
            class="user-detail-tab-content active"
            data-user-content="cuenta">


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-person"></i>
                </div>

                <div class="user-detail-info-label">
                    Usuario
                </div>

                <div
                    id="detalleUsuario"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-person-vcard"></i>
                </div>

                <div class="user-detail-info-label">
                    Nombre completo
                </div>

                <div
                    id="detalleNombreCompleto"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-telephone"></i>
                </div>

                <div class="user-detail-info-label">
                    Teléfono
                </div>

                <div
                    id="detalleTelefono"
                    class="user-detail-info-value">
                    No registrado
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-envelope"></i>
                </div>

                <div class="user-detail-info-label">
                    Correo
                </div>

                <div
                    id="detalleCorreo"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div class="user-detail-info-label">
                    Fecha de registro
                </div>

                <div
                    id="detalleFechaCreacion"
                    class="user-detail-info-value">
                    —
                </div>

            </div>

        </div>


        <!-- Acceso y seguridad -->
        <div
            class="user-detail-tab-content"
            data-user-content="seguridad">


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-shield-check"></i>
                </div>

                <div class="user-detail-info-label">
                    Rol
                </div>

                <div
                    id="detalleRol"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-clock-history"></i>
                </div>

                <div class="user-detail-info-label">
                    Último acceso
                </div>

                <div
                    id="detalleUltimoAcceso"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-info-row">

                <div class="user-detail-info-icon">
                    <i class="bi bi-arrow-repeat"></i>
                </div>

                <div class="user-detail-info-label">
                    Última actualización
                </div>

                <div
                    id="detalleActualizacion"
                    class="user-detail-info-value">
                    —
                </div>

            </div>


            <div class="user-detail-security">

                <div class="user-detail-security-icon">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div>

                    <span class="user-detail-security-label">
                        Estado de seguridad
                    </span>

                    <strong id="detalleSeguridad">
                        Acceso normal
                    </strong>

                </div>

            </div>

        </div>

    </div>


    <!-- Footer -->
    <div class="user-detail-footer">

        <button
            type="button"
            class="btn user-detail-secondary-button"
            data-bs-dismiss="offcanvas">

            Cerrar

        </button>

        <button
            type="button"
            class="btn user-detail-primary-button"
            id="btnEditarDesdeDetalle">

            <i class="bi bi-pencil me-2"></i>

            Editar usuario

        </button>

    </div>

</div>

<div
    class="modal fade"
    id="modalCrearUsuario"
    tabindex="-1"
    aria-labelledby="modalCrearUsuarioTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
                    <div class="modal-header system-form-modal-header">

            <div>

                <h5 class="system-form-modal-title">
                    Nuevo usuario
                </h5>

                <p class="system-form-modal-subtitle">
                    Registra una nueva cuenta de acceso al sistema
                </p>

            </div>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="modal"
                aria-label="Cerrar">
            </button>

        </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=usuario&action=guardar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>

                <div class="modal-body">
                    <?php if (!empty($erroresCrear)): ?>

                        <div class="alert alert-danger login-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>

                            <div>
                                <?php foreach ($erroresCrear as $error): ?>
                                    <div><?= htmlspecialchars($error) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <div class="system-form-grid">
                        <div>
                            <label for="crear_nombre" class="form-label login-label">
                                Nombre
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_nombre"
                                name="nombre"
                                value="<?= $valor($datosCrear, 'nombre') ?>"
                                required>
                        </div>

                        <div>
                            <label for="crear_apellidos" class="form-label login-label">
                                Apellidos
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_apellidos"
                                name="apellidos"
                                value="<?= $valor($datosCrear, 'apellidos') ?>"
                                required>
                        </div>

                        <div>
                            <label for="crear_telefono" class="form-label login-label">
                                Teléfono
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_telefono"
                                name="telefono"
                                value="<?= $valor($datosCrear, 'telefono') ?>">
                        </div>

                        <div>
                            <label for="crear_correo" class="form-label login-label">
                                Correo electrónico
                            </label>
                            <input
                                type="email"
                                class="form-control system-form-control"
                                id="crear_correo"
                                name="correo"
                                value="<?= $valor($datosCrear, 'correo') ?>"
                                required>
                        </div>

                        <div>
                            <label for="crear_foto_perfil" class="form-label login-label">
                                Foto de perfil
                            </label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="crear_foto_perfil"
                                name="foto_perfil"
                                accept="image/jpeg,image/png,image/webp">
                        </div>

                        <div>
                            <label for="crear_usuario" class="form-label login-label">
                                Usuario
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_usuario"
                                name="usuario"
                                value="<?= $valor($datosCrear, 'usuario') ?>"
                                required>
                        </div>

                        <div>
                            <label for="crear_rol" class="form-label login-label">
                                Rol
                            </label>
                            <select
                                class="form-select system-form-control"
                                id="crear_rol"
                                name="rol_id"
                                required>
                                <option value="">Seleccionar rol</option>

                                <?php foreach ($roles as $rol): ?>

                                    <option
                                        value="<?= (int)$rol['id'] ?>"
                                        <?= $seleccionado($datosCrear['rol_id'] ?? '', $rol['id']) ?>>
                                        <?= htmlspecialchars($rol['nombre']) ?>
                                    </option>

                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="crear_password" class="form-label login-label">
                                Contraseña
                            </label>
                            <div class="input-group login-input-group">
                                <input
                                    type="password"
                                    class="form-control"
                                    id="crear_password"
                                    name="password"
                                    autocomplete="new-password"
                                    required>
                                <button
                                    type="button"
                                    class="btn password-toggle password-view-toggle"
                                    aria-label="Mostrar contraseña">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label for="crear_confirmar_password" class="form-label login-label">
                                Confirmar contraseña
                            </label>
                            <div class="input-group login-input-group">
                                <input
                                    type="password"
                                    class="form-control"
                                    id="crear_confirmar_password"
                                    name="confirmar_password"
                                    autocomplete="new-password"
                                    required>
                                <button
                                    type="button"
                                    class="btn password-toggle password-view-toggle"
                                    aria-label="Mostrar contraseña">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-system-cancel"
                        data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEditarUsuario"
    tabindex="-1"
    aria-labelledby="modalEditarUsuarioTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
                    <div class="modal-header system-form-modal-header">

            <div>

                <h5 class="system-form-modal-title">
                    Editar usuario
                </h5>

                <p class="system-form-modal-subtitle">
                    Actualiza la información y acceso de la cuenta
                </p>

            </div>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="modal"
                aria-label="Cerrar">
            </button>

        </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=usuario&action=actualizar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>

                <input
                    type="hidden"
                    id="editar_id"
                    name="id"
                    value="<?= $valor($datosEditar, 'id') ?>">

                <input
                    type="hidden"
                    id="editar_rol_oculto"
                    name="rol_id"
                    value="<?= $valor($datosEditar, 'rol_id') ?>"
                    <?= $edicionCuentaActual ? '' : 'disabled' ?>>

                <input
                    type="hidden"
                    id="editar_estado_oculto"
                    name="estado"
                    value="<?= $valor($datosEditar, 'estado') ?>"
                    <?= $edicionCuentaActual ? '' : 'disabled' ?>>

                <div class="modal-body">
                    <?php if (!empty($erroresEditar)): ?>

                        <div class="alert alert-danger login-alert" role="alert">
                            <i class="bi bi-exclamation-circle"></i>

                            <div>
                                <?php foreach ($erroresEditar as $error): ?>
                                    <div><?= htmlspecialchars($error) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php endif; ?>

                    <div
                        class="security-note <?= $edicionCuentaActual ? '' : 'd-none' ?>"
                        id="editar_nota_cuenta_actual">
                        <i class="bi bi-shield-check"></i>
                        <span>
                            Por seguridad, no puedes modificar tu propio rol
                            ni desactivar tu cuenta.
                        </span>
                    </div>

                    <div class="system-form-grid">
                        <div>
                            <label for="editar_nombre" class="form-label login-label">
                                Nombre
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="editar_nombre"
                                name="nombre"
                                value="<?= $valor($datosEditar, 'nombre') ?>"
                                required>
                        </div>

                        <div>
                            <label for="editar_apellidos" class="form-label login-label">
                                Apellidos
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="editar_apellidos"
                                name="apellidos"
                                value="<?= $valor($datosEditar, 'apellidos') ?>"
                                required>
                        </div>

                        <div>
                            <label for="editar_telefono" class="form-label login-label">
                                Teléfono
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="editar_telefono"
                                name="telefono"
                                value="<?= $valor($datosEditar, 'telefono') ?>">
                        </div>

                        <div>
                            <label for="editar_correo" class="form-label login-label">
                                Correo
                            </label>
                            <input
                                type="email"
                                class="form-control system-form-control"
                                id="editar_correo"
                                name="correo"
                                value="<?= $valor($datosEditar, 'correo') ?>"
                                required>
                        </div>

                        <div>
                            <label for="editar_foto_perfil" class="form-label login-label">
                                Foto de perfil
                            </label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="editar_foto_perfil"
                                name="foto_perfil"
                                accept="image/jpeg,image/png,image/webp">
                        </div>

                        <div>
                            <label for="editar_usuario" class="form-label login-label">
                                Usuario
                            </label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="editar_usuario"
                                name="usuario"
                                value="<?= $valor($datosEditar, 'usuario') ?>"
                                required>
                        </div>

                        <div>
                            <label for="editar_rol" class="form-label login-label">
                                Rol
                            </label>
                            <select
                                class="form-select system-form-control"
                                id="editar_rol"
                                name="rol_id"
                                <?= $edicionCuentaActual ? 'disabled' : '' ?>
                                required>
                                <option value="">Seleccionar rol</option>

                                <?php foreach ($roles as $rol): ?>

                                    <option
                                        value="<?= (int)$rol['id'] ?>"
                                        <?= $seleccionado($datosEditar['rol_id'] ?? '', $rol['id']) ?>>
                                        <?= htmlspecialchars($rol['nombre']) ?>
                                    </option>

                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="editar_estado" class="form-label login-label">
                                Estado
                            </label>
                            <select
                                class="form-select system-form-control"
                                id="editar_estado"
                                name="estado"
                                <?= $edicionCuentaActual ? 'disabled' : '' ?>
                                required>
                                <option
                                    value="1"
                                    <?= $seleccionado($datosEditar['estado'] ?? '', '1') ?>>
                                    Activo
                                </option>
                                <option
                                    value="0"
                                    <?= $seleccionado($datosEditar['estado'] ?? '', '0') ?>>
                                    Inactivo
                                </option>
                            </select>
                        </div>

                        <div>
                            <label
                                for="editar_ultimo_acceso"
                                class="form-label login-label">
                                Último acceso
                            </label>

                            <div
                                class="readonly-value"
                                id="editar_ultimo_acceso">
                                <?= htmlspecialchars(
                                    $fechaPresentacion(
                                        $datosEditar['ultimo_acceso'] ?? null
                                    )
                                ) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-system-cancel"
                        data-bs-dismiss="modal">

                        Cancelar

                    </button>

                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Actualizar usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEstadoUsuario"
    tabindex="-1"
    aria-labelledby="modalEstadoUsuarioTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="modalEstadoUsuarioTitulo">
                        Cambiar estado
                    </h2>
                    <p class="modal-subtitle">
                        Actualiza el acceso del usuario al sistema
                    </p>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar">
                </button>
            </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=usuario&action=cambiarEstado"
                method="POST">

                <input type="hidden" id="estado_usuario_id" name="id" value="">
                <input type="hidden" id="estado_usuario_valor" name="estado" value="">

                <div class="modal-body">
                    <p class="confirm-text">
                        ¿Deseas
                        <strong id="estado_usuario_accion">actualizar</strong>
                        la cuenta de
                        <strong id="estado_usuario_nombre">este usuario</strong>?
                    </p>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-system-cancel"
                        data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="btn btn-system-save"
                        id="estado_usuario_boton">
                        <i class="bi bi-toggle-on me-2"></i>
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if ($mensajeExito !== ''): ?>

        <div
            class="toast system-toast"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-bs-delay="3200">
            <div class="toast-body">
                <i class="bi bi-check2-circle"></i>
                <span><?= htmlspecialchars($mensajeExito) ?></span>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($mensajeError !== ''): ?>

        <div
            class="toast system-toast system-toast-error"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-bs-delay="4200">
            <div class="toast-body">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= htmlspecialchars($mensajeError) ?></span>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filtrosForm = document.getElementById('usuariosFiltrosForm');
    const tablaContenido = document.getElementById('usuariosTablaContenido');
    const limpiarFiltros = document.getElementById('limpiarFiltrosUsuarios');
    const panelDetalle = document.getElementById('offcanvasDetalleUsuario');
    const botonEditarDesdeDetalle =
        document.getElementById('btnEditarDesdeDetalle');
    const modalEditar = document.getElementById('modalEditarUsuario');
    const modalEstado = document.getElementById('modalEstadoUsuario');
    const modalAbierto = '<?= htmlspecialchars($modalAbierto, ENT_QUOTES, 'UTF-8') ?>';
    const baseUrl = '<?= BASE_URL ?>index.php';

    let temporizadorBusqueda = null;
    let consultaActual = null;
    let botonEditarActual = null;

    const actualizarEnlaceLimpiar = function () {
        if (!filtrosForm || !limpiarFiltros) {
            return;
        }

        const datos = new FormData(filtrosForm);
        const hayFiltros =
            (datos.get('buscar') || '').trim() !== '' ||
            (datos.get('rol') || '') !== '' ||
            (datos.get('estado') || '') !== '';

        limpiarFiltros.classList.toggle('d-none', !hayFiltros);
    };

    const crearParametros = function (action) {
        const parametros = new URLSearchParams(new FormData(filtrosForm));

        parametros.set('controller', 'usuario');
        parametros.set('action', action);

        return parametros;
    };

    const actualizarUrl = function () {
        const parametros = crearParametros('index');

        window.history.replaceState(
            null,
            '',
            baseUrl + '?' + parametros.toString()
        );
    };

    const cargarTabla = async function () {
        if (!filtrosForm || !tablaContenido) {
            return;
        }

        if (consultaActual) {
            consultaActual.abort();
        }

        const controlador = new AbortController();
        consultaActual = controlador;
        tablaContenido.classList.add('table-loading');

        try {
            const parametros = crearParametros('tabla');
            const respuesta = await fetch(
                baseUrl + '?' + parametros.toString(),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    signal: controlador.signal
                }
            );

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar usuarios.');
            }

            tablaContenido.innerHTML = await respuesta.text();
            actualizarUrl();
            actualizarEnlaceLimpiar();
        } catch (error) {
            if (error.name !== 'AbortError') {
                tablaContenido.innerHTML =
                    '<div class="empty-table-message">' +
                    'No fue posible actualizar el listado de usuarios.' +
                    '</div>';
            }
        } finally {
            if (consultaActual === controlador) {
                tablaContenido.classList.remove('table-loading');
            }
        }
    };

    if (filtrosForm) {
        const campoBusqueda = filtrosForm.querySelector('[name="buscar"]');
        const filtros = filtrosForm.querySelectorAll('select');

        filtrosForm.addEventListener('submit', function (event) {
            event.preventDefault();
            cargarTabla();
        });

        if (campoBusqueda) {
            campoBusqueda.addEventListener('input', function () {
                window.clearTimeout(temporizadorBusqueda);
                temporizadorBusqueda = window.setTimeout(cargarTabla, 300);
            });
        }

        filtros.forEach(function (filtro) {
            filtro.addEventListener('change', cargarTabla);
        });
    }

    if (limpiarFiltros && filtrosForm) {
        limpiarFiltros.addEventListener('click', function (event) {
            event.preventDefault();

            filtrosForm.querySelector('[name="buscar"]').value = '';
            filtrosForm.querySelector('[name="rol"]').value = '';
            filtrosForm.querySelector('[name="estado"]').value = '';

            cargarTabla();
        });
    }

    if (panelDetalle) {
        panelDetalle.addEventListener('show.bs.offcanvas', function (event) {
            const boton = event.relatedTarget;

            if (!boton) {
                return;
            }

            botonEditarActual = boton
                .closest('.table-actions')
                ?.querySelector('[data-bs-target="#modalEditarUsuario"]');

            const asignarTexto = function (id, valor) {
                const elemento = document.getElementById(id);

                if (elemento) {
                    elemento.textContent = valor || 'Sin registro';
                }
            };

            const telefono = boton.getAttribute('data-telefono') ||
                'No registrado';
            const estado = boton.getAttribute('data-estado') || '0';
            const estadoTexto = boton.getAttribute('data-estado-texto') ||
                'Inactivo';
            const seguridadTipo =
                boton.getAttribute('data-seguridad-tipo') || 'normal';
            const seguridadTexto =
                boton.getAttribute('data-seguridad') || 'Acceso normal';
            const telefonoElemento =
                document.getElementById('detalleTelefono');
            const seguridadElemento =
                document.getElementById('detalleSeguridad');
            const fotoPerfil = boton.getAttribute('data-foto-perfil') || '';
            const fotoPerfilElemento =
                document.getElementById('detalleFoto');
            const inicialesElemento =
                document.getElementById('detalleIniciales');
            const estadoBadge =
                document.getElementById('detalleEstadoBadge');
            const seguridadIcono =
                document.querySelector('.user-detail-security-icon i');

            asignarTexto('detalleIniciales', boton.getAttribute('data-iniciales') || 'US');
            asignarTexto('detalleNombre', boton.getAttribute('data-nombre-completo'));
            asignarTexto('detalleNombreCompleto', boton.getAttribute('data-nombre-completo'));
            asignarTexto('detalleRolPrincipal', boton.getAttribute('data-rol'));
            asignarTexto('detalleCorreoPrincipal', boton.getAttribute('data-correo'));
            asignarTexto('detalleUsuario', boton.getAttribute('data-usuario'));
            asignarTexto('detalleCorreo', boton.getAttribute('data-correo'));
            asignarTexto('detalleTelefono', telefono);
            asignarTexto('detalleRol', boton.getAttribute('data-rol'));
            asignarTexto(
                'detalleUltimoAcceso',
                boton.getAttribute('data-ultimo-acceso')
            );
            asignarTexto(
                'detalleFechaCreacion',
                boton.getAttribute('data-created-at')
            );
            asignarTexto(
                'detalleActualizacion',
                boton.getAttribute('data-updated-at')
            );

            if (telefonoElemento) {
                telefonoElemento.classList.toggle(
                    'detail-muted',
                    telefono === 'No registrado'
                );
            }

            if (fotoPerfilElemento && inicialesElemento) {
                if (fotoPerfil !== '') {
                    fotoPerfilElemento.src = fotoPerfil;
                    fotoPerfilElemento.classList.remove('d-none');
                    inicialesElemento.classList.add('d-none');
                } else {
                    fotoPerfilElemento.src = '';
                    fotoPerfilElemento.classList.add('d-none');
                    inicialesElemento.classList.remove('d-none');
                }
            }

            if (estadoBadge) {
                estadoBadge.textContent = estadoTexto;
                estadoBadge.className = 'user-detail-status ' +
                    (estado === '1'
                        ? 'user-detail-status-active'
                        : 'user-detail-status-inactive');
            }

            if (seguridadElemento) {
                seguridadElemento.textContent = seguridadTexto;
            }

            if (seguridadIcono) {
                seguridadIcono.className = 'bi ' +
                    (seguridadTipo === 'pendiente'
                        ? 'bi-exclamation-circle'
                        : 'bi-check-circle');
            }
        });
    }

    if (botonEditarDesdeDetalle) {
        botonEditarDesdeDetalle.addEventListener('click', function () {
            if (!botonEditarActual) {
                return;
            }

            const abrirEditor = function () {
                botonEditarActual.click();
            };

            if (panelDetalle) {
                panelDetalle.addEventListener(
                    'hidden.bs.offcanvas',
                    abrirEditor,
                    { once: true }
                );

                const offcanvas =
                    bootstrap.Offcanvas.getInstance(panelDetalle);

                if (offcanvas) {
                    offcanvas.hide();
                } else {
                    abrirEditor();
                }
            } else {
                abrirEditor();
            }
        });
    }

    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function (event) {
            const boton = event.relatedTarget;

            if (!boton) {
                return;
            }

            document.getElementById('editar_id').value =
                boton.getAttribute('data-id') || '';
            document.getElementById('editar_rol_oculto').value =
                boton.getAttribute('data-rol-id') || '';
            document.getElementById('editar_estado_oculto').value =
                boton.getAttribute('data-estado') || '1';
            document.getElementById('editar_nombre').value =
                boton.getAttribute('data-nombre') || '';
            document.getElementById('editar_apellidos').value =
                boton.getAttribute('data-apellidos') || '';
            document.getElementById('editar_telefono').value =
                boton.getAttribute('data-telefono') || '';
            document.getElementById('editar_correo').value =
                boton.getAttribute('data-correo') || '';
            document.getElementById('editar_usuario').value =
                boton.getAttribute('data-usuario') || '';
            document.getElementById('editar_rol').value =
                boton.getAttribute('data-rol-id') || '';
            document.getElementById('editar_estado').value =
                boton.getAttribute('data-estado') || '1';
            document.getElementById('editar_ultimo_acceso').textContent =
                boton.getAttribute('data-ultimo-acceso') || 'Sin registro';

            const esCuentaActual =
                boton.getAttribute('data-es-cuenta-actual') === '1';
            const rolOculto = document.getElementById('editar_rol_oculto');
            const estadoOculto =
                document.getElementById('editar_estado_oculto');
            const rolSelect = document.getElementById('editar_rol');
            const estadoSelect = document.getElementById('editar_estado');
            const notaCuentaActual =
                document.getElementById('editar_nota_cuenta_actual');

            rolSelect.disabled = esCuentaActual;
            estadoSelect.disabled = esCuentaActual;
            rolOculto.disabled = !esCuentaActual;
            estadoOculto.disabled = !esCuentaActual;
            notaCuentaActual.classList.toggle('d-none', !esCuentaActual);
        });
    }

    if (modalEstado) {
        modalEstado.addEventListener('show.bs.modal', function (event) {
            const boton = event.relatedTarget;

            if (!boton) {
                return;
            }

            const estadoNuevo = boton.getAttribute('data-estado-nuevo') || '0';
            const accion = estadoNuevo === '1' ? 'activar' : 'desactivar';
            const botonConfirmar =
                document.getElementById('estado_usuario_boton');

            document.getElementById('estado_usuario_id').value =
                boton.getAttribute('data-id') || '';
            document.getElementById('estado_usuario_valor').value =
                estadoNuevo;
            document.getElementById('estado_usuario_accion').textContent =
                accion;
            document.getElementById('estado_usuario_nombre').textContent =
                boton.getAttribute('data-nombre') || 'este usuario';

            if (botonConfirmar) {
                botonConfirmar.innerHTML =
                    '<i class="bi ' +
                    (estadoNuevo === '1' ? 'bi-toggle-on' : 'bi-toggle-off') +
                    ' me-2"></i>' +
                    (estadoNuevo === '1' ? 'Activar' : 'Desactivar');
            }
        });
    }

    if (modalAbierto === 'crear') {
        const modalCrear = document.getElementById('modalCrearUsuario');

        if (modalCrear) {
            new bootstrap.Modal(modalCrear).show();
        }
    }

    if (modalAbierto === 'editar' && modalEditar) {
        new bootstrap.Modal(modalEditar).show();
    }

    document.querySelectorAll('.system-toast').forEach(function (toast) {
        new bootstrap.Toast(toast).show();
    });
});

document.addEventListener('DOMContentLoaded', function () {

    const tabs =
        document.querySelectorAll('.user-detail-tab');

    tabs.forEach(function (tab) {

        tab.addEventListener('click', function () {

            const target =
                tab.dataset.userTab;

            tabs.forEach(function (item) {
                item.classList.remove('active');
            });

            document
                .querySelectorAll('.user-detail-tab-content')
                .forEach(function (content) {

                    content.classList.remove('active');

                });

            tab.classList.add('active');

            const content =
                document.querySelector(
                    `[data-user-content="${target}"]`
                );

            if (content) {
                content.classList.add('active');
            }

        });

    });

});
</script>
