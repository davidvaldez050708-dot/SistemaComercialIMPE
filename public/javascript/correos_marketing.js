(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const modalElement = document.getElementById('modalCorreoMarketing');
        const botonRedactar = document.querySelector('[data-marketing-compose]');

        if (!modalElement || !botonRedactar || !window.bootstrap) {
            return;
        }

        const form = modalElement.querySelector('[data-marketing-mail-form]');
        const destinatario = modalElement.querySelector('[data-marketing-mail-to]');
        const destinatarioResumen = modalElement.querySelector('[data-marketing-mail-recipient]');
        const asunto = modalElement.querySelector('[data-marketing-mail-subject]');
        const cuerpo = modalElement.querySelector('[data-marketing-mail-body]');
        const archivos = modalElement.querySelector('[data-marketing-mail-files]');
        const listaArchivos = modalElement.querySelector('[data-marketing-mail-files-list]');
        const error = modalElement.querySelector('[data-marketing-mail-error]');
        const botonEnviar = modalElement.querySelector('[data-marketing-mail-send]');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const urlEnviar = 'index.php?controller=correoMarketing&action=enviar';
        let enviando = false;

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor == null ? '' : valor);
            return div.innerHTML;
        };

        const mostrarError = function (mensaje) {
            if (!error) {
                return;
            }

            error.textContent = String(
                mensaje || 'No fue posible enviar el correo.'
            );
            error.classList.remove('d-none');
        };

        const limpiarError = function () {
            if (!error) {
                return;
            }

            error.textContent = '';
            error.classList.add('d-none');
        };

        const mostrarToast = function (mensaje, esError) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                contenedor.style.zIndex = '1095';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError
                            ? 'bi-exclamation-circle'
                            : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';

            toast.querySelector('span').textContent = String(
                mensaje ||
                (esError
                    ? 'No fue posible enviar el correo.'
                    : 'Correo enviado correctamente.')
            );

            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: esError ? 4500 : 3000
            }).show();
        };

        const renderizarArchivos = function () {
            if (!listaArchivos || !archivos) {
                return;
            }

            const seleccionados = Array.from(archivos.files || []);
            listaArchivos.classList.toggle(
                'd-none',
                seleccionados.length === 0
            );

            listaArchivos.innerHTML = seleccionados.length === 0
                ? ''
                : seleccionados.map(function (archivo) {
                    const mb = Number(archivo.size || 0) / (1024 * 1024);
                    const tamano = mb >= 1
                        ? mb.toFixed(1) + ' MB'
                        : Math.max(
                            1,
                            Math.round(Number(archivo.size || 0) / 1024)
                        ) + ' KB';

                    return (
                        '<span>' +
                            '<i class="bi bi-paperclip"></i>' +
                            '<strong>' + escapar(archivo.name) + '</strong>' +
                            '<small>' + escapar(tamano) + '</small>' +
                        '</span>'
                    );
                }).join('');
        };

        const actualizarDestinatario = function () {
            if (!destinatarioResumen || !destinatario) {
                return;
            }

            const valor = String(destinatario.value || '').trim();
            destinatarioResumen.textContent =
                valor || 'Escribe un correo electrónico';
        };

        const limpiarFormulario = function () {
            form?.reset();
            limpiarError();
            actualizarDestinatario();
            renderizarArchivos();

            if (botonEnviar) {
                botonEnviar.disabled = false;
                botonEnviar.innerHTML =
                    '<i class="bi bi-send me-2"></i>Enviar correo';
            }
        };

        const validarArchivos = function () {
            const seleccionados = Array.from(archivos?.files || []);

            if (seleccionados.length > 8) {
                return 'Puedes adjuntar como máximo 8 archivos.';
            }

            if (seleccionados.some(function (archivo) {
                return Number(archivo.size || 0) > 12 * 1024 * 1024;
            })) {
                return 'Cada archivo debe pesar como máximo 12 MB.';
            }

            const total = seleccionados.reduce(function (acumulado, archivo) {
                return acumulado + Number(archivo.size || 0);
            }, 0);

            if (total > 20 * 1024 * 1024) {
                return 'Los archivos adjuntos no pueden superar 20 MB en total.';
            }

            return '';
        };

        botonRedactar.addEventListener('click', function () {
            limpiarFormulario();
            modal.show();

            modalElement.addEventListener(
                'shown.bs.modal',
                function enfocar() {
                    modalElement.removeEventListener(
                        'shown.bs.modal',
                        enfocar
                    );
                    destinatario?.focus();
                }
            );
        });

        destinatario?.addEventListener('input', actualizarDestinatario);
        archivos?.addEventListener('change', renderizarArchivos);

        form?.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (enviando) {
                return;
            }

            limpiarError();

            const correo = String(destinatario?.value || '').trim();
            const asuntoValor = String(asunto?.value || '').trim();
            const cuerpoValor = String(cuerpo?.value || '').trim();

            if (correo === '') {
                mostrarError('Ingresa el correo del destinatario.');
                destinatario?.focus();
                return;
            }

            if (asuntoValor === '') {
                mostrarError('Escribe el asunto del correo.');
                asunto?.focus();
                return;
            }

            if (cuerpoValor === '') {
                mostrarError('Escribe el mensaje antes de enviarlo.');
                cuerpo?.focus();
                return;
            }

            const errorArchivos = validarArchivos();
            if (errorArchivos !== '') {
                mostrarError(errorArchivos);
                return;
            }

            const datos = new FormData(form);
            const htmlOriginal = botonEnviar?.innerHTML || '';

            enviando = true;

            if (botonEnviar) {
                botonEnviar.disabled = true;
                botonEnviar.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" ' +
                    'aria-hidden="true"></span>Enviando...';
            }

            try {
                const respuesta = await fetch(urlEnviar, {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });

                let json = null;

                try {
                    json = await respuesta.json();
                } catch (errorJson) {
                    throw new Error(
                        'El servidor no devolvió una respuesta válida.'
                    );
                }

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible enviar el correo.'
                    );
                }

                modal.hide();
                mostrarToast(
                    json.mensaje || 'Correo enviado correctamente.',
                    false
                );

                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
            } catch (errorEnvio) {
                console.error(errorEnvio);
                mostrarError(
                    errorEnvio.message ||
                    'No fue posible comunicarse con el sistema.'
                );
            } finally {
                enviando = false;

                if (botonEnviar) {
                    botonEnviar.disabled = false;
                    botonEnviar.innerHTML = htmlOriginal;
                }
            }
        });
    });
})();
