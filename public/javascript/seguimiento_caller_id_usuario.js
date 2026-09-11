(function () {
    'use strict';

    const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

    if (rolId !== 4 || typeof window.fetch !== 'function') {
        return;
    }

    const fetchOriginal = window.fetch.bind(window);
    const tokenFragment = 'prueba_telefonia/api/token.php';
    let dispositivoParcheado = false;
    let intentosParche = 0;

    window.IMPE_ANALYST_CALLER_PROFILE = {
        callerId: '',
        verified: false,
        status: 'CARGANDO',
        source: 'USUARIO'
    };

    const obtenerUrl = function (input) {
        if (typeof input === 'string') {
            return input;
        }

        if (input instanceof URL) {
            return input.href;
        }

        if (typeof Request !== 'undefined' && input instanceof Request) {
            return input.url;
        }

        return String(input || '');
    };

    const guardarPerfil = function (data) {
        if (!data || data.ok !== true) {
            return;
        }

        window.IMPE_ANALYST_CALLER_PROFILE = {
            callerId: String(data.caller_id || '').trim(),
            verified: data.caller_id_verified === true,
            status: String(data.caller_id_status || '').trim() || 'NO_DISPONIBLE',
            source: String(data.caller_id_source || 'USUARIO').trim()
        };

        document.dispatchEvent(new CustomEvent('impe:caller-profile-ready', {
            detail: window.IMPE_ANALYST_CALLER_PROFILE
        }));
    };

    window.fetch = async function (input, init) {
        const response = await fetchOriginal(input, init);
        const url = obtenerUrl(input);

        if (url.includes(tokenFragment)) {
            try {
                const data = await response.clone().json();
                guardarPerfil(data);
            } catch (error) {
                console.warn('No fue posible leer el perfil telefónico del Analista.', error);
            }
        }

        return response;
    };

    const mensajeCallerId = function (perfil) {
        if (!perfil.callerId) {
            return 'Tu perfil de usuario no tiene un teléfono registrado. Actualiza tu teléfono antes de realizar llamadas institucionales.';
        }

        if (perfil.status === 'NO_DISPONIBLE') {
            return 'No fue posible validar tu teléfono con Twilio en este momento. Intenta nuevamente antes de realizar la llamada.';
        }

        return 'Tu teléfono registrado (' + perfil.callerId + ') todavía no está verificado como identificador de salida en Twilio.';
    };

    const parchearDevice = function () {
        if (dispositivoParcheado || !window.Twilio || !window.Twilio.Device) {
            return false;
        }

        const prototipo = window.Twilio.Device.prototype;
        const connectOriginal = prototipo.connect;

        if (typeof connectOriginal !== 'function') {
            return false;
        }

        prototipo.connect = function (options) {
            const opciones = options && typeof options === 'object'
                ? Object.assign({}, options)
                : {};
            const params = opciones.params && typeof opciones.params === 'object'
                ? Object.assign({}, opciones.params)
                : {};

            if (String(params.To || '').trim() !== '') {
                const perfil = window.IMPE_ANALYST_CALLER_PROFILE || {};

                if (!perfil.callerId || perfil.verified !== true) {
                    return Promise.reject(new Error(mensajeCallerId(perfil)));
                }

                params.CallerId = perfil.callerId;
                params.ImpeUserCallerId = perfil.callerId;
                opciones.params = params;
            }

            return connectOriginal.call(this, opciones);
        };

        dispositivoParcheado = true;
        return true;
    };

    const temporizadorParche = window.setInterval(function () {
        intentosParche += 1;

        if (parchearDevice() || intentosParche >= 200) {
            window.clearInterval(temporizadorParche);
        }
    }, 100);

    const asegurarLineaOrigen = function () {
        const modal = document.getElementById('modalLlamadaVinculacion');
        const numero = modal?.querySelector('[data-call-number]');

        if (!modal || !numero) {
            return;
        }

        if (String(modal.dataset.callProvider || '').toUpperCase() === 'ZADARMA') {
            return;
        }

        let origen = modal.querySelector('[data-call-origin]');
        if (!origen) {
            origen = document.createElement('div');
            origen.className = 'linkage-call-origin';
            origen.setAttribute('data-call-origin', '');
            numero.insertAdjacentElement('afterend', origen);
        }

        const perfil = window.IMPE_ANALYST_CALLER_PROFILE || {};
        const telefono = String(perfil.callerId || '').trim();
        const firma = [
            telefono,
            perfil.verified === true ? '1' : '0',
            String(perfil.status || '')
        ].join('|');

        if (origen.dataset.callerSignature === firma) {
            return;
        }

        origen.dataset.callerSignature = firma;

        if (telefono === '') {
            origen.innerHTML =
                '<i class="bi bi-person-lines-fill" aria-hidden="true"></i>' +
                '<span>Desde: teléfono de usuario no configurado</span>';
            origen.classList.add('is-warning');
            return;
        }

        origen.innerHTML =
            '<i class="bi bi-person-lines-fill" aria-hidden="true"></i>' +
            '<span>Desde: <strong></strong></span>' +
            (perfil.verified === true
                ? '<small><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Verificado</small>'
                : '<small class="is-warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Pendiente de verificar</small>');
        origen.querySelector('strong').textContent = telefono;
        origen.classList.toggle('is-warning', perfil.verified !== true);
    };

    document.addEventListener('impe:caller-profile-ready', asegurarLineaOrigen);

    document.addEventListener('DOMContentLoaded', function () {
        asegurarLineaOrigen();

        if (window.MutationObserver) {
            const observer = new MutationObserver(function () {
                asegurarLineaOrigen();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
