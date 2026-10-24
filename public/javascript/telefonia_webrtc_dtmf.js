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
                lastError = 'Este navegador no permite utilizar el teclado del teléfono.';
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
                lastError = 'No se pudo preparar el teclado del teléfono.';
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
                lastError = 'El teclado no está disponible para esta llamada. Comprueba que el teléfono esté conectado.';
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
        // Diagnóstico de sólo lectura: no altera pistas, volumen, SDP ni SIP.
        // Ejecutar durante una llamada desde la consola del host autenticado.
        async function diagnoseAudio() {
            const calls = [];
            for (const pc of peers) {
                if (!pc || pc.signalingState === 'closed') continue;
                const inbound = [];
                try {
                    const reports = await pc.getStats();
                    reports.forEach(function (stat) {
                        if (stat.type === 'inbound-rtp' &&
                            (stat.kind === 'audio' || stat.mediaType === 'audio')) {
                            inbound.push({
                                packetsReceived: Number(stat.packetsReceived || 0),
                                bytesReceived: Number(stat.bytesReceived || 0),
                                packetsLost: Number(stat.packetsLost || 0),
                                totalAudioEnergy: stat.totalAudioEnergy ?? null,
                                audioLevel: stat.audioLevel ?? null
                            });
                        }
                    });
                } catch (_) { /* disponibilidad del navegador */ }
                calls.push({
                    connectionState: pc.connectionState,
                    iceConnectionState: pc.iceConnectionState,
                    receivers: pc.getReceivers().filter(
                        receiver => receiver.track?.kind === 'audio'
                    ).map(receiver => ({
                        state: receiver.track.readyState,
                        muted: receiver.track.muted,
                        enabled: receiver.track.enabled
                    })),
                    inbound
                });
            }
            const elements = Array.from(view.document.querySelectorAll('audio,video'))
                .map(element => ({
                    tag: element.tagName.toLowerCase(),
                    paused: element.paused,
                    muted: element.muted,
                    volume: element.volume,
                    readyState: element.readyState,
                    hasStream: Boolean(element.srcObject),
                    sinkSelected: element.sinkId ? 'custom' : 'default'
                }));
            return {peers: calls, mediaElements: elements};
        }
        return Object.freeze({
            install,
            send,
            error: () => lastError,
            trackedCount: () => peers.size,
            diagnoseAudio
        });
    }

    const bridge = create(root);
    root.IMPE_ZADARMA_WEBRTC_DTMF = bridge;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { create };
    }
})(typeof window !== 'undefined' ? window : {});
