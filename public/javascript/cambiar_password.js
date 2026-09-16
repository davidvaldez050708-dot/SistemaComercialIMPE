(function () {
    'use strict';

    const mostrarToastSistema = function (mensaje, titulo) {
        if (!window.bootstrap) {
            return;
        }

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

        const cuerpo = document.createElement('div');
        cuerpo.className = 'toast-body d-flex align-items-start gap-2';

        const icono = document.createElement('i');
        icono.className = 'bi bi-check2-circle mt-1';

        const copia = document.createElement('div');
        copia.className = 'd-grid gap-1 flex-grow-1';

        const encabezado = document.createElement('strong');
        encabezado.className = 'd-block lh-sm';

        const detalle = document.createElement('span');
        detalle.className = 'd-block fw-normal text-body-secondary lh-sm';

        encabezado.textContent = titulo || 'Contraseña actualizada';
        detalle.textContent = mensaje || 'La contraseña se actualizó correctamente.';

        copia.appendChild(encabezado);
        copia.appendChild(detalle);
        cuerpo.appendChild(icono);
        cuerpo.appendChild(copia);
        toast.appendChild(cuerpo);
        contenedor.appendChild(toast);

        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });

        bootstrap.Toast.getOrCreateInstance(toast, {
            autohide: true,
            delay: 3200
        }).show();
    };

    document.addEventListener('DOMContentLoaded', function () {
        const botones = document.querySelectorAll('.password-view-toggle');

        botones.forEach(function (boton) {
            boton.addEventListener('click', function () {
                const contenedor = boton.closest('.login-input-group');

                if (!contenedor) {
                    return;
                }

                const input = contenedor.querySelector('input');
                const icono = boton.querySelector('i');

                if (!input || !icono) {
                    return;
                }

                if (input.type === 'password') {
                    input.type = 'text';
                    icono.classList.remove('bi-eye');
                    icono.classList.add('bi-eye-slash');
                    boton.setAttribute('aria-label', 'Ocultar contraseña');
                } else {
                    input.type = 'password';
                    icono.classList.remove('bi-eye-slash');
                    icono.classList.add('bi-eye');
                    boton.setAttribute('aria-label', 'Mostrar contraseña');
                }
            });
        });

        const confirmacion = document.querySelector('[data-password-update-success]');

        if (confirmacion) {
            window.setTimeout(function () {
                mostrarToastSistema(
                    confirmacion.getAttribute('data-message') ||
                        'Tu nueva contraseña se guardó correctamente.',
                    'Contraseña actualizada'
                );
            }, 150);
        }
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

        modalElemento.addEventListener('hidden.bs.modal', limpiarFormulario);

        formulario.addEventListener('submit', async function (event) {
            event.preventDefault();
            alerta.classList.add('d-none');

            if (!formulario.checkValidity()) {
                formulario.reportValidity();
                return;
            }

            if (
                formulario.elements.password_nueva.value !==
                formulario.elements.confirmar_password.value
            ) {
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
                    throw new Error(
                        datos.mensaje || 'No fue posible actualizar la contraseña.'
                    );
                }

                modal.hide();
                mostrarToastSistema(
                    datos.mensaje || 'Tu nueva contraseña se guardó correctamente.',
                    'Contraseña actualizada'
                );
            } catch (error) {
                mostrarError(error.message);
            } finally {
                botonGuardar.disabled = false;
                botonGuardar.textContent = textoOriginal;
            }
        });
    });
})();
