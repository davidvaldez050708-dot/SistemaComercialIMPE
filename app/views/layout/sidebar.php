<?php

$opcionActiva = $opcionActiva ?? 'inicio';
$idRol = (int)($_SESSION['rol_id'] ?? 0);

$claseInicio = $opcionActiva === 'inicio' ? 'active' : '';
$claseUsuarios = $opcionActiva === 'usuarios' ? 'active' : '';
$claseRoles = $opcionActiva === 'roles' ? 'active' : '';

?>

<aside class="admin-sidebar admin-sidebar-fixed d-none d-lg-flex flex-column">

    <div class="sidebar-brand">

        <div class="sidebar-brand-card">

            <img
                src="<?= BASE_URL ?>public/img/brand/porcayo-grupo.png"
                alt="Grupo Porcayo"
                class="sidebar-brand-logo">

        </div>

        <div class="sidebar-system-name">
            Sistema Comercial
        </div>

    </div>

    <nav>
        <div class="sidebar-section">
            <p class="sidebar-section-title">
                INICIO
            </p>

            <a
                href="<?= BASE_URL ?>index.php?controller=home&action=index"
                class="sidebar-link <?= $claseInicio ?>">
                <i class="bi bi-grid-1x2"></i>
                Inicio
            </a>
        </div>

        <?php if ($idRol === 1): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    GESTIÓN
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=usuario&action=index"
                    class="sidebar-link <?= $claseUsuarios ?>">
                    <i class="bi bi-people"></i>
                    Usuarios
                </a>

                <a
                    href="<?= BASE_URL ?>index.php?controller=home&action=index&vista=roles"
                    class="sidebar-link <?= $claseRoles ?>">
                    <i class="bi bi-shield-lock"></i>
                    Roles y permisos
                </a>
            </div>

        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?php if ($fotoPerfilUrl !== ''): ?>

                <img
                    src="<?= htmlspecialchars($fotoPerfilUrl) ?>"
                    class="system-avatar system-avatar-sm system-avatar-image"
                    alt="Foto de perfil">

            <?php else: ?>

                <div class="system-avatar system-avatar-sm">
                    <?= htmlspecialchars($iniciales) ?>
                </div>

            <?php endif; ?>

            <div>
                <div class="sidebar-user-name">
                    <?= htmlspecialchars($nombreCompleto) ?>
                </div>

                <div class="sidebar-user-email">
                    <?= htmlspecialchars($_SESSION['rol'] ?? '') ?>
                </div>
            </div>

            <a
                href="<?= BASE_URL ?>logout.php"
                class="sidebar-logout"
                aria-label="Cerrar sesión">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

</aside>

<div
    class="offcanvas offcanvas-start admin-sidebar d-lg-none"
    tabindex="-1"
    id="mobileSidebar"
    aria-labelledby="mobileSidebarTitle">

    <div class="sidebar-brand">
        <div class="system-avatar">
            IM
        </div>

        <div>
            <div
                class="sidebar-brand-name"
                id="mobileSidebarTitle">
                IMPE
            </div>

            <div class="sidebar-brand-subtitle">
                Sistema Comercial
            </div>
        </div>
    </div>

    <div class="offcanvas-body d-flex flex-column">
        <nav>
            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    INICIO
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=home&action=index"
                    class="sidebar-link <?= $claseInicio ?>">
                    <i class="bi bi-grid-1x2"></i>
                    Inicio
                </a>
            </div>

            <?php if ($idRol === 1): ?>

                <div class="sidebar-section">
                    <p class="sidebar-section-title">
                        GESTIÓN
                    </p>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=usuario&action=index"
                        class="sidebar-link <?= $claseUsuarios ?>">
                        <i class="bi bi-people"></i>
                        Usuarios
                    </a>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=home&action=index&vista=roles"
                        class="sidebar-link <?= $claseRoles ?>">
                        <i class="bi bi-shield-lock"></i>
                        Roles y permisos
                    </a>
                </div>

            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <?php if ($fotoPerfilUrl !== ''): ?>

                    <img
                        src="<?= htmlspecialchars($fotoPerfilUrl) ?>"
                        class="system-avatar system-avatar-sm system-avatar-image sidebar-user-avatar"
                        alt="Foto de perfil">

                <?php else: ?>

                    <div class="system-avatar system-avatar-sm sidebar-user-avatar">
                        <?= htmlspecialchars($iniciales) ?>
                    </div>

                <?php endif; ?>

                <div>
                    <div class="sidebar-user-name">
                        <?= htmlspecialchars($nombreCompleto) ?>
                    </div>

                    <div class="sidebar-user-email">
                        <?= htmlspecialchars($_SESSION['rol'] ?? '') ?>
                    </div>
                </div>

                <a
                    href="<?= BASE_URL ?>logout.php"
                    class="sidebar-logout"
                    aria-label="Cerrar sesión">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>
