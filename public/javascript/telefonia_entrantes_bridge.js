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

    function create(doc, isRinging, isTalking) {
        let lastError = '';
        let lastAction = '';
        let lastActionAt = 0;
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
            if (lastAction === action && now - lastActionAt < 1200) {
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
                lastAction = action;
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
            decline: () => perform('rechazar', 'cancel', [], ringing),
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
