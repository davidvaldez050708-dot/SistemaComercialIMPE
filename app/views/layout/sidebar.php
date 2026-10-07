<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$opcionActiva = $opcionActiva ?? 'inicio';
$mostrarUsuarios = tienePermiso('usuarios.ver');
$mostrarRoles = tienePermiso('roles.ver');
$mostrarTerritorios = tienePermiso('territorios.ver');
$mostrarTelefoniaConfiguracion =
    (int)($_SESSION['rol_id'] ?? 0) === 1;
$mostrarDataTerritorial = tienePermiso('data_territorial.ver');
$mostrarSeguimientoVinculacion = tienePermiso('seguimientos_vinculacion.ver');
$mostrarAliados = tienePermiso('aliados.ver');
$mostrarWhatsapp = tienePermiso('whatsapp.ver');
$mostrarConvocatorias = tienePermiso('convocatorias.ver');
$mostrarReporteTerritorial =
    tienePermiso('reportes.territorial') &&
    $mostrarDataTerritorial;
$mostrarReporteSeguimiento =
    $mostrarSeguimientoVinculacion &&
    (
        tienePermiso('reportes.seguimiento.cartera') ||
        tienePermiso('reportes.seguimiento.actividad') ||
        tienePermiso('reportes.seguimiento.institucion')
    );
$mostrarReporteUsuarios =
    (int)($_SESSION['rol_id'] ?? 0) === 1 &&
    tienePermiso('reportes.usuarios');
$mostrarReporteConvocatorias =
    tienePermiso('reportes.convocatorias');
$esRolMarketingSidebar = strcasecmp(
    trim((string)($_SESSION['rol'] ?? '')),
    'Marketing'
) === 0;
$mostrarReporteConvocatoriasEnMarketing =
    $esRolMarketingSidebar &&
    tienePermiso('reportes.ver') &&
    $mostrarReporteConvocatorias;
$mostrarReportes =
    tienePermiso('reportes.ver') &&
    (
        $mostrarReporteTerritorial ||
        $mostrarReporteSeguimiento ||
        $mostrarReporteUsuarios ||
        $mostrarReporteConvocatorias
    );
$mostrarReportesEnAnalisis =
    $mostrarReportes;

$mostrarDesempeno = tienePermiso('desempeno.ver');
$mostrarAnalisis =
    $mostrarReportesEnAnalisis ||
    $mostrarDesempeno;

$etiquetaMenuConvocatorias = tienePermiso('convocatorias.gestionar')
    ? 'Gestión de Convocatorias'
    : 'Convocatorias';

$claseInicio = $opcionActiva === 'inicio' ? 'active' : '';
$claseUsuarios = $opcionActiva === 'usuarios' ? 'active' : '';
$claseRoles = $opcionActiva === 'roles' ? 'active' : '';
$claseTerritorios = $opcionActiva === 'territorios' ? 'active' : '';
$claseTelefonia = $opcionActiva === 'telefonia' ? 'active' : '';
$claseDataTerritorial =
    $opcionActiva === 'data_territorial' ? 'active' : '';
$claseSeguimientoVinculacion =
    $opcionActiva === 'seguimiento_vinculacion' ? 'active' : '';
$claseAliados = $opcionActiva === 'aliados' ? 'active' : '';
$claseWhatsapp = $opcionActiva === 'whatsapp' ? 'active' : '';
$claseReportes = $opcionActiva === 'reportes' ? 'active' : '';
$claseDesempeno = $opcionActiva === 'desempeno' ? 'active' : '';
$claseConvocatorias = $opcionActiva === 'convocatorias' ? 'active' : '';
$claseConvocatoriasReportes =
    $opcionActiva === 'convocatorias_reportes' ? 'active' : '';
$claseCorreosMarketing =
    $opcionActiva === 'correos_marketing' ? 'active' : '';
$claseFormularios =
    $opcionActiva === 'formularios' ? 'active' : '';

?>

