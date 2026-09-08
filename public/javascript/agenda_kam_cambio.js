(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-agenda-root]');
        const detalle = document.querySelector('[data-agenda-detail-body]');

        if (!root || !detalle || Number(root.getAttribute('data-agenda-role') || 0) !== 6) {
            return;
        }

        const prepararCambio = function () {
            const formularioCambio = detalle.querySelector(
                'form[data-agenda-action="solicitarCambio"]'
            );
            const formularioConfirmar = detalle.querySelector(
                'form[data-agenda-action="confirmar"]'
            );

            if (!formularioCambio || !formularioConfirmar) {
                return;
            }

            const bloqueCambio = formularioCambio.closest('.agenda-action-box');
            const filaConfirmacion = formularioConfirmar.querySelector('.agenda-action-row');

            if (
                !bloqueCambio ||
                !filaConfirmacion ||
                bloqueCambio.getAttribute('data-kam-cambio-preparado') === '1'
            ) {
                return;
            }

            bloqueCambio.setAttribute('data-kam-cambio-preparado', '1');

            const titulo = bloqueCambio.querySelector('h6');
            const descripcion = bloqueCambio.querySelector('p');
            const motivo = formularioCambio.querySelector('[name="motivo"]');

            if (titulo) {
                titulo.textContent = 'Solicitar cambio de fecha';
            }

            if (descripcion) {
                descripcion.textContent =
                    'Si la fecha u horario propuesto no están disponibles, indica el motivo para que el Analista envíe una nueva propuesta.';
            }

            if (motivo) {
                motivo.placeholder =
                    'Ej. El horario ya está ocupado; solicita una nueva propuesta por la tarde.';
            }

            bloqueCambio.classList.add('d-none');

            const botonCambio = document.createElement('button');
            botonCambio.type = 'button';
            botonCambio.className = 'btn btn-system-light';
            botonCambio.setAttribute('data-agenda-toggle-cambio', '');
            botonCambio.setAttribute('aria-expanded', 'false');
            botonCambio.innerHTML =
                '<i class="bi bi-calendar2-x"></i> Solicitar cambio';

            botonCambio.addEventListener('click', function () {
                const abrir = bloqueCambio.classList.contains('d-none');

                bloqueCambio.classList.toggle('d-none', !abrir);
                botonCambio.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                botonCambio.innerHTML = abrir
                    ? '<i class="bi bi-x-lg"></i> Cerrar cambio'
                    : '<i class="bi bi-calendar2-x"></i> Solicitar cambio';

                if (abrir) {
                    window.setTimeout(function () {
                        motivo?.focus();
                        bloqueCambio.scrollIntoView({
                            behavior: 'smooth',
                            block: 'nearest'
                        });
                    }, 80);
                }
            });

            filaConfirmacion.prepend(botonCambio);
        };

        const observador = new MutationObserver(prepararCambio);
        observador.observe(detalle, {
            childList: true,
            subtree: true
        });

        prepararCambio();
    });
})();
