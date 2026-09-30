<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';
require_once __DIR__ . '/../../helpers/ReminderHelper.php';

$rolTopbarId = (int)($_SESSION['rol_id'] ?? 0);
$esAnalistaDatos = $rolTopbarId === 4;
$esCuentaClave = $rolTopbarId === 6;
$esMarketingTopbar = strcasecmp(
    trim((string)($_SESSION['rol'] ?? '')),
    'Marketing'
) === 0;
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

            <?php if ($esAnalistaDatos): ?>
                <div
                    class="dropdown topbar-call-goal"
                    data-topbar-call-goal
                    data-endpoint="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=resumenVerificacionTelefonicaHoy">
                    <button
                        class="topbar-call-goal-button dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Abrir mi actividad telefónica de hoy"
                        title="Mi actividad telefónica de hoy">
                        <span class="topbar-call-goal-icon" aria-hidden="true">
                            <i class="bi bi-telephone-fill"></i>
                        </span>
                        <span class="topbar-call-goal-value">
                            <strong data-topbar-call-effective>—</strong>
                            <small>/25</small>
                        </span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end topbar-call-goal-menu">
                        <div class="topbar-call-goal-header">
                            <div>
                                <strong>Mi actividad telefónica</strong>
                                <span>Resumen personal de hoy</span>
                            </div>
                            <span class="topbar-call-goal-pill">
                                <b data-topbar-call-effective-menu>—</b>/25
                            </span>
                        </div>

                        <div class="topbar-call-goal-progress" aria-hidden="true">
                            <span data-topbar-call-progress style="width:0%"></span>
                        </div>

                        <div class="topbar-call-goal-stats">
                            <div>
                                <strong data-topbar-call-total>—</strong>
                                <span>Llamadas</span>
                            </div>
                            <div>
                                <strong data-topbar-call-contact>—</strong>
                                <span>Con contacto</span>
                            </div>
                            <div>
                                <strong data-topbar-call-remaining>—</strong>
                                <span>Por verificar</span>
                            </div>
                        </div>

                        <div class="topbar-call-goal-status" data-topbar-call-status>
                            Actualizando avance…
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($esMarketingTopbar): ?>
                <div
                    class="dropdown convocatoria-notification-root"
                    data-convocatoria-notification-root
                    data-endpoint="<?= BASE_URL ?>index.php?controller=convocatoriaNotificacion&action=pendientes"
                    data-read-endpoint="<?= BASE_URL ?>index.php?controller=convocatoriaNotificacion&action=marcarLeida"
                    data-read-all-endpoint="<?= BASE_URL ?>index.php?controller=convocatoriaNotificacion&action=marcarTodasLeidas">
                    <button
                        class="topbar-reminder-button dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Abrir alertas de convocatorias"
                        title="Alertas de convocatorias">
                        <i class="bi bi-bell"></i>
                        <span
                            class="topbar-reminder-badge d-none"
                            data-convocatoria-notification-badge>
                            0
                        </span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end topbar-reminder-menu convocatoria-notification-menu">
                        <div class="topbar-reminder-header convocatoria-notification-header">
                            <div>
                                <strong>Alertas de convocatorias</strong>
                                <span>Activaciones automáticas y vencimientos próximos.</span>
                            </div>
                            <button
                                type="button"
                                class="convocatoria-notification-read-all"
                                data-convocatoria-notification-read-all>
                                Marcar leídas
                            </button>
                        </div>

                        <div
                            class="convocatoria-notification-list"
                            data-convocatoria-notification-content
                            aria-live="polite"
                            aria-busy="true">
                            <div class="topbar-reminder-empty">
                                <i class="bi bi-arrow-repeat"></i>
                                <strong>Actualizando alertas</strong>
                                <span>Consultando activaciones programadas.</span>
                            </div>
                        </div>
                    </div>
                </div>
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
