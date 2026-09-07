document.addEventListener('DOMContentLoaded', function () {

    const botones =
        document.querySelectorAll('.password-view-toggle');

    botones.forEach(function (boton) {

        boton.addEventListener('click', function () {

            const contenedor =
                boton.closest('.login-input-group');

            if (!contenedor) {
                return;
            }

            const input =
                contenedor.querySelector('input');

            const icono =
                boton.querySelector('i');

            if (!input || !icono) {
                return;
            }


            if (input.type === 'password') {

                input.type = 'text';

                icono.classList.remove('bi-eye');
                icono.classList.add('bi-eye-slash');

                boton.setAttribute(
                    'aria-label',
                    'Ocultar contraseña'
                );

            } else {

                input.type = 'password';

                icono.classList.remove('bi-eye-slash');
                icono.classList.add('bi-eye');

                boton.setAttribute(
                    'aria-label',
                    'Mostrar contraseña'
                );

            }

        });

    });

});

document.addEventListener('DOMContentLoaded', function () {
    const modalElemento = document.querySelector('[data-my-password-modal]');
    const formulario = document.querySelector('[data-my-password-form]');

    if (!modalElemento || !formulario || !window.bootstrap) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElemento);
    const alerta = modalElemento.querySelector('[data-my-password-alert]');
    const botonGuardar = modalElemento.querySelector('[data-my-password-submit]');

    const limpiarFormulario = function () {
        formulario.reset();
        alerta.textContent = '';
        alerta.classList.add('d-none');

        formulario.querySelectorAll('input[type="text"]').forEach(function (input) {
            input.type = 'password';
        });

        formulario.querySelectorAll('.password-view-toggle').forEach(function (boton) {
            const icono = boton.querySelector('i');
            icono?.classList.remove('bi-eye-slash');
            icono?.classList.add('bi-eye');
            boton.setAttribute('aria-label', 'Mostrar contraseña');
        });
    };

    const mostrarError = function (mensaje) {
        alerta.textContent = mensaje;
        alerta.classList.remove('d-none');
    };

    const mostrarToastSistema = function (mensaje) {
        let contenedor = document.querySelector('.toast-container');

        if (!contenedor) {
            contenedor = document.createElement('div');
            contenedor.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(contenedor);
        }

        const toast = document.createElement('div');
        toast.className = 'toast system-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.setAttribute('data-bs-delay', '3200');
        toast.innerHTML =
            '<div class="toast-body">' +
                '<i class="bi bi-check2-circle"></i>' +
                '<span></span>' +
            '</div>';
        toast.querySelector('span').textContent = mensaje;
        contenedor.appendChild(toast);

        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });
        new bootstrap.Toast(toast).show();
    };

    modalElemento.addEventListener('hidden.bs.modal', limpiarFormulario);

    formulario.addEventListener('submit', async function (event) {
        event.preventDefault();
        alerta.classList.add('d-none');

        if (!formulario.checkValidity()) {
            formulario.reportValidity();
            return;
        }

        if (formulario.elements.password_nueva.value !== formulario.elements.confirmar_password.value) {
            mostrarError('Las contraseñas no coinciden.');
            return;
        }

        const textoOriginal = botonGuardar.textContent;
        botonGuardar.disabled = true;
        botonGuardar.textContent = 'Actualizando...';

        try {
            const respuesta = await fetch(modalElemento.dataset.passwordUpdateUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new FormData(formulario)
            });
            const datos = await respuesta.json();

            if (!respuesta.ok || !datos.ok) {
                throw new Error(datos.mensaje || 'No fue posible actualizar la contraseña.');
            }

            modal.hide();
            mostrarToastSistema(datos.mensaje || 'Contraseña actualizada correctamente.');
        } catch (error) {
            mostrarError(error.message);
        } finally {
            botonGuardar.disabled = false;
            botonGuardar.textContent = textoOriginal;
        }
    });
});
