<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro recibido</title>

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

<body class="formulario-publico-body formulario-publico-thanks-body">
    <main class="formulario-publico-thanks">
        <section class="formulario-publico-thanks-card">
            <div class="formulario-publico-thanks-logo">
                <img
                    src="<?= BASE_URL ?>public/img/brand/porcayo-grupo8.png"
                    alt="Porcayo Learning Group">
            </div>

            <span class="formulario-publico-thanks-icon" aria-hidden="true">
                <i class="bi bi-check2-circle"></i>
            </span>

            <h1>¡Muchas gracias por registrarte!</h1>

            <p>
                Hemos recibido correctamente tu información.
                Muy pronto nos pondremos en contacto contigo.
            </p>
        </section>
    </main>
</body>
</html>
