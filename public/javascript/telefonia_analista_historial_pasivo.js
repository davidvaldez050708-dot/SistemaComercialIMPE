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
        // Evitar que el shell muestre otro Registrar resultado del SDK.
        'body [data-analyst-call-result] {' +
          'display:none!important;}',
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
        '.impe-incoming-transfer-context {padding:11px 13px;',
        '  border:1px solid #d8e4f2;border-radius:11px;',
        '  background:#f3f7fd;color:#334c72;font-size:12px;',
        '  line-height:1.45;margin:8px 0;}',
        '.impe-incoming-transfer-context strong {display:block;font-size:13px;}',
        '.impe-incoming-transfer-context small {display:block;color:#5b6f91;}'
    ].join('\n');
    root.document.head.appendChild(finishedCss);

    // El shell anterior persistía un aviso de esquina en sessionStorage
    // durante 24 horas. Descartarlo del estado VISUAL al abrir sesión,
    // incluso si no hay llamada activa. Los pendientes reales permanecen
    // en MySQL y siguen disponibles en Seguimiento > Llamadas recibidas.
    try {
        const legacyKey = 'impe:analyst-incoming-pending:' + userId;
        if (root.sessionStorage?.getItem(legacyKey)) {
            root.document.querySelector('[data-analyst-dismiss-call]')?.click();
            root.sessionStorage.removeItem(legacyKey);
        }
    } catch (_) { /* El navegador puede restringir sessionStorage. */ }

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
    const contextCard = root.document.createElement('div');
    contextCard.className = 'impe-incoming-transfer-context';
    contextCard.hidden = true;
    number?.parentElement?.insertAdjacentElement?.('afterend', contextCard);
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
    let pbxSession = null;
    let finished = null;
    let lastSignature = '';
    let chain = Promise.resolve();
    // La misma conversación puede pasar de sesión PBX a SDK (o al revés).
    // No borrar la institución detectada ante esa transición de estado.
    let visibleContextKey = '';
    let visibleContextPhone = '';
    const clearVisibleContext = () => {
        visibleContextKey = '';
        visibleContextPhone = '';
        contextCard.hidden = true;
        contextCard.replaceChildren?.();
    };
    const contextStorageKey = id => 'impe:crm:transfer-context:' + userId + ':' + id;
    // No consultar por una extensión (100/101) ni por caller ID aún vacío.
    const usableCallerNumber = value => {
        const digits = String(value || '').replace(/\D+/g, '');
        return digits.length >= 10 && digits.length <= 15;
    };
    const contextStillLive = id => active?.id === id || pbxSession?.id === id;
    const fetchContext = async (id, phone, attempt = 0) => {
        if (!contextStillLive(id) || !usableCallerNumber(phone)) return;
        const retry = () => {
            if (attempt < 17 && contextStillLive(id)) {
                root.setTimeout?.(() => void fetchContext(id, phone, attempt + 1), 750);
            }
        };
        try {
            const uri = new URL('index.php?controller=telefonia&action=contextoTransferencia',
                root.location.href);
            uri.searchParams.set('numero', phone);
            const response = await root.fetch(uri.toString(), {
                credentials:'same-origin', cache:'no-store',
                headers:{Accept:'application/json'}
            });
            const data = await response.json();
            if (!contextStillLive(id)) return;
            if (!response.ok || !data.ok || !data.contexto) {retry();return;}
            const ctx = data.contexto;
            if (!Number.isInteger(Number(ctx.seguimiento_id)) ||
                Number(ctx.seguimiento_id) <= 0) return;
            // No asociar a otra conversación una institución previa.
            if (visibleContextPhone && visibleContextPhone !== phone) clearVisibleContext();
            const presentationKey = [String(ctx.seguimiento_id),
                String(ctx.institucion || ''), String(ctx.estado || '')].join('|');
            if (visibleContextKey !== presentationKey) {
                contextCard.replaceChildren?.();
            }
            const heading = root.document.createElement('strong');
            heading.textContent = String(ctx.institucion || '') + ' · ' +
                String(ctx.estado || '');
            const hint = root.document.createElement('small');
            hint.textContent = 'Identificada por ' +
                String(ctx.confirmado_por || 'Recepción') +
                '. Confirma los datos con la persona que llama.';
            if (visibleContextKey !== presentationKey) {
                contextCard.appendChild(heading);
                contextCard.appendChild(hint);
                visibleContextKey = presentationKey;
                visibleContextPhone = phone;
            }
            contextCard.hidden = false;
            try {
                root.sessionStorage?.setItem(contextStorageKey(id), JSON.stringify({
                    call_id:id, seguimiento_id:Number(ctx.seguimiento_id),
                    institucion:String(ctx.institucion || ''),
                    estado:String(ctx.estado || ''), saved_at:Date.now()
                }));
            } catch (_) {}
        } catch (_) { retry(); }
    };
    const postEvent = function (event, call) {
        const data = new URLSearchParams({
            csrf_token: String(cfg.csrf),
            session_id: String(call.id),
            evento: event,
            numero: String(call.number || ''),
            caller_name: String(call.name || ''),
            duracion_observada_segundos: event === 'ended'
                ? String(Math.max(0, Math.floor(Number(call.duration || 0)))) : '0'
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
        // El estado SDK tiene un solo modal; no borrar un pendiente PBX.
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
        if (confirmedLocally && observed > 0) {
            try {
                root.sessionStorage?.setItem(
                    'impe:crm:call-summary:' + userId + ':' + call.id,
                    JSON.stringify({call_id:call.id, duration:observed, saved_at:Date.now()})
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
        // El traspaso institucional debe mostrarse incluso cuando
        // NOTIFY_INTERNAL identifica primero la sesión del Analista.
        const pbxIncoming = state.active && state.direction === 'incoming' &&
            state.context?.type === 'INCOMING' &&
            /^[A-Za-z0-9_-]{3,100}$/.test(String(state.pbxCallId || ''));
        if (pbxIncoming) {
            const id = String(state.pbxCallId);
            const phone = String(state.destination || '')
                .replace(/[^0-9+*#]/g,'').slice(0,40);
            if (!pbxSession || pbxSession.id !== id) {
                pbxSession = {id,number:''};
                // Mantener el bloque detectado mientras PBX y SDK
                // terminan de identificar esta misma conversación.
            }
            if (usableCallerNumber(phone) && pbxSession.number !== phone) {
                if (visibleContextPhone && visibleContextPhone !== phone) clearVisibleContext();
                pbxSession.number = phone;
                void fetchContext(id, phone);
            }
        } else if (!state.active) {
            pbxSession = null;
        }
        const incoming = state.direction === 'incoming' &&
            state.context?.type === 'SDK_INCOMING' &&
            validId(state.callToken);
        if (incoming && state.active) {
            const id = String(state.callToken);
            const reportedNumber = String(state.destination || '')
                .replace(/[^0-9+*#]/g, '').slice(0, 40);
            if (active && active.id !== id) end();
            if (!active) {
                active = {
                    id, number: reportedNumber,
                    name: String(state.context?.callerName || '').slice(0, 100),
                    answeredAt: 0,
                    lastDuration: 0,
                    sawConversation: false
                };
                finished = null;
                // El identificador del SDK puede aparecer después del PBX.
                // Mantener sólo el contexto del mismo teléfono, si se conoce.
                if (usableCallerNumber(reportedNumber) && visibleContextPhone &&
                    visibleContextPhone !== reportedNumber) clearVisibleContext();
                draw();
                if (usableCallerNumber(active.number)) {
                    void fetchContext(id, active.number);
                }
                if (compactActions) compactActions.hidden = false;
                notice.hidden = true;
                closeBtn.hidden = true;
                void postEvent('ringing', active);
            }
            // El SDK puede publicar el número real después del timbrado.
            // Actualizar únicamente la sesión que sigue activa.
            if (active?.id === id &&
                !usableCallerNumber(active.number) &&
                usableCallerNumber(reportedNumber)) {
                active.number = reportedNumber;
                void fetchContext(id, active.number);
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
        } else if (active && pbxIncoming) {
            // Una sesión SDK transferida puede adquirir su identificador PBX
            // durante el timbrado o después de contestar. No finalizar aquí
            // la sesión SDK: es la MISMA conversación, no una llamada nueva.
            if (state.phase === 'in-progress' || state.status === 'in-progress') {
                active.sawConversation = true;
                const hostAt = Number(state.answeredAtMs || 0);
                if (!active.answeredAt) {
                    active.answeredAt = hostAt > 0 && hostAt <= Date.now() &&
                        Date.now() - hostAt < 86400000 ? hostAt : Date.now();
                    void postEvent('answered', active);
                }
                active.lastDuration = Math.max(
                    active.lastDuration, Number(state.duration || 0),
                    Math.floor((Date.now() - active.answeredAt) / 1000)
                );
            }
        } else if (active && (!state.active ||
            state.phase === 'ready' || state.phase === 'incoming-finished')) {
            // Finalizar sólo al terminar realmente la conversación.
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