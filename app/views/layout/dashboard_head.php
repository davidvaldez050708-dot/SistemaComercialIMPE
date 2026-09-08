<?php

$tituloPagina = $tituloPagina ?? 'Panel administrativo';
$subtituloPagina = $subtituloPagina ?? 'Resumen del sistema';

$nombreCompleto = trim(
    ($_SESSION['nombre'] ?? '') . ' ' .
    ($_SESSION['apellidos'] ?? '')
);

if ($nombreCompleto === '') {
    $nombreCompleto = $_SESSION['usuario'] ?? 'Usuario';
}

$iniciales = strtoupper(
    substr($_SESSION['nombre'] ?? 'U', 0, 1) .
    substr($_SESSION['apellidos'] ?? '', 0, 1)
);

if (strlen($iniciales) < 2) {
    $iniciales = strtoupper(substr($nombreCompleto, 0, 2));
}

$fotoPerfil = trim($_SESSION['foto_perfil'] ?? '');
$fotoPerfilUrl = $fotoPerfil !== ''
    ? BASE_URL . ltrim($fotoPerfil, '/')
    : '';

$cssOpcionalDashboard = [
    'seguimiento_filtros_layout.css',
    'seguimiento_panel_ruta.css',
    'oficios_vista_previa.css',
    'oficios_vista_previa_documento.css',
    'oficios_correo_ajustes.css',
    'seguimiento_expediente.css',
    'seguimiento_expediente_v2.css',
    'seguimiento_flujo.css',
    'seguimiento_post_envio.css',
    'agenda_reunion.css',
    'agenda_reunion_refinamientos.css'
];

$jsOpcionalHead = [
    'agenda_reunion.js',
    'agenda_kam_cambio.js',
    'reunion_fecha_guard.js',
    'reunion_resultado.js',
    'reprogramacion_reunion.js',
    'agenda_correo_natural.js',
    'agenda_historial_navegacion.js',
    'seguimiento_bandeja_sync.js',
    'seguimiento_resumen_ruta.js',
    'seguimiento_panel_ruta.js',
    'seguimiento_flujo_loading.js',
    'seguimiento_resultados_humanizados.js',
    'oficios_correo_formato.js',
    'firma_correo_perfil.js',
    'correo_firma_envio.js'
];

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($tituloPagina) ?> | Grupo Porcayo
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/global.css?v=<?= filemtime(ROOT_PATH . '/public/css/global.css') ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/dashboard.css?v=<?= time() ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/recordatorios.css?v=<?= filemtime(ROOT_PATH . '/public/css/recordatorios.css') ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/poblacion_objetivo_educativa.css?v=<?= filemtime(ROOT_PATH . '/public/css/poblacion_objetivo_educativa.css') ?>">

    <?php foreach ($cssOpcionalDashboard as $archivoCss): ?>
        <?php $rutaCss = ROOT_PATH . '/public/css/' . $archivoCss; ?>
        <?php if (is_file($rutaCss)): ?>
            <link
                rel="stylesheet"
                href="<?= BASE_URL ?>public/css/<?= htmlspecialchars($archivoCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= filemtime($rutaCss) ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <script>
        window.IMPE_CURRENT_ROLE_ID = <?= (int)($_SESSION['rol_id'] ?? 0) ?>;
    </script>

    <?php foreach ($jsOpcionalHead as $archivoJs): ?>
        <?php $rutaJs = ROOT_PATH . '/public/javascript/' . $archivoJs; ?>
        <?php if (is_file($rutaJs)): ?>
            <script
                src="<?= BASE_URL ?>public/javascript/<?= htmlspecialchars($archivoJs, ENT_QUOTES, 'UTF-8') ?>?v=<?= filemtime($rutaJs) ?>">
            </script>
        <?php endif; ?>
    <?php endforeach; ?>
</head>

<body>

<div class="admin-shell">
