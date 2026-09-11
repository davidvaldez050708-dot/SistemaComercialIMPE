(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (rolId !== 4 || !offcanvas || !window.bootstrap) {
            return;
        }

        const modalId = 'modalLlamadaVinculacion';
        let modalEl = null;
        let hangupButton = null;
        let resultBox = null;
        let registerButton = null;
        let modalObserver = null;
        let hadActiveCall = false;
        let registrationPending = false;
        let allowModalHide = false;
        let pendingSeguimientoId = 0;

        const mostrarToast = function (mensaje, esError) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-info-circle') + '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedorToastsAppend(contenedor, toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 4400 : 3300
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const contenedorToastsAppend = function (contenedor, toast) {
            contenedor.appendChild(toast);
        };

        const obtenerSeguimientoActual = function () {
            return Number(offcanvas.dataset.flowSeguimientoId || 0);
        };

        const llamadaActiva = function () {
            return Boolean(hangupButton && !hangupButton.disabled);
        };

        const estaPendiente = function () {
            return registrationPending ||
                String(modalEl?.dataset.callRegistrationPending || '') === '1';
        };

        const limpiarAvisoFormulario = function () {
            offcanvas.querySelector('[data-call-registration-required-note]')?.remove();
        };

        const establecerPendiente = function (valor) {
            const nuevoValor = Boolean(valor);

            if (registrationPending === nuevoValor) {
                return;
            }

            registrationPending = nuevoValor;

            if (registrationPending) {
                pendingSeguimientoId = obtenerSeguimientoActual();
                document.body.classList.add('impe-call-registration-pending');

                if (modalEl) {
                    modalEl.dataset.callRegistrationPending = '1';
                    modalEl.classList.add('linkage-call-registration-required');
                }
            } else {
                document.body.classList.remove('impe-call-registration-pending');
                pendingSeguimientoId = 0;
                hadActiveCall = false;
                allowModalHide = false;

                if (modalEl) {
                    modalEl.dataset.callRegistrationPending = '0';
                    modalEl.classList.remove('linkage-call-registration-required');
                }

                limpiarAvisoFormulario();
            }

            document.dispatchEvent(new CustomEvent('impe:call-registration-pending-changed', {
                detail: {
                    pending: registrationPending,
                    seguimientoId: pendingSeguimientoId
                }
            }));
        };

        const fechaLocalAhora = function () {
            const fecha = new Date();
            const pad = function (numero) {
                return String(numero).padStart(2, '0');
            };

            return fecha.getFullYear() + '-' +
                pad(fecha.getMonth() + 1) + '-' +
                pad(fecha.getDate()) + 'T' +
                pad(fecha.getHours()) + ':' +
                pad(fecha.getMinutes());
        };

        const prepararFormulario = function () {
            const formulario = offcanvas.querySelector('[data-work-interaction-form]');
            const botonAbrir = offcanvas.querySelector('[data-work-toggle-interaction]');

            if (!formulario) {
                mostrarToast(
                    'No se encontró el formulario para registrar el resultado de la llamada.',
                    true
                );
                return;
            }

            if (formulario.classList.contains('d-none') && botonAbrir && !botonAbrir.disabled) {
                botonAbrir.click();
            }

            window.setTimeout(function () {
                const canal = formulario.querySelector('[name="canal"]');
                const fechaInicio = formulario.querySelector('[name="fecha_inicio"]');
                const resultado = formulario.querySelector('[name="resultado"]');

                if (canal && canal.value !== 'LLAMADA') {
                    canal.value = 'LLAMADA';
                    canal.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (fechaInicio && String(fechaInicio.value || '').trim() === '') {
                    fechaInicio.value = fechaLocalAhora();
                }

                let aviso = formulario.querySelector('[data-call-registration-required-note]');
                if (!aviso) {
                    aviso = document.createElement('div');
                    aviso.className = 'col-12';
                    aviso.setAttribute('data-call-registration-required-note', '');
                    aviso.innerHTML =
                        '<div class="linkage-call-required-note">' +
                            '<i class="bi bi-journal-check" aria-hidden="true"></i>' +
                            '<div>' +
                                '<strong>Registro obligatorio de llamada</strong>' +
                                '<span>Selecciona el resultado y guarda la interacción para cerrar esta gestión.</span>' +
                            '</div>' +
                        '</div>';
                    formulario.querySelector('.row')?.prepend(aviso);
                }

                formulario.classList.remove('d-none');
                formulario.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                if (resultado && String(resultado.value || '').trim() === '') {
                    window.setTimeout(function () {
                        resultado.focus({ preventScroll: true });
                    }, 80);
                }
            }, 80);
        };

        const abrirPanelYFormulario = function () {
            if (!estaPendiente()) {
                return;
            }

            if (modalEl?.classList.contains('show')) {
                allowModalHide = true;
                const instanciaModal = bootstrap.Modal.getInstance(modalEl) ||
                    bootstrap.Modal.getOrCreateInstance(modalEl, {
                        backdrop: 'static',
                        keyboard: false
                    });
                instanciaModal.hide();
            }

            const instanciaPanel = bootstrap.Offcanvas.getInstance(offcanvas) ||
                bootstrap.Offcanvas.getOrCreateInstance(offcanvas);

            if (!offcanvas.classList.contains('show')) {
                instanciaPanel.show();
            }

            window.setTimeout(prepararFormulario, 110);
            window.setTimeout(function () {
                allowModalHide = false;
            }, 450);
        };

        const sincronizarModal = function () {
            if (!modalEl || !hangupButton) {
                return;
            }

            const activa = !hangupButton.disabled;

            if (activa) {
                hadActiveCall = true;
                return;
            }

            if (hadActiveCall && !estaPendiente()) {
                establecerPendiente(true);
            }

            if (!estaPendiente()) {
                return;
            }

            if (resultBox && !resultBox.classList.contains('d-none') && registerButton) {
                if (registerButton.classList.contains('d-none')) {
                    registerButton.classList.remove('d-none');
                }

                const titulo = resultBox.querySelector('strong');
                const textoTitulo = 'Llamada finalizada · registro pendiente';

                if (titulo && titulo.textContent !== textoTitulo) {
                    titulo.textContent = textoTitulo;
                }
            }
        };

        const inicializarModal = function (elemento) {
            if (!elemento || modalEl === elemento) {
                return;
            }

            modalEl = elemento;
            hangupButton = modalEl.querySelector('[data-call-hangup]');
            resultBox = modalEl.querySelector('[data-call-result]');
            registerButton = modalEl.querySelector('[data-call-register]');

            if (!hangupButton || !registerButton) {
                return;
            }

            modalEl.dataset.callRegistrationPending = '0';

            modalEl.addEventListener('hide.bs.modal', function (event) {
                if (!estaPendiente() || allowModalHide) {
                    return;
                }

                event.preventDefault();
                mostrarToast(
                    'Debes registrar el resultado de la llamada antes de cerrar esta gestión.',
                    true
                );
            });

            modalEl.addEventListener('hidden.bs.modal', function () {
                allowModalHide = false;
            });

            modalObserver?.disconnect();
            modalObserver = new MutationObserver(function () {
                sincronizarModal();
            });

            modalObserver.observe(hangupButton, {
                attributes: true,
                attributeFilter: ['disabled']
            });

            if (resultBox) {
                modalObserver.observe(resultBox, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            }

            sincronizarModal();
        };

        const localizarModal = function () {
            const encontrado = document.getElementById(modalId);
            if (!encontrado) {
                return false;
            }

            inicializarModal(encontrado);
            return true;
        };

        if (!localizarModal()) {
            const observer = new MutationObserver(function () {
                if (localizarModal()) {
                    observer.disconnect();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        const ajustarProteccionNavegacion = function () {
            if (!llamadaActiva()) {
                return;
            }

            const botonSalir = document.querySelector('[data-call-navigation-leave]');
            if (!botonSalir || botonSalir.disabled) {
                return;
            }

            const textoEsperado = 'Finalizar y registrar';
            if (String(botonSalir.textContent || '').trim() !== textoEsperado) {
                botonSalir.innerHTML =
                    '<i class="bi bi-journal-check"></i> Finalizar y registrar';
            }

            const tituloEsperado =
                'Finaliza la llamada y obliga a registrar su resultado antes de salir';
            if (botonSalir.getAttribute('title') !== tituloEsperado) {
                botonSalir.setAttribute('title', tituloEsperado);
            }
        };

        const navigationObserver = new MutationObserver(function () {
            ajustarProteccionNavegacion();
        });
        navigationObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['hidden']
        });

        document.addEventListener('click', function (event) {
            const botonFinalizarDesdeNavegacion = event.target.closest(
                '[data-call-navigation-leave]'
            );

            if (botonFinalizarDesdeNavegacion && llamadaActiva()) {
                event.preventDefault();
                event.stopImmediatePropagation();

                hangupButton.click();
                document.querySelector('[data-call-navigation-stay]')?.click();

                mostrarToast(
                    'La llamada se está finalizando. Registra su resultado antes de salir.',
                    false
                );
                return;
            }

            const botonRegistrar = event.target.closest('[data-call-register]');

            if (botonRegistrar && estaPendiente()) {
                allowModalHide = true;

                window.setTimeout(function () {
                    abrirPanelYFormulario();
                }, 45);
                return;
            }

            if (!estaPendiente()) {
                return;
            }

            const botonLlamar = event.target.closest('[data-work-call-button]');
            if (botonLlamar) {
                event.preventDefault();
                event.stopImmediatePropagation();
                mostrarToast(
                    'Primero registra el resultado de la llamada anterior.',
                    true
                );
                abrirPanelYFormulario();
                return;
            }

            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }

            const enlace = event.target.closest('a[href]');
            if (!enlace || enlace.hasAttribute('download')) {
                return;
            }

            const href = String(enlace.getAttribute('href') || '').trim();
            const target = String(enlace.getAttribute('target') || '').toLowerCase();
            const hrefLower = href.toLowerCase();

            if (
                href === '' ||
                href === '#' ||
                href.startsWith('#') ||
                hrefLower.startsWith('javascript:') ||
                hrefLower.startsWith('mailto:') ||
                hrefLower.startsWith('tel:') ||
                hrefLower.startsWith('callto:') ||
                hrefLower.startsWith('sip:') ||
                target === '_blank'
            ) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            mostrarToast(
                'La llamada ya finalizó. Registra su resultado antes de cambiar de sección.',
                true
            );
            abrirPanelYFormulario();
        }, true);

        offcanvas.addEventListener('hide.bs.offcanvas', function (event) {
            if (!estaPendiente()) {
                return;
            }

            event.preventDefault();
            mostrarToast(
                'Guarda el resultado de la llamada antes de cerrar el panel de trabajo.',
                true
            );
            window.setTimeout(prepararFormulario, 40);
        });

        document.addEventListener('impe:interaction-exact-id-ready', function (event) {
            if (!estaPendiente()) {
                return;
            }

            const seguimientoId = Number(event.detail?.seguimientoId || 0);
            if (seguimientoId > 0 && seguimientoId === Number(pendingSeguimientoId)) {
                establecerPendiente(false);
            }
        });

        document.addEventListener('impe:interaction-informative-saved', function (event) {
            if (!estaPendiente()) {
                return;
            }

            const seguimientoId = Number(event.detail?.seguimientoId || 0);
            if (seguimientoId > 0 && seguimientoId === Number(pendingSeguimientoId)) {
                establecerPendiente(false);
            }
        });

        const toastContainer = document.querySelector('.toast-container');
        if (toastContainer && window.MutationObserver) {
            const toastObserver = new MutationObserver(function (mutations) {
                if (!estaPendiente()) {
                    return;
                }

                const guardada = mutations.some(function (mutation) {
                    return Array.from(mutation.addedNodes).some(function (node) {
                        if (!(node instanceof HTMLElement)) {
                            return false;
                        }

                        const texto = String(node.textContent || '');
                        return texto.includes('Interacción registrada');
                    });
                });

                if (guardada) {
                    establecerPendiente(false);
                }
            });
            toastObserver.observe(toastContainer, { childList: true });
        }

        window.addEventListener('beforeunload', function (event) {
            if (!estaPendiente()) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });
    });
})();
