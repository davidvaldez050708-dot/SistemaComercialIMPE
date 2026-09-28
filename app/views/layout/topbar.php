<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';
require_once __DIR__ . '/../../helpers/ReminderHelper.php';

$rolTopbarId = (int)($_SESSION['rol_id'] ?? 0);
$esAnalistaDatos = $rolTopbarId === 4;
$esCuentaClave = $rolTopbarId === 6;
$mostrarCentroAvisos = $esAnalistaDatos || $esCuentaClave;
$agendaDisponible = is_file(ROOT_PATH . '/app/controllers/AgendaReunionController.php');
$mostrarAgendaReuniones = $mostrarCentroAvisos && $agendaDisponible;
$trabajarNotificacionId = (int)($_GET['trabajar_id'] ?? 0);

/*
 * La campana se hidrata únicamente desde ReminderController.
 * Antes se precargaban aquí recordatorios genéricos de seguimiento y,
 * al terminar el fetch del frontend, eran sustituidos por la fuente
 * operativa completa (agenda + reuniones + acuerdos + seguimiento).
 * Eso provocaba un "flash" de estados antiguos al recargar.
 */
$totalRecordatoriosSeguimiento = 0;

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
            <?php if ($mostrarAgendaReuniones): ?>
                <a
                    class="topbar-reminder-button"
                    href="<?= BASE_URL ?>index.php?controller=agendaReunion&action=index"
                    aria-label="Abrir agenda de reuniones"
                    title="Agenda de reuniones">
                    <i class="bi bi-calendar3"></i>
                </a>
            <?php endif; ?>

            <?php if ($mostrarCentroAvisos): ?>
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
                        aria-label="Abrir notificaciones">
                        <i class="bi bi-bell"></i>

                        <span
                            class="topbar-reminder-badge <?= $totalRecordatoriosSeguimiento > 0 ? '' : 'd-none' ?>"
                            data-reminder-badge>
                            <?= $totalRecordatoriosSeguimiento > 9 ? '9+' : $totalRecordatoriosSeguimiento ?>
                        </span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end topbar-reminder-menu">
                        <div class="topbar-reminder-header">
                            <strong>Notificaciones</strong>
                            <span>Reuniones, confirmaciones y acciones próximas.</span>
                        </div>

                        <div data-reminder-content aria-live="polite" aria-busy="true">
                            <div class="topbar-reminder-empty">
                                <i class="bi bi-arrow-repeat"></i>
                                <strong>Actualizando notificaciones</strong>
                                <span>Consultando reuniones y acciones pendientes.</span>
                            </div>
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
                            href="#"
                            data-my-profile-open>
                            <i class="bi bi-person me-2"></i>
                            Mi perfil
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#modalCambiarMiPassword">
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
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#modalCerrarSesion">
                            <i class="bi bi-box-arrow-right me-2"></i>
                            Cerrar sesión
                        </a>
                    </li>
                </ul>
            </div>
        </div>

    </header>

    <?php require_once __DIR__ . '/my_profile_modal.php'; ?>
    <?php require_once __DIR__ . '/my_password_modal.php'; ?>

    <?php if ($trabajarNotificacionId > 0): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const seguimientoId = <?= $trabajarNotificacionId ?>;
            let intentos = 0;

            const abrirTrabajoNotificacion = function () {
                const boton = document.querySelector(
                    '[data-work-follow-id="' + seguimientoId + '"]'
                );

                if (!boton) {
                    intentos += 1;
                    if (intentos < 20) {
                        window.setTimeout(abrirTrabajoNotificacion, 100);
                    }
                    return;
                }

                boton.click();

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('trabajar_id');
                    window.history.replaceState(
                        null,
                        '',
                        url.pathname + url.search + url.hash
                    );
                } catch (error) {
                    console.warn('No fue posible limpiar el destino de la notificación.', error);
                }
            };

            window.setTimeout(abrirTrabajoNotificacion, 140);
        });
        </script>
    <?php endif; ?>

    <section class="admin-content">
