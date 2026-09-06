(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const tabla = document.querySelector('[data-linkage-table-wrapper]');
        if (!tabla) {
            return;
        }

        const observadores = new WeakMap();

        const fijarAccionAutoritativa = function (celda, titulo) {
            if (!celda) {
                return;
            }

            const texto = String(titulo || '').trim();
            if (texto === '') {
                return;
            }

            celda.dataset.flowNextAction = texto;

            if (String(celda.textContent || '').trim() !== texto) {
                celda.textContent = texto;
            }

            if (observadores.has(celda) || !window.MutationObserver) {
                return;
            }

            const observador = new MutationObserver(function () {
                const autoritativa = String(celda.dataset.flowNextAction || '').trim();
                const actual = String(celda.textContent || '').trim();

                if (autoritativa === '' || actual === autoritativa) {
                    return;
                }

                observador.disconnect();
                celda.textContent = autoritativa;
                observador.observe(celda, {
                    childList: true,
                    characterData: true,
                    subtree: true
                });
            });

            observador.observe(celda, {
                childList: true,
                characterData: true,
                subtree: true
            });
            observadores.set(celda, observador);
        };

        const filas = Array.from(
            tabla.querySelectorAll('[data-linkage-follow-row]')
        ).map(function (fila) {
            const boton = fila.querySelector('[data-work-follow-id]');
            const seguimientoId = Number(
                boton?.getAttribute('data-work-follow-id') || 0
            );

            return {
                fila: fila,
                seguimientoId: seguimientoId
            };
        }).filter(function (item) {
            return item.seguimientoId > 0;
        });

        if (filas.length === 0) {
            return;
        }

        let indice = 0;
        const trabajadores = Math.min(4, filas.length);

        for (let i = 0; i < trabajadores; i++) {
            procesarSiguiente();
        }

        async function procesarSiguiente() {
            const posicion = indice++;
            if (posicion >= filas.length) {
                return;
            }

            const item = filas[posicion];

            try {
                const respuesta = await fetch(
                    'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                    encodeURIComponent(item.seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );

                if (!respuesta.ok) {
                    return;
                }

                const datos = await respuesta.json();
                const titulo = String(datos?.flujo?.titulo || '').trim();
                const celda = item.fila.querySelector('[data-row-next-action]');

                if (datos?.ok && titulo !== '' && celda) {
                    fijarAccionAutoritativa(celda, titulo);
                }
            } catch (error) {
                // La bandeja conserva el valor renderizado por PHP si no puede sincronizarse.
            } finally {
                procesarSiguiente();
            }
        }
    });
})();
