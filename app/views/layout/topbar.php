<?php require_once __DIR__ . '/../../helpers/AvatarHelper.php'; ?>

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

    </header>

    <section class="admin-content">
