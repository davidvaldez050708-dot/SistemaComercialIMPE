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

        // La prueba manual que validamos con el webphone de Zadarma utiliza
        // formato internacional E.164 (+52...). El integrador principal quitaba
        // el signo + antes de llamar a regToCall(), haciendo que Zadarma pudiera
        // interpretar el destino como una marcación local inválida.
        if (/^52\d{10}$/.test(numero)) {
            numero = '+' + numero;
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
