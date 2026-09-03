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
</head>

<body>

<div class="admin-shell">
