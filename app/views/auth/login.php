<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Portal Institucional | Grupo Porcayo</title>

    <!-- Fuente Manrope -->
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Estilos propios -->
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/login.css">

</head>

<body>

<div class="login-page">

    <div class="login-card">

        <!-- Logo -->
        <div class="login-brand">

            <img
                src="<?= BASE_URL ?>public/img/brand/porcayo-grupo.png"
                alt="Grupo Porcayo"
                class="login-brand-logo">

        </div>


        <div class="text-center mb-4">

            <h1 class="login-title">
                Portal Institucional
            </h1>

            <p class="login-subtitle mb-0">
                Ingrese sus credenciales para acceder
            </p>

        </div>


        <?php if (
            isset($_GET['sesion']) &&
            $_GET['sesion'] === 'expirada'
        ): ?>

            <div
                class="alert alert-warning login-alert login-alert-warning"
                role="alert">

                <i class="bi bi-clock-history"></i>

                <span>
                    Su sesión finalizó por inactividad.
                    Inicie sesión nuevamente.
                </span>

            </div>

        <?php endif; ?>


        <?php if (isset($_SESSION['error_login'])): ?>

            <div
                class="alert alert-danger login-alert"
                role="alert">

                <i class="bi bi-exclamation-circle"></i>

                <span>
                    <?= htmlspecialchars($_SESSION['error_login']) ?>
                </span>

            </div>

            <?php unset($_SESSION['error_login']); ?>

        <?php endif; ?>


        <form
            action="<?= BASE_URL ?>index.php?controller=login&action=iniciarSesion"
            method="POST">

            <!-- Usuario -->
            <div class="mb-3">

                <label
                    for="usuario"
                    class="form-label login-label">

                    Usuario o correo

                </label>

                <div class="input-group login-input-group">

                    <span class="input-group-text">

                        <i class="bi bi-person"></i>

                    </span>

                    <input
                        type="text"
                        class="form-control"
                        id="usuario"
                        name="usuario"
                        placeholder="Ingrese su usuario o correo"
                        autocomplete="username"
                        required>

                </div>

            </div>


            <!-- Contraseña -->
            <div class="mb-2">

                <label
                    for="password"
                    class="form-label login-label">

                    Contraseña

                </label>

                <div class="input-group login-input-group">

                    <span class="input-group-text">

                        <i class="bi bi-lock"></i>

                    </span>

                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        placeholder="Ingrese su contraseña"
                        autocomplete="current-password"
                        required>

                    <button
                        type="button"
                        class="btn password-toggle"
                        id="togglePassword"
                        aria-label="Mostrar contraseña">

                        <i
                            class="bi bi-eye"
                            id="passwordIcon">
                        </i>

                    </button>

                </div>

            </div>


            <!-- Recuperar contraseña -->
            <div class="text-end mb-4">

                <a
                    href="<?= BASE_URL ?>index.php?controller=login&action=mostrarRecuperacion"
                    class="forgot-link">

                    ¿Olvidó su contraseña?

                </a>

            </div>


            <!-- Iniciar sesión -->
            <div class="d-grid">

                <button
                    type="submit"
                    class="btn btn-login">

                    <i class="bi bi-box-arrow-in-right me-2"></i>

                    Iniciar sesión

                </button>

            </div>

        </form>


        <div class="login-footer">

            <a href="#">
                Soporte técnico
            </a>

            <span>•</span>

            <a href="#">
                Aviso de privacidad
            </a>

        </div>

    </div>

</div>


<!-- Bootstrap JS -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>

<!-- JS propio -->
<script
    src="<?= BASE_URL ?>public/javascript/login.js">
</script>

</body>

</html>