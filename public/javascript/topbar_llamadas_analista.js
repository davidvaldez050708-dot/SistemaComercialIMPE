(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-topbar-call-goal]');

        if (!root || typeof window.fetch !== 'function') {
            return;
        }

        const endpoint = String(root.dataset.endpoint || '').trim();
        const effectiveButton = root.querySelector('[data-topbar-call-effective]');
        const effectiveMenu = root.querySelector('[data-topbar-call-effective-menu]');
        const total = root.querySelector('[data-topbar-call-total]');
        const contact = root.querySelector('[data-topbar-call-contact]');
        const remaining = root.querySelector('[data-topbar-call-remaining]');
        const progress = root.querySelector('[data-topbar-call-progress]');
        const status = root.querySelector('[data-topbar-call-status]');
        let loading = false;

        const render = function (resumen) {
            const meta = Math.max(1, Number(resumen?.meta || 25));
            const efectivas = Math.max(0, Number(resumen?.verificaciones_efectivas || 0));
            const realizadas = Math.max(0, Number(resumen?.llamadas_realizadas || 0));
            const conContacto = Math.max(0, Number(resumen?.llamadas_con_contacto || 0));
            const pendientes = Math.max(
                0,
                Number(resumen?.verificaciones_pendientes_vinculo || 0)
            );
            const faltan = Math.max(0, meta - efectivas);
            const porcentaje = Math.max(0, Math.min(100, (efectivas / meta) * 100));

            if (effectiveButton) effectiveButton.textContent = String(efectivas);
            if (effectiveMenu) effectiveMenu.textContent = String(efectivas);
            if (total) total.textContent = String(realizadas);
            if (contact) contact.textContent = String(conContacto);
            if (remaining) remaining.textContent = String(faltan);
            if (progress) progress.style.width = porcentaje.toFixed(1) + '%';

            root.classList.toggle('is-complete', efectivas >= meta);

            if (status) {
                if (efectivas >= meta) {
                    status.textContent = 'Meta diaria alcanzada';
                } else if (pendientes > 0) {
                    status.textContent =
                        pendientes +
                        (pendientes === 1
                            ? ' verificación en proceso · '
                            : ' verificaciones en proceso · ') +
                        'faltan ' + faltan;
                } else {
                    status.textContent =
                        'Faltan ' + faltan +
                        (faltan === 1 ? ' institución' : ' instituciones') +
                        ' para la meta';
                }
            }
        };

        const cargar = async function () {
            if (loading || endpoint === '') {
                return;
            }

            loading = true;

            try {
                const response = await fetch(endpoint, {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                const data = await response.json();

                if (response.ok && data?.ok && data?.resumen) {
                    render(data.resumen);
                }
            } catch (error) {
                if (status) {
                    status.textContent = 'No fue posible actualizar el avance.';
                }
                console.debug('No fue posible actualizar la meta telefónica del analista.', error);
            } finally {
                loading = false;
            }
        };

        root.addEventListener('show.bs.dropdown', cargar);

        [
            'impe:telephony-call-linked',
            'impe:interaction-exact-id-ready',
            'impe:interaction-informative-saved'
        ].forEach(function (eventName) {
            document.addEventListener(eventName, function () {
                window.setTimeout(cargar, 180);
            });
        });

        cargar();
    });
})();
