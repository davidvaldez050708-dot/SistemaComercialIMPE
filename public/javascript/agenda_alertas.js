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
        const p = read('preferencias', {});
        return {sound: p.sound !== false, browser: p.browser === true,
            lead: [5, 10, 15, 30].includes(Number(p.lead)) ? Number(p.lead) : 10};
    }
    const audio = new Audio(new URL('audio/agenda-aviso.mp3', base).href);
    audio.volume = 0.7;
    audio.preload = 'none';
    let playing = false, unlocked = false, lastSound = 0;
    let schedules = [], offset = 0, presenter = null, lastUpdate = 0, zone = 'America/Mexico_City';
    const visualQueue = new Map();
    let draining = false;
    function play() {
        if (!preferences().sound || !unlocked || playing || Date.now() - lastSound < 3000) return;
        playing = true;
        lastSound = Date.now();
        audio.currentTime = 0;
        Promise.resolve(audio.play()).catch(() => { playing = false; });
    }
    audio.addEventListener('ended', () => { playing = false; });
    audio.addEventListener('error', () => { playing = false; });
    function unlock() {
        if (unlocked) return;
        // La llamada ocurre dentro del gesto del usuario; no reproducir avisos antiguos al desbloquear.
        audio.muted = true;
        Promise.resolve(audio.play()).then(() => {
            audio.pause(); audio.currentTime = 0; audio.muted = false; unlocked = true;
        }).catch(() => { audio.muted = false; });
    }
    document.addEventListener('pointerdown', unlock);
    document.addEventListener('keydown', unlock);
    const supported = () => window.isSecureContext && 'Notification' in window;
    function safeUrl(value) {
        try { const u = new URL(value || '', location.href); return u.origin === location.origin ? u.href : null; }
        catch (_) { return null; }
    }
    async function claim(key, action) {
        const deliver = () => {
            const now = Date.now(), ledger = read('entregas', {});
            if (ledger[key]) return true;
            if (action() === false) return false;
            ledger[key] = now;
            write('entregas', Object.fromEntries(Object.entries(ledger)
                .filter(([, at]) => now - at < 7 * 86400000).slice(-1000)));
            return true;
        };
        // Web Locks serializa la entrega entre pestañas del mismo usuario, sin retener workers PHP.
        if (navigator.locks) return navigator.locks.request(prefix + 'entrega', deliver);
        return deliver();
    }
    function deliver(item, key, title, message, visual) {
        if (visual && !visualQueue.has(key)) visualQueue.set(key, {item, title, message});
        void claim(key, () => {
            play();
            if (preferences().browser && supported() && Notification.permission === 'granted') {
                try {
                    const notification = new Notification(title, {body: message,
                        icon: new URL('favicon.ico', base).href, tag: prefix + key, silent: true});
                    notification.onclick = () => {
                        const target = item.url || (item.id
                            ? 'index.php?controller=seguimientoVinculacion&action=detalle&id=' + Number(item.id)
                            : 'index.php?controller=agendaReunion&action=index');
                        const url = safeUrl(target);
                        window.focus(); if (url) location.href = url; notification.close();
                    };
                } catch (_) { /* La campana sigue disponible, incluso sin soporte del SO. */ }
            }
        });
    }
    async function drainVisual() {
        if (draining || document.hidden || !presenter) return;
        draining = true;
        try {
            for (const [key, entry] of visualQueue) {
                const shown = await claim('visual:' + key, () => presenter({...entry.item, titulo:entry.title, mensaje:entry.message}));
                if (!shown) break;
                visualQueue.delete(key);
            }
        } finally { draining = false; }
    }
    function tick() {
        // No seguir entregando datos antiguos si la sesión expira o falla el servidor.
        if (Date.now() - lastUpdate > 90000) return;
        const now = Date.now() + offset, lead = preferences().lead;
        for (const item of schedules) {
            const at = Number(item.agenda_horario_at || item.alerta_at) * 1000;
            if (!(at > 0)) continue;
            const delta = at - now;
            const id = item.agenda_comentario_id ? 'c:' + item.agenda_comentario_id :
                (item.reunion_id ? 'r:' + item.reunion_id : 's:' + item.id);
            const phase = delta > 0 && delta <= lead * 60000 ? 'previo:' + lead :
                (delta <= 0 && delta >= -90000 ? 'hora' : null);
            if (!phase) continue;
            const minutes = Math.max(0, Math.ceil(delta / 60000));
            const description = item.agenda_horario_mensaje || item.nombre_entidad || item.accion || 'Actividad programada';
            const hora = new Date(at).toLocaleTimeString('es-MX', {timeZone: zone, hour:'2-digit', minute:'2-digit'});
            deliver(item, id + ':' + at + ':' + phase, minutes
                ? (item.reunion_id ? 'Próxima reunión' : 'Actividad próxima') : 'Hora de la actividad',
                description + ' · ' + hora + ' · ' + (minutes ? 'En ' + minutes + (minutes === 1 ? ' minuto' : ' minutos') : 'Comienza ahora') + ' · Ver en Agenda', true);
        }
        void drainVisual();
    }
    function freshComment(item) {
        const baseline = read('inicio', null);
        const created = Number(item.agenda_creado_at) * 1000;
        const now = Date.now() + offset;
        return baseline !== null && created > baseline && now - created <= 5 * 60000 &&
            created <= now + 1000 && Number(item.agenda_autor_id) !== user;
    }
    window.IMPEAgendaAlerts = {
        preferences,
        canAnnounceComment(item) { return Boolean(item.agenda_comentario_visto_por) || freshComment(item); },
        suspend() { schedules = []; visualQueue.clear(); lastUpdate = 0; },
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
    function setup() {
        const panel = document.querySelector('[data-agenda-alertas]');
        if (!panel) return;
        const sound = panel.querySelector('[name="agenda_sonido"]');
        const browser = panel.querySelector('[name="agenda_navegador"]');
        const lead = panel.querySelector('[name="agenda_anticipacion"]');
        const status = panel.querySelector('[data-agenda-alertas-status]');
        function render() { const p = preferences(); sound.checked = p.sound; browser.checked = p.browser; lead.value = p.lead; }
        render();
        function save() { write('preferencias', {sound:sound.checked, browser:browser.checked, lead:Number(lead.value)}); status.textContent = 'Preferencias guardadas en este navegador.'; tick(); }
        sound.addEventListener('change', save);
        lead.addEventListener('change', save);
        browser.addEventListener('change', async () => {
            if (browser.checked) {
                if (!supported()) { browser.checked = false; save(); status.textContent = 'Este navegador necesita HTTPS y soporte de notificaciones.'; return; }
                let permission = 'denied';
                try { permission = await Notification.requestPermission(); } catch (_) { /* notificaciones internas disponibles */ }
                if (permission !== 'granted') { browser.checked = false; save(); status.textContent = 'Permiso no concedido. Las alertas internas siguen disponibles.'; return; }
            }
            save();
        });
        panel.querySelector('[data-agenda-probar-sonido]').addEventListener('click', () => {
            unlocked = true; play(); status.textContent = 'Prueba de sonido solicitada. Si no se escucha, revisa el audio del navegador.';
        });
        window.addEventListener('storage', event => { if (event.key === prefix + 'preferencias') render(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, {once:true}); else setup();
})();
