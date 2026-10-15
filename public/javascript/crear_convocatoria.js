document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const module = document.querySelector('[data-crear-convocatoria-module]');
    if (!module) {
        return;
    }

    const cards = Array.from(
        module.querySelectorAll('[data-convenio-notification]')
    );
    const search = module.querySelector('[data-convenio-search]');
    const stateFilter = module.querySelector('[data-convenio-state-filter]');
    const results = module.querySelector('[data-convenio-results]');
    const emptyFilter = module.querySelector('[data-convenio-empty-filter]');
    const modalElement = document.getElementById(
        'modalConvenioCrearConvocatoria'
    );

    const normalize = function (value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const applyFilters = function () {
        const term = normalize(search ? search.value : '');
        const state = String(stateFilter ? stateFilter.value : '');
        let visible = 0;

        cards.forEach(function (card) {
            const text = normalize(card.dataset.convenioSearchText || '');
            const cardState = String(card.dataset.convenioState || '');
            const show =
                (term === '' || text.includes(term)) &&
                (state === '' || state === cardState);

            card.classList.toggle('d-none', !show);

            if (show) {
                visible += 1;
            }
        });

        if (results) {
            results.textContent =
                visible + (visible === 1 ? ' notificación' : ' notificaciones');
        }

        if (emptyFilter) {
            emptyFilter.classList.toggle('d-none', visible > 0);
        }
    };

    search?.addEventListener('input', applyFilters);
    stateFilter?.addEventListener('change', applyFilters);

    if (!modalElement || !window.bootstrap) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const baseUrl = String(module.dataset.convocatoriaBaseUrl || '');

    const setText = function (selector, value, fallback) {
        const element = modalElement.querySelector(selector);
        if (!element) {
            return;
        }

        const normalized = String(value || '').trim();
        element.textContent = normalized !== ''
            ? normalized
            : (fallback || '—');
    };

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            let data = {};

            try {
                data = JSON.parse(card.dataset.convenio || '{}');
            } catch (error) {
                data = {};
            }

            setText(
                '[data-convenio-modal-title]',
                data.institucion,
                'Información del convenio'
            );
            setText('[data-convenio-modal-state]', data.estado);
            setText('[data-convenio-modal-institution]', data.institucion);
            setText(
                '[data-convenio-modal-municipality]',
                data.municipio,
                'No especificado'
            );
            setText('[data-convenio-modal-received]', data.fecha_recibido);
            setText(
                '[data-convenio-modal-document-date]',
                data.fecha_documento
            );
            setText(
                '[data-convenio-modal-analyst]',
                data.analista,
                'No especificado'
            );
            const contacto = [
                data.contacto,
                data.cargo_contacto,
                data.correo_contacto
            ].filter(function (value) {
                return String(value || '').trim() !== '';
            }).join(' · ');

            setText(
                '[data-convenio-modal-contact]',
                contacto,
                'No especificado'
            );
            setText(
                '[data-convenio-modal-notes]',
                data.notas,
                'Sin notas registradas.'
            );

            const createLink = modalElement.querySelector(
                '[data-convenio-create-link]'
            );

            if (createLink) {
                const stateId = Number(data.estado_id || 0);
                createLink.href = stateId > 0
                    ? baseUrl + '&territorio_id=' + encodeURIComponent(stateId)
                    : baseUrl;
            }

            modal.show();
        });
    });
});
