/* DTMF por el RTCRtpSender real: evita abrir el widget nativo de Zadarma.
 * Instalación ANTES del SDK, desde el propio host WebRTC autenticado.
 * No intercepta audio, SDP, SIP ni las llamadas del SDK.
 */
(function (root) {
    'use strict';

    function create(view) {
        const peers = new Set();
        let installed = false;
        let lastError = '';

        function install() {
            if (installed) return true;
            const NativePeer = view?.RTCPeerConnection;
            if (typeof NativePeer !== 'function' ||
                typeof view?.Proxy !== 'function' ||
                typeof view?.Reflect?.construct !== 'function') {
                lastError = 'Este navegador no permite observar el canal WebRTC.';
                return false;
            }
            // Proxy intercepta SOLO la construcción de conexiones de ESTE
            // iframe del motor; sus propiedades y funciones permanecen nativas.
            const Wrapped = new view.Proxy(NativePeer, {
                construct(target, args, newTarget) {
                    const pc = view.Reflect.construct(target, args, newTarget);
                    peers.add(pc);
                    const remove = () => {
                        if (pc.signalingState === 'closed' ||
                            pc.connectionState === 'closed' ||
                            pc.connectionState === 'failed') peers.delete(pc);
                    };
                    pc.addEventListener?.('signalingstatechange', remove);
                    pc.addEventListener?.('connectionstatechange', remove);
                    return pc;
                }
            });
            try {
                view.RTCPeerConnection = Wrapped;
                // Algunos SDK usan un alias, sin manipular instancias.
                if (view.webkitRTCPeerConnection === NativePeer) {
                    view.webkitRTCPeerConnection = Wrapped;
                }
                installed = true;
                return true;
            } catch (_) {
                lastError = 'No se pudo preparar DTMF WebRTC.';
                return false;
            }
        }

        function senderActivo() {
            const matches = [];
            for (const pc of peers) {
                if (!pc || pc.signalingState === 'closed' ||
                    !['connected', 'completed'].includes(
                        String(pc.iceConnectionState || ''))) continue;
                const senders = typeof pc.getSenders === 'function'
                    ? pc.getSenders() : [];
                for (const sender of senders) {
                    if (sender?.track?.kind !== 'audio' ||
                        sender.track.readyState === 'ended' ||
                        !sender.dtmf?.canInsertDTMF ||
                        typeof sender.dtmf.insertDTMF !== 'function') continue;
                    matches.push(sender.dtmf);
                }
            }
            // No elegir un canal al azar si hay varias conversaciones SIP.
            return matches.length === 1 ? matches[0] : null;
        }

        function send(tones) {
            lastError = '';
            if (!/^[#*][0-9]{3,6}#$/.test(String(tones || ''))) {
                lastError = 'La combinación de transferencia no es válida.';
                return false;
            }
            if (!installed && !install()) return false;
            const sender = senderActivo();
            if (!sender) {
                lastError = 'No hay un canal de audio WebRTC único con DTMF habilitado. Verifica la conexión SIP de esta extensión.';
                return false;
            }
            try {
                // Zadarma requiere RFC2833/telephone-event: los tonos los
                // transmite el navegador al peer SIP existente.
                sender.insertDTMF(tones, 180, 100);
                return true; // Aceptado por WebRTC, NO confirmación de PBX.
            } catch (_) {
                lastError = 'El navegador no pudo enviar los tonos de transferencia.';
                return false;
            }
        }
        return Object.freeze({
            install,
            send,
            error: () => lastError,
            trackedCount: () => peers.size
        });
    }

    const bridge = create(root);
    root.IMPE_ZADARMA_WEBRTC_DTMF = bridge;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { create };
    }
})(typeof window !== 'undefined' ? window : {});
