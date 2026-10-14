/* Observador PASIVO del shell WebRTC del Analista.
 * Nunca ejecuta SDK, SIP, accept, reject, hangup ni modifica el motor.
 * Estado fuente: almacenamiento compartido publicado por el host existente.
 * El historial reporta observaciones del navegador, NO eventos PBX firmados.
 */
(function (root) {
    'use strict';
    const cfg = root.IMPE_ANALYST_WEBRTC_JOURNAL || {};
    const shell = root.IMPE_PHONE_SHELL_CONFIG || {};
    const userId = Number(shell.userId || 0);
    if (!cfg.enabled || !userId || !cfg.endpoint || !cfg.csrf ||
        !shell.analystIncomingResults || !root.document?.body) return;

    const stateKey = 'impe:telephony:state:' + userId;
    const crm = root.document.getElementById('impeCrmFrame');
    const overlay = root.document.createElement('section');
    overlay.className = 'persistent-phone-panel impe-incoming-panel';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-label', 'Resultado de llamada entrante');
    overlay.setAttribute('data-analyst-webrtc-finished', '1');
    overlay.style.cssText = [
        'position:fixed', 'right:18px', 'bottom:18px',
        'width:min(420px,calc(100vw - 28px))', 'max-height:90dvh',
        'overflow-y:auto', 'z-index:1300', 'display:none'
    ].join(';');
    overlay.innerHTML =
        '<header class="persistent-phone-header">' +
          '<div><span>RECEPCIÓN TELEFÓNICA</span>' +
            '<strong>Llamada finalizada</strong></div></header>' +
        '<div class="persistent-phone-number" data-webrtc-number>—</div>' +
        '<div class="persistent-phone-body">' +
          '<div class="persistent-phone-status">' +
            '<span data-webrtc-state>Llamada finalizada</span>' +
            '<strong data-webrtc-duration>00:00</strong>' +
          '</div>' +
          '<p class="impe-incoming-notice" data-webrtc-notice></p>' +
          '<div class="persistent-phone-actions">' +
            '<button type="button" class="persistent-phone-action is-primary" ' +
                'data-webrtc-result>Registrar resultado</button>' +
            '<button type="button" class="persistent-phone-action" ' +
                'data-webrtc-retry>Reintentar registro</button>' +
            '<button type="button" class="persistent-phone-action" ' +
                'data-webrtc-close>Cerrar</button>' +
          '</div>' +
        '</div>';
    root.document.body.appendChild(overlay);

    const el = selector => overlay.querySelector(selector);
    const resultBtn = el('[data-webrtc-result]');
    const retryBtn = el('[data-webrtc-retry]');
    const format = sec => {
        const n = Math.max(0, Math.floor(Number(sec) || 0));
        return String(Math.floor(n / 60)).padStart(2, '0') + ':' +
            String(n % 60).padStart(2, '0');
    };
    const validId = id => /^sdk-[a-z0-9-]{12,94}$/.test(String(id || ''));

    let active = null;
    let finished = null;
    let lastSignature = '';
    let chain = Promise.resolve();
    const postEvent = function (event, call) {
        const data = new URLSearchParams({
            csrf_token: String(cfg.csrf),
            session_id: String(call.id),
            evento: event,
            numero: String(call.number || ''),
            caller_name: String(call.name || '')
        });
        chain = chain.catch(() => false).then(async () => {
            for (let n = 0; n < 2; n++) {
                try {
                    const response = await root.fetch(String(cfg.endpoint), {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        },
                        body: data.toString()
                    });
                    const result = await response.json();
                    if (response.ok && result.ok === true) return true;
                    throw new Error('El servidor no confirmó el registro.');
                } catch (error) {
                    if (n === 1) {
                        root.console.warn(
                            '[Llamadas recibidas] Error al guardar evento WebRTC ' + event,
                            error
                        );
                    }
                }
            }
            return false;
        });
        return chain;
    };
    const draw = function () {
        overlay.style.display = finished ? 'block' : 'none';
        if (!finished) return;
        el('[data-webrtc-number]').textContent = finished.number || 'Número desconocido';
        el('[data-webrtc-duration]').textContent = format(finished.duration);
        el('[data-webrtc-state]').textContent = finished.answered
            ? 'Llamada atendida · sesión WebRTC' : 'Llamada no contestada · sesión WebRTC';
        el('[data-webrtc-notice]').textContent =
            finished.saved === true
                ? 'Guardada en Llamadas recibidas (sin confirmación PBX).'
                : finished.saved === false
                ? 'No se pudo guardar la llamada. Reintenta el registro.'
                : 'Guardando en Llamadas recibidas…';
        resultBtn.hidden = !finished.answered || finished.saved !== true;
        retryBtn.hidden = finished.saved !== false;
    };
    const saveFinished = function (call) {
        if (!finished || finished.id !== call.id) return;
        finished.saved = null;
        draw();
        // Reintentos idempotentes; el servidor conserva un único registro.
        void postEvent('ringing', call);
        if (call.answered) void postEvent('answered', call);
        postEvent(call.answered ? 'ended' : 'missed', call).then(saved => {
            if (finished?.id !== call.id) return;
            finished.saved = saved;
            draw();
        });
    };
    const end = function () {
        if (!active) return;
        const call = active;
        active = null;
        finished = {
            ...call,
            duration: call.answeredAt
                ? Math.max(0, Math.round((Date.now() - call.answeredAt) / 1000))
                : 0,
            answered: Boolean(call.answeredAt), saved: null
        };
        draw();
        saveFinished(finished);
    };
    const observe = function (state) {
        if (!state || Number(state.userId) !== userId ||
            !Number.isFinite(Number(state.updatedAt)) ||
            Date.now() - Number(state.updatedAt) > 6000) return;
        const incoming = state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            validId(state.callToken);
        if (incoming && state.active) {
            const id = String(state.callToken);
            if (active && active.id !== id) end();
            if (!active) {
                active = {
                    id, number: String(state.destination || '').replace(/[^0-9+*#]/g, '').slice(0, 40),
                    name: String(state.context?.callerName || '').slice(0, 100),
                    answeredAt: 0
                };
                finished = null;
                draw();
                void postEvent('ringing', active);
            }
            if (state.phase === 'in-progress' && !active.answeredAt) {
                active.answeredAt = Date.now();
                void postEvent('answered', active);
            }
        } else if (active && (!state.active ||
            state.phase === 'ready' || state.phase === 'incoming-finished')) {
            end();
        }
    };
    const consume = function () {
        try {
            const json = root.localStorage.getItem(stateKey) || '';
            if (!json || json === lastSignature) return;
            lastSignature = json;
            observe(JSON.parse(json));
        } catch (_) { /* almacenamiento temporalmente restringido */ }
    };
    root.addEventListener('storage', function (e) {
        if (e.key === stateKey) consume();
    });
    root.setInterval(consume, 500);
    consume();

    el('[data-webrtc-close]').addEventListener('click', () => {
        finished = null;
        draw();
    });
    retryBtn.addEventListener('click', () => {
        if (finished?.saved === false) saveFinished(finished);
    });
    resultBtn.addEventListener('click', () => {
        if (!finished?.answered || finished.saved !== true ||
            !crm || !shell.incomingResultsUrl) return;
        const url = new URL(String(shell.incomingResultsUrl), root.location.href);
        if (url.origin !== root.location.origin) return;
        url.searchParams.set('sdk_session_id', finished.id);
        crm.src = url.toString();
        finished = null;
        draw();
    });

    root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG = Object.freeze({
        isObserving: () => Boolean(active),
        hasFinalized: () => Boolean(finished),
        saved: () => finished?.saved ?? null
    });
})(typeof window !== 'undefined' ? window : {});