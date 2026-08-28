<?php

$mostrarModalRecuperacion =
    !empty($_SESSION['mostrar_modal_recuperacion']);

unset($_SESSION['mostrar_modal_recuperacion']);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Recuperar contraseña</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <!-- Manrope -->
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- CSS del login -->
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/login.css">

</head>


<body>

<div class="login-page">

    <div class="login-card">

        <!-- Icono institucional temporal -->
        <div class="text-center mb-4">

            <div class="login-brand">

                <img
                    src="<?= BASE_URL ?>public/img/brand/porcayo-grupo.png"
                    alt="Grupo Porcayo"
                    class="login-brand-logo">

            </div>

        </div>


        <!-- Encabezado -->
        <div class="text-center mb-4">

            <h1 class="login-title">
                Recuperar contraseña
            </h1>

            <p class="login-subtitle mb-0">
                Ingrese su usuario o correo para recuperar el acceso a su cuenta
            </p>

        </div>

        <?php if (isset($_SESSION['error_recuperacion'])): ?>

            <div
                class="alert alert-danger login-alert"
                role="alert">

                <i class="bi bi-exclamation-circle"></i>

                <span>
                    <?= htmlspecialchars(
                        $_SESSION['error_recuperacion']
                    ) ?>
                </span>

            </div>

            <?php unset($_SESSION['error_recuperacion']); ?>

        <?php endif; ?>


        <!--
            Por ahora este formulario únicamente recarga
            la pantalla. En el siguiente paso conectaremos
            la generación de contraseña temporal.
        -->
        <form
            action="<?= BASE_URL ?>index.php?controller=login&action=procesarRecuperacion"
            method="POST">

            <div class="mb-4">

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


            <div class="d-grid mb-3">

                <button
                    type="submit"
                    class="btn btn-login">

                    <i class="bi bi-envelope-arrow-up me-2"></i>

                    Enviar contraseña temporal

                </button>

            </div>

        </form>


        <!-- Regresar -->
        <div class="text-center">

            <a
                href="<?= BASE_URL ?>index.php?controller=login&action=mostrarLogin"
                class="back-login-link">

                <i class="bi bi-arrow-left me-1"></i>

                Volver al inicio de sesión

            </a>

        </div>


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

<?php if ($mostrarModalRecuperacion): ?>

<div
    class="modal fade"
    id="modalRecuperacion"
    tabindex="-1"
    aria-labelledby="modalRecuperacionTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered recovery-modal-dialog">
        <div class="modal-content recovery-modal">
            <div class="modal-body">

                <!-- Icono -->
                <div class="recovery-modal-icon">
                    <i class="bi bi-envelope-check"></i>
                </div>


                <!-- Título -->
                <h2
                    class="recovery-modal-title"
                    id="modalRecuperacionTitulo">
                    Revisa tu correo
                </h2>


                <!-- Mensaje -->
                <p class="recovery-modal-text">
                    Si la cuenta existe, enviaremos una
                    <strong>contraseña temporal</strong>
                    al correo registrado.
                </p>


                <!-- Información -->
                <div class="recovery-modal-info">

                    <div class="recovery-info-item">

                        <div class="recovery-info-icon">
                            <i class="bi bi-envelope"></i>
                        </div>

                        <span>
                            Revisa tu bandeja de entrada.
                        </span>

                    </div>


                    <div class="recovery-info-item">

                        <div class="recovery-info-icon">
                            <i class="bi bi-shield-exclamation"></i>
                        </div>

                        <span>
                            Si no encuentras el correo,
                            revisa la carpeta de
                            <strong>Spam o correo no deseado</strong>.
                        </span>

                    </div>


                    <div class="recovery-info-item">

                        <div class="recovery-info-icon">
                            <i class="bi bi-key"></i>
                        </div>

                        <span>
                            Inicia sesión con la contraseña temporal
                            y establece una contraseña nueva.
                        </span>
                    </div>
                </div>

                <!-- Botón -->
                <div class="d-grid mt-4">

                    <button
                        type="button"
                        class="btn btn-login"
                        data-bs-dismiss="modal">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>

<?php if ($mostrarModalRecuperacion): ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {

        const modalElement =
            document.getElementById('modalRecuperacion');

        if (modalElement) {

            const modal =
                new bootstrap.Modal(modalElement);

            modal.show();

        }
    });
    </script>

<?php endif; ?>

</body>

</html>