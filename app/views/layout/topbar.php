<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';
require_once __DIR__ . '/../../helpers/ReminderHelper.php';

$recordatoriosSeguimiento = [];
$esAnalistaDatos = (int)($_SESSION['rol_id'] ?? 0) === 4;

if ($esAnalistaDatos) {
    $recordatoriosSeguimiento = obtenerRecordatoriosSeguimientoAnalista(
        (int)($_SESSION['usuario_id'] ?? 0),
        8
    );
}

$totalRecordatoriosSeguimiento = count($recordatoriosSeguimiento);

?>

<main class="admin-main">

    <header class="admin-topbar">

        <div class="d-flex align-items-center gap-3">
            <button
                class="mobile-menu-button d-lg-none"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#mobileSidebar"
                aria-controls="mobileSidebar"
                aria-label="Abrir navegación">
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="page-title">
                    <?= htmlspecialchars($tituloPagina) ?>
                </h1>

                <p class="page-subtitle">
                    <?= htmlspecialchars($subtituloPagina) ?>
                </p>
            </div>
        </div>

        <div class="topbar-actions">
            <?php if ($esAnalistaDatos): ?>
                <div
                    class="dropdown"
                    data-reminder-root
                    data-reminder-endpoint="<?= BASE_URL ?>index.php?controller=reminder&action=pendientes">
                    <button
                        class="topbar-reminder-button dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Abrir recordatorios">
                        <i class="bi bi-bell"></i>

                        <span
                            class="topbar-reminder-badge <?= $totalRecordatoriosSeguimiento > 0 ? '' : 'd-none' ?>"
                            data-reminder-badge>
                            <?= $totalRecordatoriosSeguimiento > 9 ? '9+' : $totalRecordatoriosSeguimiento ?>
                        </span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end topbar-reminder-menu">
                        <div class="topbar-reminder-header">
                            <strong>Recordatorios</strong>
                            <span>Acciones próximas en 24 h y acciones vencidas.</span>
                        </div>

                        <div data-reminder-content>
                            <?php if (!empty($recordatoriosSeguimiento)): ?>
                                <div class="topbar-reminder-list">
                                    <?php foreach ($recordatoriosSeguimiento as $recordatorio): ?>
                                        <?php
                                        $accionRecordatorio = trim(
                                            (string)($recordatorio['proxima_accion_texto'] ?? '')
                                        );
                                        $estadoRecordatorio = (string)(
                                            $recordatorio['recordatorio']['estado'] ?? 'normal'
                                        );
                                        $etiquetaRecordatorio = (string)(
                                            $recordatorio['recordatorio']['etiqueta'] ?? ''
                                        );
                                        ?>
                                        <a
                                            class="topbar-reminder-item"
                                            href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=<?= (int)($recordatorio['id'] ?? 0) ?>">
                                            <span class="topbar-reminder-icon">
                                                <i class="bi <?= htmlspecialchars(iconoAccionRecordatorioSeguimiento($accionRecordatorio), ENT_QUOTES, 'UTF-8') ?>"></i>
                                            </span>

                                            <span class="topbar-reminder-copy">
                                                <strong>
                                                    <?= htmlspecialchars((string)($recordatorio['nombre_entidad'] ?? 'Seguimiento'), ENT_QUOTES, 'UTF-8') ?>
                                                </strong>
                                                <span>
                                                    <?= htmlspecialchars($accionRecordatorio, ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </span>

                                            <span class="topbar-reminder-time is-<?= htmlspecialchars($estadoRecordatorio, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($etiquetaRecordatorio, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="topbar-reminder-empty">
                                    <i class="bi bi-check2-circle"></i>
                                    <strong>Sin recordatorios pendientes</strong>
                                    <span>No tienes llamadas o mensajes próximos.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dropdown">
                <button
                    class="topbar-account-button dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <?= renderAvatarUsuario(
                        $_SESSION['nombre'] ?? $nombreCompleto,
                        $_SESSION['apellidos'] ?? '',
                        $_SESSION['rol'] ?? 'Usuario',
                        $_SESSION['foto_perfil'] ?? '',
                        'sm',
                        'general',
                        'topbar-user-avatar',
                        false
                    ) ?>

                    <span class="topbar-account-text d-none d-sm-grid">
                        <span class="topbar-account-name">
                            <?= htmlspecialchars($nombreCompleto) ?>
                        </span>

                        <span class="topbar-account-role">
                            <?= htmlspecialchars($_SESSION['rol'] ?? 'Usuario') ?>
                        </span>
                    </span>
                </button>

                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a
                            class="dropdown-item"
                            href="<?= BASE_URL ?>index.php?controller=home&action=index&vista=perfil">
                            <i class="bi bi-person me-2"></i>
                            Mi perfil
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="<?= BASE_URL ?>index.php?controller=home&action=index&vista=cambiar-password">
                            <i class="bi bi-key me-2"></i>
                            Cambiar contraseña
                        </a>
                    </li>

                    <li>
                        <hr class="dropdown-divider">
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="<?= BASE_URL ?>logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>
                            Cerrar sesión
                        </a>
                    </li>
                </ul>
            </div>
        </div>

    </header>

    <section class="admin-content">
