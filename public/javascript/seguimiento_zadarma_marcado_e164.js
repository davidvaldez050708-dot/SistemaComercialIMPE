(function () {
    'use strict';

    const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

    if (rolId !== 4) {
        return;
    }

    let ultimaApiParcheada = null;

    const normalizarParaZadarma = function (valor) {
        let numero = String(valor || '')
            .trim()
            .replace(/[^0-9+*#]/g, '');

        // En las llamadas que ya validamos correctamente con la extensión 100,
        // Zadarma registró el destino mexicano en 10 dígitos. Cuando enviamos
        // 52XXXXXXXXXX devolvió status 34 y con +52XXXXXXXXXX el celular llegó a
        // timbrar, pero la PBX terminó la llamada como "no answer" aun cuando fue
        // contestada. Para México entregamos al webphone exactamente los 10
        // dígitos que ya demostraron completar llamada, audio y grabación.
        if (/^\+?52\d{10}$/.test(numero)) {
            numero = numero.replace(/^\+?52/, '');
        }

        return numero;
    };

    const parchearMarcado = function () {
        const api = window.zdrmWebPhone;

        if (!api || typeof api.regToCall !== 'function') {
            return false;
        }

        if (api === ultimaApiParcheada || api.__impeE164Patched === true) {
            ultimaApiParcheada = api;
            return true;
        }

        const regToCallOriginal = api.regToCall.bind(api);

        api.regToCall = function (numero) {
            return regToCallOriginal(normalizarParaZadarma(numero));
        };

        try {
            Object.defineProperty(api, '__impeE164Patched', {
                value: true,
                configurable: true
            });
        } catch (error) {
            api.__impeE164Patched = true;
        }

        ultimaApiParcheada = api;
        return true;
    };

    // Asegura el parche justo antes de que el manejador principal marque.
    document.addEventListener('click', function (event) {
        const boton = event.target.closest('[data-call-start]');
        const modal = boton ? boton.closest('#modalLlamadaVinculacion') : null;

        if (boton && modal && String(modal.dataset.callProvider || '') === 'ZADARMA') {
            parchearMarcado();
        }
    }, true);

    // El widget se publica de forma asíncrona, por eso también se observa
    // durante unos segundos al cargar la página.
    let intentos = 0;
    const timer = window.setInterval(function () {
        intentos += 1;

        if (parchearMarcado() || intentos >= 200) {
            window.clearInterval(timer);
        }
    }, 100);
})();
