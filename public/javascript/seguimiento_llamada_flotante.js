(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

        if (rolId !== 4 || !window.bootstrap) {
            return;
        }

        const modalId = 'modalLlamadaVinculacion';
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        let modalEl = null;
        let toggleWindow = null;
        let hangupButton = null;
        let statusElement = null;
        let initialized = false;
        let minimized = false;
        let previousActive = false;
        let allowUnloadOnce = false;
        let savedBodyState = null;
        let observer = null;
        let navigationGuard = null;
        let navigationGuardContinue = null;
        let navigationGuardLeave = null;
        let pendingNavigation = null;
        let focusBeforeGuard = null;

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
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 4300 : 3200
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const llamadaActiva = function () {
            return Boolean(hangupButton && !hangupButton.disabled);
        };

        const obtenerInstanciaModal = function () {
            if (!modalEl) {
                return null;
            }

            return bootstrap.Modal.getInstance(modalEl) ||
                bootstrap.Modal.getOrCreateInstance(modalEl, {
                    backdrop: 'static',
                    keyboard: false
                });
        };

        const actualizarBotonVentana = function () {
            if (!toggleWindow) {
                return;
            }

            const activa = llamadaActiva();
            toggleWindow.hidden = !activa && !minimized;
            toggleWindow.disabled = !activa && !minimized;
            toggleWindow.classList.toggle('is-restore', minimized);
            toggleWindow.setAttribute(
                'aria-label',
                minimized ? 'Restaurar llamada' : 'Minimizar llamada'
            );
            toggleWindow.setAttribute(
                'title',
                minimized ? 'Restaurar llamada' : 'Minimizar y seguir trabajando'
            );
            toggleWindow.innerHTML = minimized
                ? '<i class="bi bi-arrows-angle-expand" aria-hidden="true"></i><span>Abrir</span>'
                : '<i class="bi bi-dash-lg" aria-hidden="true"></i><span>Minimizar</span>';
        };

        const guardarEstadoBody = function () {
            if (savedBodyState) {
                return;
            }

            savedBodyState = {
                overflow: document.body.style.overflow,
                paddingRight: document.body.style.paddingRight,
                hadModalOpen: document.body.classList.contains('modal-open')
            };
        };

        const liberarInterfaz = function () {
            guardarEstadoBody();
            document.body.classList.add('impe-call-workspace-mode');
            document.body.classList.remove('modal-open');
            document.body.style.overflow = 'auto';
            document.body.style.paddingRight = '';

            const instancia = obtenerInstanciaModal();
            try {
                instancia?._focustrap?.deactivate();
            } catch (error) {
                console.debug('No fue necesario desactivar el foco del modal.', error);
            }
        };

        const restaurarInterfaz = function () {
            document.body.classList.remove('impe-call-workspace-mode');

            if (savedBodyState) {
                document.body.style.overflow = savedBodyState.overflow;
                document.body.style.paddingRight = savedBodyState.paddingRight;

                if (savedBodyState.hadModalOpen) {
                    document.body.classList.add('modal-open');
                }
            } else if (modalEl?.classList.contains('show')) {
                document.body.classList.add('modal-open');
            }

            const instancia = obtenerInstanciaModal();
            try {
                instancia?._focustrap?.activate();
            } catch (error) {
                console.debug('No fue necesario restaurar el foco del modal.', error);
            }

            savedBodyState = null;
        };

        const minimizar = function () {
            if (!modalEl || !llamadaActiva() || minimized) {
                return;
            }

            minimized = true;
            modalEl.classList.add('linkage-call-minimized');
            modalEl.setAttribute('data-call-window-state', 'minimized');
            liberarInterfaz();
            actualizarBotonVentana();

            window.setTimeout(function () {
                const foco = document.querySelector(
                    '#offcanvasSeguimientoTrabajo.show button:not(:disabled), ' +
                    'main a, main button:not(:disabled)'
                );
                foco?.focus?.({ preventScroll: true });
            }, 40);
        };

        const restaurar = function (enfocar) {
            if (!modalEl || !minimized) {
                return;
            }

            minimized = false;
            modalEl.classList.remove('linkage-call-minimized');
            modalEl.setAttribute('data-call-window-state', 'expanded');
            restaurarInterfaz();
            actualizarBotonVentana();

            if (enfocar !== false) {
                window.setTimeout(function () {
                    toggleWindow?.focus?.({ preventScroll: true });
                }, 50);
            }
        };

        const cerrarProteccionNavegacion = function (devolverFoco) {
            if (!navigationGuard || navigationGuard.hidden) {
                pendingNavigation = null;
                return;
            }

            navigationGuard.hidden = true;
            navigationGuard.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('impe-call-navigation-guard-open');
            pendingNavigation = null;

            if (devolverFoco !== false && focusBeforeGuard instanceof HTMLElement) {
                window.setTimeout(function () {
                    focusBeforeGuard?.focus?.({ preventScroll: true });
                    focusBeforeGuard = null;
                }, 20);
            } else {
                focusBeforeGuard = null;
            }
        };

        const crearProteccionNavegacion = function () {
            if (navigationGuard) {
                return;
            }

            navigationGuard = document.createElement('div');
            navigationGuard.className = 'linkage-call-navigation-guard';
            navigationGuard.hidden = true;
            navigationGuard.setAttribute('role', 'dialog');
            navigationGuard.setAttribute('aria-modal', 'true');
            navigationGuard.setAttribute('aria-hidden', 'true');
            navigationGuard.setAttribute('aria-labelledby', 'tituloProteccionLlamada');
            navigationGuard.setAttribute('aria-describedby', 'textoProteccionLlamada');
            navigationGuard.innerHTML =
                '<div class="linkage-call-navigation-guard-backdrop"></div>' +
                '<div class="linkage-call-navigation-card" role="document">' +
                    '<div class="linkage-call-navigation-icon" aria-hidden="true">' +
                        '<i class="bi bi-telephone-fill"></i>' +
                    '</div>' +
                    '<div class="linkage-call-navigation-copy">' +
                        '<span class="linkage-call-navigation-eyebrow">LLAMADA EN CURSO</span>' +
                        '<h3 id="tituloProteccionLlamada">¿Salir de esta sección?</h3>' +
                        '<p id="textoProteccionLlamada">Cambiar de página finalizará la llamada actual. Puedes continuar trabajando aquí sin cerrarla.</p>' +
                    '</div>' +
                    '<div class="linkage-call-navigation-actions">' +
                        '<button type="button" class="btn btn-system-light" data-call-navigation-stay>' +
                            '<i class="bi bi-arrow-left"></i> Continuar llamada' +
                        '</button>' +
                        '<button type="button" class="btn btn-outline-danger" data-call-navigation-leave>' +
                            '<i class="bi bi-box-arrow-right"></i> Salir y finalizar' +
                        '</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(navigationGuard);

            navigationGuardContinue = navigationGuard.querySelector('[data-call-navigation-stay]');
            navigationGuardLeave = navigationGuard.querySelector('[data-call-navigation-leave]');

            navigationGuardContinue?.addEventListener('click', function () {
                cerrarProteccionNavegacion(true);
            });

            navigationGuard.querySelector('.linkage-call-navigation-guard-backdrop')
                ?.addEventListener('click', function () {
                    cerrarProteccionNavegacion(true);
                });

            navigationGuardLeave?.addEventListener('click', function () {
                if (!pendingNavigation) {
                    cerrarProteccionNavegacion(false);
                    return;
                }

                const destino = pendingNavigation.href;
                navigationGuardLeave.disabled = true;
                navigationGuardContinue.disabled = true;
                navigationGuardLeave.innerHTML =
                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Finalizando…';
                allowUnloadOnce = true;

                if (hangupButton && !hangupButton.disabled) {
                    hangupButton.click();
                }

                window.setTimeout(function () {
                    window.location.assign(destino);
                }, 220);

                window.setTimeout(function () {
                    allowUnloadOnce = false;
                    if (navigationGuardLeave) {
                        navigationGuardLeave.disabled = false;
                        navigationGuardLeave.innerHTML =
                            '<i class="bi bi-box-arrow-right"></i> Salir y finalizar';
                    }
                    if (navigationGuardContinue) {
                        navigationGuardContinue.disabled = false;
                    }
                }, 2500);
            });

            navigationGuard.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    cerrarProteccionNavegacion(true);
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                const focos = Array.from(
                    navigationGuard.querySelectorAll('button:not(:disabled)')
                );

                if (focos.length < 2) {
                    return;
                }

                const primero = focos[0];
                const ultimo = focos[focos.length - 1];

                if (event.shiftKey && document.activeElement === primero) {
                    event.preventDefault();
                    ultimo.focus();
                } else if (!event.shiftKey && document.activeElement === ultimo) {
                    event.preventDefault();
                    primero.focus();
                }
            });
        };

        const mostrarProteccionNavegacion = function (href) {
            crearProteccionNavegacion();
            pendingNavigation = { href: href };
            focusBeforeGuard = document.activeElement;
            navigationGuard.hidden = false;
            navigationGuard.setAttribute('aria-hidden', 'false');
            document.body.classList.add('impe-call-navigation-guard-open');

            if (navigationGuardLeave) {
                navigationGuardLeave.disabled = false;
                navigationGuardLeave.innerHTML =
                    '<i class="bi bi-box-arrow-right"></i> Salir y finalizar';
            }
            if (navigationGuardContinue) {
                navigationGuardContinue.disabled = false;
            }

            window.setTimeout(function () {
                navigationGuardContinue?.focus?.({ preventScroll: true });
            }, 30);
        };

        const sincronizarEstado = function () {
            const activeNow = llamadaActiva();

            if (previousActive && !activeNow) {
                cerrarProteccionNavegacion(false);

                if (minimized) {
                    window.setTimeout(function () {
                        restaurar(true);
                    }, 120);
                }
            }

            previousActive = activeNow;
            actualizarBotonVentana();
        };

        const inicializarModal = function (elemento) {
            if (initialized || !elemento) {
                return;
            }

            modalEl = elemento;
            hangupButton = modalEl.querySelector('[data-call-hangup]');
            statusElement = modalEl.querySelector('[data-call-status]');
            const header = modalEl.querySelector('.modal-header');
            const closeButton = header?.querySelector('.btn-close');

            if (!header || !hangupButton || !statusElement) {
                return;
            }

            initialized = true;
            modalEl.setAttribute('data-call-window-state', 'expanded');

            const acciones = document.createElement('div');
            acciones.className = 'linkage-call-window-actions';

            toggleWindow = document.createElement('button');
            toggleWindow.type = 'button';
            toggleWindow.className = 'linkage-call-window-toggle';
            toggleWindow.setAttribute('data-call-window-toggle', '');
            toggleWindow.hidden = true;
            acciones.appendChild(toggleWindow);

            if (closeButton) {
                acciones.appendChild(closeButton);
            }
            header.appendChild(acciones);

            toggleWindow.addEventListener('click', function () {
                if (minimized) {
                    restaurar(true);
                } else {
                    minimizar();
                }
            });

            observer = new MutationObserver(sincronizarEstado);
            observer.observe(hangupButton, {
                attributes: true,
                attributeFilter: ['disabled']
            });
            observer.observe(statusElement, {
                childList: true,
                subtree: true,
                characterData: true
            });

            modalEl.addEventListener('hidden.bs.modal', function () {
                if (minimized) {
                    minimized = false;
                    modalEl.classList.remove('linkage-call-minimized');
                    restaurarInterfaz();
                }
                actualizarBotonVentana();
            });

            sincronizarEstado();
        };

        const localizarModal = function () {
            const existente = document.getElementById(modalId);
            if (existente) {
                inicializarModal(existente);
                return true;
            }
            return false;
        };

        if (!localizarModal()) {
            const domObserver = new MutationObserver(function () {
                if (localizarModal()) {
                    domObserver.disconnect();
                }
            });
            domObserver.observe(document.body, { childList: true, subtree: true });
        }

        /*
         * El módulo principal de Twilio incluye una protección histórica que
         * desconecta la llamada cuando el panel de trabajo termina de ocultarse.
         * En modo flotante el panel sí puede cerrarse: detenemos únicamente ese
         * manejador durante una llamada activa y mantenemos la llamada en pantalla.
         * Usamos captura para ejecutarnos antes del listener original.
         */
        if (offcanvas) {
            offcanvas.addEventListener('hidden.bs.offcanvas', function (event) {
                if (!llamadaActiva()) {
                    return;
                }

                event.stopImmediatePropagation();

                if (!minimized) {
                    minimizar();
                }

                mostrarToast(
                    'El panel se cerró. La llamada continúa en el control flotante.',
                    false
                );
            }, true);
        }

        document.addEventListener('click', function (event) {
            if (!llamadaActiva() || event.defaultPrevented) {
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

            let destino;
            try {
                destino = new URL(enlace.href, window.location.href);
            } catch (error) {
                return;
            }

            const actual = new URL(window.location.href);
            if (
                destino.origin === actual.origin &&
                destino.pathname === actual.pathname &&
                destino.search === actual.search &&
                destino.hash !== actual.hash
            ) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            mostrarProteccionNavegacion(destino.href);
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (!llamadaActiva() || allowUnloadOnce) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });
    });
})();
