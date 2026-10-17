/* Observa únicamente el timbrado entrante VISTO en el SDK WebRTC.
 * No intercepta funciones privadas ni inventa NOTIFY_INTERNAL ni PBX call IDs.
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
        let connectedSamples = 0;
        let connectedReported = false;
        let connectedSince = 0;
        let connectionCandidateAt = 0;

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
            const call = read();
            if (call) {
                seenIncoming = true;
                missingSamples = 0;
                idleSamples = 0;
                idleReported = false;
                if (!ringing) {
                    ringing = true;
                    connectedSamples = 0;
                    connectedReported = false;
                    connectedSince = 0;
                    connectionCandidateAt = 0;
                    acceptanceRequestedUntil = 0;
                    hooks.onRinging(call);
                }
                return;
            }
            if (ringing && ++missingSamples >= 2) {
                ringing = false;
                missingSamples = 0;
                // Al pulsar Contestar, el SDK retira inmediatamente la
                // clase .incoming: NO equivale a llamada perdida.
                if (Date.now() >= acceptanceRequestedUntil &&
                    !connectedReported) hooks.onStopped();
            }
            // La desaparición de «ringing» no significa fin de conversación:
            // puede desaparecer justo al CONTESTAR. Esperar a que el widget
            // abandone simultáneamente el campo entrante y el control Colgar.
            // Un único cambio transitorio de clase no dispara cierre.
            if (!seenIncoming || idleReported) return;
            const incomingInput = doc.querySelector(
                '#zdrm-webphone-phonenumber-input.incoming'
            );
            const hangup = doc.querySelector(
                '.zdrm-webphone-call-btn-up'
            );
            if (!incomingInput && hangup) {
                // En algunas transferencias el control Colgar aparece
                // brevemente DURANTE la limpieza al finalizar. Dos muestras
                // de DOM no bastan para declarar una conexión retrospectiva.
                if (!connectionCandidateAt) connectionCandidateAt = Date.now();
                connectedSamples++;
                idleSamples = 0;
                if (connectedSamples >= 5 &&
                    Date.now() - connectionCandidateAt >= 1000 &&
                    !connectedReported &&
                    typeof hooks.onConnected === 'function') {
                    connectedReported = true;
                    connectedSince = Date.now();
                    acceptanceRequestedUntil = 0;
                    hooks.onConnected();
                }
            } else {
                connectedSamples = 0;
                connectionCandidateAt = 0;
            }
            if (!incomingInput && !hangup) {
                idleSamples += 1;
                // El SDK puede ocultar ambos controles mientras negocia SIP.
                if (Date.now() < acceptanceRequestedUntil) return;
                if (idleSamples >= 8 && typeof hooks.onIdle === 'function') {
                    idleReported = true;
                    hooks.onIdle();
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