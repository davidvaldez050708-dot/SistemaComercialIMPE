<?php $texto = static fn($v) => htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html>
<html lang="es">
<head>
    <?php require dirname(__DIR__) . '/layout/favicon.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formulario de inscripción</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/global.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/formularios_publico.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_publico.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_hero_institucional.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_hero_institucional.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios.css?v=<?= filemtime(ROOT_PATH.'/public/css/formularios.css') ?>">
</head>

<body class="formulario-publico-body">
    <main class="formulario-publico-shell">
        <section class="formulario-publico-hero">
            <div class="formulario-publico-hero-copy">
                <h1>Formulario de inscripción</h1>
                <p class="formulario-publico-hero-slogan">
                    <?= $texto($programaInscripcion['nombre']) ?><?= $programaInscripcion['slug'] === 'bachillerato-286' ? ' · Examen Único · Acuerdo 286' : '' ?>
                </p>
                <p class="formulario-publico-hero-required">
                    Los campos marcados con <strong>*</strong> son obligatorios.
                </p>
            </div>

            <div class="formulario-publico-hero-logos">
                <div class="formulario-publico-hero-logo formulario-publico-hero-logo-principal">
                    <img
                        src="<?= BASE_URL ?>public/img/brand/Logotipos-blanco.png"
                        alt="Fundación Red Educativa México y Universidad MPE"
                        loading="eager">
                </div>
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-logo-sep">
                    <img
                        src="<?= BASE_URL ?>public/img/brand/Logo-Sep-Blanco.png"
                        alt="SEP · Acuerdo 286"
                        loading="eager">
                </div>
            </div>
        </section>

<?php require __DIR__.'/inscripcion_completa.php'; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
