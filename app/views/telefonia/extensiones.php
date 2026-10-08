<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$usuariosTelefonia = $usuariosTelefonia ?? [];
$resumenTelefonia = $resumenTelefonia ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$datosFormulario = $datosFormulario ?? [];

$esc = static function ($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
};

?>

<div
    class="telephony-admin"
    data-telephony-admin
    data-reopen-user="<?= (int)($datosFormulario['usuario_id'] ?? 0) ?>"
    data-reopen-extension="<?= $esc($datosFormulario['extension'] ?? '') ?>"
    data-reopen-caller-id="<?= $esc($datosFormulario['caller_id'] ?? '') ?>"
    data-reopen-outgoing="<?= (int)($datosFormulario['permite_salientes'] ?? 0) ?>"
    data-reopen-incoming="<?= (int)($datosFormulario['permite_entrantes'] ?? 0) ?>"
    data-reopen-active="<?= (int)($datosFormulario['activo'] ?? 0) ?>">

    <?php if ($mensajeExito !== ''): ?>
        <div class="alert alert-success telephony-alert" role="status">
            <i class="bi bi-check2-circle"></i>
            <span><?= $esc($mensajeExito) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($mensajeError !== ''): ?>
        <div class="alert alert-danger telephony-alert" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $esc($mensajeError) ?></span>
        </div>
    <?php endif; ?>

    <nav class="telephony-admin-tabs" aria-label="Secciones de Telefonía">
        <a href="<?= BASE_URL ?>index.php?controller=telefonia&action=index">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Control de llamadas
        </a>
        <a href="<?= BASE_URL ?>index.php?controller=telefonia&action=extensiones"
           class="is-active" aria-current="page">
            <i class="bi bi-diagram-3" aria-hidden="true"></i> Extensiones
        </a>
    </nav>

    <section class="telephony-summary-grid">
        <article class="telephony-summary-card">
            <span class="telephony-summary-icon">
                <i class="bi bi-people"></i>
            </span>
            <div>
                <strong><?= (int)($resumenTelefonia['usuarios'] ?? 0) ?></strong>
                <span>Usuarios de telefonía</span>
            </div>
        </article>

        <article class="telephony-summary-card">
            <span class="telephony-summary-icon">
                <i class="bi bi-diagram-3"></i>
            </span>
            <div>
                <strong><?= (int)($resumenTelefonia['configurados'] ?? 0) ?></strong>
                <span>Extensiones configuradas</span>
            </div>
        </article>

        <article class="telephony-summary-card">
            <span class="telephony-summary-icon">
                <i class="bi bi-telephone-fill"></i>
            </span>
            <div>
                <strong><?= (int)($resumenTelefonia['activos'] ?? 0) ?></strong>
                <span>Extensiones activas</span>
            </div>
        </article>

        <article class="telephony-summary-card">
            <span class="telephony-summary-icon">
                <i class="bi bi-hourglass-split"></i>
            </span>
            <div>
                <strong><?= (int)($resumenTelefonia['pendientes'] ?? 0) ?></strong>
                <span>Pendientes de asignar</span>
            </div>
        </article>
    </section>

    <section class="dashboard-panel telephony-config-panel">
        <div class="telephony-config-heading">
            <div>
                <span class="telephony-eyebrow">CENTRALITA · ZADARMA</span>
                <h2>Extensiones por usuario</h2>
                <p>
                    Administra las extensiones de las cuentas que tengan permisos de telefonía.
                    Sus capacidades dependen de los permisos y de la configuración de la centralita.
                </p>
            </div>

            <div class="telephony-step-badge">
                <i class="bi bi-shield-check"></i>
                <span>
                    <strong>Administración</strong>
                    Extensiones y permisos
                </span>
            </div>
        </div>

        <div class="telephony-info-note">
            <i class="bi bi-info-circle"></i>
            <span>
                El Caller ID es opcional y requiere autorización de Zadarma.
                Una extensión activa solo permite las capacidades telefónicas
                habilitadas para el usuario y la centralita.
            </span>
        </div>

        <?php if (empty($usuariosTelefonia)): ?>
            <div class="telephony-empty">
                <i class="bi bi-telephone-x"></i>
                <strong>No hay usuarios disponibles para configurar.</strong>
                <span>
                    Los usuarios aparecerán aquí cuando su rol tenga el permiso
                    Usar telefonía. Revisa los permisos de sus roles desde Administración.
                </span>
            </div>
        <?php else: ?>
            <div class="table-responsive telephony-table-wrap">
                <table class="table telephony-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Extensión</th>
                            <th>Capacidades</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuariosTelefonia as $usuario): ?>
                            <?php
                            $tieneConfiguracion =
                                (int)($usuario['telefonia_id'] ?? 0) > 0;
                            $usuarioActivo =
                                (int)($usuario['estado'] ?? 0) === 1;
                            $telefoniaActiva =
                                $tieneConfiguracion &&
                                (int)($usuario['telefonia_activa'] ?? 0) === 1 &&
                                $usuarioActivo;
                            ?>

                            <tr>
                                <td>
                                    <div class="telephony-user">
                                        <?= renderAvatarUsuario(
                                            $usuario['nombre'] ?? '',
                                            $usuario['apellidos'] ?? '',
                                            $usuario['rol'] ?? 'Usuario',
                                            $usuario['foto_perfil'] ?? '',
                                            'sm',
                                            'general'
                                        ) ?>

                                        <div>
                                            <strong>
                                                <?= $esc(trim(
                                                    (string)($usuario['nombre'] ?? '') .
                                                    ' ' .
                                                    (string)($usuario['apellidos'] ?? '')
                                                )) ?>
                                            </strong>
                                            <span><?= $esc($usuario['correo'] ?? '') ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="telephony-role-pill">
                                        <?= $esc($usuario['rol'] ?? '') ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($tieneConfiguracion): ?>
                                        <strong class="telephony-extension-value">
                                            <?= $esc($usuario['extension'] ?? '') ?>
                                        </strong>
                                        <?php if (trim((string)($usuario['caller_id'] ?? '')) !== ''): ?>
                                            <small class="telephony-caller-id">
                                                Caller ID:
                                                <?= $esc($usuario['caller_id']) ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="telephony-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="telephony-capabilities">
                                        <?php if (
                                            $tieneConfiguracion &&
                                            (int)($usuario['permite_salientes'] ?? 0) === 1
                                        ): ?>
                                            <span>
                                                <i class="bi bi-telephone-outbound"></i>
                                                Salientes
                                            </span>
                                        <?php endif; ?>

                                        <?php if (
                                            $tieneConfiguracion &&
                                            (int)($usuario['permite_entrantes'] ?? 0) === 1
                                        ): ?>
                                            <span>
                                                <i class="bi bi-telephone-inbound"></i>
                                                Entrantes
                                            </span>
                                        <?php endif; ?>

                                        <?php if (
                                            (int)($usuario['puede_transferir'] ?? 0) === 1
                                        ): ?>
                                            <span>
                                                <i class="bi bi-arrow-left-right"></i>
                                                Transferencias
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!$tieneConfiguracion): ?>
                                            <span class="is-muted">Pendiente</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!$usuarioActivo): ?>
                                        <span class="telephony-status is-user-inactive">
                                            Usuario inactivo
                                        </span>
                                    <?php elseif ($telefoniaActiva): ?>
                                        <span class="telephony-status is-active">
                                            <i class="bi bi-check-circle-fill"></i>
                                            Activa
                                        </span>
                                    <?php elseif ($tieneConfiguracion): ?>
                                        <span class="telephony-status is-inactive">
                                            Configurada · inactiva
                                        </span>
                                    <?php else: ?>
                                        <span class="telephony-status is-pending">
                                            Pendiente
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <div class="telephony-row-actions">
                                        <button
                                            type="button"
                                            class="btn telephony-config-button"
                                            data-telephony-configure
                                            data-user-id="<?= (int)$usuario['id'] ?>"
                                            data-user-name="<?= $esc(trim(
                                                (string)($usuario['nombre'] ?? '') .
                                                ' ' .
                                                (string)($usuario['apellidos'] ?? '')
                                            )) ?>"
                                            data-user-role="<?= $esc($usuario['rol'] ?? '') ?>"
                                            data-user-active="<?= $usuarioActivo ? '1' : '0' ?>"
                                            data-extension="<?= $esc($usuario['extension'] ?? '') ?>"
                                            data-caller-id="<?= $esc($usuario['caller_id'] ?? '') ?>"
                                            data-outgoing="<?= (int)($usuario['permite_salientes'] ?? 0) ?>"
                                            data-incoming="<?= (int)($usuario['permite_entrantes'] ?? 0) ?>"
                                            data-can-outgoing="<?= (int)($usuario['puede_salientes'] ?? 0) ?>"
                                            data-can-incoming="<?= (int)($usuario['puede_entrantes'] ?? 0) ?>"
                                            data-can-transfer="<?= (int)($usuario['puede_transferir'] ?? 0) ?>"
                                            data-active="<?= (int)($usuario['telefonia_activa'] ?? 0) ?>">
                                            <i class="bi bi-sliders"></i>
                                            <?= $tieneConfiguracion
                                                ? 'Editar'
                                                : 'Configurar' ?>
                                        </button>

                                        <?php if ($tieneConfiguracion): ?>
                                            <button
                                                type="button"
                                                class="btn telephony-release-button"
                                                data-telephony-release
                                                data-user-id="<?= (int)$usuario['id'] ?>"
                                                data-user-name="<?= $esc(trim(
                                                    (string)($usuario['nombre'] ?? '') .
                                                    ' ' .
                                                    (string)($usuario['apellidos'] ?? '')
                                                )) ?>"
                                                data-extension="<?= $esc($usuario['extension'] ?? '') ?>"
                                                aria-label="Liberar extensión">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<div
    class="modal fade"
    id="modalTelefoniaExtension"
    tabindex="-1"
    aria-labelledby="modalTelefoniaExtensionTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered system-form-dialog telephony-modal-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5
                        class="system-form-modal-title"
                        id="modalTelefoniaExtensionTitulo">
                        Configurar extensión
                    </h5>
                    <p
                        class="system-form-modal-subtitle"
                        data-telephony-modal-user>
                        Usuario
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
                action="<?= BASE_URL ?>index.php?controller=telefonia&action=guardarExtension"
                method="POST"
                data-telephony-config-form>
                <input
                    type="hidden"
                    name="usuario_id"
                    value=""
                    data-telephony-user-id>

                <div class="modal-body">
                    <div class="telephony-form-grid">
                        <div>
                            <label
                                class="form-label login-label"
                                for="telefonia_extension">
                                Extensión Zadarma
                            </label>
                            <input
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]{3,6}"
                                maxlength="6"
                                class="form-control system-form-control"
                                id="telefonia_extension"
                                name="extension"
                                placeholder="Ej. 101"
                                required
                                data-telephony-extension>
                            <small class="telephony-field-help">
                                Entre 3 y 6 dígitos. No puede repetirse entre usuarios.
                            </small>
                        </div>

                        <div>
                            <label
                                class="form-label login-label"
                                for="telefonia_caller_id">
                                Caller ID institucional
                            </label>
                            <input
                                type="tel"
                                class="form-control system-form-control"
                                id="telefonia_caller_id"
                                name="caller_id"
                                placeholder="+52..."
                                data-telephony-caller-id>
                            <small class="telephony-field-help">
                                Opcional. Déjalo vacío hasta definir el número institucional.
                            </small>
                        </div>
                    </div>

                    <div class="telephony-permissions-box">
                        <div>
                            <strong>Uso de la extensión</strong>
                            <span>
                                Define qué tipo de llamadas podrá manejar este usuario.
                            </span>
                        </div>

                        <label class="telephony-switch-row">
                            <span>
                                <i class="bi bi-telephone-outbound"></i>
                                <span>
                                    <strong>Llamadas salientes</strong>
                                    <small data-telephony-outgoing-help>Permite originar llamadas desde el sistema.</small>
                                </span>
                            </span>
                            <span class="telephony-toggle">
                                <input
                                    class="telephony-toggle-input"
                                    type="checkbox"
                                    name="permite_salientes"
                                    value="1"
                                    data-telephony-outgoing>
                                <span
                                    class="telephony-toggle-track"
                                    aria-hidden="true"></span>
                            </span>
                        </label>

                        <label class="telephony-switch-row">
                            <span>
                                <i class="bi bi-telephone-inbound"></i>
                                <span>
                                    <strong>Llamadas entrantes</strong>
                                    <small data-telephony-incoming-help>Prepara la extensión para recibir llamadas.</small>
                                </span>
                            </span>
                            <span class="telephony-toggle">
                                <input
                                    class="telephony-toggle-input"
                                    type="checkbox"
                                    name="permite_entrantes"
                                    value="1"
                                    data-telephony-incoming>
                                <span
                                    class="telephony-toggle-track"
                                    aria-hidden="true"></span>
                            </span>
                        </label>

                        <label class="telephony-switch-row telephony-active-row">
                            <span>
                                <i class="bi bi-power"></i>
                                <span>
                                    <strong>Extensión activa</strong>
                                    <small data-telephony-active-help>
                                        El usuario podrá usar esta identidad telefónica.
                                    </small>
                                </span>
                            </span>
                            <span class="telephony-toggle">
                                <input
                                    class="telephony-toggle-input"
                                    type="checkbox"
                                    name="activo"
                                    value="1"
                                    data-telephony-active>
                                <span
                                    class="telephony-toggle-track"
                                    aria-hidden="true"></span>
                            </span>
                        </label>
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
                        <i class="bi bi-check2-circle"></i>
                        Guardar extensión
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalTelefoniaLiberar"
    tabindex="-1"
    aria-labelledby="modalTelefoniaLiberarTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5
                        class="system-form-modal-title"
                        id="modalTelefoniaLiberarTitulo">
                        Liberar extensión
                    </h5>
                    <p class="system-form-modal-subtitle">
                        La extensión podrá asignarse a otra persona.
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
                action="<?= BASE_URL ?>index.php?controller=telefonia&action=liberarExtension"
                method="POST">
                <input
                    type="hidden"
                    name="usuario_id"
                    value=""
                    data-telephony-release-user-id>

                <div class="modal-body">
                    <div class="telephony-release-copy">
                        <span class="telephony-release-icon">
                            <i class="bi bi-telephone-x"></i>
                        </span>
                        <div>
                            <strong data-telephony-release-title>
                                Liberar extensión
                            </strong>
                            <p>
                                El usuario dejará de tener una extensión Zadarma
                                asignada. Esta acción solo afecta la configuración
                                actual; no elimina usuarios.
                            </p>
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
                        class="btn btn-system-danger">
                        <i class="bi bi-x-circle"></i>
                        Liberar extensión
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
