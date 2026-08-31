<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$opcionActiva = $opcionActiva ?? 'inicio';
$mostrarUsuarios = tienePermiso('usuarios.ver');
$mostrarRoles = tienePermiso('roles.ver');
$mostrarTerritorios = tienePermiso('territorios.ver');
$mostrarDataTerritorial = tienePermiso('data_territorial.ver');

$claseInicio = $opcionActiva === 'inicio' ? 'active' : '';
$claseUsuarios = $opcionActiva === 'usuarios' ? 'active' : '';
$claseRoles = $opcionActiva === 'roles' ? 'active' : '';
$claseTerritorios = $opcionActiva === 'territorios' ? 'active' : '';
$claseDataTerritorial =
    $opcionActiva === 'data_territorial' ? 'active' : '';

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

        <?php if ($mostrarUsuarios || $mostrarRoles || $mostrarTerritorios): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    GESTIÓN
                </p>

                <?php if ($mostrarUsuarios): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=usuario&action=index"
                        class="sidebar-link <?= $claseUsuarios ?>">
                        <i class="bi bi-people"></i>
                        Usuarios
                    </a>

                <?php endif; ?>

                <?php if ($mostrarRoles): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=rol&action=index"
                        class="sidebar-link <?= $claseRoles ?>">
                        <i class="bi bi-shield-lock"></i>
                        Roles y permisos
                    </a>

                <?php endif; ?>

                <?php if ($mostrarTerritorios): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=territorio&action=index"
                        class="sidebar-link <?= $claseTerritorios ?>">
                        <i class="bi bi-geo-alt"></i>
                        Territorios
                    </a>

                <?php endif; ?>
            </div>

        <?php endif; ?>

        <?php if ($mostrarDataTerritorial): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    VINCULACIÓN
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index"
                    class="sidebar-link <?= $claseDataTerritorial ?>">
                    <i class="bi bi-database"></i>
                    Información territorial
                </a>
            </div>

        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?= renderAvatarUsuario(
                $_SESSION['nombre'] ?? $nombreCompleto,
                $_SESSION['apellidos'] ?? '',
                $_SESSION['rol'] ?? 'Usuario',
                $_SESSION['foto_perfil'] ?? '',
                'sm',
                'general'
            ) ?>

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

            <?php if ($mostrarUsuarios || $mostrarRoles || $mostrarTerritorios): ?>

                <div class="sidebar-section">
                    <p class="sidebar-section-title">
                        GESTIÓN
                    </p>

                    <?php if ($mostrarUsuarios): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=usuario&action=index"
                            class="sidebar-link <?= $claseUsuarios ?>">
                            <i class="bi bi-people"></i>
                            Usuarios
                        </a>

                    <?php endif; ?>

                    <?php if ($mostrarRoles): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=rol&action=index"
                            class="sidebar-link <?= $claseRoles ?>">
                            <i class="bi bi-shield-lock"></i>
                            Roles y permisos
                        </a>

                    <?php endif; ?>

                    <?php if ($mostrarTerritorios): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=territorio&action=index"
                            class="sidebar-link <?= $claseTerritorios ?>">
                            <i class="bi bi-geo-alt"></i>
                            Territorios
                        </a>

                    <?php endif; ?>
                </div>

            <?php endif; ?>

            <?php if ($mostrarDataTerritorial): ?>

                <div class="sidebar-section">
                    <p class="sidebar-section-title">
                        VINCULACIÓN
                    </p>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index"
                        class="sidebar-link <?= $claseDataTerritorial ?>">
                        <i class="bi bi-database"></i>
                        Información territorial
                    </a>
                </div>

            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <?= renderAvatarUsuario(
                    $_SESSION['nombre'] ?? $nombreCompleto,
                    $_SESSION['apellidos'] ?? '',
                    $_SESSION['rol'] ?? 'Usuario',
                    $_SESSION['foto_perfil'] ?? '',
                    'sm',
                    'general',
                    'sidebar-user-avatar'
                ) ?>

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
