(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoDetalleId = Number(parametros.get('id') || 0);
        const urlVistaPrevia =
            'index.php?controller=oficioVinculacion&action=vistaPrevia';
        let seguimientoTrabajoId = 0;
        let temporizadorActualizacion = null;

        const folioDisponible = function (valor) {
            const folio = String(valor || '').trim();

            return folio !== '' &&
                folio !== '—' &&
                folio.toLowerCase() !== 'pendiente';
        };

        const crearModal = function () {
            let modal = document.getElementById('modalVistaPreviaOficio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.className = 'modal fade oficio-preview-modal';
            modal.id = 'modalVistaPreviaOficio';
            modal.tabIndex = -1;
            modal.setAttribute('aria-labelledby', 'modalVistaPreviaOficioTitulo');
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header">' +
                            '<div>' +
                                '<h5 class="modal-title" id="modalVistaPreviaOficioTitulo">Vista previa del oficio</h5>' +
                                '<p class="oficio-preview-modal-subtitle" data-preview-template-label>' +
                                    'Plantilla institucional provisional' +
                                '</p>' +
                            '</div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body oficio-preview-modal-body">' +
                            '<article class="oficio-preview-paper">' +
                                '<div class="oficio-preview-brand">' +
                                    '<strong>Fundación Red Educativa México</strong>' +
                                    '<span>Vinculación institucional</span>' +
                                '</div>' +
                                '<div class="oficio-preview-meta">' +
                                    '<div>' +
                                        '<span>Folio</span>' +
                                        '<strong data-preview-folio>—</strong>' +
                                    '</div>' +
                                    '<div>' +
                                        '<span>Fecha</span>' +
                                        '<strong data-preview-fecha>—</strong>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="oficio-preview-subject">' +
                                    '<span>Asunto:</span> ' +
                                    '<strong data-preview-asunto>—</strong>' +
                                '</div>' +
                                '<div class="oficio-preview-content" data-preview-contenido></div>' +
                                '<div class="oficio-preview-note">' +
                                    '<i class="bi bi-info-circle"></i>' +
                                    '<span>Plantilla provisional del prototipo. El texto institucional podrá reemplazarse posteriormente.</span>' +
                                '</div>' +
                            '</article>' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cerrar</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            return modal;
        };

        const asignarTexto = function (modal, selector, valor) {
            const elemento = modal.querySelector(selector);

            if (elemento) {
                elemento.textContent = String(valor || '—');
            }
        };

        const mostrarError = function (mensaje) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast system-toast-error';
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: 5000
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
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
                boton.innerHTML =
                    '<i class="bi bi-eye"></i> Vista previa';
                acciones.appendChild(boton);
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
                boton.innerHTML =
                    '<i class="bi bi-eye me-2"></i>Vista previa';
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
                const respuesta = await fetch(
                    urlVistaPrevia + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );
                const datos = await respuesta.json();

                if (!datos.ok || !datos.vista_previa) {
                    throw new Error(
                        datos.mensaje || 'No fue posible obtener la vista previa del oficio.'
                    );
                }

                const vista = datos.vista_previa;
                const modal = crearModal();
                asignarTexto(modal, '[data-preview-folio]', vista.folio);
                asignarTexto(modal, '[data-preview-fecha]', vista.fecha);
                asignarTexto(modal, '[data-preview-asunto]', vista.asunto);
                asignarTexto(modal, '[data-preview-contenido]', vista.contenido);
                asignarTexto(
                    modal,
                    '[data-preview-template-label]',
                    vista.provisional
                        ? 'Plantilla institucional provisional'
                        : vista.plantilla
                );

                bootstrap.Modal.getOrCreateInstance(modal).show();
            } catch (error) {
                console.error(error);
                mostrarError(
                    error.message || 'No fue posible obtener la vista previa del oficio.'
                );
            } finally {
                boton.disabled = false;
                boton.innerHTML = contenidoOriginal;
            }
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoTrabajoId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
                programarActualizacion();
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
            }
        });

        document
            .getElementById('offcanvasSeguimientoTrabajo')
            ?.addEventListener('hidden.bs.offcanvas', function () {
                seguimientoTrabajoId = 0;
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
    });
})();
