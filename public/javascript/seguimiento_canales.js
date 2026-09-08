(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        let temporizadorTitulos = null;

        if (!offcanvas) {
            return;
        }

        const mostrarToast = function (mensaje, esError) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);

            const instancia = new bootstrap.Toast(toast, {
                autohide: true,
                delay: esError ? 4600 : 3200
            });
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        const valorDato = function (selector) {
            const texto = String(
                offcanvas.querySelector(selector)?.textContent || ''
            ).trim();

            return texto === '—' ? '' : texto;
        };

        const normalizarTelefono = function (valor) {
            let texto = String(valor || '').trim();

            if (texto === '') {
                return '';
            }

            const tieneMas = texto.startsWith('+');
            texto = texto.replace(/[^0-9]/g, '');

            if (texto === '') {
                return '';
            }

            return tieneMas ? '+' + texto : texto;
        };

        const normalizarWhatsApp = function (valor) {
            let numero = String(valor || '').replace(/[^0-9]/g, '');

            if (numero.length === 13 && numero.startsWith('521')) {
                numero = '52' + numero.slice(3);
            }

            if (numero.length === 10) {
                numero = '52' + numero;
            }

            return numero;
        };

        const uriLlamada = function (telefono) {
            const esquema = String(window.IMPE_VOIP_SCHEME || 'tel')
                .replace(':', '')
                .trim()
                .toLowerCase();

            if (esquema === 'sip') {
                const dominio = String(window.IMPE_VOIP_SIP_DOMAIN || '').trim();

                if (dominio !== '') {
                    return 'sip:' + telefono + '@' + dominio;
                }
            }

            if (esquema === 'callto') {
                return 'callto:' + telefono;
            }

            return 'tel:' + telefono;
        };

        const abrirLlamada = function () {
            const telefono = normalizarTelefono(valorDato('[data-work-phone]'));

            if (telefono === '') {
                mostrarToast('No hay un teléfono disponible para realizar la llamada.', true);
                return;
            }

            const uri = uriLlamada(telefono);
            const usaSip = uri.startsWith('sip:');

            mostrarToast(
                usaSip
                    ? 'Abriendo la llamada en el cliente SIP configurado.'
                    : 'Abriendo la llamada en la aplicación telefónica del dispositivo.',
                false
            );

            window.location.href = uri;
        };

        const abrirWhatsApp = function () {
            const numero = normalizarWhatsApp(valorDato('[data-work-whatsapp]'));

            if (numero === '') {
                mostrarToast('No hay un WhatsApp verificado disponible.', true);
                return;
            }

            const mensaje = encodeURIComponent(
                'Hola, me comunico de parte de la Fundación Red Educativa México.'
            );
            const ventana = window.open(
                'https://wa.me/' + numero + '?text=' + mensaje,
                '_blank'
            );

            if (!ventana) {
                mostrarToast(
                    'El navegador bloqueó la ventana de WhatsApp. Permite ventanas emergentes e intenta nuevamente.',
                    true
                );
                return;
            }

            try {
                ventana.opener = null;
            } catch (error) {
                // El navegador puede impedir modificar opener; no afecta la apertura.
            }

            mostrarToast('WhatsApp abierto en una nueva ventana.', false);
        };

        const actualizarTitulos = function () {
            const botonLlamar = offcanvas.querySelector('[data-work-call-button]');
            const botonWhatsapp = offcanvas.querySelector('[data-work-whatsapp-button]');
            const telefono = valorDato('[data-work-phone]');
            const whatsapp = valorDato('[data-work-whatsapp]');

            if (botonLlamar && !botonLlamar.disabled) {
                const titulo = telefono !== ''
                    ? 'Llamar a ' + telefono
                    : 'Realizar llamada';

                if (botonLlamar.title !== titulo) {
                    botonLlamar.title = titulo;
                }
            }

            if (botonWhatsapp && !botonWhatsapp.disabled) {
                const titulo = whatsapp !== ''
                    ? 'Abrir WhatsApp con ' + whatsapp
                    : 'Abrir WhatsApp';

                if (botonWhatsapp.title !== titulo) {
                    botonWhatsapp.title = titulo;
                }
            }
        };

        const programarTitulos = function () {
            window.clearTimeout(temporizadorTitulos);
            temporizadorTitulos = window.setTimeout(actualizarTitulos, 80);
        };

        document.addEventListener('click', function (event) {
            const botonLlamar = event.target.closest('[data-work-call-button]');
            const botonWhatsapp = event.target.closest('[data-work-whatsapp-button]');

            if (!botonLlamar && !botonWhatsapp) {
                return;
            }

            if ((botonLlamar && botonLlamar.disabled) ||
                (botonWhatsapp && botonWhatsapp.disabled)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (botonLlamar) {
                abrirLlamada();
                return;
            }

            abrirWhatsApp();
        }, true);

        if (window.MutationObserver) {
            const observador = new MutationObserver(programarTitulos);
            observador.observe(offcanvas, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['disabled', 'title']
            });
        }

        actualizarTitulos();
    });
})();
