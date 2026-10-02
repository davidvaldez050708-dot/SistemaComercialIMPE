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
        const modalDetalleElement =
            document.getElementById('modalCorreoMarketingDetalle');
        const modalDetalle = modalDetalleElement
            ? bootstrap.Modal.getOrCreateInstance(modalDetalleElement)
            : null;
        const urlEnviar = 'index.php?controller=correoMarketing&action=enviar';
        const urlVer = 'index.php?controller=correoMarketing&action=ver';
        let enviando = false;
        let correoDetalleActual = null;

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

        const limpiarDetalle = function () {
            correoDetalleActual = null;

            if (!modalDetalleElement) {
                return;
            }

            const errorDetalle = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-error]'
            );
            const cargando = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-loading]'
            );
            const contenido = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-content]'
            );
            const adjuntosSeccion = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments-section]'
            );
            const adjuntosLista = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments]'
            );

            errorDetalle?.classList.add('d-none');
            if (errorDetalle) {
                errorDetalle.textContent = '';
            }

            cargando?.classList.remove('d-none');
            contenido?.classList.add('d-none');
            adjuntosSeccion?.classList.add('d-none');

            if (adjuntosLista) {
                adjuntosLista.innerHTML = '';
            }
        };

        const renderizarDetalle = function (correo) {
            if (!modalDetalleElement) {
                return;
            }

            correoDetalleActual = correo;

            const asignarTexto = function (selector, valor) {
                const nodo = modalDetalleElement.querySelector(selector);
                if (nodo) {
                    nodo.textContent = String(
                        valor == null || valor === '' ? '—' : valor
                    );
                }
            };

            asignarTexto(
                '[data-marketing-mail-detail-to]',
                correo.destinatario || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-name]',
                correo.destinatario_nombre || ''
            );
            asignarTexto(
                '[data-marketing-mail-detail-date]',
                correo.fecha_envio || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-status]',
                correo.estado || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-subject]',
                correo.asunto || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-body]',
                correo.cuerpo || '—'
            );

            const proveedor = String(correo.proveedor || '').trim();
            asignarTexto(
                '[data-marketing-mail-detail-provider]',
                proveedor !== ''
                    ? 'Enviado mediante ' + proveedor
                    : 'Correo registrado en el sistema'
            );

            const firma = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-signature]'
            );
            firma?.classList.toggle(
                'd-none',
                !Boolean(correo.firma_incluida)
            );

            const adjuntos = Array.isArray(correo.adjuntos)
                ? correo.adjuntos
                : [];
            const adjuntosSeccion = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments-section]'
            );
            const adjuntosLista = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments]'
            );

            if (adjuntosLista) {
                adjuntosLista.innerHTML = '';

                adjuntos.forEach(function (nombre) {
                    const item = document.createElement('span');
                    const icono = document.createElement('i');
                    const textoNombre = document.createElement('strong');

                    icono.className = 'bi bi-paperclip';
                    textoNombre.textContent = String(nombre || 'Archivo');
                    item.appendChild(icono);
                    item.appendChild(textoNombre);
                    adjuntosLista.appendChild(item);
                });
            }

            adjuntosSeccion?.classList.toggle(
                'd-none',
                adjuntos.length === 0
            );

            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-loading]')
                ?.classList.add('d-none');
            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-content]')
                ?.classList.remove('d-none');
        };

        const mostrarErrorDetalle = function (mensaje) {
            if (!modalDetalleElement) {
                return;
            }

            const errorDetalle = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-error]'
            );

            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-loading]')
                ?.classList.add('d-none');
            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-content]')
                ?.classList.add('d-none');

            if (errorDetalle) {
                errorDetalle.textContent = String(
                    mensaje || 'No fue posible cargar el correo.'
                );
                errorDetalle.classList.remove('d-none');
            }
        };

        const abrirDetalle = async function (correoId) {
            if (!modalDetalleElement || !modalDetalle || correoId <= 0) {
                return;
            }

            limpiarDetalle();
            modalDetalle.show();

            try {
                const respuesta = await fetch(
                    urlVer + '&id=' + encodeURIComponent(correoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );

                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok || !json.correo) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible cargar el correo.'
                    );
                }

                renderizarDetalle(json.correo);
            } catch (errorDetalle) {
                console.error(errorDetalle);
                mostrarErrorDetalle(
                    errorDetalle.message ||
                    'No fue posible comunicarse con el sistema.'
                );
            }
        };

        document.addEventListener('click', function (event) {
            const botonVer = event.target.closest(
                '[data-marketing-mail-view]'
            );

            if (!botonVer) {
                return;
            }

            event.preventDefault();

            abrirDetalle(
                Number(botonVer.getAttribute('data-mail-id') || 0)
            );
        });

        modalDetalleElement
            ?.querySelector('[data-marketing-mail-detail-copy]')
            ?.addEventListener('click', async function () {
                if (!correoDetalleActual) {
                    return;
                }

                const textoCorreo = [
                    'Para: ' + String(
                        correoDetalleActual.destinatario || ''
                    ),
                    'Asunto: ' + String(
                        correoDetalleActual.asunto || ''
                    ),
                    '',
                    String(correoDetalleActual.cuerpo || '')
                ].join('\n');

                try {
                    await navigator.clipboard.writeText(textoCorreo);
                    mostrarToast(
                        'Contenido del correo copiado.',
                        false
                    );
                } catch (errorCopiar) {
                    console.error(errorCopiar);
                    mostrarToast(
                        'No fue posible copiar el contenido.',
                        true
                    );
                }
            });

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
