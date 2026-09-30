(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-analyst-report-modes]');
        const form = document.querySelector('[data-report-form]');
        const inputType = form?.querySelector('[data-report-type-input]');

        if (!root || !form || !inputType) {
            return;
        }

        const buttons = Array.from(root.querySelectorAll('[data-report-mode]'));
        const help = form.querySelector('[data-report-mode-help]');
        const institution = form.querySelector('#reporte_institucion');
        const periodShortcuts = form.querySelector('[data-report-period-shortcuts]');
        const startDate = form.querySelector('#reporte_fecha_inicial');
        const endDate = form.querySelector('#reporte_fecha_final');
        const state = form.querySelector('#reporte_estado');
        const municipality = form.querySelector('#reporte_municipio');
        const institutionTrigger = form.querySelector('[data-report-institution-picker]');
        const institutionLabel = form.querySelector('[data-report-institution-label]');
        const institutionHint = form.querySelector('[data-report-institution-hint]');
        const institutionDialog = form.closest('section')?.querySelector('[data-report-institution-dialog]');
        const institutionSearch = institutionDialog?.querySelector('[data-report-institution-search]');
        const institutionList = institutionDialog?.querySelector('[data-report-institution-list]');
        const institutionStatus = institutionDialog?.querySelector('[data-report-institution-status]');
        const institutionEmpty = institutionDialog?.querySelector('[data-report-institution-empty]');
        const institutionContext = institutionDialog?.querySelector('[data-report-institution-context]');
        const institutionSummary = institutionDialog?.querySelector('[data-report-institution-page-summary]');
        const institutionPrev = institutionDialog?.querySelector('[data-report-institution-prev]');
        const institutionNext = institutionDialog?.querySelector('[data-report-institution-next]');
        const institutionClose = institutionDialog?.querySelector('[data-report-institution-close]');
        const clearFiltersButton = form.querySelector('[data-report-clear-filters]');
        let institutionPage = 1;
        let institutionPages = 0;
        let institutionRequest = 0;
        let institutionSearchTimer = null;
        const labels = {
            actividad: {
                help: 'El periodo se aplica a tus actividades e interacciones. Puedes filtrar también por canal.',
                fields: ['periodo', 'territorio', 'actividad']
            },
            cartera: {
                help: 'Consulta el estado actual de tu cartera. Puedes acotar por estado, municipio, etapa, canal o inactividad.',
                fields: ['territorio', 'municipio', 'estatus', 'actividad', 'inactividad']
            },
            institucion: {
                help: 'Selecciona una institución para consultar su expediente ejecutivo de seguimiento.',
                fields: ['territorio', 'municipio', 'institucion']
            }
        };

        const neutralValue = function (control) {
            if (!control) return;
            if (control.matches?.('[data-report-institution-input]')) {
                control.value = '0';
                control.dataset.reportInstitutionId = '0';
                if (institutionLabel) {
                    institutionLabel.textContent = 'Seleccionar institución';
                }
                institutionTrigger?.classList.remove('has-selection', 'is-invalid');
            } else if (control.tagName === 'SELECT') {
                const preferred = Array.from(control.options).find(function (option) {
                    return option.value === '0' || option.value === '';
                });
                if (preferred) control.value = preferred.value;
            } else if (control.type === 'date') {
                control.value = '';
            }
        };

        const applyMode = function (mode, resetHidden) {
            if (!labels[mode]) {
                mode = 'cartera';
            }

            inputType.value = mode;
            const visibleFields = new Set(labels[mode].fields);

            const clearCurrentModeFilters = function () {
            const mode = String(inputType.value || 'cartera');
            const visibleFields = new Set(labels[mode]?.fields || []);

            periodShortcuts?.querySelectorAll('[data-report-period]').forEach(function (button) {
                button.classList.remove('is-active');
            });

            form.querySelectorAll('[data-report-field]').forEach(function (wrapper) {
                const field = String(wrapper.dataset.reportField || '');
                if (!visibleFields.has(field)) {
                    return;
                }

                wrapper.querySelectorAll('input, select, textarea').forEach(function (control) {
                    if (control === inputType) {
                        return;
                    }

                    if (control.matches?.('[data-report-institution-input]')) {
                        clearInstitutionSelection();
                        return;
                    }

                    if (control.tagName === 'SELECT') {
                        const preferred = Array.from(control.options).find(function (option) {
                            return option.value === '0' || option.value === '';
                        });
                        if (preferred) {
                            control.value = preferred.value;
                        }
                        return;
                    }

                    if (control.type === 'date' || control.type === 'search' || control.type === 'text') {
                        control.value = '';
                    }
                });
            });

            clearInstitutionSelection();
            closeInstitutionPicker();

            if (institutionSearch) {
                institutionSearch.value = '';
            }
            institutionList?.replaceChildren();
            institutionEmpty?.classList.add('d-none');
            if (institutionStatus) {
                institutionStatus.textContent = '';
            }
            if (institutionSummary) {
                institutionSummary.textContent = '';
            }

            if (institutionTrigger) {
                institutionTrigger.disabled = true;
                institutionTrigger.dataset.hasInstitutions = '0';
                institutionTrigger.dataset.validatedStateId = '';
                institutionTrigger.dataset.validatedMunicipalityId = '';
            }

            if (institutionHint && mode === 'institucion') {
                institutionHint.textContent =
                    'Selecciona primero un estado para consultar las instituciones disponibles.';
            }

            if (state) {
                state.value = '0';
                state.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (mode === 'actividad') {
                if (startDate) startDate.value = '';
                if (endDate) endDate.value = '';
            }

            institutionTrigger?.classList.remove('is-invalid');
        };

        clearFiltersButton?.addEventListener('click', function (event) {
            event.preventDefault();
            clearCurrentModeFilters();
        });

        buttons.forEach(function (button) {
                const active = button.dataset.reportMode === mode;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            form.querySelectorAll('[data-report-field]').forEach(function (wrapper) {
                const field = String(wrapper.dataset.reportField || '');
                const visible = visibleFields.has(field) && field !== 'responsable';
                wrapper.classList.toggle('d-none', !visible);

                wrapper.querySelectorAll('input, select, textarea').forEach(function (control) {
                    if (!visible && resetHidden) {
                        neutralValue(control);
                    }
                    control.disabled = !visible;
                });
            });

            if (institution) {
                institution.required = false;
            }

            if (institutionTrigger) {
                const stateId = String(state?.value || '0');
                const municipalityId = String(municipality?.value || '0');
                const stateSelected = Number(stateId) > 0;
                const availabilityValidated =
                    institutionTrigger.dataset.validatedStateId === stateId &&
                    institutionTrigger.dataset.validatedMunicipalityId === municipalityId;
                const hasInstitutions =
                    availabilityValidated &&
                    institutionTrigger.dataset.hasInstitutions === '1';

                institutionTrigger.disabled =
                    mode !== 'institucion' ||
                    !stateSelected ||
                    !hasInstitutions;

                if (institutionHint && mode === 'institucion') {
                    if (!stateSelected) {
                        institutionHint.textContent =
                            'Selecciona primero un estado para consultar las instituciones disponibles.';
                    } else if (!availabilityValidated) {
                        institutionHint.textContent =
                            'Consultando instituciones disponibles…';
                    } else if (!hasInstitutions) {
                        institutionHint.textContent = Number(municipalityId) > 0
                            ? 'No tienes instituciones con seguimiento en este municipio.'
                            : 'No tienes instituciones con seguimiento en este estado.';
                    } else {
                        institutionHint.textContent =
                            'Puedes acotar por municipio o elegir una institución del estado seleccionado.';
                    }
                }
            }

            const startLabel = form.querySelector('label[for="reporte_fecha_inicial"]');
            const endLabel = form.querySelector('label[for="reporte_fecha_final"]');
            if (startLabel) startLabel.textContent = mode === 'actividad' ? 'Actividad desde' : 'Fecha inicial';
            if (endLabel) endLabel.textContent = mode === 'actividad' ? 'Actividad hasta' : 'Fecha final';
            if (help) help.textContent = labels[mode].help;
            periodShortcuts?.classList.toggle('d-none', mode !== 'actividad');

            if (
                mode === 'actividad' &&
                startDate &&
                endDate &&
                startDate.value === '' &&
                endDate.value === ''
            ) {
                const now = new Date();
                const today = [
                    now.getFullYear(),
                    String(now.getMonth() + 1).padStart(2, '0'),
                    String(now.getDate()).padStart(2, '0')
                ].join('-');
                startDate.value = today;
                endDate.value = today;
            }
        };

        const clearInstitutionSelection = function () {
            if (institution) {
                institution.value = '0';
                institution.dataset.reportInstitutionId = '0';
            }
            if (institutionLabel) {
                institutionLabel.textContent = 'Seleccionar institución';
            }
            institutionTrigger?.classList.remove('has-selection', 'is-invalid');
        };

        const closeInstitutionPicker = function () {
            if (!institutionDialog) return;
            institutionDialog.classList.add('d-none');
            institutionDialog.setAttribute('aria-hidden', 'true');
        };

        const buildInstitutionUrl = function (page) {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('controller', 'seguimientoVinculacionReporte');
            url.searchParams.set('action', 'institucionesSelector');
            url.searchParams.set('estado_id', String(state?.value || '0'));
            url.searchParams.set('municipio_id', String(municipality?.value || '0'));
            url.searchParams.set('pagina', String(page || 1));
            const query = String(institutionSearch?.value || '').trim();
            if (query !== '') {
                url.searchParams.set('q', query);
            }
            return url.toString();
        };

        const renderInstitutions = function (data) {
            if (!institutionList || !institutionStatus || !institutionEmpty) return;

            const items = Array.isArray(data.instituciones) ? data.instituciones : [];
            institutionList.replaceChildren();
            institutionPage = Number(data.pagina || 1);
            institutionPages = Number(data.paginas || 0);

            const stateLabel = state?.selectedOptions?.[0]?.textContent?.trim() || '';
            const municipalityLabel = Number(municipality?.value || 0) > 0
                ? (municipality?.selectedOptions?.[0]?.textContent?.trim() || '')
                : '';

            if (institutionContext) {
                institutionContext.textContent = municipalityLabel
                    ? stateLabel + ' · ' + municipalityLabel
                    : stateLabel + ' · Todos los municipios';
            }

            institutionStatus.textContent = Number(data.total || 0) > 0
                ? Number(data.total || 0) + (Number(data.total || 0) === 1 ? ' institución disponible' : ' instituciones disponibles')
                : '';
            institutionEmpty.classList.toggle('d-none', items.length > 0);

            items.forEach(function (item) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'report-institution-option';
                button.dataset.institutionId = String(item.id || 0);

                const main = document.createElement('span');
                main.className = 'report-institution-option-main';

                const title = document.createElement('strong');
                title.textContent = String(item.nombre || 'Institución');

                const meta = document.createElement('small');
                const place = [item.municipio, item.estado].filter(Boolean).join(', ');
                meta.textContent = place !== '' ? place : 'Ubicación no disponible';

                main.append(title, meta);

                const badge = document.createElement('span');
                badge.className = 'report-institution-option-status';
                badge.textContent = String(item.estatus || 'Seguimiento');

                button.append(main, badge);
                button.addEventListener('click', function () {
                    if (institution) {
                        institution.value = String(item.id || 0);
                        institution.dataset.reportInstitutionId = String(item.id || 0);
                    }
                    if (institutionLabel) {
                        institutionLabel.textContent = String(item.nombre || 'Institución');
                    }
                    institutionTrigger?.classList.add('has-selection');
                    institutionTrigger?.classList.remove('is-invalid');
                    closeInstitutionPicker();
                });
                institutionList.appendChild(button);
            });

            if (institutionSummary) {
                institutionSummary.textContent = institutionPages > 1
                    ? 'Página ' + institutionPage + ' de ' + institutionPages
                    : (Number(data.total || 0) > 0 ? Number(data.total || 0) + ' resultados' : '');
            }

            if (institutionPrev) {
                institutionPrev.disabled = institutionPage <= 1;
            }
            if (institutionNext) {
                institutionNext.disabled = institutionPages === 0 || institutionPage >= institutionPages;
            }
        };

        const loadInstitutions = function (page) {
            if (!institutionDialog || Number(state?.value || 0) <= 0) return;

            const request = ++institutionRequest;
            institutionStatus.textContent = 'Cargando instituciones…';
            institutionEmpty?.classList.add('d-none');

            fetch(buildInstitutionUrl(page), {
                headers: { 'X-Requested-With': 'fetch' },
                cache: 'no-store'
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('No fue posible consultar las instituciones.');
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (request !== institutionRequest) return;
                    renderInstitutions(data || {});
                })
                .catch(function () {
                    if (request !== institutionRequest) return;
                    institutionStatus.textContent = 'No fue posible consultar las instituciones.';
                    institutionList?.replaceChildren();
                    institutionEmpty?.classList.remove('d-none');
                });
        };

        const openInstitutionPicker = function () {
            if (!institutionDialog || Number(state?.value || 0) <= 0) return;
            institutionDialog.classList.remove('d-none');
            institutionDialog.setAttribute('aria-hidden', 'false');
            institutionPage = 1;
            loadInstitutions(1);
            window.setTimeout(function () {
                institutionSearch?.focus();
            }, 50);
        };

        const formatDate = function (date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        };

        periodShortcuts?.querySelectorAll('[data-report-period]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!startDate || !endDate) {
                    return;
                }

                const now = new Date();
                const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                const end = new Date(start);
                const period = String(button.dataset.reportPeriod || '');

                if (period === 'week') {
                    const weekday = start.getDay();
                    const diffToMonday = weekday === 0 ? 6 : weekday - 1;
                    start.setDate(start.getDate() - diffToMonday);
                } else if (period === 'month') {
                    start.setDate(1);
                }

                startDate.value = formatDate(start);
                endDate.value = formatDate(end);

                periodShortcuts.querySelectorAll('[data-report-period]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
        });

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                applyMode(String(button.dataset.reportMode || ''), true);
            });
        });

        form.addEventListener('submit', function (event) {
            const mode = String(inputType.value || 'cartera');

            if (mode === 'institucion') {
                const value = String(institution?.value || '').trim();
                if (Number(state?.value || 0) <= 0) {
                    event.preventDefault();
                    state?.focus();
                    return;
                }
                if (value === '' || value === '0') {
                    event.preventDefault();
                    institutionTrigger?.classList.add('is-invalid');
                    institutionTrigger?.focus();
                    return;
                }
            }

            institutionTrigger?.classList.remove('is-invalid');
        });

        institutionTrigger?.addEventListener('click', openInstitutionPicker);
        institutionClose?.addEventListener('click', closeInstitutionPicker);
        institutionDialog?.addEventListener('click', function (event) {
            if (event.target === institutionDialog) {
                closeInstitutionPicker();
            }
        });

        institutionSearch?.addEventListener('input', function () {
            window.clearTimeout(institutionSearchTimer);
            institutionSearchTimer = window.setTimeout(function () {
                institutionPage = 1;
                loadInstitutions(1);
            }, 250);
        });

        institutionPrev?.addEventListener('click', function () {
            if (institutionPage > 1) {
                loadInstitutions(institutionPage - 1);
            }
        });
        institutionNext?.addEventListener('click', function () {
            if (institutionPage < institutionPages) {
                loadInstitutions(institutionPage + 1);
            }
        });

        state?.addEventListener('change', function () {
            clearInstitutionSelection();

            if (institutionTrigger) {
                institutionTrigger.disabled = true;
                institutionTrigger.dataset.hasInstitutions = '0';
                institutionTrigger.dataset.validatedStateId = '';
                institutionTrigger.dataset.validatedMunicipalityId = '';
            }

            if (institutionHint && String(inputType.value || '') === 'institucion') {
                institutionHint.textContent = Number(state.value || 0) > 0
                    ? 'Consultando instituciones disponibles…'
                    : 'Selecciona primero un estado para consultar las instituciones disponibles.';
            }
        });

        municipality?.addEventListener('change', function () {
            clearInstitutionSelection();

            if (institutionTrigger) {
                institutionTrigger.disabled = true;
                institutionTrigger.dataset.hasInstitutions = '0';
                institutionTrigger.dataset.validatedStateId = '';
                institutionTrigger.dataset.validatedMunicipalityId = '';
            }

            if (institutionHint && String(inputType.value || '') === 'institucion') {
                institutionHint.textContent = 'Consultando instituciones disponibles…';
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && institutionDialog && !institutionDialog.classList.contains('d-none')) {
                closeInstitutionPicker();
            }
        });

        applyMode(String(inputType.value || 'cartera'), false);
    });
})();
