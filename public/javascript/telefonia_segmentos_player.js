/* Reproductor uniforme para los segmentos PBX verificados.
 * Los controles pertenecen al CRM; el audio sigue siendo privado en el endpoint.
 */
(function (global) {
    'use strict';
    function time(value) {
        if (!Number.isFinite(value) || value < 0) return '0:00';
        const s = Math.floor(value);
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }
    function create(host, endpoint, count, segments = []) {
        if (!host || !/^https?:\/\//.test(String(endpoint || '')) ||
            !Number.isInteger(count) || count < 1 || count > 8) return false;
        const list = [];
        const fragment = document.createDocumentFragment();
        for (let i = 0; i < count; i++) {
            const frame = document.createElement('div');
            frame.className = 'linkage-call-inline-player';
            frame.style.marginBottom = '10px';
            const head = document.createElement('div');
            head.className = 'linkage-call-inline-player-heading';
            const title = document.createElement('span');
            title.textContent = count === 1 ? 'Grabación de la llamada' :
                'Grabación de la llamada · parte ' + (i + 1) + ' de ' + count;
            const info=Array.isArray(segments)?segments[i]:null;
            if(Number.isInteger(info?.tramo)&&info.tramo>0&&Number.isInteger(info?.segundos)&&info.segundos>=0)
                title.textContent+=' · Tramo '+info.tramo+' · '+time(info.segundos);
            head.appendChild(title);
            const controls = document.createElement('div');
            controls.className = 'linkage-call-custom-player';
            const audio = document.createElement('audio');
            audio.className = 'linkage-call-player-audio-source';
            audio.preload = 'none';
            const url = new URL(endpoint);
            url.searchParams.delete('verificar');
            url.searchParams.set('segmento', String(i));
            audio.src = url.toString();
            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'linkage-call-player-button linkage-call-player-play';
            play.setAttribute('aria-label', 'Reproducir grabación');
            play.innerHTML = '<i class="bi bi-play-fill" aria-hidden="true"></i>';
            const elapsed = document.createElement('span');
            elapsed.className = 'linkage-call-player-time';
            elapsed.textContent = '0:00';
            const progress = document.createElement('input');
            progress.type = 'range';
            progress.min = '0';
            progress.max = '100';
            progress.step = '0.1';
            progress.value = '0';
            progress.className = 'linkage-call-player-progress';
            progress.setAttribute('aria-label', 'Posición del audio');
            const duration = document.createElement('span');
            duration.className = 'linkage-call-player-time';
            duration.textContent = '0:00';
            const mute = document.createElement('button');
            mute.type = 'button';
            mute.className = 'linkage-call-player-button linkage-call-player-volume';
            mute.setAttribute('aria-label', 'Silenciar grabación');
            mute.innerHTML = '<i class="bi bi-volume-up" aria-hidden="true"></i>';
            // Misma acción de tres puntos utilizada por los reproductores
            // del expediente; el enlace pasa por el endpoint autenticado.
            const menu = document.createElement('details');
            menu.className = 'linkage-call-player-menu';
            const summary = document.createElement('summary');
            summary.className = 'linkage-call-player-button linkage-call-player-more';
            summary.setAttribute('aria-label', 'Opciones de grabación');
            summary.title = 'Opciones';
            summary.innerHTML = '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>';
            const menuContent = document.createElement('div');
            menuContent.className = 'linkage-call-player-menu-content';
            const download = document.createElement('a');
            download.className = 'linkage-call-player-download';
            const downloadUrl = new URL(url);
            downloadUrl.searchParams.set('download', '1');
            download.href = downloadUrl.toString();
            download.innerHTML = '<i class="bi bi-download" aria-hidden="true"></i> Descargar audio';
            menuContent.appendChild(download);
            menu.append(summary, menuContent);
            controls.append(audio, play, elapsed, progress, duration, mute, menu);
            const error = document.createElement('p');
            error.className = 'linkage-incoming-player-error';
            error.hidden = true;
            error.setAttribute('role', 'status');
            const refresh = () => {
                const total = Number.isFinite(audio.duration) ? audio.duration : 0;
                elapsed.textContent = time(audio.currentTime);
                duration.textContent = time(total);
                const pct = total > 0 ? Math.max(0, Math.min(100, audio.currentTime / total * 100)) : 0;
                progress.value = String(pct);
                progress.style.setProperty('--player-progress', pct + '%');
                play.innerHTML = audio.paused || audio.ended
                    ? '<i class="bi bi-play-fill" aria-hidden="true"></i>'
                    : '<i class="bi bi-pause-fill" aria-hidden="true"></i>';
                play.setAttribute('aria-label', audio.paused ? 'Reproducir grabación' : 'Pausar grabación');
            };
            play.addEventListener('click', () => {
                if (!audio.paused) { audio.pause(); return; }
                list.forEach(other => { if (other !== audio) other.pause(); });
                error.hidden = true;
                audio.play().catch(() => {
                    error.textContent = 'No fue posible cargar este segmento. Vuelve a intentarlo.';
                    error.hidden = false;
                    refresh();
                });
            });
            progress.addEventListener('input', () => {
                if (Number.isFinite(audio.duration) && audio.duration > 0)
                    audio.currentTime = Number(progress.value) * audio.duration / 100;
                refresh();
            });
            mute.addEventListener('click', () => {
                audio.muted = !audio.muted;
                mute.innerHTML = audio.muted
                    ? '<i class="bi bi-volume-mute" aria-hidden="true"></i>'
                    : '<i class="bi bi-volume-up" aria-hidden="true"></i>';
                mute.setAttribute('aria-label', audio.muted ? 'Activar sonido' : 'Silenciar grabación');
            });
            for (const event of ['loadedmetadata', 'durationchange', 'timeupdate',
                'play', 'pause', 'ended']) audio.addEventListener(event, refresh);
            audio.addEventListener('error', () => {
                error.textContent = 'El servidor no pudo entregar este audio.';
                error.hidden = false;
                refresh();
            });
            audio.addEventListener('ended', () => {
                if (list[i + 1]) list[i + 1].play().catch(() => {});
            });
            frame.append(head, controls, error);
            fragment.appendChild(frame);
            list.push(audio);
        }
        host.replaceChildren(fragment);
        return true;
    }
    global.IMPE_TELEPHONY_SEGMENTS = Object.freeze({create});
})(window);
