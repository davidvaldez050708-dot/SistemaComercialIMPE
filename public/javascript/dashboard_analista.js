(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');

        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        // Los accesos generales a un seguimiento pueden abrir el expediente completo,
        // pero el botón "Trabajar" de Prioridad operativa conserva la ruta territorial
        // con abrir_seguimiento para que Seguimiento abra directamente su panel de trabajo.
        tablero.addEventListener('click', function (event) {
            if (
                event.defaultPrevented ||
                event.button !== 0 ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            const enlace = event.target.closest('a[href*="abrir_seguimiento="]');
            if (!enlace || !tablero.contains(enlace)) {
                return;
            }

            if (enlace.classList.contains('analyst-work-button')) {
                return;
            }

            let seguimientoId = 0;
            try {
                const url = new URL(enlace.href, window.location.href);
                seguimientoId = Number(url.searchParams.get('abrir_seguimiento') || 0);
            } catch (error) {
                seguimientoId = 0;
            }

            if (seguimientoId <= 0) {
                return;
            }

            event.preventDefault();
            window.location.href =
                'index.php?controller=seguimientoVinculacion&action=detalle&id=' +
                encodeURIComponent(seguimientoId);
        });

        const elementos = Array.from(
            tablero.querySelectorAll('[data-analyst-attention-item]')
        );

        if (elementos.length === 0) {
            return;
        }

        const endpoint = 'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=';
        let indice = 0;
        const trabajadores = Math.min(3, elementos.length);

        const pintar = function (elemento, flujo) {
            const destino = elemento.querySelector('[data-route-action]');
            if (!destino || !flujo) {
                return;
            }

            const titulo = String(flujo.titulo || '').trim();
            const paso = Number(flujo.paso_actual || 0);

            if (titulo === '') {
                destino.textContent = 'Ruta de vinculación disponible en el seguimiento';
                return;
            }

            destino.textContent = paso > 0
                ? 'Ruta · Paso ' + paso + ' de ' + Number(flujo.total_pasos || 13) + ' · ' + titulo
                : 'Ruta · ' + titulo;
            destino.dataset.routeReady = '1';
        };

        const procesar = async function (elemento) {
            const seguimientoId = Number(elemento.dataset.seguimientoId || 0);
            if (seguimientoId <= 0) {
                return;
            }

            // En Inicio no pintamos datos de caché antes de consultar al servidor.
            // Es preferible conservar el estado "Consultando ruta..." unos milisegundos
            // antes que mostrar una ruta anterior y sustituirla después.
            try {
                const respuesta = await fetch(
                    endpoint + encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );

                if (!respuesta.ok) {
                    throw new Error('Ruta no disponible');
                }

                const datos = await respuesta.json();
                if (!datos?.ok || !datos?.flujo) {
                    throw new Error('Ruta no disponible');
                }

                pintar(elemento, datos.flujo);
            } catch (error) {
                const destino = elemento.querySelector('[data-route-action]');
                if (destino) {
                    destino.textContent = 'Abre el seguimiento para consultar la ruta actual';
                }
            }
        };

        const siguiente = async function () {
            const posicion = indice++;
            if (posicion >= elementos.length) {
                return;
            }

            await procesar(elementos[posicion]);
            await siguiente();
        };

        for (let trabajador = 0; trabajador < trabajadores; trabajador += 1) {
            void siguiente();
        }
    });
})();
