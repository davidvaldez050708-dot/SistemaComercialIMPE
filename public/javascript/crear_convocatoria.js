document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const module = document.querySelector('[data-crear-convocatoria-module]');
    if (!module) {
        return;
    }

    const cards = Array.from(
        module.querySelectorAll('[data-convenio-notification]')
    );
    // Selector territorial: misma navegación visual que en Aliados.
    const stateCards = Array.from(module.querySelectorAll('[data-convenio-territorio-card]'));
    const stateSearch = module.querySelector('[data-convenios-territorio-buscar]');
    const stateKind = module.querySelector('[data-convenios-territorio-tipo]');
    const stateChooser = module.querySelector('[data-convenios-territorio-seleccionar]');
    const stateCount = module.querySelector('[data-convenios-territorio-contador]');
    const stateEmpty = module.querySelector('[data-convenios-territorio-vacio]');
    const filterStates = () => {
        const q = String(stateSearch?.value || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
        const type = String(stateKind?.value || '');
        let visible = 0;
        stateCards.forEach(card => {
            const name = String(card.dataset.nombre || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
            const has = card.dataset.tiene === '1';
            const show = (!q || name.includes(q)) && (!type || (type === 'con' ? has : !has));
            card.hidden = !show;
            if (show) visible++;
        });
        if (stateCount) stateCount.textContent = visible + (visible === 1 ? ' territorio' : ' territorios');
        if (stateEmpty) stateEmpty.classList.toggle('d-none', visible > 0);
    };
    stateSearch?.addEventListener('input', filterStates);
    stateKind?.addEventListener('change', filterStates);
    stateChooser?.addEventListener('change', () => {
        if (!stateChooser.value) return;
        const card = stateCards.find(el => el.querySelector('a[href$="estado_id=' + stateChooser.value + '"]'));
        const link = card?.querySelector('a[href]');
        if (link) location.assign(link.href);
    });
    const search = module.querySelector('[data-convenio-search]');
    const originFilter = module.querySelector('[data-convenio-origin-filter]');
    const stateFilter = module.querySelector('[data-convenio-state-filter]');
    const stateDropdown = module.querySelector('[data-state-dropdown]');
    const stateTrigger = module.querySelector('[data-state-trigger]');
    const stateMenu = module.querySelector('[data-state-menu]');
    const stateLabel = module.querySelector('[data-state-label]');
    const stateOptions = Array.from(
        module.querySelectorAll('[data-state-value]')
    );
    const clearFilters = module.querySelector('[data-convenio-clear-filters]');
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

    const updateClearFilters = function () {
        const hasSearch = String(search?.value || '').trim() !== '';
        const hasState = String(stateFilter?.value || '') !== '';

        clearFilters?.classList.toggle(
            'd-none',
            !(hasSearch || hasState || originFilter?.value)
        );
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
                (state === '' || state === cardState) &&
                (!originFilter?.value || originFilter.value === card.dataset.convenioOrigin);

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
        // No mostrar encabezados de municipio si todas sus filas están filtradas.
        module.querySelectorAll('[data-convenio-municipio-header]').forEach(header => {
            let row = header.nextElementSibling;
            let hasVisible = false;
            while (row && !row.matches('[data-convenio-municipio-header]')) {
                if (row.matches('[data-convenio-notification]') && !row.classList.contains('d-none')) hasVisible = true;
                row = row.nextElementSibling;
            }
            header.classList.toggle('d-none', !hasVisible);
        });

        updateClearFilters();
    };

    updateClearFilters();

    search?.addEventListener('input', applyFilters);
    originFilter?.addEventListener('change', applyFilters);

    clearFilters?.addEventListener('click', function () {
        if (originFilter) originFilter.value = '';
        if (search) {
            search.value = '';
        }

        if (stateFilter) {
            stateFilter.value = '';
        }

        if (stateLabel) {
            stateLabel.textContent = 'Todos los estados';
        }

        stateOptions.forEach(function (item) {
            const selected =
                String(item.dataset.stateValue || '') === '';

            item.classList.toggle('is-selected', selected);
            item.setAttribute(
                'aria-selected',
                selected ? 'true' : 'false'
            );
        });

        closeStateDropdown();
        applyFilters();
        search?.focus();
    });

    const closeStateDropdown = function () {
        if (!stateDropdown || !stateTrigger) {
            return;
        }

        stateDropdown.classList.remove('is-open');
        stateTrigger.setAttribute('aria-expanded', 'false');
    };

    stateTrigger?.addEventListener('click', function () {
        const opening = !stateDropdown?.classList.contains('is-open');

        if (opening) {
            stateDropdown?.classList.add('is-open');
            stateTrigger.setAttribute('aria-expanded', 'true');
        } else {
            closeStateDropdown();
        }
    });

    stateOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            const value = String(option.dataset.stateValue || '');

            if (stateFilter) {
                stateFilter.value = value;
            }

            if (stateLabel) {
                stateLabel.textContent = option.textContent.trim();
            }

            stateOptions.forEach(function (item) {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false'
                );
            });

            closeStateDropdown();
            applyFilters();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            stateDropdown &&
            !stateDropdown.contains(event.target)
        ) {
            closeStateDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeStateDropdown();
        }
    });

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

    const setAttentionStatus = function (status, card) {
        const done = String(status || '') === 'con_convocatoria';
        const label = done ? 'Con convocatoria' : 'Pendiente';

        const modalBadge = modalElement.querySelector(
            '[data-convenio-modal-attention]'
        );

        if (modalBadge) {
            modalBadge.textContent = label;
            modalBadge.classList.toggle('is-done', done);
            modalBadge.classList.toggle('is-pending', !done);
        }

        const cardBadge = card?.querySelector(
            '[data-convenio-card-attention]'
        );

        if (cardBadge) {
            cardBadge.textContent = label;
            cardBadge.classList.toggle('is-done', done);
            cardBadge.classList.toggle('is-pending', !done);
        }

        if (card) {
            card.dataset.convenioAttention = done
                ? 'con_convocatoria'
                : 'pendiente';
        }
    };

    cards.forEach(function (card) {
        // Cada fila conserva accesibilidad con teclado, igual que las tarjetas enlazadas.
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
        });
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

            setAttentionStatus(
                data.estado_atencion || card.dataset.convenioAttention,
                card
            );

            const createLink = modalElement.querySelector(
                '[data-convenio-create-link]'
            );

            if (createLink) {
                const stateId = Number(data.estado_id || 0);
                const stateName = String(data.estado || '').trim();
                const seguimientoId = Number(data.seguimiento_id || 0);
                const convenioNombre = String(data.institucion || '').trim();
                const params = new URLSearchParams();

                if (stateId > 0) {
                    params.set('territorio_id', String(stateId));
                }

                if (stateName !== '') {
                    params.set('estado_nombre', stateName);
                }

                params.set('desde_convenio', '1');
                params.set('abrir_nueva_convocatoria', '1');

                if (convenioNombre !== '') {
                    params.set('convenio_nombre', convenioNombre);
                }

                if (seguimientoId > 0) {
                    params.set('seguimiento_id', String(seguimientoId));
                }

                createLink.href = baseUrl + '&' + params.toString();
                createLink.hidden = data.origen_tipo === 'manual';
                const help = modalElement.querySelector('.crear-convocatoria-modal-help span');
                if (help) help.textContent = data.origen_tipo === 'manual'
                    ? 'Crear convocatoria requiere un expediente de Vinculación. El enlace de Registro se gestiona de forma independiente.'
                    : 'La convocatoria se abrirá en el estado correspondiente al aliado.';
            }

            setText('[data-convenio-fecha-label]', data.origen_tipo === 'manual' ? 'Fecha de relación' : 'Fecha del documento');
            setText('[data-convenio-origin-label]', data.origen_tipo === 'manual' ? 'Relación manual · Sin formalización documental' : 'Vinculación');
            document.dispatchEvent(new CustomEvent('impe:convenio-abierto', {detail:data}));
            modal.show();
        });
    });


});
