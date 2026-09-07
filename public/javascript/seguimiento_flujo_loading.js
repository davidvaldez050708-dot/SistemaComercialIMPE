(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const textoCarga = 'Consultando ruta...';
        let temporizadorRespaldo = null;

        const limpiarPendiente = function () {
            window.clearTimeout(temporizadorRespaldo);
            temporizadorRespaldo = null;
            offcanvas.removeAttribute('data-flow-ui-pending');
            offcanvas.removeAttribute('data-flow-ui-prepared');
            offcanvas.removeAttribute('data-flow-ui-fallback');
        };

        const restaurarFallback = function () {
            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
            const fallback = String(
                offcanvas.getAttribute('data-flow-ui-fallback') || ''
            ).trim();

            if (proximaAccion && fallback !== '') {
                proximaAccion.textContent = fallback;
            }

            limpiarPendiente();
        };

        const prepararBloqueRuta = function () {
            const bloque = offcanvas.querySelector('[data-work-flow-section]');

            if (!bloque) {
                offcanvas.setAttribute('data-flow-ui-prepared', '1');
                return;
            }

            const contador = bloque.querySelector('[data-flow-step-count]');
            const progreso = bloque.querySelector('[data-flow-progress]');
            const ventana = bloque.querySelector('[data-flow-window]');
            const titulo = bloque.querySelector('[data-flow-title]');
            const descripcion = bloque.querySelector('[data-flow-description]');
            const faltantes = bloque.querySelector('[data-flow-missing]');
            const acciones = bloque.querySelector('[data-flow-actions]');

            if (contador) {
                contador.textContent = 'Consultando...';
            }
            if (progreso) {
                progreso.style.width = '0%';
            }
            if (ventana) {
                ventana.innerHTML = '';
            }
            if (titulo) {
                titulo.textContent = textoCarga;
            }
            if (descripcion) {
                descripcion.textContent = 'Actualizando el estado real del seguimiento.';
            }
            if (faltantes) {
                faltantes.innerHTML = '';
                faltantes.classList.add('d-none');
            }
            if (acciones) {
                acciones.innerHTML = '';
                acciones.classList.add('has-single-action');
            }

            bloque.classList.remove('d-none');
            offcanvas.setAttribute('data-flow-ui-prepared', '1');
        };

        const iniciarCarga = function (seguimientoId) {
            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');

            window.clearTimeout(temporizadorRespaldo);
            offcanvas.setAttribute('data-flow-ui-pending', String(seguimientoId || '1'));
            offcanvas.setAttribute('data-flow-ui-prepared', '0');
            offcanvas.removeAttribute('data-flow-ui-fallback');

            if (proximaAccion) {
                proximaAccion.textContent = textoCarga;
            }

            window.setTimeout(prepararBloqueRuta, 0);

            temporizadorRespaldo = window.setTimeout(function () {
                if (offcanvas.hasAttribute('data-flow-ui-pending')) {
                    restaurarFallback();
                }
            }, 5000);
        };

        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-work-follow]');

            if (!boton) {
                return;
            }

            iniciarCarga(Number(boton.getAttribute('data-work-follow-id') || 0));
        }, true);

        const observador = new MutationObserver(function () {
            if (!offcanvas.hasAttribute('data-flow-ui-pending')) {
                return;
            }

            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
            const bloque = offcanvas.querySelector('[data-work-flow-section]');
            const titulo = bloque?.querySelector('[data-flow-title]');
            const contador = bloque?.querySelector('[data-flow-step-count]');
            const preparado = offcanvas.getAttribute('data-flow-ui-prepared') === '1';
            const tituloActual = String(titulo?.textContent || '').trim();
            const contadorActual = String(contador?.textContent || '').trim();
            const flujoResuelto = Boolean(
                preparado &&
                bloque &&
                !bloque.classList.contains('d-none') &&
                tituloActual !== '' &&
                tituloActual !== textoCarga &&
                /^Paso\s+\d+\s+de\s+\d+/i.test(contadorActual)
            );

            if (flujoResuelto) {
                if (proximaAccion) {
                    proximaAccion.textContent = tituloActual;
                }
                limpiarPendiente();
                return;
            }

            if (proximaAccion) {
                const valorActual = String(proximaAccion.textContent || '').trim();

                if (valorActual !== '' && valorActual !== textoCarga) {
                    offcanvas.setAttribute('data-flow-ui-fallback', valorActual);
                    proximaAccion.textContent = textoCarga;
                }
            }

            if (
                preparado &&
                bloque &&
                bloque.classList.contains('d-none') &&
                offcanvas.getAttribute('data-flow-ui-fallback')
            ) {
                restaurarFallback();
            }
        });

        observador.observe(offcanvas, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['class']
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            limpiarPendiente();
        });
    });
})();
