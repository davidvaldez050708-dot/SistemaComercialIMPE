(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoDetalleId = Number(parametros.get('id') || 0);
        const urlVistaDocumento = 'public/oficio_vista_previa_documento.php';
        const urlEstadoPdf =
            'index.php?controller=oficioVinculacion&action=estadoPdf';
        const urlGenerarPdf =
            'index.php?controller=oficioVinculacion&action=generarPdf';
        const urlVerPdf =
            'index.php?controller=oficioVinculacion&action=verPdf';
        let seguimientoTrabajoId = 0;
        let temporizadorActualizacion = null;
        let estadoPdfActual = null;

        const folioDisponible = function (valor) {
            const folio = String(valor || '').trim();

            return folio !== '' &&
                folio !== '—' &&
                folio.toLowerCase() !== 'pendiente';
        };

        const mostrarToast = function (mensaje, esError) {
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
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 5000 : 3600
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const crearModal = function () {
            let modal = document.getElementById('modalVistaPreviaOficio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade oficio-preview-modal oficio-preview-document-modal';
            modal.id = 'modalVistaPreviaOficio';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalVistaPreviaOficioTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-xl modal-dialog-centered">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<div>' +
                                '<h5 class="modal-title" id="modalVistaPreviaOficioTitulo">Vista previa del oficio</h5>' +
                                '<p class="oficio-preview-modal-subtitle" data-preview-template-label>' +
                                    'Formato institucional REDMEX · vista real del documento' +
                                '</p>' +
                            '</div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="oficio-preview-document-message">' +
                            '<i class="bi bi-file-earmark-check"></i>' +
                            '<span>La vista se genera directamente desde la misma plantilla DOCX utilizada para crear el PDF.</span>' +
                        '</div>' +
                        '<div class="modal-body oficio-preview-document-body">' +
                            '<div class="oficio-preview-document-loading" data-preview-document-loading>' +
                                '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                                '<span>Generando vista del documento...</span>' +
                            '</div>' +
                            '<iframe class="oficio-preview-document-frame" data-preview-document-frame title="Vista previa del oficio"></iframe>' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<span class="me-auto text-muted small d-none" data-preview-pdf-status></span>' +
                            '<a class="btn btn-system-light d-none" target="_blank" rel="noopener" data-preview-view-pdf>' +
                                '<i class="bi bi-box-arrow-up-right me-2"></i>Ver PDF' +
                            '</a>' +
                            '<a class="btn btn-system-light d-none" data-preview-download-pdf>' +
                                '<i class="bi bi-download me-2"></i>Descargar PDF' +
                            '</a>' +
                            '<button type="button" class="btn btn-system-save d-none" data-preview-generate-pdf>' +
                                '<i class="bi bi-file-earmark-arrow-down me-2"></i>Generar PDF' +
                            '</button>' +
                            '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cerrar</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            const frame = modal.querySelector('[data-preview-document-frame]');
            const loading = modal.querySelector('[data-preview-document-loading]');

            frame?.addEventListener('load', function () {
                loading?.classList.add('d-none');
                frame.classList.add('is-loaded');
            });

            modal.addEventListener('hidden.bs.modal', function () {
                if (frame) {
                    frame.removeAttribute('src');
                    frame.classList.remove('is-loaded');
                }
                loading?.classList.remove('d-none');
            });

            return modal;
        };

        const urlPdf = function (seguimientoId, descargar) {
            return urlVerPdf +
                '&seguimiento_id=' + encodeURIComponent(seguimientoId) +
                (descargar ? '&descargar=1' : '');
        };

        const urlVistaReal = function (seguimientoId) {
            return urlVistaDocumento +
                '?seguimiento_id=' + encodeURIComponent(seguimientoId) +
                '&v=' + Date.now();
        };

        const cargarDocumentoEnModal = function (modal, seguimientoId, estadoPdf) {
            const frame = modal?.querySelector('[data-preview-document-frame]');
            const loading = modal?.querySelector('[data-preview-document-loading]');

            if (!frame) {
                return;
            }

            loading?.classList.remove('d-none');
            frame.classList.remove('is-loaded');

            if (estadoPdf?.pdf_generado) {
                frame.src = urlPdf(seguimientoId, false) + '&v=' + Date.now();
                return;
            }

            frame.src = urlVistaReal(seguimientoId);
        };

        const configurarModalPdf = function (modal, estadoPdf, seguimientoId) {
            if (!modal) {
                return;
            }

            const botonGenerar = modal.querySelector('[data-preview-generate-pdf]');
            const enlaceVer = modal.querySelector('[data-preview-view-pdf]');
            const enlaceDescargar = modal.querySelector('[data-preview-download-pdf]');
            const estado = modal.querySelector('[data-preview-pdf-status]');
            const etiqueta = modal.querySelector('[data-preview-template-label]');
            const pdfGenerado = Boolean(estadoPdf?.pdf_generado);
            const puedeGenerar = Boolean(estadoPdf?.puede_generar);

            estadoPdfActual = estadoPdf || null;
            modal.dataset.seguimientoId = String(seguimientoId || 0);

            if (etiqueta) {
                etiqueta.textContent = pdfGenerado
                    ? 'Formato institucional REDMEX · PDF generado'
                    : 'Formato institucional REDMEX · vista real antes de generar el PDF';
            }

            if (estado) {
                estado.classList.toggle('d-none', !pdfGenerado);
                estado.textContent = pdfGenerado
                    ? 'PDF generado' +
                        (estadoPdf?.fecha_generacion_label
                            ? ' · ' + estadoPdf.fecha_generacion_label
                            : '')
                    : '';
            }

            if (botonGenerar) {
                botonGenerar.classList.toggle('d-none', pdfGenerado || !puedeGenerar);
                botonGenerar.disabled = false;
            }

            [enlaceVer, enlaceDescargar].forEach(function (enlace) {
                enlace?.classList.toggle('d-none', !pdfGenerado);
            });

            if (pdfGenerado) {
                if (enlaceVer) {
                    enlaceVer.href = urlPdf(seguimientoId, false);
                }
                if (enlaceDescargar) {
                    enlaceDescargar.href = urlPdf(seguimientoId, true);
                }
            }
        };

        const limpiarBotonesPdfPanel = function (contenedor) {
            contenedor?.querySelectorAll(
                '[data-work-view-pdf], [data-work-download-pdf], ' +
                '[data-detail-view-pdf], [data-detail-download-pdf]'
            ).forEach(function (elemento) {
                elemento.remove();
            });
        };

        const aplicarEstadoPdfPanel = function (estadoPdf, seguimientoId) {
            const pdfGenerado = Boolean(estadoPdf?.pdf_generado);
            const seccionTrabajo = document.querySelector('[data-work-oficio-section]');
            const bloqueDetalle = document.querySelector('[data-detail-oficio-action]');

            if (seccionTrabajo && seguimientoId === seguimientoTrabajoId) {
                const status = seccionTrabajo.querySelector('[data-work-oficio-status]');
                const acciones = seccionTrabajo.querySelector('.linkage-work-verify-row');
                limpiarBotonesPdfPanel(seccionTrabajo);

                if (pdfGenerado && status) {
                    status.textContent = 'PDF generado';
                }

                if (pdfGenerado && acciones) {
                    const ver = document.createElement('a');
                    ver.className = 'btn btn-system-light linkage-work-small-button';
                    ver.setAttribute('data-work-view-pdf', '');
                    ver.target = '_blank';
                    ver.rel = 'noopener';
                    ver.href = urlPdf(seguimientoId, false);
                    ver.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Ver PDF';

                    const descargar = document.createElement('a');
                    descargar.className = 'btn btn-system-light linkage-work-small-button';
                    descargar.setAttribute('data-work-download-pdf', '');
                    descargar.href = urlPdf(seguimientoId, true);
                    descargar.innerHTML = '<i class="bi bi-download"></i> Descargar';

                    acciones.appendChild(ver);
                    acciones.appendChild(descargar);
                }
            }

            if (bloqueDetalle && esDetalle && seguimientoId === seguimientoDetalleId) {
                const status = bloqueDetalle.querySelector('[data-detail-oficio-status]');
                limpiarBotonesPdfPanel(bloqueDetalle);

                if (pdfGenerado && status) {
                    status.textContent = estadoPdf?.fecha_generacion_label
                        ? 'PDF generado · ' + estadoPdf.fecha_generacion_label
                        : 'PDF generado';
                }

                if (pdfGenerado) {
                    const ver = document.createElement('a');
                    ver.className = 'btn btn-system-light';
                    ver.setAttribute('data-detail-view-pdf', '');
                    ver.target = '_blank';
                    ver.rel = 'noopener';
                    ver.href = urlPdf(seguimientoId, false);
                    ver.innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i>Ver PDF';

                    const descargar = document.createElement('a');
                    descargar.className = 'btn btn-system-light';
                    descargar.setAttribute('data-detail-download-pdf', '');
                    descargar.href = urlPdf(seguimientoId, true);
                    descargar.innerHTML = '<i class="bi bi-download me-2"></i>Descargar PDF';

                    bloqueDetalle.appendChild(ver);
                    bloqueDetalle.appendChild(descargar);
                }
            }
        };

        const consultarEstadoPdf = async function (seguimientoId) {
            if (!seguimientoId) {
                return null;
            }

            try {
                const respuesta = await fetch(
                    urlEstadoPdf + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!datos.ok || !datos.estado_pdf) {
                    return null;
                }

                estadoPdfActual = datos.estado_pdf;
                aplicarEstadoPdfPanel(datos.estado_pdf, seguimientoId);
                return datos.estado_pdf;
            } catch (error) {
                console.error(error);
                return null;
            }
        };

        const asegurarBotonTrabajo = function () {
            const seccion = document.querySelector('[data-work-oficio-section]');

            if (!seccion) {
                return;
            }

            const folio = String(
                seccion.querySelector('[data-work-oficio-folio]')?.textContent || ''
            ).trim();
            let boton = seccion.querySelector('[data-work-preview-oficio]');

            if (!folioDisponible(folio)) {
                boton?.classList.add('d-none');
                return;
            }

            const acciones = seccion.querySelector('.linkage-work-verify-row');

            if (!acciones) {
                return;
            }

            if (!boton) {
                boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'btn btn-system-light linkage-work-small-button';
                boton.setAttribute('data-work-preview-oficio', '');
                boton.innerHTML = '<i class="bi bi-eye"></i> Vista previa';
                acciones.prepend(boton);
            }

            boton.classList.remove('d-none');
        };

        const asegurarBotonDetalle = function () {
            if (!esDetalle || seguimientoDetalleId <= 0) {
                return;
            }

            const bloque = document.querySelector('[data-detail-oficio-action]');

            if (!bloque) {
                return;
            }

            const folio = String(
                bloque.querySelector('[data-detail-oficio-folio]')?.textContent || ''
            ).trim();
            let boton = bloque.querySelector('[data-detail-preview-oficio]');

            if (!folioDisponible(folio)) {
                boton?.classList.add('d-none');
                return;
            }

            if (!boton) {
                boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'btn btn-system-light';
                boton.setAttribute('data-detail-preview-oficio', '');
                boton.innerHTML = '<i class="bi bi-eye me-2"></i>Vista previa';
                bloque.appendChild(boton);
            }

            boton.classList.remove('d-none');
        };

        const actualizarBotones = function () {
            asegurarBotonTrabajo();
            asegurarBotonDetalle();
        };

        const programarActualizacion = function () {
            window.clearTimeout(temporizadorActualizacion);
            temporizadorActualizacion = window.setTimeout(actualizarBotones, 40);
        };

        const abrirVistaPrevia = async function (seguimientoId, boton) {
            if (!seguimientoId || !boton) {
                return;
            }

            const contenidoOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Cargando...';

            try {
                const estado = await consultarEstadoPdf(seguimientoId);
                const modal = crearModal();
                configurarModalPdf(modal, estado || estadoPdfActual, seguimientoId);
                cargarDocumentoEnModal(modal, seguimientoId, estado || estadoPdfActual);
                bootstrap.Modal.getOrCreateInstance(modal).show();
            } catch (error) {
                console.error(error);
                mostrarToast(
                    error.message || 'No fue posible obtener la vista previa del oficio.',
                    true
                );
            } finally {
                boton.disabled = false;
                boton.innerHTML = contenidoOriginal;
            }
        };

        const generarPdf = async function (seguimientoId, boton) {
            if (!seguimientoId || !boton || boton.disabled) {
                return;
            }

            const contenidoOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Generando...';

            const formulario = new FormData();
            formulario.append('seguimiento_id', String(seguimientoId));

            try {
                const respuesta = await fetch(urlGenerarPdf, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formulario
                });
                const datos = await respuesta.json();

                if (!datos.ok || !datos.estado_pdf) {
                    throw new Error(datos.mensaje || 'No fue posible generar el PDF.');
                }

                estadoPdfActual = datos.estado_pdf;
                const modal = document.getElementById('modalVistaPreviaOficio');
                configurarModalPdf(modal, datos.estado_pdf, seguimientoId);
                cargarDocumentoEnModal(modal, seguimientoId, datos.estado_pdf);
                aplicarEstadoPdfPanel(datos.estado_pdf, seguimientoId);
                mostrarToast(
                    datos.existente
                        ? 'El PDF del oficio ya estaba generado.'
                        : 'PDF generado correctamente.',
                    false
                );
            } catch (error) {
                console.error(error);
                mostrarToast(error.message || 'No fue posible generar el PDF.', true);
            } finally {
                boton.disabled = false;
                if (!boton.classList.contains('d-none')) {
                    boton.innerHTML = contenidoOriginal;
                }
            }
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoTrabajoId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
                estadoPdfActual = null;
                programarActualizacion();
                window.setTimeout(function () {
                    consultarEstadoPdf(seguimientoTrabajoId);
                }, 180);
                return;
            }

            const botonVistaTrabajo = event.target.closest('[data-work-preview-oficio]');

            if (botonVistaTrabajo) {
                event.preventDefault();
                abrirVistaPrevia(seguimientoTrabajoId, botonVistaTrabajo);
                return;
            }

            const botonVistaDetalle = event.target.closest('[data-detail-preview-oficio]');

            if (botonVistaDetalle) {
                event.preventDefault();
                abrirVistaPrevia(seguimientoDetalleId, botonVistaDetalle);
                return;
            }

            const botonGenerarPdf = event.target.closest('[data-preview-generate-pdf]');

            if (botonGenerarPdf) {
                event.preventDefault();
                const modal = document.getElementById('modalVistaPreviaOficio');
                const seguimientoId = Number(modal?.dataset.seguimientoId || 0);
                generarPdf(seguimientoId, botonGenerarPdf);
            }
        });

        document
            .getElementById('offcanvasSeguimientoTrabajo')
            ?.addEventListener('hidden.bs.offcanvas', function () {
                seguimientoTrabajoId = 0;
                estadoPdfActual = null;
            });

        if (window.MutationObserver) {
            const observador = new MutationObserver(programarActualizacion);
            observador.observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true
            });
        }

        actualizarBotones();

        if (esDetalle && seguimientoDetalleId > 0) {
            window.setTimeout(function () {
                consultarEstadoPdf(seguimientoDetalleId);
            }, 180);
        }
    });
})();
