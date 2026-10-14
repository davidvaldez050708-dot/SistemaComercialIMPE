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
    // MISMO modal usado por el shell para recibir y contestar llamadas.
    // Nunca crear una segunda tarjeta ni mover el SDK/iframe de Zadarma.
    const panel = root.document.querySelector('[data-incoming-panel]');
    const actions = panel?.querySelector('.persistent-phone-actions');
    if (!panel || !actions) {
        root.console.warn('[Llamadas recibidas] No se encontró el modal telefónico.');
        return;
    }

    // Ajustes exclusivos del estado final; colores, proporciones y controles
    // normales siguen siendo los del CSS original del teléfono.
    const finishedCss = root.document.createElement('style');
    finishedCss.textContent = [
        // El shell original hace que «Cerrar» ocupe toda una fila de
        // su grid. Cambiar SÓLO las acciones del estado final a flex
        // evita filas vacías y da a los dos botones idénticas dimensiones.
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions {',
        '  display:flex!important;flex-direction:row;',
        '  align-items:stretch;flex-wrap:nowrap;gap:10px;',
        '}',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-register-finished],',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-retry-save],',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-close-finished] {',
        '  flex:1 1 0!important;min-width:0;min-height:48px;',
        '  width:auto!important;margin:0!important;',
        '  grid-column:auto!important;justify-self:stretch!important;',
        '  order:0!important;box-sizing:border-box;',
        '}',
        '@media (max-width:420px) {',
        '  .impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions {flex-direction:column;}',
        '  .impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-register-finished],',
        '  .impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-retry-save],',
        '  .impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-actions > [data-incoming-close-finished] {',
        '    flex:0 0 auto!important;width:100%!important;',
        '  }',
        '}',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-compact-actions,',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '[data-incoming-minimize] {display:none!important;}',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '.persistent-phone-live i {background:#8492a8;box-shadow:none;}',
        '.impe-incoming-panel[data-analyst-webrtc-finished="1"] ' +
          '[data-incoming-notice] {line-height:1.5;font-weight:700;}'
    ].join('\n');
    root.document.head.appendChild(finishedCss);

    const el = selector => panel.querySelector(selector);
    const addAction = function (selector, label, icon, primary) {
        const present = el(selector);
        if (present) return present;
        const button = root.document.createElement('button');
        button.type = 'button';
        button.className = 'persistent-phone-action' + (primary ? ' is-primary' : '');
        button.setAttribute(selector.slice(1, -1), '');
        button.hidden = true;
        // Los textos e iconos son literales del CRM, nunca datos del llamante.
        button.innerHTML = '<i class="bi bi-' + icon +
            '" aria-hidden="true"></i><span>' + label + '</span>';
        actions.appendChild(button);
        return button;
    };
    // En algunos respaldos el modal sólo tiene «Cerrar»: añadir las dos
    // acciones con las mismas clases de la UI, sin alterar la recepción.
    const resultBtn = addAction('[data-incoming-register-finished]',
        'Registrar resultado', 'journal-check', true);
    const retryBtn = addAction('[data-incoming-retry-save]',
        'Reintentar registro', 'arrow-clockwise', false);
    const closeBtn = el('[data-incoming-close-finished]');
    const status = el('[data-incoming-state]');
    const title = el('[data-incoming-title]');
    const eyebrow = el('[data-incoming-eyebrow]');
    const number = el('[data-incoming-number]');
    const clock = el('[data-incoming-timer]');
    const compactClock = el('[data-incoming-compact-timer]');
    const notice = el('[data-incoming-notice]');
    const compactActions = el('.persistent-phone-compact-actions');
    if (!closeBtn || !status || !title || !number || !clock || !notice) {
        root.console.warn('[Llamadas recibidas] Modal telefónico incompleto.');
        return;
    }

    // El shell sigue montado y dibuja cada tres segundos. Al terminar una
    // llamada interna vuelve a marcar hidden=true. Únicamente en estado
    // «finalizada» restauramos la visibilidad del MISMO modal.
    const keepFinishedVisible = function () {
        if (!finished) return;
        if (panel.hidden) panel.hidden = false;
    };
    if (typeof root.MutationObserver === 'function') {
        const observer = new root.MutationObserver(keepFinishedVisible);
        observer.observe(panel, {attributes: true, attributeFilter: ['hidden']});
    }
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
        if (!finished) {
            panel.removeAttribute('data-analyst-webrtc-finished');
            // Desde aquí ya no controlamos el modal: el shell continúa con
            // la siguiente llamada y recupera sus propios controles.
            resultBtn.hidden = true;
            retryBtn.hidden = true;
            return;
        }
        panel.setAttribute('data-analyst-webrtc-finished', '1');
        panel.classList.remove('is-compact');
        keepFinishedVisible();
        if (compactActions) compactActions.hidden = true;
        for (const selector of [
            '[data-incoming-answer]', '[data-incoming-decline]',
            '[data-incoming-mute]', '[data-incoming-hangup]',
            '[data-incoming-answer-compact]', '[data-incoming-decline-compact]',
            '[data-incoming-mute-compact]', '[data-incoming-hangup-compact]'
        ]) {
            const button = el(selector);
            if (button) button.hidden = true;
        }
        title.textContent = 'Llamada finalizada';
        if (eyebrow) eyebrow.textContent = 'RECEPCIÓN TELEFÓNICA';
        number.textContent = finished.number || 'Número desconocido';
        const duration = format(finished.duration);
        clock.textContent = duration;
        if (compactClock) compactClock.textContent = duration;
        status.textContent = finished.answered
            ? 'Llamada atendida' : 'Llamada sin respuesta';
        notice.hidden = false;
        notice.textContent = finished.saved === true
            ? 'Guardada en Llamadas recibidas · sin confirmación PBX.'
            : finished.saved === false
            ? 'No se pudo guardar. Utiliza Reintentar registro.'
            : 'Guardando en Llamadas recibidas…';
        notice.style.color = finished.saved === true ? '#16735a'
            : finished.saved === false ? '#b42318' : '#667791';
        resultBtn.hidden = !finished.answered || finished.saved !== true;
        retryBtn.hidden = finished.saved !== false;
        closeBtn.hidden = false;
        // Un único botón Cerrar cuando no hubo respuesta; dos cuando sí.
        closeBtn.classList.toggle('is-primary', !finished.answered);
        closeBtn.classList.toggle('is-secondary', finished.answered);
        keepFinishedVisible();
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
                if (compactActions) compactActions.hidden = false;
                notice.hidden = true;
                closeBtn.hidden = true;
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

    closeBtn.addEventListener('click', () => {
        if (!finished) return;
        finished = null;
        draw();
        panel.hidden = true;
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
        panel.hidden = true;
    });

    root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG = Object.freeze({
        isObserving: () => Boolean(active),
        hasFinalized: () => Boolean(finished),
        saved: () => finished?.saved ?? null
    });
})(typeof window !== 'undefined' ? window : {});