<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Inicio</title>

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

    <!-- Estilos -->
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/login.css">

</head>

<body>


    <!-- Dashboard temporal -->

    <div class="container py-5">

        <h2>
            Bienvenido,
            <?= htmlspecialchars($_SESSION['nombre']) ?>
        </h2>

        <p>
            Rol:
            <strong>
                <?= htmlspecialchars($_SESSION['rol']) ?>
            </strong>
        </p>

        <p>
            El inicio de sesión funciona correctamente.
        </p>

        <a
            href="<?= BASE_URL ?>logout.php"
            class="btn btn-outline-secondary">

            <i class="bi bi-box-arrow-right me-1"></i>

            Cerrar sesión

        </a>

    </div>



    <!-- Modal obligatorio -->

    <?php if (
        !empty($_SESSION['requiere_cambio_password'])
    ): ?>

        <div
            class="modal fade"
            id="modalCambioPassword"
            tabindex="-1"
            data-bs-backdrop="static"
            data-bs-keyboard="false"
            aria-labelledby="modalCambioPasswordTitulo"
            aria-hidden="true">

            <div
                class="modal-dialog modal-dialog-centered recovery-modal-dialog">

                <div class="modal-content recovery-modal">

                    <div class="modal-body">


                        <!-- Icono -->

                        <div class="recovery-modal-icon">

                            <i class="bi bi-shield-lock"></i>

                        </div>


                        <!-- Título -->

                        <h2
                            class="recovery-modal-title"
                            id="modalCambioPasswordTitulo">

                            Actualiza tu contraseña

                        </h2>


                        <!-- Descripción -->

                        <p class="recovery-modal-text">

                            Has iniciado sesión con una contraseña temporal.
                            Por seguridad, establece una nueva contraseña
                            antes de continuar.

                        </p>


                        <!-- Error -->

                        <?php if (
                            isset($_SESSION['error_cambio_password'])
                        ): ?>

                            <div
                                class="alert alert-danger login-alert"
                                role="alert">

                                <i class="bi bi-exclamation-circle"></i>

                                <span>
                                    <?= htmlspecialchars(
                                        $_SESSION['error_cambio_password']
                                    ) ?>
                                </span>

                            </div>

                            <?php
                                unset(
                                    $_SESSION['error_cambio_password']
                                );
                            ?>

                        <?php endif; ?>


                        <!-- Formulario -->

                        <form
                            action="<?= BASE_URL ?>index.php?controller=login&action=cambiarPassword"
                            method="POST">


                            <!-- Nueva contraseña -->

                            <div class="mb-3">

                                <label
                                    for="password_nueva"
                                    class="form-label login-label">

                                    Nueva contraseña

                                </label>

                                <div class="input-group login-input-group">

                                    <span class="input-group-text">

                                        <i class="bi bi-lock"></i>

                                    </span>

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="password_nueva"
                                        name="password_nueva"
                                        placeholder="Ingrese su nueva contraseña"
                                        autocomplete="new-password"
                                        required>

                                    <button
                                        type="button"
                                        class="btn password-toggle password-view-toggle"
                                        aria-label="Mostrar contraseña">

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>


                            <!-- Confirmar contraseña -->

                            <div class="mb-2">

                                <label
                                    for="confirmar_password"
                                    class="form-label login-label">

                                    Confirmar contraseña

                                </label>

                                <div class="input-group login-input-group">

                                    <span class="input-group-text">

                                        <i class="bi bi-lock"></i>

                                    </span>

                                    <input
                                        type="password"
                                        class="form-control"
                                        id="confirmar_password"
                                        name="confirmar_password"
                                        placeholder="Repita su nueva contraseña"
                                        autocomplete="new-password"
                                        required>

                                    <button
                                        type="button"
                                        class="btn password-toggle password-view-toggle"
                                        aria-label="Mostrar contraseña">

                                        <i class="bi bi-eye"></i>

                                    </button>

                                </div>

                            </div>


                            <p class="password-help">
                                Utilice al menos 8 caracteres.
                            </p>


                            <div class="d-grid mt-4">

                                <button
                                    type="submit"
                                    class="btn btn-login">

                                    <i class="bi bi-check2-circle me-2"></i>

                                    Guardar nueva contraseña

                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>



    <!-- Bootstrap JS -->

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>


    <!-- JS contraseña -->

    <script
        src="<?= BASE_URL ?>public/javascript/cambiar_password.js">
    </script>


    <!-- Abrir modal -->

    <?php if (
        !empty($_SESSION['requiere_cambio_password'])
    ): ?>

        <script>

        document.addEventListener(
            'DOMContentLoaded',
            function () {

                const modalElement =
                    document.getElementById(
                        'modalCambioPassword'
                    );

                if (modalElement) {

                    const modal =
                        new bootstrap.Modal(
                            modalElement,
                            {
                                backdrop: 'static',
                                keyboard: false
                            }
                        );

                    modal.show();

                }

            }
        );

        </script>

    <?php endif; ?>


</body>

</html>