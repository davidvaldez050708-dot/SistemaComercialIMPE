(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esExpediente =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoId = Number(parametros.get('id') || 0);

        if (!esExpediente || seguimientoId <= 0) {
            return;
        }

        let intentos = 0;
        let inicializado = false;

        const cerrarOtros = function (excepto) {
            document.querySelectorAll('.linkage-call-inline-player').forEach(function (panel) {
                if (panel === excepto) {
                    return;
                }

                panel.hidden = true;
                const audio = panel.querySelector('audio');
                if (audio) {
                    audio.pause();
                }

                const tarjeta = panel.closest('.linkage-call-history-card');
                const boton = tarjeta?.querySelector('[data-call-recording-toggle]');
                if (boton) {
                    boton.setAttribute('aria-expanded', 'false');
                    boton.classList.remove('is-open');
                }
            });
        };

        const crearReproductor = function (llamada, tarjeta, boton) {
            let panel = tarjeta.querySelector('.linkage-call-inline-player');

            if (panel) {
                return panel;
            }

            panel = document.createElement('div');
            panel.className = 'linkage-call-inline-player';
            panel.hidden = true;

            const encabezado = document.createElement('div');
            encabezado.className = 'linkage-call-inline-player-heading';
            encabezado.innerHTML =
                '<span><i class="bi bi-soundwave"></i> Grabación de la llamada</span>' +
                '<small>Usa el menú ⋮ del reproductor para descargar cuando el navegador lo permita.</small>';

            const fila = document.createElement('div');
            fila.className = 'linkage-call-inline-player-row';

            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'none';
            audio.src = String(llamada.grabacion_url || '');
            audio.setAttribute('aria-label', 'Grabación de llamada');

            const error = document.createElement('span');
            error.className = 'linkage-call-recording-unavailable d-none';
            error.innerHTML =
                '<i class="bi bi-exclamation-circle"></i>' +
                '<span>La grabación no está disponible en este momento.</span>';

            audio.addEventListener('error', function () {
                audio.classList.add('d-none');
                error.classList.remove('d-none');
                boton.disabled = true;
                boton.setAttribute('aria-expanded', 'false');
            });

            fila.appendChild(audio);
            fila.appendChild(error);
            panel.appendChild(encabezado);
            panel.appendChild(fila);

            const cuerpo = tarjeta.querySelector('.linkage-call-recording-body');
            if (cuerpo) {
                cuerpo.appendChild(panel);
            } else {
                tarjeta.appendChild(panel);
            }

            return panel;
        };

        const convertirBoton = function (tarjeta, llamada) {
            const estadoActual = tarjeta.querySelector('.linkage-call-audio-state.is-available');

            if (!estadoActual || !llamada.tiene_grabacion || !llamada.grabacion_url) {
                return;
            }

            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'linkage-call-audio-state is-available linkage-call-recording-toggle';
            boton.setAttribute('data-call-recording-toggle', '');
            boton.setAttribute('aria-expanded', 'false');
            boton.innerHTML =
                '<i class="bi bi-record-circle"></i>' +
                '<span>Grabación</span>' +
                '<i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>';

            estadoActual.replaceWith(boton);

            boton.addEventListener('click', function () {
                const panel = crearReproductor(llamada, tarjeta, boton);
                const abrir = panel.hidden;

                if (abrir) {
                    cerrarOtros(panel);
                }

                panel.hidden = !abrir;
                boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                boton.classList.toggle('is-open', abrir);

                if (!abrir) {
                    const audio = panel.querySelector('audio');
                    if (audio) {
                        audio.pause();
                    }
                }
            });
        };

        const configurar = function () {
            if (inicializado) {
                return;
            }

            const pane = document.querySelector('[data-expediente-pane="llamadas"]');
            const tarjetas = pane
                ? Array.from(pane.querySelectorAll('.linkage-call-history-card'))
                : [];

            if ((!pane || tarjetas.length === 0) && intentos < 60) {
                intentos++;
                window.setTimeout(configurar, 60);
                return;
            }

            if (!pane) {
                return;
            }

            inicializado = true;

            const seccionGrabaciones = pane.querySelector('.linkage-call-recordings-section');
            if (seccionGrabaciones) {
                seccionGrabaciones.remove();
            }

            fetch(
                'prueba_telefonia/api/llamadas_seguimiento.php?seguimiento_id=' +
                    encodeURIComponent(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            )
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.ok) {
                            throw new Error(data.mensaje || 'No fue posible cargar las grabaciones.');
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    const llamadas = Array.isArray(data.llamadas) ? data.llamadas : [];
                    const tarjetasActuales = Array.from(
                        pane.querySelectorAll('.linkage-call-history-card')
                    );

                    tarjetasActuales.forEach(function (tarjeta, indice) {
                        const llamada = llamadas[indice];
                        if (llamada) {
                            convertirBoton(tarjeta, llamada);
                        }
                    });
                })
                .catch(function (error) {
                    console.warn('No fue posible preparar los reproductores de llamada.', error);
                });
        };

        configurar();
    });
})();