<aside class="admin-sidebar admin-sidebar-fixed d-none d-lg-flex flex-column">

    <div class="sidebar-brand">

        <div class="sidebar-brand-card">

            <img
                src="<?= BASE_URL ?>public/img/brand/porcayo-grupo8.png"
                alt="Grupo Porcayo"
                class="sidebar-brand-logo">

        </div>

       <div class="sidebar-system-name">
            Sistema Comercial
        </div>

    </div>

    <nav
        class="sidebar-navigation"
        data-sidebar-scroll="desktop"
        data-sidebar-user="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>">
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

        <?php if (
            $mostrarUsuarios ||
            $mostrarRoles ||
            $mostrarTerritorios ||
            $mostrarTelefoniaConfiguracion
        ): ?>

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

                <?php if ($mostrarTelefoniaConfiguracion): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=telefonia&action=index"
                        class="sidebar-link <?= $claseTelefonia ?>">
                        <i class="bi bi-headset"></i>
                        Telefonía
                    </a>

                <?php endif; ?>
            </div>

        <?php endif; ?>

        <?php if ($mostrarConvocatorias): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    MARKETING
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index"
                    class="sidebar-link <?= $claseConvocatorias ?>">
                    <i class="bi bi-megaphone"></i>
                    <?= htmlspecialchars($etiquetaMenuConvocatorias) ?>
                </a>

                <?php if ($esRolMarketingSidebar): ?>
                    <a
                        href="<?= BASE_URL ?>index.php?controller=formulario&action=index"
                        class="sidebar-link <?= $claseFormularios ?>">
                        <i class="bi bi-ui-checks-grid"></i>
                        Formularios
                    </a>
                <?php endif; ?>

                <?php if ($esRolMarketingSidebar): ?>
                    <a
                        href="<?= BASE_URL ?>index.php?controller=correoMarketing&action=index"
                        class="sidebar-link <?= $claseCorreosMarketing ?>">
                        <i class="bi bi-envelope"></i>
                        Correos
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <?php if ($mostrarWhatsapp): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    COMUNICACIÓN
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=whatsapp&action=index"
                    class="sidebar-link <?= $claseWhatsapp ?>">
                    <i class="bi bi-whatsapp"></i>
                    Conversaciones
                </a>
            </div>

        <?php endif; ?>

        <?php if ($mostrarDataTerritorial || $mostrarSeguimientoVinculacion || $mostrarAliados): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    VINCULACIÓN
                </p>

                <?php if ($mostrarDataTerritorial): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index"
                        class="sidebar-link <?= $claseDataTerritorial ?>">
                        <i class="bi bi-database"></i>
                        Información territorial
                    </a>

                <?php endif; ?>

                <?php if ($mostrarSeguimientoVinculacion): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index"
                        class="sidebar-link <?= $claseSeguimientoVinculacion ?>">
                        <i class="bi bi-kanban"></i>
                        Seguimiento
                    </a>

                <?php endif; ?>

                <?php if ($mostrarAliados): ?>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=aliado&action=index"
                        class="sidebar-link <?= $claseAliados ?>">
                        <i class="bi bi-building-check"></i>
                        Aliados
                    </a>

                <?php endif; ?>
            </div>

        <?php endif; ?>

        <?php if ($mostrarAnalisis): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    ANÁLISIS
                </p>

                <?php if ($mostrarDesempeno): ?>
                    <a
                        href="<?= BASE_URL ?>index.php?controller=desempeno&action=index"
                        class="sidebar-link <?= $claseDesempeno ?>">
                        <i class="bi bi-trophy"></i>
                        Desempeño
                    </a>
                <?php endif; ?>

                <?php if ($mostrarReportesEnAnalisis): ?>
                    <a
                        href="<?= BASE_URL ?>index.php?controller=reporte&action=index"
                        class="sidebar-link <?= $claseReportes ?>">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        Reportes
                    </a>
                <?php endif; ?>
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
                href="#"
            class="sidebar-logout"
    aria-label="Cerrar sesión"
    data-bs-toggle="modal"
    data-bs-target="#modalCerrarSesion">

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

    <!-- =====================================
         LOGO RESPONSIVE
    ====================================== -->

    <div
        class="sidebar-brand sidebar-brand-mobile"
        id="mobileSidebarTitle">

        <div class="sidebar-brand-card">

            <img
                src="<?= BASE_URL ?>public/img/brand/porcayo-grupo8.png"
                alt="Grupo Porcayo"
                class="sidebar-brand-logo">

        </div>

        <div class="sidebar-system-name">
            Sistema Comercial
        </div>

    </div>


    <!-- =====================================
         CONTENIDO DEL SIDEBAR
    ====================================== -->

    <div class="offcanvas-body d-flex flex-column">

        <nav
            class="sidebar-navigation"
            data-sidebar-scroll="mobile"
            data-sidebar-user="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>">

            <!-- INICIO -->
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


            <!-- GESTIÓN -->
            <?php if (
                $mostrarUsuarios ||
                $mostrarRoles ||
                $mostrarTerritorios ||
                $mostrarTelefoniaConfiguracion
            ): ?>

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

                    <?php if ($mostrarTelefoniaConfiguracion): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=telefonia&action=index"
                            class="sidebar-link <?= $claseTelefonia ?>">

                            <i class="bi bi-headset"></i>
                            Telefonía

                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <!-- MARKETING -->
            <?php if ($mostrarConvocatorias): ?>

                <div class="sidebar-section">
                    <p class="sidebar-section-title">
                        MARKETING
                    </p>

                    <a
                        href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index"
                        class="sidebar-link <?= $claseConvocatorias ?>">
                        <i class="bi bi-megaphone"></i>
                        <?= htmlspecialchars($etiquetaMenuConvocatorias) ?>
                    </a>

                    <?php if ($esRolMarketingSidebar): ?>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=formulario&action=index"
                            class="sidebar-link <?= $claseFormularios ?>">
                            <i class="bi bi-ui-checks-grid"></i>
                            Formularios
                        </a>
                    <?php endif; ?>

                    <?php if ($esRolMarketingSidebar): ?>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=correoMarketing&action=index"
                            class="sidebar-link <?= $claseCorreosMarketing ?>">
                            <i class="bi bi-envelope"></i>
                            Correos
                        </a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>


            <!-- VINCULACIÓN -->
            <?php if ($mostrarWhatsapp): ?>

            <div class="sidebar-section">
                <p class="sidebar-section-title">
                    COMUNICACIÓN
                </p>

                <a
                    href="<?= BASE_URL ?>index.php?controller=whatsapp&action=index"
                    class="sidebar-link <?= $claseWhatsapp ?>">
                    <i class="bi bi-whatsapp"></i>
                    Conversaciones
                </a>
            </div>

        <?php endif; ?>

        <?php if ($mostrarDataTerritorial || $mostrarSeguimientoVinculacion || $mostrarAliados): ?>

                <div class="sidebar-section">

                    <p class="sidebar-section-title">
                        VINCULACIÓN
                    </p>

                    <?php if ($mostrarDataTerritorial): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index"
                            class="sidebar-link <?= $claseDataTerritorial ?>">

                            <i class="bi bi-database"></i>
                            Información territorial

                        </a>

                    <?php endif; ?>


                    <?php if ($mostrarSeguimientoVinculacion): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index"
                            class="sidebar-link <?= $claseSeguimientoVinculacion ?>">

                            <i class="bi bi-kanban"></i>
                            Seguimiento

                        </a>

                    <?php endif; ?>

                    <?php if ($mostrarAliados): ?>

                        <a
                            href="<?= BASE_URL ?>index.php?controller=aliado&action=index"
                            class="sidebar-link <?= $claseAliados ?>">

                            <i class="bi bi-building-check"></i>
                            Aliados

                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <!-- ANÁLISIS -->
            <?php if ($mostrarAnalisis): ?>

                <div class="sidebar-section">

                    <p class="sidebar-section-title">
                        ANÁLISIS
                    </p>

                    <?php if ($mostrarDesempeno): ?>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=desempeno&action=index"
                            class="sidebar-link <?= $claseDesempeno ?>">

                            <i class="bi bi-trophy"></i>
                            Desempeño

                        </a>
                    <?php endif; ?>

                    <?php if ($mostrarReportesEnAnalisis): ?>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=reporte&action=index"
                            class="sidebar-link <?= $claseReportes ?>">

                            <i class="bi bi-file-earmark-bar-graph"></i>
                            Reportes

                        </a>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </nav>


        <!-- =====================================
             USUARIO
        ====================================== -->

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

<!-- =====================================
     MODAL CERRAR SESIÓN
====================================== -->

<div
    class="modal fade"
    id="modalCerrarSesion"
    tabindex="-1"
    aria-labelledby="modalCerrarSesionLabel"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content logout-confirm-modal">

            <div class="modal-body text-center">

                <div class="logout-confirm-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h5
                    class="logout-confirm-title"
                    id="modalCerrarSesionLabel">
                    ¿Cerrar sesión?
                </h5>

                <p class="logout-confirm-text">
                    ¿Estás seguro de que deseas cerrar tu sesión?
                </p>

                <div class="logout-confirm-actions">

                    <button
                        type="button"
                        class="btn logout-cancel-button"
                        data-bs-dismiss="modal">

                        Cancelar

                    </button>

                    <a
                        href="<?= BASE_URL ?>logout.php"
                        class="btn logout-confirm-button">

                        Sí, cerrar sesión

                    </a>

                </div>

            </div>

        </div>

    </div>

</div>