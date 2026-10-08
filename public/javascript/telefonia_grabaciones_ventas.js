(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const tabla = document.querySelector('.telephony-history-table');
        if (!tabla) return;

        function tiempo(segundos) {
            const s = Math.max(0, Math.floor(Number(segundos) || 0));
            return String(Math.floor(s / 60)).padStart(2, '0') + ':' +
                String(s % 60).padStart(2, '0');
        }

        function urlDescarga(urlOriginal) {
            const url = new URL(urlOriginal, window.location.href);
            if (url.origin !== window.location.origin) {
                throw new Error('La grabación debe obtenerse desde este sistema.');
            }
            url.searchParams.set('download', '1');
            return url.toString();
        }

        function cerrarOtros(excepto) {
            tabla.querySelectorAll('[data-sales-recording-row]').forEach(function (fila) {
                if (fila === excepto || fila.hidden) return;
                const audio = fila.querySelector('audio');
                if (audio) audio.pause();
                fila.querySelectorAll('details[open]').forEach(function (menu) {
                    menu.removeAttribute('open');
                });
                fila.hidden = true;
                const boton = fila.previousElementSibling?.querySelector(
                    '[data-sales-recording-toggle]'
                );
                if (boton) {
                    boton.setAttribute('aria-expanded', 'false');
                    boton.classList.remove('is-open');
                }
            });
        }

        function construirReproductor(fila, boton) {
            const slot = fila.querySelector('[data-sales-recording-slot]');
            if (!slot) return null;
            const existente = slot.querySelector('.linkage-call-inline-player');
            if (existente) return existente;

            const origen = boton.dataset.recordingUrl || '';
            const segundos = Number(boton.dataset.recordingSeconds) || 0;
            let destinoDescarga;
            try {
                destinoDescarga = urlDescarga(origen);
            } catch (error) {
                return null;
            }

            const panel = document.createElement('div');
            panel.className = 'linkage-call-inline-player';

            const heading = document.createElement('div');
            heading.className = 'linkage-call-inline-player-heading';
            heading.innerHTML =
                '<span><i class="bi bi-soundwave"></i> Grabación de la llamada</span>' +
                '<small>Arrastra la barra para avanzar o retroceder.</small>';

            const audio = document.createElement('audio');
            audio.src = origen;
            audio.preload = 'metadata';
            audio.className = 'linkage-call-player-audio-source';
            audio.setAttribute('aria-label', 'Grabación de la llamada telefónica');

            const control = document.createElement('div');
            control.className = 'linkage-call-custom-player';

            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'linkage-call-player-button linkage-call-player-play';
            play.setAttribute('aria-label', 'Reproducir grabación');
            play.innerHTML = '<i class="bi bi-play-fill"></i>';

            const actual = document.createElement('span');
            actual.className = 'linkage-call-player-time';
            actual.textContent = '00:00';

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
            duracion.textContent = tiempo(segundos);

            const volumen = document.createElement('button');
            volumen.type = 'button';
            volumen.className = 'linkage-call-player-button linkage-call-player-volume';
            volumen.setAttribute('aria-label', 'Silenciar grabación');
            volumen.innerHTML = '<i class="bi bi-volume-up"></i>';

            const menu = document.createElement('details');
            menu.className = 'linkage-call-player-menu';
            const summary = document.createElement('summary');
            summary.className = 'linkage-call-player-button linkage-call-player-more';
            summary.setAttribute('aria-label', 'Más opciones');
            summary.innerHTML = '<i class="bi bi-three-dots-vertical"></i>';

            const menuBody = document.createElement('div');
            menuBody.className = 'linkage-call-player-menu-content';
            const descargar = document.createElement('a');
            descargar.href = destinoDescarga;
            descargar.className = 'linkage-call-player-download';
            descargar.innerHTML = '<i class="bi bi-download"></i><span>Descargar audio</span>';
            menuBody.appendChild(descargar);
            menu.append(summary, menuBody);

            const error = document.createElement('span');
            error.className = 'linkage-call-recording-unavailable d-none';
            error.innerHTML = '<i class="bi bi-exclamation-circle"></i>' +
                '<span>La grabación no está disponible en este momento.</span>';

            control.append(play, actual, progreso, duracion, volumen, menu);
            panel.append(heading, audio, control, error);
            slot.appendChild(panel);

            function tiempoTotal() {
                return Number.isFinite(audio.duration) && audio.duration > 0
                    ? audio.duration
                    : Math.max(0, segundos);
            }
            function actualizarTiempo() {
                const total = tiempoTotal();
                const transcurrido = Math.max(0, Number(audio.currentTime) || 0);
                actual.textContent = tiempo(transcurrido);
                if (total > 0) {
                    const valor = Math.min(1000, Math.max(0, Math.round(
                        (transcurrido / total) * 1000
                    )));
                    progreso.value = String(valor);
                    progreso.style.setProperty('--player-progress', (valor / 10) + '%');
                }
            }
            function actualizarDuracion() {
                const total = tiempoTotal();
                if (total > 0) {
                    duracion.textContent = tiempo(total);
                    progreso.disabled = false;
                }
            }
            function actualizarPlay() {
                const pausado = audio.paused;
                play.innerHTML = pausado
                    ? '<i class="bi bi-play-fill"></i>'
                    : '<i class="bi bi-pause-fill"></i>';
                play.setAttribute('aria-label',
                    pausado ? 'Reproducir grabación' : 'Pausar grabación');
                control.classList.toggle('is-playing', !pausado);
            }

            play.addEventListener('click', function () {
                if (audio.paused) {
                    void audio.play().catch(function () {
                        error.classList.remove('d-none');
                    });
                } else {
                    audio.pause();
                }
            });
            progreso.addEventListener('input', function () {
                const total = tiempoTotal();
                if (total <= 0) return;
                const posicion = Math.min(1000, Math.max(0, Number(progreso.value) || 0));
                progreso.style.setProperty('--player-progress', (posicion / 10) + '%');
                const segundo = posicion / 1000 * total;
                actual.textContent = tiempo(segundo);
                audio.currentTime = segundo;
            });
            volumen.addEventListener('click', function () {
                audio.muted = !audio.muted;
                volumen.innerHTML = audio.muted
                    ? '<i class="bi bi-volume-mute"></i>'
                    : '<i class="bi bi-volume-up"></i>';
                volumen.setAttribute('aria-label',
                    audio.muted ? 'Activar sonido' : 'Silenciar grabación');
            });
            audio.addEventListener('loadedmetadata', actualizarDuracion);
            audio.addEventListener('durationchange', actualizarDuracion);
            audio.addEventListener('timeupdate', actualizarTiempo);
            audio.addEventListener('play', actualizarPlay);
            audio.addEventListener('pause', actualizarPlay);
            audio.addEventListener('ended', function () {
                actualizarPlay();
                actualizarTiempo();
            });
            audio.addEventListener('waiting', function () {
                control.classList.add('is-loading');
            });
            audio.addEventListener('canplay', function () {
                control.classList.remove('is-loading');
            });
            audio.addEventListener('error', function () {
                control.classList.add('d-none');
                error.classList.remove('d-none');
            });
            actualizarDuracion();
            return panel;
        }

        tabla.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-sales-recording-toggle]');
            if (!boton || !tabla.contains(boton)) return;
            const fila = boton.closest('tr')?.nextElementSibling;
            if (!fila?.hasAttribute('data-sales-recording-row')) return;

            const abrir = fila.hidden;
            if (abrir) {
                cerrarOtros(fila);
                const panel = construirReproductor(fila, boton);
                if (!panel) return;
            } else {
                fila.querySelector('audio')?.pause();
                fila.querySelectorAll('details[open]').forEach(function (menu) {
                    menu.removeAttribute('open');
                });
            }

            fila.hidden = !abrir;
            boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            boton.classList.toggle('is-open', abrir);
        });
    });
})();