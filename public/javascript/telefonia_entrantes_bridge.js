/* Adaptador de la sesión WebRTC de Zadarma para el modal de Marketing.
 * Utiliza los métodos reales observados en la instancia SDK del navegador.
 * Ejecuta las acciones sin clicks programáticos ni colores del widget.
 * Al confirmar un rechazo PUSH consulta también el reposo de sus controles.
 *
 * Nota: la interfaz JavaScript zdrmWebPhone es del proveedor y puede variar
 * con futuras versiones. La aceptación se confirma por eventos PBX, no
 * por el valor devuelto al invocar una operación.
 */
(function (root) {
    'use strict';

    function create(doc, isRinging, isTalking, invitationKey) {
        let lastError = '';
        let lastAction = '';
        let lastActionAt = 0, lastActionIdentity;
        const declinedSessions = new WeakSet(), declinedPushes = new Set();
        const declineConfirmations = new Map();
        const engine = function () {
            try {return doc?.defaultView?.zdrmWebPhone || null;}
            catch (_) {return null;}
        };
        const supported = function (method) {
            const sdk = engine();
            return Boolean(sdk && typeof sdk[method] === 'function');
        };
        const perform = function (action, method, args, allowed) {
            lastError = '';
            if (!allowed()) {
                lastError = 'La llamada ya no está en el estado requerido.';
                return false;
            }
            const sdk = engine();
            if (!sdk || typeof sdk[method] !== 'function') {
                lastError = 'Zadarma no tiene disponible el control ' + action + '.';
                return false;
            }
            const now = Date.now();
            const identity=sdk.webCallSession?.id||sdk.webCallSession||invitationKey?.();
            if (lastAction === action && identity===lastActionIdentity && now - lastActionAt < 1200) {
                lastError = 'La acción ya fue enviada. Espera la confirmación.';
                return false;
            }
            try {
                // Mantener "this" como la instancia original del proveedor.
                const result = sdk[method].apply(sdk, args || []);
                // Algunas versiones retornan error_text como cadena.
                if (typeof result === 'string' && result.trim() !== '') {
                    lastError = result.trim().slice(0, 160);
                    return false;
                }
                if (result === false) {
                    lastError = 'Zadarma rechazó la operación.';
                    return false;
                }
                lastAction = action;lastActionIdentity=identity;
                lastActionAt = now;
                // True significa "se invocó", NO "llamada atendida".
                return true;
            } catch (error) {
                lastError = 'Zadarma no pudo ejecutar ' + action + '.';
                if (typeof console !== 'undefined') console.warn(lastError, error);
                return false;
            }
        };
        const talking = () => Boolean(isTalking?.());
        const ringing = () => Boolean(isRinging?.());
        const watchDecline = function (invitation, session) {
            if (!invitation) return;
            const record={session,terminal:false,idleSince:null};
            declineConfirmations.set(invitation,record);
            if (declineConfirmations.size>40) declineConfirmations.delete(declineConfirmations.keys().next().value);
            // Suscribirse ANTES de terminate: failed/ended puede ser síncrono
            // y Zadarma retira webCallSession antes del siguiente sondeo.
            if (session && typeof session.on==='function') {
                const ended=()=>{if(declineConfirmations.get(invitation)===record)record.terminal=true;};
                session.on('ended',ended);session.on('failed',ended);
            }
        };
        const declineStatus = function (invitation) {
            const record=declineConfirmations.get(invitation),sdk=engine();
            if (!record || !sdk) return {status:'pending'};
            const current=sdk.webCallSession;
            const live=Boolean(current && current.isEnded?.()!==true);
            // Un END viejo nunca libera otro diálogo, ni una llamada que
            // haya pasado a conversación mientras se procesaba el rechazo.
            if (live && (current!==record.session || current.isEstablished?.())) {
                record.idleSince=null;return {status:'superseded'};
            }
            if (current?.isEstablished?.()) {
                record.idleSince=null;return {status:'pending'};
            }
            if (record.terminal || record.session?.isEnded?.()) return {status:'ended',source:'SIP'};
            // PUSH puede cerrarse sin dejar una RTCSession observable. El
            // reposo nativo sostenido, sin timbre ni sesión, acredita sólo
            // el fin LOCAL. Un timeout o la falta de webhook no lo acreditan.
            if (!live && doc.defaultView?.navigator?.onLine!==false &&
                sdk.callState==='' && typeof doc.querySelector==='function' &&
                !doc.querySelector('#zdrm-webphone-phonenumber-input.incoming') &&
                !doc.querySelector('.zdrm-webphone-call-ico.zdrm-ringing')) {
                if(record.idleSince===null)record.idleSince=Date.now();
                if(Date.now()-record.idleSince>=2000)return {status:'ended',source:'SDK_IDLE'};
            } else record.idleSince=null;
            return {status:'pending'};
        };
        return Object.freeze({
            available: () => ringing() && supported('answer'),
            canHangup: () => talking() && supported('finishCall'),
            answer: () => perform('contestar', 'answer', [], ringing),
            // Solo el host, tras acreditar un nuevo INVITE/PUSH, libera la
            // deduplicación. Conservar el mismo PBX ID no hace al nuevo
            // timbrado un doble clic del rechazo anterior.
            allowNewInvitation: invitation => {
                declinedPushes.delete(invitation);
                declineConfirmations.delete(invitation);
                if (lastAction === 'rechazar') {
                    lastAction='';lastActionIdentity=undefined;
                }
            },
            decline: invitation => {
                const session = engine()?.webCallSession;
                if (session && declinedSessions.has(session) && (!session.isEnded?.() || declinedPushes.has(invitation))) return true;
                if ((!session || session.isEnded?.()) && invitation && declinedPushes.has(invitation)) return true;
                if (!ringing()) return false;
                if(session&&!session.isEnded?.()&&session.direction!=='incoming'){
                    lastError='La sesión activa no corresponde a una invitación entrante.';return false;
                }
                if (session?.direction === 'incoming' && !session.isEnded?.()) {
                    if (session.isEstablished?.() || typeof session.terminate !== 'function') {
                        lastError = 'La llamada ya no se puede rechazar.';return false;
                    }
                    try {
                        watchDecline(invitation,session);
                        declinedSessions.add(session);
                        session.terminate({status_code:486,reason_phrase:'Busy Here'});
                        if(invitation){declinedPushes.add(invitation);if(declinedPushes.size>40)declinedPushes.delete(declinedPushes.values().next().value);}
                        lastError='';return true; // Enviado, no confirmación remota.
                    } catch (_) {
                        declinedSessions.delete(session);
                        declineConfirmations.delete(invitation);
                        lastError='No se pudo rechazar la llamada.';return false;
                    }
                }
                // El PUSH puede preceder a RTCSession: conservar el control
                // nativo, pero una sola invocación por invitación del host.
                // Es el mismo control que usa Rechazar del widget oficial.
                // cancel() solo inicia el registro SIP; finishCall() también
                // establece canceling para rechazar el INVITE cuando llegue.
                watchDecline(invitation,null);
                const sent=perform('rechazar','finishCall',[],ringing);
                if(!sent)declineConfirmations.delete(invitation);
                if(sent&&invitation){declinedPushes.add(invitation);if(declinedPushes.size>40)declinedPushes.delete(declinedPushes.values().next().value);}
                return sent;
            },
            declineStatus,
            hangup: () => perform('colgar', 'finishCall', [], talking),
            mute: value => perform(
                value ? 'silenciar' : 'activar micrófono',
                'micSwitch',
                [value ? 'on' : 'off'],
                talking
            ),
            error: () => lastError
        });
    }

    const factory = Object.freeze({create});
    root.IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY = factory;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = factory;
    }
})(typeof window !== 'undefined' ? window : {});
