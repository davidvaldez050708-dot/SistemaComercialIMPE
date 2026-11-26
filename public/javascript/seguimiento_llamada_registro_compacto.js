(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (rolId !== 4 || !offcanvas) {
            return;
        }

        const claseOculta = 'impe-call-compact-hide';
        let pendiente = document.body.classList.contains('impe-call-registration-pending');
        let programado = null;

        const normalizarTexto = function (valor) {
            return String(valor || '').replace(/\s+/g, ' ').trim();
        };

        const obtenerFormulario = function () {
            return offcanvas.querySelector('[data-work-interaction-form]');
        };

        const buscarBotonCancelar = function (formulario) {
            if (!formulario) {
                return null;
            }

            return Array.from(formulario.querySelectorAll('button')).find(function (boton) {
                return normalizarTexto(boton.textContent).toLowerCase() === 'cancelar';
            }) || null;
        };

        const restaurarFormulario = function () {
            const formulario = obtenerFormulario();

            if (!formulario) {
                return;
            }

            formulario.querySelectorAll('.' + claseOculta).forEach(function (elemento) {
                elemento.classList.remove(claseOculta);
            });
        };

        const aplicarModoCompacto = function () {
            if (!pendiente && !document.body.classList.contains('impe-call-registration-pending')) {
                restaurarFormulario();
                return;
            }

            const formulario = obtenerFormulario();
            if (!formulario) {
                return;
            }

            // Este módulo solo oculta información redundante.
            // El aviso de registro obligatorio tiene un único responsable
            // (seguimiento_llamada_registro_obligatorio.js): no cambiar su
            // texto, icono ni contenido durante el sondeo telefónico.
            const avisoInformativo = formulario.querySelector('[data-work-informative-interaction-note]');
            const resumenTecnico = formulario.querySelector('[data-telephony-call-summary]');
            const botonCancelar = buscarBotonCancelar(formulario);

            avisoInformativo?.classList.add(claseOculta);
            resumenTecnico?.classList.add(claseOculta);
            botonCancelar?.classList.add(claseOculta);
        };

        const programarAplicacion = function () {
            window.clearTimeout(programado);
            programado = window.setTimeout(aplicarModoCompacto, 30);
        };

        document.addEventListener('impe:call-registration-pending-changed', function (event) {
            pendiente = Boolean(event.detail?.pending);

            if (!pendiente) {
                restaurarFormulario();
                return;
            }

            programarAplicacion();
            window.setTimeout(aplicarModoCompacto, 120);
            window.setTimeout(aplicarModoCompacto, 320);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-call-register]')) {
                return;
            }

            window.setTimeout(aplicarModoCompacto, 120);
            window.setTimeout(aplicarModoCompacto, 300);
        }, true);

        if (window.MutationObserver) {
            const observer = new MutationObserver(function (mutations) {
                if (!pendiente && !document.body.classList.contains('impe-call-registration-pending')) {
                    return;
                }

                const cambioUtil = mutations.some(function (mutation) {
                    return Array.from(mutation.addedNodes).some(function (node) {
                        if (!(node instanceof HTMLElement)) {
                            return false;
                        }

                        return node.matches?.(
                            '[data-call-registration-required-note], [data-telephony-call-summary], [data-work-informative-interaction-note]'
                        ) || Boolean(node.querySelector?.(
                            '[data-call-registration-required-note], [data-telephony-call-summary], [data-work-informative-interaction-note]'
                        ));
                    });
                });

                if (cambioUtil) {
                    programarAplicacion();
                }
            });

            observer.observe(offcanvas, {
                childList: true,
                subtree: true
            });
        }

        aplicarModoCompacto();
    });
})();
