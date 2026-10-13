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
                missingSamples = 0;
                if (!ringing) {
                    ringing = true;
                    hooks.onRinging(call);
                }
                return;
            }
            if (ringing && ++missingSamples >= 2) {
                ringing = false;
                missingSamples = 0;
                hooks.onStopped();
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
            isRinging: function () { return ringing; }
        });
    };

    const factory = Object.freeze({create});
    root.IMPE_ZADARMA_SDK_RING_OBSERVER = factory;
    if (typeof module !== 'undefined' && module.exports) module.exports = factory;
})(typeof window !== 'undefined' ? window : {});