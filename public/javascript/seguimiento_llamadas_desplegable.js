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

        const formatearTiempo = function (segundos) {
            const total = Math.max(0, Math.floor(Number(segundos) || 0));
            const minutos = Math.floor(total / 60);
            const resto = total % 60;
            return String(minutos).padStart(2, '0') + ':' + String(resto).padStart(2, '0');
        };

        const obtenerUrlDescarga = function (urlOriginal) {
            try {
                const url = new URL(String(urlOriginal || ''), window.location.href);
                url.searchParams.set('download', '1');
                return url.toString();
            } catch (error) {
                return String(urlOriginal || '');
            }
        };

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

                panel.querySelectorAll('details[open]').forEach(function (detalle) {
                    detalle.removeAttribute('open');
                });

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
                '<small>Arrastra la barra para avanzar o retroceder.</small>';

            const audio = document.createElement('audio');
            audio.preload = 'metadata';
            audio.src = String(llamada.grabacion_url || '');
            audio.className = 'linkage-call-player-audio-source';
            audio.setAttribute('aria-hidden', 'true');

            const controles = document.createElement('div');
            controles.className = 'linkage-call-custom-player';

            const botonPlay = document.createElement('button');
            botonPlay.type = 'button';
            botonPlay.className = 'linkage-call-player-button linkage-call-player-play';
            botonPlay.setAttribute('aria-label', 'Reproducir grabación');
            botonPlay.innerHTML = '<i class="bi bi-play-fill"></i>';

            const tiempoActual = document.createElement('span');
            tiempoActual.className = 'linkage-call-player-time';
            tiempoActual.textContent = '00:00';

            const progreso = document.createElement('input');
            progreso.type = 'range';
            progreso.min = '0';
            progreso.max = '1000';
            progreso.step = '1';
            progreso.value = '0';
            progreso.disabled = true;
            progreso.className = 'linkage-call-player-progress';
            progreso.setAttribute('aria-label', 'Posición de la grabación');
            progreso.style.setProperty('--player-progress', '0%');

            const duracion = document.createElement('span');
            duracion.className = 'linkage-call-player-time linkage-call-player-duration';
            duracion.textContent = formatearTiempo(llamada.duracion_segundos);

            const botonVolumen = document.createElement('button');
            botonVolumen.type = 'button';
            botonVolumen.className = 'linkage-call-player-button linkage-call-player-volume';
            botonVolumen.setAttribute('aria-label', 'Silenciar grabación');
            botonVolumen.innerHTML = '<i class="bi bi-volume-up"></i>';

            const menu = document.createElement('details');
            menu.className = 'linkage-call-player-menu';
            const resumenMenu = document.createElement('summary');
            resumenMenu.className = 'linkage-call-player-button linkage-call-player-more';
            resumenMenu.setAttribute('aria-label', 'Más opciones');
            resumenMenu.innerHTML = '<i class="bi bi-three-dots-vertical"></i>';

            const menuContenido = document.createElement('div');
            menuContenido.className = 'linkage-call-player-menu-content';
            const descargar = document.createElement('a');
            descargar.href = obtenerUrlDescarga(llamada.grabacion_url);
            descargar.className = 'linkage-call-player-download';
            descargar.innerHTML = '<i class="bi bi-download"></i><span>Descargar audio</span>';
            menuContenido.appendChild(descargar);
            menu.appendChild(resumenMenu);
            menu.appendChild(menuContenido);

            const error = document.createElement('span');
            error.className = 'linkage-call-recording-unavailable d-none';
            error.innerHTML =
                '<i class="bi bi-exclamation-circle"></i>' +
                '<span>La grabación no está disponible en este momento.</span>';

            controles.appendChild(botonPlay);
            controles.appendChild(tiempoActual);
            controles.appendChild(progreso);
            controles.appendChild(duracion);
            controles.appendChild(botonVolumen);
            controles.appendChild(menu);

            const obtenerDuracion = function () {
                if (Number.isFinite(audio.duration) && audio.duration > 0) {
                    return audio.duration;
                }
                return Math.max(0, Number(llamada.duracion_segundos) || 0);
            };

            const actualizarProgreso = function () {
                const total = obtenerDuracion();
                const actual = Math.max(0, Number(audio.currentTime) || 0);
                tiempoActual.textContent = formatearTiempo(actual);

                if (total > 0) {
                    const valor = Math.min(1000, Math.max(0, Math.round((actual / total) * 1000)));
                    progreso.value = String(valor);
                    progreso.style.setProperty('--player-progress', (valor / 10) + '%');
                }
            };

            const actualizarDuracion = function () {
                const total = obtenerDuracion();
                if (total > 0) {
                    duracion.textContent = formatearTiempo(total);
                    progreso.disabled = false;
                }
            };

            const actualizarEstadoPlay = function () {
                if (audio.paused) {
                    botonPlay.innerHTML = '<i class="bi bi-play-fill"></i>';
                    botonPlay.setAttribute('aria-label', 'Reproducir grabación');
                    controles.classList.remove('is-playing');
                } else {
                    botonPlay.innerHTML = '<i class="bi bi-pause-fill"></i>';
                    botonPlay.setAttribute('aria-label', 'Pausar grabación');
                    controles.classList.add('is-playing');
                }
            };

            botonPlay.addEventListener('click', function () {
                if (audio.paused) {
                    audio.play().catch(function () {
                        error.classList.remove('d-none');
                    });
                } else {
                    audio.pause();
                }
            });

            progreso.addEventListener('input', function () {
                const total = obtenerDuracion();
                if (total <= 0) {
                    return;
                }

                const porcentaje = Math.min(1000, Math.max(0, Number(progreso.value) || 0));
                progreso.style.setProperty('--player-progress', (porcentaje / 10) + '%');
                const destino = (porcentaje / 1000) * total;
                tiempoActual.textContent = formatearTiempo(destino);
                audio.currentTime = destino;
            });

            botonVolumen.addEventListener('click', function () {
                audio.muted = !audio.muted;
                botonVolumen.innerHTML = audio.muted
                    ? '<i class="bi bi-volume-mute"></i>'
                    : '<i class="bi bi-volume-up"></i>';
                botonVolumen.setAttribute(
                    'aria-label',
                    audio.muted ? 'Activar sonido' : 'Silenciar grabación'
                );
            });

            audio.addEventListener('loadedmetadata', actualizarDuracion);
            audio.addEventListener('durationchange', actualizarDuracion);
            audio.addEventListener('timeupdate', actualizarProgreso);
            audio.addEventListener('play', actualizarEstadoPlay);
            audio.addEventListener('pause', actualizarEstadoPlay);
            audio.addEventListener('ended', function () {
                actualizarEstadoPlay();
                actualizarProgreso();
            });
            audio.addEventListener('waiting', function () {
                controles.classList.add('is-loading');
            });
            audio.addEventListener('canplay', function () {
                controles.classList.remove('is-loading');
            });
            audio.addEventListener('error', function () {
                controles.classList.add('d-none');
                error.classList.remove('d-none');
                boton.disabled = true;
                boton.setAttribute('aria-expanded', 'false');
            });

            panel.appendChild(encabezado);
            panel.appendChild(audio);
            panel.appendChild(controles);
            panel.appendChild(error);

            const cuerpo = tarjeta.querySelector('.linkage-call-recording-body');
            if (cuerpo) {
                cuerpo.appendChild(panel);
            } else {
                tarjeta.appendChild(panel);
            }

            actualizarDuracion();
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
                    panel.querySelectorAll('details[open]').forEach(function (detalle) {
                        detalle.removeAttribute('open');
                    });
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
