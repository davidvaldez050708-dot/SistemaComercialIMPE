<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro | Sistema Comercial</title>

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
        href="<?= BASE_URL ?>public/css/dashboard.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/formularios.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/formularios_publico.css">
</head>

<body class="formulario-publico-body">
    <header class="formulario-publico-header">
        <div class="formulario-publico-brand">
            <span class="formulario-publico-brand-icon">
                <i class="bi bi-ui-checks-grid"></i>
            </span>
            <div>
                <strong>Sistema Comercial</strong>
                <small>Formulario de Registro</small>
            </div>
        </div>
    </header>

    <main class="formulario-publico-main">
        <?php require __DIR__ . '/registro.php'; ?>
    </main>

    <footer class="formulario-publico-footer">
        <span>
            La información capturada será utilizada para dar seguimiento
            a tu solicitud de información educativa.
        </span>
    </footer>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>
    <script
        src="<?= BASE_URL ?>public/javascript/formularios_registro.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/formularios_registro.js') ?>">
    </script>
</body>
</html>
