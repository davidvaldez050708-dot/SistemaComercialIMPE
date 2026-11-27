(function () {
    'use strict';
    const script = document.currentScript;
    const base = new URL('../', script.src);
    const user = Number(window.IMPE_CURRENT_USER_ID || 0);
    if (!user || window.IMPEAgendaAlerts) return;
    const prefix = 'impe:agenda-alertas:' + user + ':';
    const memory = new Map();
    function read(key, fallback) {
        try { return JSON.parse(localStorage.getItem(prefix + key)) ?? memory.get(key) ?? fallback; }
        catch (_) { return memory.get(key) ?? fallback; }
    }
    function write(key, value) {
        memory.set(key, value);
        try { localStorage.setItem(prefix + key, JSON.stringify(value)); } catch (_) { /* memoria de esta pestaña */ }
    }
    function preferences() {
        // Política del sistema: los ajustes antiguos no desactivan los avisos.
        return {sound: true, browser: supported() && Notification.permission === 'granted'};
    }
    const audio = new Audio(new URL('audio/agenda-aviso.mp3', base).href);
    audio.volume = 1;
    audio.preload = 'none';
    let playing = false, unlocked = true, lastSound = 0;
    let remainingPlays = 0;
    const soundQueue = new Map();
    let drainingSound = false;
    let schedules = [], offset = 0, presenter = null, lastUpdate = 0, zone = 'America/Mexico_City';
    const visualQueue = new Map();
    let draining = false;
    async function play() {
        if (!preferences().sound || !unlocked || playing || Date.now() - lastSound < 3000) return false;
        playing = true;
        remainingPlays = 2;
        let timeout;
        try {
            audio.muted = false;
            audio.currentTime = 0;
            await Promise.race([audio.play(), new Promise((_, reject) => {
                timeout = setTimeout(() => reject(Error('Audio no disponible')), 3000);
            })]);
            lastSound = Date.now();
            return true;
        } catch (error) {
            if (error?.name === 'NotAllowedError') unlocked = false;
            audio.pause(); playing = false; remainingPlays = 0; return false;
        }
        finally { clearTimeout(timeout); }
    }
    audio.addEventListener('ended', async () => {
        if (!playing) return;
        remainingPlays -= 1;
        if (remainingPlays > 0) {
            try { audio.currentTime = 0; await audio.play(); }
            catch (_) { playing = false; remainingPlays = 0; }
        } else { playing = false; }
    });
    audio.addEventListener('error', () => { playing = false; remainingPlays = 0; });
    function unlock() {
        unlocked = true;
        // No iniciar una reproducción muda que pueda pausar la prueba del usuario.
        void drainSound();
    }
    document.addEventListener('pointerdown', unlock);
    document.addEventListener('keydown', unlock);
    const supported = () => window.isSecureContext && 'Notification' in window;
    document.addEventListener('click', async () => {
        if (!document.querySelector('[data-agenda-root]') || !supported() ||
            Notification.permission !== 'default' || read('permiso-escritorio-solicitado', false)) return;
        write('permiso-escritorio-solicitado', true);
        try { await Notification.requestPermission(); }
        catch (_) { /* El rechazo no interrumpe la campana ni el sonido interno. */ }
    });
    function queueSound(key) {
        if (!soundQueue.has(key)) soundQueue.set(key, Date.now());
        void drainSound();
    }
    function safeUrl(value) {
        try { const u = new URL(value || '', location.href); return u.origin === location.origin ? u.href : null; }
        catch (_) { return null; }
    }
    async function claim(key, action) {
        const deliver = async () => {
            const now = Date.now(), ledger = read('entregas', {});
            if (ledger[key]) return true;
            if (await action() === false) return false;
            ledger[key] = now;
            write('entregas', Object.fromEntries(Object.entries(ledger)
                .filter(([, at]) => now - at < 7 * 86400000).slice(-1000)));
            return true;
        };
        // Web Locks serializa la entrega entre pestañas del mismo usuario, sin retener workers PHP.
        if (navigator.locks?.request) {
            try { return await navigator.locks.request(prefix + 'entrega', deliver); }
            catch (error) { console.warn('No fue posible coordinar la alerta entre pestañas.', error); return false; }
        }
        return deliver();
    }
    function expiredComment(item) {
        if (!(Number(item.agenda_comentario_id) > 0) || Number(item.agenda_comentario_visto_por) > 0) return false;
        const at = Number(item.agenda_horario_at || 0) * 1000;
        const created = Number(item.agenda_creado_at || 0) * 1000;
        const now = Date.now() + offset;
        return String(item.estado || '').trim().toLowerCase() === 'vencida' ||
            (at > 0 && at <= now) ||
            (!(at > 0) && created > 0 && now - created > 5 * 60000);
    }
    function deliver(item, key, title, message, visual) {
        if (expiredComment(item)) return;
        if (visual && !visualQueue.has(key)) visualQueue.set(key, {item, title, message});
        void claim('browser:' + key, () => {
            if (preferences().browser && supported() && Notification.permission === 'granted') {
                try {
                    const notification = new Notification(title, {body: message,
                        icon: new URL('favicon.ico', base).href, tag: prefix + key,
                        silent: true, requireInteraction: true});
                    const duration = Number(item.agenda_comentario_id) > 0 ? 6600 : 12000;
                    setTimeout(() => notification.close(), duration);
                    // En segundo plano no se muestra un toast del DOM: el aviso de
                    // escritorio es la entrega visible que dispara el mismo audio.
                    if (document.hidden) queueSound(key);
                    notification.onclick = () => {
                        const target = item.url || (item.id
                            ? 'index.php?controller=seguimientoVinculacion&action=detalle&id=' + Number(item.id)
                            : 'index.php?controller=agendaReunion&action=index');
                        const url = safeUrl(target);
                        window.focus(); if (url) location.href = url; notification.close();
                    };
                    return true;
                } catch (_) { /* La campana sigue disponible, incluso sin soporte del SO. */ }
            }
            return false;
        });
    }
    async function drainSound() {
        if (drainingSound || !unlocked || !preferences().sound) return;
        drainingSound = true;
        try {
            for (const [key, received] of soundQueue) {
                if (Date.now() - received > 90000) { soundQueue.delete(key); continue; }
                const played = await claim('audio:' + key, play);
                if (!played) break;
                soundQueue.delete(key);
            }
        } finally { drainingSound = false; }
    }
    async function drainVisual() {
        if (draining || document.hidden || !presenter) return;
        draining = true;
        try {
            for (const [key, entry] of visualQueue) {
                if (expiredComment(entry.item)) { visualQueue.delete(key); continue; }
                const shown = await claim('visual:' + key, () => presenter({...entry.item,
                    agenda_alerta_clave:key, titulo:entry.title, mensaje:entry.message}));
                if (!shown) break;
                visualQueue.delete(key);
            }
        } finally { draining = false; }
    }
    function tick() {
        // No seguir entregando datos antiguos si la sesión expira o falla el servidor.
        if (Date.now() - lastUpdate > 90000) return;
        const now = Date.now() + offset;
        for (const item of schedules) {
            const at = Number(item.agenda_horario_at || item.alerta_at) * 1000;
            if (!(at > 0) || expiredComment(item)) continue;
            const delta = at - now;
            const id = item.agenda_comentario_id ? 'c:' + item.agenda_comentario_id :
                (item.reunion_id ? 'r:' + item.reunion_id : 's:' + item.id);
            const minutes = Math.max(0, Math.ceil(delta / 60000));
            const phase = delta > 0 && [30, 10, 5, 1].includes(minutes) ? 'previo:' + minutes :
                (delta <= 0 && delta >= -90000 ? 'hora' : null);
            if (!phase) continue;
            const description = item.agenda_horario_mensaje || item.nombre_entidad || item.accion || 'Actividad programada';
            const hora = new Date(at).toLocaleTimeString('es-MX', {timeZone: zone, hour:'2-digit', minute:'2-digit'});
            deliver(item, id + ':' + at + ':' + phase, minutes
                ? (item.reunion_id ? 'Próxima reunión' : 'Actividad próxima') : 'Hora de la actividad',
                description + ' · ' + hora + ' · ' + (minutes ? 'En ' + minutes + (minutes === 1 ? ' minuto' : ' minutos') : 'Comienza ahora') + ' · Ver en Agenda', true);
        }
        void drainVisual();
        void drainSound();
    }
    function freshComment(item) {
        const baseline = read('inicio', null);
        const created = Number(item.agenda_creado_at) * 1000;
        const now = Date.now() + offset;
        return !expiredComment(item) && baseline !== null && created > baseline && now - created <= 5 * 60000 &&
            created <= now + 1000 && Number(item.agenda_autor_id) !== user;
    }
    window.IMPEAgendaAlerts = {
        preferences,
        onToastShown(item) {
            const key = item.agenda_alerta_clave || (item.agenda_comentario_id && !item.agenda_comentario_visto_por
                ? 'comentario:' + item.agenda_comentario_id + ':' + (item.agenda_comentario_evento || 'nuevo') : null);
            if (!key) return;
            queueSound(key);
        },
        canAnnounceComment(item) { return Boolean(item.agenda_comentario_visto_por) || freshComment(item); },
        hasScheduleSource() { return presenter !== null && lastUpdate > 0 && Date.now() - lastUpdate <= 90000; },
        suspend() { schedules = []; visualQueue.clear(); soundQueue.clear(); lastUpdate = 0; },
        setPresenter(fn) { presenter = fn; },
        ingest(data) {
            if (data.usuario_id && Number(data.usuario_id) !== user) {
                this.suspend();
                return;
            }
            lastUpdate = Date.now();
            if (data.zona_horaria) {
                try { new Intl.DateTimeFormat('es-MX', {timeZone:data.zona_horaria}); zone = data.zona_horaria; } catch (_) { /* zona previa */ }
            }
            offset = Number(data.servidor_at) > 0 ? Number(data.servidor_at) * 1000 - Date.now() : 0;
            const records = Array.isArray(data.recordatorios) ? data.recordatorios : [];
            visualQueue.clear();
            schedules = [...(Array.isArray(data.horarios) ? data.horarios : []), ...records.filter(r => r.alerta_at)];
            const now = Date.now() + offset;
            const baseline = read('inicio', null);
            if (baseline === null) write('inicio', now);
            for (const item of records) {
                if (!item.agenda_comentario_id || !item.agenda_creado_at || Number(item.agenda_autor_id) === user || item.agenda_comentario_visto_por) continue;
                // Primera conexión: no anunciar como nuevos los comentarios del historial.
                if (baseline === null || !freshComment(item)) continue;
                deliver(item, 'comentario:' + item.agenda_comentario_id, 'Nuevo comentario en la agenda',
                    item.nombre_entidad + ' · ' + item.accion, false);
            }
            tick();
        }
    };
    setInterval(tick, 1000);
})();
