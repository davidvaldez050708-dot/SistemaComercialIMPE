<!doctype html>
<html lang="es">
<head>
    <?php require dirname(__DIR__) . '/layout/favicon.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inscripción recibida</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/formularios_publico.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_publico.css') ?>">
</head>

<body class="formulario-publico-body formulario-publico-thanks-body formulario-publico-inscripcion">
    <main class="formulario-publico-thanks">
        <section class="formulario-publico-thanks-card">
            <div class="formulario-publico-thanks-logo">
                <img
                    src="<?= BASE_URL ?>public/img/brand/logo-duo-color.png"
                    alt="Fundación Red Educativa México y Universidad MPE">
            </div>

            <span class="formulario-publico-thanks-icon" aria-hidden="true">
                <i class="bi bi-check2-circle"></i>
            </span>

            <h1>¡Muchas gracias por completar tu inscripción!</h1>

            <p>
                Hemos recibido correctamente tu información de inscripción.
                Muy pronto nos pondremos en contacto contigo.
            </p>
        </section>
    </main>
</body>
</html>
