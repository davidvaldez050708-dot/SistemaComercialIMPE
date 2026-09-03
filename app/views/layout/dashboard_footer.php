    </section>

</main>

</div>

<?php require_once __DIR__ . '/profile_photo_modal.php'; ?>

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

                    <div class="recovery-modal-icon">
                        <i class="bi bi-shield-lock"></i>
                    </div>

                    <h2
                        class="recovery-modal-title"
                        id="modalCambioPasswordTitulo">
                        Actualiza tu contraseña
                    </h2>

                    <p class="recovery-modal-text">
                        Has iniciado sesión con una contraseña temporal.
                        Por seguridad, establece una nueva contraseña
                        antes de continuar.
                    </p>

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

                    <form
                        action="<?= BASE_URL ?>index.php?controller=login&action=cambiarPassword"
                        method="POST">

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

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/cambiar_password.js">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/seguimiento_interacciones.js">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/oficios_vinculacion.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/oficios_vinculacion.js') ?>">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/oficios_vista_previa.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/oficios_vista_previa.js') ?>">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/oficios_correo.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/oficios_correo.js') ?>">
</script>

<script
    src="<?= BASE_URL ?>public/javascript/recordatorios.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/recordatorios.js') ?>">
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalFoto = document.getElementById('modalVistaFotoPerfil');
    const imagenFoto = document.getElementById('modalVistaFotoPerfilImagen');
    const nombreFoto = document.getElementById('modalVistaFotoPerfilNombre');
    const rolFoto = document.getElementById('modalVistaFotoPerfilRol');

    document.addEventListener('click', function (event) {
        const boton = event.target.closest('[data-profile-photo]');

        if (!boton || !modalFoto || !imagenFoto || !nombreFoto || !rolFoto) {
            return;
        }

        const url = boton.getAttribute('data-photo-url') || '';
        const nombre = boton.getAttribute('data-photo-name') || 'Usuario';
        const rol = boton.getAttribute('data-photo-role') || 'Usuario';

        if (url === '') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        imagenFoto.src = url;
        imagenFoto.alt = 'Foto de ' + nombre;
        nombreFoto.textContent = nombre;
        rolFoto.textContent = rol;

        bootstrap.Modal.getOrCreateInstance(modalFoto).show();
    });

    document.addEventListener('error', function (event) {
        const imagen = event.target;

        if (
            !(imagen instanceof HTMLImageElement) ||
            !imagen.classList.contains('system-avatar-image')
        ) {
            return;
        }

        const avatar = imagen.closest('.system-avatar');

        if (!avatar) {
            return;
        }

        const reemplazo = document.createElement('span');
        reemplazo.className = avatar.className
            .replace('system-avatar-photo-button', '')
            .replace('system-avatar-image', '')
            .trim() + ' system-avatar-initials';
        reemplazo.textContent = imagen.getAttribute('data-avatar-initials') || 'US';

        avatar.replaceWith(reemplazo);
    }, true);
});
</script>

<?php if (
    !empty($_SESSION['requiere_cambio_password'])
): ?>

    <script>
    document.addEventListener(
        'DOMContentLoaded',
        function () {
            const modalElement =
                document.getElementById('modalCambioPassword');

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
