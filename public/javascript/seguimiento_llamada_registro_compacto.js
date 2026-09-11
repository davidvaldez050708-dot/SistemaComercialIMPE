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

        const obtenerResumenLlamada = function (formulario) {
            const resumenTecnico = formulario?.querySelector('[data-twilio-call-summary]');
            const texto = normalizarTexto(resumenTecnico?.textContent || '');
            const duracion = texto.match(/\b\d{2}:\d{2}\b/)?.[0] || '';
            const tieneGrabacion = /grabaci[oó]n disponible/i.test(texto);
            const sinGrabacion = /sin grabaci[oó]n/i.test(texto);

            let titulo = 'Llamada finalizada';

            if (duracion !== '') {
                titulo += ' · ' + duracion;
            }

            if (tieneGrabacion) {
                titulo += ' · Grabación disponible';
            } else if (sinGrabacion || texto !== '') {
                titulo += ' · Sin grabación';
            }

            return {
                titulo: titulo,
                resumenTecnico: resumenTecnico
            };
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

            const avisoObligatorio = formulario.querySelector('[data-call-registration-required-note]');
            const avisoInformativo = formulario.querySelector('[data-work-informative-interaction-note]');
            const botonCancelar = buscarBotonCancelar(formulario);
            const resumen = obtenerResumenLlamada(formulario);

            avisoInformativo?.classList.add(claseOculta);
            resumen.resumenTecnico?.classList.add(claseOculta);
            botonCancelar?.classList.add(claseOculta);

            if (avisoObligatorio) {
                const tituloActual = avisoObligatorio.querySelector('strong');
                const textoActual = avisoObligatorio.querySelector('span');
                const icono = avisoObligatorio.querySelector('i');

                if (icono) {
                    icono.className = 'bi bi-telephone-check';
                }

                if (tituloActual && tituloActual.textContent !== resumen.titulo) {
                    tituloActual.textContent = resumen.titulo;
                }

                const subtitulo =
                    'Selecciona el resultado y guarda la interacción para conservar esta llamada en el expediente.';

                if (textoActual && textoActual.textContent !== subtitulo) {
                    textoActual.textContent = subtitulo;
                }
            }
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
                            '[data-call-registration-required-note], [data-twilio-call-summary], [data-work-informative-interaction-note]'
                        ) || Boolean(node.querySelector?.(
                            '[data-call-registration-required-note], [data-twilio-call-summary], [data-work-informative-interaction-note]'
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
