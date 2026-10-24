/* Adaptador de la sesión WebRTC de Zadarma para el modal de Marketing.
 * Utiliza los métodos reales observados en la instancia SDK del navegador.
 * No depende de colores, selectores ni clicks programáticos sobre su interfaz.
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
        return Object.freeze({
            available: () => ringing() && supported('answer'),
            canHangup: () => talking() && supported('finishCall'),
            answer: () => perform('contestar', 'answer', [], ringing),
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
                        declinedSessions.add(session);
                        session.terminate({status_code:486,reason_phrase:'Busy Here'});
                        if(invitation){declinedPushes.add(invitation);if(declinedPushes.size>40)declinedPushes.delete(declinedPushes.values().next().value);}
                        lastError='';return true; // Enviado, no confirmación remota.
                    } catch (_) {
                        declinedSessions.delete(session);
                        lastError='No se pudo rechazar la llamada.';return false;
                    }
                }
                // El PUSH puede preceder a RTCSession: conservar el control
                // nativo, pero una sola invocación por invitación del host.
                const sent=perform('rechazar','cancel',[],ringing);
                if(sent&&invitation){declinedPushes.add(invitation);if(declinedPushes.size>40)declinedPushes.delete(declinedPushes.values().next().value);}
                return sent;
            },
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
