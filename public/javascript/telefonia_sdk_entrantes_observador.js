/* Observa el timbrado del widget y consulta la sesión SIP entrante existente.
 * No modifica métodos del SDK ni inventa NOTIFY_INTERNAL ni PBX call IDs.
 * Habilitar exclusivamente en el host embebido del Analista.
 */
(function (root) {
    'use strict';

    const create = function (doc, hooks) {
        const win = doc?.defaultView;
        if (!doc?.body || !win?.MutationObserver ||
            typeof hooks?.onRinging !== 'function' ||
            typeof hooks?.onStopped !== 'function') return null;

        let ringing = false;
        let missingSamples = 0;
        let stopped = false;
        let idleSamples = 0;
        let seenIncoming = false;
        let idleReported = false;
        let acceptanceRequestedUntil = 0;
        let connectedReported = false;
        let observedSession = null;
        const listenedSessions = new WeakSet();
        let domRinging = false;
        let declined = false, declinedSession = null, declinedIdle=false;
        let sdkState='';
        const sameSession=(a,b)=>Boolean(a&&b&&(a===b||(a.id&&b.id&&String(a.id)===String(b.id))));

        // El SDK actual expone una JsSIP.RTCSession. Consultar sus métodos
        // públicos: las clases del botón y del input NO confirman respuesta.
        const readSession = function () {
            try {
                const session = win.zdrmWebPhone?.webCallSession;
                return session?.direction === 'incoming' &&
                    typeof session.isEstablished === 'function' &&
                    typeof session.isEnded === 'function' ? session : null;
            } catch (_) { return null; }
        };

        const reportIdle = function (terminalEvidence = false, endedSession = null) {
            ringing = false;
            acceptanceRequestedUntil = 0;
            if (!idleReported) {
                idleReported = true;
                hooks.onIdle?.({
                    sessionEnded: Boolean(terminalEvidence || observedSession?.isEnded()),
                    pushEnded: Boolean(!observedSession && !connectedReported &&
                        win.zdrmWebPhone?.callState === '' && !read()),
                    sessionId: String(endedSession?.id || observedSession?.id || '')
                });
            }
        };
        // La instancia de Zadarma puede retirar webCallSession antes del
        // siguiente muestreo (250 ms). Conservar el END real de la RTCSession
        // mediante sus eventos públicos, sin alterar métodos del proveedor.
        const watchSession = function (session) {
            if (!session || listenedSessions.has(session) ||
                typeof session.on !== 'function') return;
            listenedSessions.add(session);
            const ended = function () {
                if (stopped || !seenIncoming ||
                    !sameSession(session, observedSession)) return;
                reportIdle(true, session);
            };
            session.on('ended', ended);
            session.on('failed', ended);
        };
        const announceSession = function (session) {
            if (seenIncoming && session?.direction === 'incoming' &&
                !session.isEnded?.() && String(session.id || '')) {
                hooks.onSessionBound?.({sessionId:String(session.id)});
            }
        };

        const read = function () {
            const input = doc.querySelector(
                '#zdrm-webphone-phonenumber-input.incoming'
            );
            const icon = doc.querySelector(
                '.zdrm-webphone-call-ico.zdrm-ringing'
            );
            if (!input || !icon) return null;
            const value = String(input.value || '').trim();
            const digits = value.replace(/[^0-9+*#]/g, '').slice(0, 40);
            const name = String(
                doc.querySelector('.zdrm-webphone-callername')?.textContent || ''
            ).replace(/[\x00-\x1f\x7f]/g, ' ').trim().slice(0, 100);
            return {caller: digits, callerName: name};
        };

        const check = function () {
            if (stopped) return;
            const domCall = read();
            const nativeState=String(win.zdrmWebPhone?.callState ?? '');
            const pushInvite=Boolean(domCall && nativeState==='incoming' && sdkState!=='incoming');
            sdkState=nativeState;
            const session = readSession();
            // INVITE real no depende de que Zadarma dibuje sus clases CSS.
            const liveInvite=session&&!session.isEnded()&&!session.isEstablished();
            const call=domCall||(liveInvite?{
                caller:String(session.remote_identity?.uri?.user||'').slice(0,40),
                callerName:String(session.remote_identity?.display_name||'').slice(0,100)
            }:null);
            const wasDomRinging = domRinging;
            domRinging = Boolean(domCall);
            if (declined) {
                if ((session && !sameSession(session,declinedSession) && !session.isEnded()) ||
                    (pushInvite && (declinedIdle || !session || session.isEnded()))) {
                    // Un nuevo PUSH incoming después de canceling acredita
                    // otro timbrado aunque no se haya observado reposo. Las
                    // clases residuales sin esa transición siguen ignoradas.
                    declined=false;idleReported=true;
                } else {
                    if (!domCall && nativeState === '') {
                        declinedIdle=true;
                        if (!session && ++idleSamples >= 8) reportIdle();
                    } else idleSamples=0;
                    if (session?.isEnded()) reportIdle();
                    return; // DOM residual no acredita una nueva invitación.
                }
            }
            // Zadarma recibe primero un PUSH: hace sonar y dibuja el widget.
            // La nueva RTCSession puede no existir hasta pulsar Contestar.
            // No dejar que una sesión anterior terminada bloquee ese timbrado.
            const newSession = session && !sameSession(session,observedSession) &&
                !session.isEnded();
            // Una sesión nueva no requiere haber observado el reposo entre llamadas.
            // En PUSH el SDK puede retirar y restaurar ringing antes del END PBX.
            const freshPush = domCall && !wasDomRinging &&
                (!observedSession || observedSession.isEnded());
            if (call && (!seenIncoming || (newSession && liveInvite && observedSession) || freshPush ||
                (idleReported && (!wasDomRinging || newSession || pushInvite)))) {
                seenIncoming = true;
                ringing = true;
                missingSamples = 0;
                idleSamples = 0;
                idleReported = false;
                connectedReported = false;
                acceptanceRequestedUntil = 0;
                observedSession = session && !session.isEnded() ? session : null;
                watchSession(observedSession);
                if (session?.id && liveInvite) call.sipSessionId=String(session.id);
                if(pushInvite)call.pushInvitation=true;
                if (hooks.onRinging(call) === false) {
                    // El host aún no puede publicarla: reintentar, no consumirla.
                    seenIncoming=false;ringing=false;observedSession=null;
                } else {
                    // PUSH puede existir antes de que Zadarma cree la RTCSession.
                    announceSession(observedSession);
                }
                return;
            }
            if (!seenIncoming || idleReported) return;
            if (session && !session.isEnded() && !observedSession) {
                observedSession = session;
                watchSession(observedSession);
                announceSession(observedSession);
            }
            // Evaluar el fin ANTES que la respuesta y antes del DOM: el SDK
            // puede conservar incoming, ringing o el reloj después del cierre.
            const tracked = observedSession;
            if (tracked?.isEnded()) {
                if (seenIncoming) reportIdle();
                return;
            }
            if (seenIncoming && !idleReported && observedSession?.isEstablished()) {
                ringing = false;
                missingSamples = 0;
                idleSamples = 0;
                acceptanceRequestedUntil = 0;
                if (!connectedReported) {
                    connectedReported = true;
                    // Algunos flujos ya pasaron a in-progress por PBX antes
                    // de que JsSIP anunciara la sesión establecida.
                    announceSession(observedSession);
                    hooks.onConnected?.({sessionId:String(observedSession?.id||'')});
                }
                return;
            }
            if (call) {
                missingSamples = 0;
                idleSamples = 0;
                return;
            }
            // Una sesión SIP todavía viva también protege la negociación
            // aunque el widget retire sus clases de timbrado.
            if (observedSession) return;
            if (ringing && ++missingSamples >= 2) {
                ringing = false;
                missingSamples = 0;
                // La retirada de una clase de timbrado por sí sola no
                // confirma que se haya perdido una llamada.
                if (Date.now() >= acceptanceRequestedUntil &&
                    !connectedReported) hooks.onStopped();
            }
            // Sin sesión legible sólo reconocer reposo sostenido. Nunca
            // inventar una respuesta usando un icono o la ausencia de incoming.
            if (!seenIncoming || idleReported) return;
            const idleButton = doc.querySelector(
                '.zdrm-webphone-call-ico.zdrm-webphone-call-btn-up'
            );
            if (!call && idleButton) {
                idleSamples += 1;
                // El SDK puede ocultar ambos controles mientras negocia SIP.
                if (Date.now() < acceptanceRequestedUntil) return;
                if (idleSamples >= 8 && typeof hooks.onIdle === 'function') {
                    reportIdle();
                }
            } else {
                idleSamples = 0;
            }
        };

        const observer = new win.MutationObserver(check);
        observer.observe(doc.body, {
            childList: true,
            attributes: true,
            subtree: true,
            attributeFilter: ['class', 'style', 'hidden']
        });
        // La actualización de un valor de input no siempre genera mutación.
        // El sondeo ligero evita depender de callbacks internos del proveedor.
        const interval = win.setInterval(check, 250);
        check();

        return Object.freeze({
            stop: function () {
                stopped = true;
                observer.disconnect();
                win.clearInterval(interval);
            },
            isRinging: function () { return ringing; },
            isConnected: function () { return connectedReported && !idleReported; },
            checkNow: check,
            markDeclined: function (session=readSession()) {
                declined=true;declinedIdle=false;declinedSession=session;ringing=false;idleSamples=0;
                // finishCall cambia incoming por canceling sin esperar al
                // siguiente muestreo. Conservar esa transición aun si el
                // nuevo PUSH llega antes de los próximos 250 ms.
                sdkState=String(win.zdrmWebPhone?.callState ?? '');
                if(declinedSession?.isEnded())reportIdle();
            },
            allowNewInvitation: function () { declined=false;declinedSession=null;idleReported=true;observedSession=null; },
            markAnswerRequested: function () {
                if (!seenIncoming) return false;
                acceptanceRequestedUntil = Date.now() + 12000;
                idleSamples = 0;
                return true;
            },
            isConnecting: function () {
                return Date.now() < acceptanceRequestedUntil;
            }
        });
    };

    const factory = Object.freeze({create});
    root.IMPE_ZADARMA_SDK_RING_OBSERVER = factory;
    if (typeof module !== 'undefined' && module.exports) module.exports = factory;
})(typeof window !== 'undefined' ? window : {});
