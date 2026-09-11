(function () {
    'use strict';

    const normalizarEncabezado = function (texto) {
        texto = String(texto || '').replace(/\r\n/g, '\n');
        const presente = 'P R E S E N T E';
        const indice = texto.indexOf(presente);

        if (indice <= 0) {
            return texto;
        }

        const encabezado = texto
            .slice(0, indice)
            .split(/\n+/)
            .map(function (linea) {
                return linea.trim();
            })
            .filter(Boolean);

        if (encabezado.length < 4) {
            return texto;
        }

        let resto = texto.slice(indice + presente.length);
        resto = resto.replace(/^[ \t]*(?:\n[ \t]*)+/, '\n\n');

        return encabezado.slice(0, 4).join('\n') +
            '\n\n' +
            presente +
            resto;
    };

    const normalizarModal = function (modal) {
        if (!modal || modal.id !== 'modalBorradorCorreoOficio') {
            return;
        }

        /*
         * Solo corregimos el formato inicial generado por la plantilla.
         * Si el Analista ya guardó un borrador, respetamos sus cambios.
         */
        if (modal.dataset.guardado === '1') {
            return;
        }

        const campo = modal.querySelector('[data-mail-body]');

        if (!campo) {
            return;
        }

        const normalizado = normalizarEncabezado(campo.value);

        if (normalizado !== campo.value) {
            campo.value = normalizado;
        }
    };

    const mostrarToastPdf = function (mensaje, esError) {
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
        toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
        toast.setAttribute('role', esError ? 'alert' : 'status');
        toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML =
            '<div class="toast-body">' +
                '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                '<span></span>' +
            '</div>';
        toast.querySelector('span').textContent = mensaje;
        contenedor.appendChild(toast);

        const instancia = new bootstrap.Toast(toast, {
            autohide: true,
            delay: esError ? 5000 : 3400
        });
        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });
        instancia.show();
    };

    const consultarEstadoPdf = async function (seguimientoId) {
        try {
            const respuesta = await fetch(
                'index.php?controller=oficioVinculacion&action=estadoPdf&seguimiento_id=' +
                    encodeURIComponent(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                }
            );
            const datos = await respuesta.json();

            return respuesta.ok && datos?.ok
                ? datos.estado_pdf || null
                : null;
        } catch (error) {
            console.error(error);
            return null;
        }
    };

    const aplicarPdfGenerado = function (modal, seguimientoId, estadoPdf) {
        if (!modal || !estadoPdf?.pdf_generado) {
            return false;
        }

        const boton = modal.querySelector('[data-preview-generate-pdf]');
        const ver = modal.querySelector('[data-preview-view-pdf]');
        const descargar = modal.querySelector('[data-preview-download-pdf]');
        const estado = modal.querySelector('[data-preview-pdf-status]');
        const etiqueta = modal.querySelector('[data-preview-template-label]');
        const frame = modal.querySelector('[data-preview-document-frame]');
        const urlBase =
            'index.php?controller=oficioVinculacion&action=verPdf&seguimiento_id=' +
            encodeURIComponent(seguimientoId);

        boton?.classList.add('d-none');

        if (estado) {
            estado.classList.remove('d-none');
            estado.textContent = 'PDF generado' +
                (estadoPdf.fecha_generacion_label
                    ? ' · ' + estadoPdf.fecha_generacion_label
                    : '');
        }

        if (etiqueta) {
            etiqueta.textContent = 'Formato institucional REDMEX · PDF generado';
        }

        if (ver) {
            ver.href = urlBase;
            ver.classList.remove('d-none');
        }

        if (descargar) {
            descargar.href = urlBase + '&descargar=1';
            descargar.classList.remove('d-none');
        }

        if (frame) {
            frame.src = urlBase + '&v=' + Date.now();
        }

        document
            .querySelectorAll('[data-work-oficio-status], [data-detail-oficio-status]')
            .forEach(function (elemento) {
                elemento.textContent = 'PDF generado';
            });

        return true;
    };

    document.addEventListener('shown.bs.modal', function (evento) {
        const modal = evento.target;

        if (!(modal instanceof HTMLElement)) {
            return;
        }

        window.setTimeout(function () {
            normalizarModal(modal);
        }, 0);
    });

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-mail-save]');

        if (!boton) {
            return;
        }

        normalizarModal(boton.closest('#modalBorradorCorreoOficio'));
    }, true);

    /*
     * La generación de PDF puede recibir avisos HTML de PHP antes del JSON
     * en algunos entornos locales. Interceptamos únicamente este botón para
     * validar la respuesta de forma segura y confirmar el resultado mediante
     * el estado autoritativo del oficio antes de mostrar un error al Analista.
     */
    document.addEventListener('click', async function (evento) {
        const boton = evento.target.closest('[data-preview-generate-pdf]');

        if (!boton) {
            return;
        }

        evento.preventDefault();
        evento.stopPropagation();
        evento.stopImmediatePropagation();

        if (boton.dataset.pdfGenerando === '1') {
            return;
        }

        const modal = boton.closest('#modalVistaPreviaOficio');
        const seguimientoId = Number(modal?.dataset.seguimientoId || 0);

        if (!modal || seguimientoId <= 0) {
            mostrarToastPdf('No fue posible identificar el seguimiento del oficio.', true);
            return;
        }

        const contenidoOriginal = boton.innerHTML;
        boton.dataset.pdfGenerando = '1';
        boton.disabled = true;
        boton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
            'Generando...';

        const formulario = new FormData();
        formulario.append('seguimiento_id', String(seguimientoId));

        try {
            const respuesta = await fetch(
                'index.php?controller=oficioVinculacion&action=generarPdf',
                {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: formulario
                }
            );
            const texto = await respuesta.text();
            let datos = null;

            if (texto.trim()) {
                try {
                    datos = JSON.parse(texto);
                } catch (error) {
                    console.error('Respuesta no JSON al generar PDF:', texto);
                }
            }

            if (datos?.ok && datos?.estado_pdf?.pdf_generado) {
                aplicarPdfGenerado(modal, seguimientoId, datos.estado_pdf);
                mostrarToastPdf(
                    datos.existente
                        ? 'El PDF del oficio ya estaba generado.'
                        : 'PDF generado correctamente.',
                    false
                );
                return;
            }

            const estadoPdf = await consultarEstadoPdf(seguimientoId);

            if (estadoPdf?.pdf_generado) {
                aplicarPdfGenerado(modal, seguimientoId, estadoPdf);
                mostrarToastPdf('PDF generado correctamente.', false);
                return;
            }

            throw new Error(
                datos?.mensaje ||
                'No fue posible generar el PDF. El servidor devolvió una respuesta inesperada.'
            );
        } catch (error) {
            console.error(error);
            mostrarToastPdf(
                error.message || 'No fue posible generar el PDF.',
                true
            );
        } finally {
            boton.dataset.pdfGenerando = '0';
            boton.disabled = false;

            if (!boton.classList.contains('d-none')) {
                boton.innerHTML = contenidoOriginal;
            }
        }
    }, true);
})();
