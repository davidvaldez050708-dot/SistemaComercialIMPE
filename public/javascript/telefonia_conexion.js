/* Conexión del host: evidencia del transporte, no inferencias del DOM.
 * Zadarma v8/sub_v=68 usa Socket.IO 2.3 push en reposo y JsSIP por llamada.
 * No modifica métodos del widget, SIP, audio, PBX ni credenciales.
 */
(function (root) {
    'use strict';
    function create(view, changed = () => {}) {
        let socket = null, initialized = false, installed = false;
        let attempts = 0, nextAttempt = 0, lastEvidence = 0;
        const watched = new WeakSet();
        const now = () => view.Date.now();
        function watch(value) {
            if (!value || watched.has(value) || typeof value.on !== 'function') return;
            watched.add(value); socket = value; initialized = false;
            const invalidate = () => { if (socket === value) {initialized = false; changed();} };
            value.on('init', () => {
                if (socket !== value) return;
                initialized = true; attempts = 0; nextAttempt = 0;
                lastEvidence = now(); changed();
            });
            value.on('disconnect', invalidate);
            value.on('connect_error', invalidate);
            value.on('error', invalidate);
            // Pong es respuesta del servidor; un timer local no acredita red.
            value.on('pong', () => { if (socket === value) {lastEvidence = now(); changed();} });
        }
        function install() {
            if (installed) return true;
            if (typeof view.io !== 'function' || typeof view.Proxy !== 'function') return false;
            const native = view.io;
            view.io = new view.Proxy(native, {
                apply(target, self, args) {
                    const value = view.Reflect.apply(target, self, args);
                    try {
                        const url = new view.URL(String(args[0]));
                        if (url.protocol === 'https:' && url.hostname === 'ws.zadarma.com' &&
                            url.pathname === '/webrtc_pushes') watch(value);
                    } catch (_) { /* Otros sockets son ajenos al teléfono. */ }
                    return value;
                }
            });
            installed = true;
            return true;
        }
        function snapshot() {
            const sdk = view.zdrmWebPhone;
            let sipRegistered = false;
            try { sipRegistered = sdk?.webPhoneUA?.isRegistered?.() === true &&
                sdk.webPhoneUA.isConnected?.() === true; } catch (_) {}
            const online = view.navigator.onLine !== false;
            const pushConnected = Boolean(online && socket?.connected && initialized &&
                now() - lastEvidence < 90000);
            return {verified: Boolean(online && (pushConnected || sipRegistered)),
                pushConnected, sipRegistered: online && sipRegistered,
                connectionMode: sipRegistered ? 'sip' : pushConnected ? 'push' : 'unconfirmed',
                online};
        }
        function recover(active = false) {
            const health = snapshot();
            if (health.verified) {attempts = 0; nextAttempt = 0; return health;}
            // El SDK conserva la llamada y su política de recuperación SIP.
            if (active || !health.online || !socket || socket.connected || now() < nextAttempt) return health;
            nextAttempt = now() + Math.min(60000, 5000 * 2 ** Math.min(attempts++, 4));
            // API pública de Socket.IO: reutiliza la instancia, no regenera el widget.
            socket.connect();
            return snapshot();
        }
        return Object.freeze({install, snapshot, recover});
    }
    root.IMPE_PHONE_CONNECTION_FACTORY = Object.freeze({create});
})(typeof window !== 'undefined' ? window : globalThis);
