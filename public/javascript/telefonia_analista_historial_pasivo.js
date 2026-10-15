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
          '[data-incoming-notice] {line-height:1.5;font-weight:700;}',
        // El aviso inferior del shell pertenece al flujo PBX histórico.
        // Una llamada SDK ya usa el modal principal para registrar y nunca
        // debe mostrar dos acciones simultáneas, incluso si el shell
        // anterior vuelve a quitar el atributo hidden durante su render.
        'body.impe-sdk-result-in-modal [data-analyst-call-result] {',
        '  display:none!important;',
        '}',
        '.impe-incoming-transfer-context {',
        '  padding:12px 14px; border:1px solid #d9e4f6;',
        '  border-radius:12px;background:#f3f6fd;margin:8px 0;',
        '  font-size:13px;line-height:1.45;color:#30456c;',
        '}',
        '.impe-incoming-transfer-context strong {display:block;color:#20356b;font-size:14px;}',
        '.impe-incoming-transfer-context small {display:block;color:#586b8e;margin-top:3px;}'
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
    const transferContext = root.document.createElement('div');
    transferContext.className = 'impe-incoming-transfer-context';
    transferContext.hidden = true;
    number?.parentElement?.insertAdjacentElement?.('afterend',transferContext);
    if (!closeBtn || !status || !title || !number || !clock || !notice) {
        root.console.warn('[Llamadas recibidas] Modal telefónico incompleto.');
        return;
    }

    // El shell sigue montado y dibuja cada tres segundos. Al terminar una
    // llamada interna vuelve a marcar hidden=true. Únicamente en estado
    // «finalizada» restauramos la visibilidad del MISMO modal.
    const keepFinishedVisible = function () {
        if (!finished || finished.registrationOpen) return;
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
    const contextKey = id => 'impe:crm:transfer-context:' + userId + ':' + id;
    const fetchReceptionContext = async (id, phone, attempt = 0) => {
        if (!id || !phone || !root.fetch || active?.id !== id) return;
        // Al empezar el timbrado el traspaso de Tania puede estar aún
        // validándose. Un GET único llegaba demasiado pronto y dejaba el
        // expediente ausente durante toda la llamada. Consultar por poco
        // tiempo, exclusivamente mientras exista esta misma sesión SDK.
        const retry = () => {
            if (attempt < 8 && active?.id === id) {
                root.setTimeout?.(() => {
                    void fetchReceptionContext(id, phone, attempt + 1);
                }, 800);
            }
        };
        try {
            const url = new URL('index.php?controller=telefonia&action=contextoTransferencia',
                root.location.href);
            url.searchParams.set('numero',phone);
            const response = await root.fetch(url.toString(), {
                credentials:'same-origin',cache:'no-store',
                headers:{Accept:'application/json'}
            });
            const data = await response.json();
            if (active?.id !== id) return;
            if (!response.ok || !data.ok || !data.contexto) {
                retry();
                return;
            }
            const found = data.contexto;
            if (!Number.isInteger(Number(found.seguimiento_id)) ||
                Number(found.seguimiento_id) <= 0) return;
            transferContext.replaceChildren?.();
            const heading = root.document.createElement('strong');
            heading.textContent = String(found.institucion || '') + ' · ' +
                String(found.estado || '');
            const detail = root.document.createElement('small');
            detail.textContent = 'Identificada por ' +
                String(found.confirmado_por || 'Recepción') +
                '. Confirma con la persona que llama.';
            transferContext.appendChild(heading);
            transferContext.appendChild(detail);
            transferContext.hidden = false;
            try {
                root.sessionStorage?.setItem(contextKey(id),JSON.stringify({
                    call_id:id,seguimiento_id:Number(found.seguimiento_id),
                    institucion:String(found.institucion || ''),
                    estado:String(found.estado || ''),saved_at:Date.now()
                }));
            } catch (_) {}
        } catch (_) {
            // Un servicio de contexto no puede interrumpir el audio WebRTC.
            retry();
        }
    };
    const postEvent = function (event, call) {
        const data = new URLSearchParams({
            csrf_token: String(cfg.csrf),
            session_id: String(call.id),
            evento: event,
            numero: String(call.number || ''),
            caller_name: String(call.name || ''),
            // No se presenta como duración PBX certificada. El servidor
            // sólo admitirá un valor compatible con sus marcas de tiempo.
            duracion_observada_segundos: event === 'ended'
                ? String(Math.max(0, Math.floor(Number(call.duration || 0))))
                : '0'
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
                    if (response.ok && result.ok === true)
                        return result.interna === true ? 'INTERNA' : true;
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
        // El observador es dueño del registro de una entrante SDK. Mantener
        // el CTA inferior del shell oculto durante timbrado/conversación,
        // al terminar y mientras el formulario de resultado está abierto.
        // No modificar el CTA legado de una llamada PBX no observada.
        root.document.body?.classList?.toggle(
            'impe-sdk-result-in-modal', Boolean(active || finished)
        );
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
        // Registrar resultado usa el formulario existente en el CRM.
        // Mientras esté abierto no duplicar un modal que lo tape.
        if (finished.registrationOpen) {
            panel.hidden = true;
            return;
        }
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
            ? 'Llamada atendida · tiempo observado por WebRTC'
            : 'Llamada sin respuesta';
        notice.hidden = false;
        notice.textContent = finished.isInternal
            ? 'Llamada interna guardada en historial · sin contacto institucional.'
            : finished.saved === true
            ? 'Guardada en Llamadas recibidas · sin confirmación PBX.'
            : finished.saved === false
            ? 'No se pudo guardar. Utiliza Reintentar registro.'
            : 'Guardando en Llamadas recibidas…';
        notice.style.color = finished.saved === true ? '#16735a'
            : finished.saved === false ? '#b42318' : '#667791';
        resultBtn.hidden = Boolean(finished.isInternal) ||
            !finished.answered || finished.saved !== true;
        retryBtn.hidden = finished.saved !== false;
        // Una llamada contestada requiere registrar su resultado. Las
        // perdidas e internas sí pueden cerrarse sin expediente.
        closeBtn.hidden = finished.answered && !finished.isInternal;
        closeBtn.classList.toggle('is-primary', !finished.answered || finished.isInternal);
        closeBtn.classList.toggle('is-secondary', false);
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
            finished.isInternal = saved === 'INTERNA';
            finished.saved = saved === true || saved === 'INTERNA';
            draw();
        });
    };
    const end = function (lastState) {
        if (!active) return;
        const call = active;
        active = null;
        // La transición 'incoming-finished' del motor puede llegar antes
        // de que este observador vea un 'in-progress' de localStorage.
        // Conservar el inicio y tiempo de la conversación que publicó el
        // host y no sustituirlo con 00:00 al cerrar la sesión.
        const rawAnswer = Number(lastState?.answeredAtMs || 0);
        const answerFromHost = rawAnswer > 0 &&
            rawAnswer <= Date.now() && Date.now() - rawAnswer < 86400000
            ? rawAnswer : 0;
        const answeredAt = call.answeredAt || answerFromHost;
        const confirmedLocally = Boolean(answeredAt ||
            call.sawConversation || lastState?.status === 'completed');
        const observed = Math.max(
            0,
            Number(call.lastDuration || 0),
            Number(lastState?.duration || 0),
            answeredAt ? Math.floor((Date.now() - answeredAt) / 1000) : 0
        );
        // Conservar la duración vista en el teléfono únicamente para
        // esta sesión. La bitácora del servidor sigue diferenciándola de
        // un evento PBX firmado y puede reconciliarla con sus marcas UTC.
        if (confirmedLocally && observed > 0) {
            try {
                root.sessionStorage?.setItem(
                    'impe:crm:call-summary:' + userId + ':' + call.id,
                    JSON.stringify({
                        call_id:call.id, duration:observed, saved_at:Date.now()
                    })
                );
            } catch (_) {}
        }
        finished = {
            ...call,
            answeredAt,
            duration: confirmedLocally ? observed : 0,
            answered: confirmedLocally, saved: null, isInternal: false,
            registrationOpen: false
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
                    answeredAt: 0,
                    lastDuration: 0,
                    sawConversation: false
                };
                finished = null;
                transferContext.hidden = true;
                transferContext.replaceChildren?.();
                void fetchReceptionContext(id, active.number);
                draw();
                if (compactActions) compactActions.hidden = false;
                notice.hidden = true;
                closeBtn.hidden = true;
                void postEvent('ringing', active);
            }
            if (state.phase === 'in-progress' || state.status === 'in-progress') {
                active.sawConversation = true;
                const hostAt = Number(state.answeredAtMs || 0);
                if (!active.answeredAt) {
                    active.answeredAt = hostAt > 0 && hostAt <= Date.now() &&
                        Date.now() - hostAt < 86400000 ? hostAt : Date.now();
                    void postEvent('answered', active);
                }
                active.lastDuration = Math.max(
                    active.lastDuration,
                    Number(state.duration || 0),
                    Math.floor((Date.now() - active.answeredAt) / 1000)
                );
            }
        } else if (active && (!state.active ||
            state.phase === 'ready' || state.phase === 'incoming-finished')) {
            // El motor sí publicó un estado final; aprovechar su duración.
            end(state);
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
    // El shell histórico puede redibujar el botón Cerrar al refrescar la
    // llamada finalizada; aplicar sólo el estado de acciones pendiente,
    // conservando sus estilos, posición y comportamiento WebRTC.
    root.setInterval(() => {
        if (!finished || finished.registrationOpen) return;
        const requireResult = finished.answered && !finished.isInternal;
        const shouldRegister = requireResult && finished.saved === true;
        if (closeBtn.hidden !== requireResult ||
            resultBtn.hidden === shouldRegister ||
            (shouldRegister && panel.hidden)) draw();
    }, 600);
    consume();

    closeBtn.addEventListener('click', () => {
        if (!finished || (finished.answered && !finished.isInternal)) return;
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
        finished.registrationOpen = true;
        draw();
        crm.src = url.toString();
    });
    // Sólo el formulario del CRM (mismo origen e iframe) confirma que el
    // servidor guardó el resultado; navegar no equivale a registrarlo.
    root.addEventListener('message', event => {
        if (event.origin !== root.location.origin ||
            event.source !== crm?.contentWindow ||
            event.data?.type !== 'IMPE_ANALYST_RESULT_SAVED' ||
            !finished || String(event.data.callId || '') !== finished.id) return;
        const completed = finished.id;
        finished = null;
        // El aviso inferior usa el estado privado del shell. Liberarlo por
        // su propio control después del OK del backend, sin duplicados.
        root.document.querySelector('[data-analyst-dismiss-call]')?.click();
        draw();
        panel.hidden = true;
        // Sólo tras el OK del servidor se despeja la llamada terminada
        // del motor WebRTC; así no reaparece Cerrar en el shell.
        try {
            const channel = new root.BroadcastChannel('impe-telephony-' + userId);
            channel.postMessage({
                type:'COMMAND',
                id:'result-saved-' + completed + '-' + Date.now(),
                action:'CLEAR_FINISHED',
                payload:{callToken:completed}
            });
            channel.close();
        } catch (_) {}
    });
    crm?.addEventListener('load', () => {
        if (!finished?.registrationOpen) return;
        try {
            const url = new URL(crm.contentWindow.location.href);
            if (url.origin !== root.location.origin) return;
            const selected = url.searchParams.get('sdk_session_id');
            if (url.searchParams.get('action') !== 'llamadasEntrantes' ||
                selected !== finished.id) {
                // Se abandonó el formulario sin registrarlo: recuperar
                // el botón Registrar resultado y mantener el pendiente.
                finished.registrationOpen = false;
                draw();
            }
        } catch (_) {}
    });

    root.IMPE_ANALYST_WEBRTC_JOURNAL_DEBUG = Object.freeze({
        isObserving: () => Boolean(active),
        hasFinalized: () => Boolean(finished),
        saved: () => finished?.saved ?? null
    });
})(typeof window !== 'undefined' ? window : {});