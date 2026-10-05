document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-aliados-module]');
    const modalElement = document.getElementById('modalAliadoSeguimiento');

    if (!root || !modalElement || !window.bootstrap) {
        return;
    }

    const baseUrl = String(root.dataset.baseUrl || '');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const context = modalElement.querySelector('[data-aliado-followup-context]');
    const loading = modalElement.querySelector('[data-aliado-followup-loading]');
    const empty = modalElement.querySelector('[data-aliado-followup-empty]');
    const content = modalElement.querySelector('[data-aliado-followup-content]');
    const form = modalElement.querySelector('[data-aliado-followup-form]');
    const saveButton = modalElement.querySelector('[data-aliado-followup-save]');
    const statusBox = modalElement.querySelector('[data-aliado-followup-status]');
    const stateSelect = document.getElementById('aliado_seguimiento_estado');
    const noteInput = document.getElementById('aliado_seguimiento_nota');
    const nextInput = document.getElementById('aliado_seguimiento_proximo');
    const nextWrap = modalElement.querySelector('[data-aliado-followup-next-wrap]');
    const stateHelp = modalElement.querySelector('[data-aliado-followup-state-help]');
    const convocatoria = modalElement.querySelector('[data-aliado-followup-convocatoria]');
    const canal = modalElement.querySelector('[data-aliado-followup-canal]');
    const envio = modalElement.querySelector('[data-aliado-followup-envio]');
    const eventsRoot = modalElement.querySelector('[data-aliado-followup-events]');

    let currentAllyId = 0;
    let currentTrackingId = 0;
    let loadingRequest = false;

    const stateMeta = {
        ESPERANDO_RESPUESTA: {
            label: 'Esperando respuesta',
            help: 'La convocatoria fue compartida y estamos esperando respuesta del aliado.'
        },
        DIFUSION_CONFIRMADA: {
            label: 'Difusión confirmada',
            help: 'El aliado confirmó que recibió o difundirá la convocatoria.'
        },
        SOLICITA_INFORMACION: {
            label: 'Solicita información',
            help: 'El aliado necesita información adicional antes de continuar.'
        },
        SIN_RESPUESTA: {
            label: 'Sin respuesta',
            help: 'Aún no hubo respuesta. Puedes programar cuándo volver a escribir.'
        },
        NO_PARTICIPARA: {
            label: 'No participará',
            help: 'El aliado indicó que no participará en esta convocatoria.'
        }
    };

    const endpoint = function (action, params) {
        const url = new URL(baseUrl + 'index.php', window.location.href);
        url.searchParams.set('controller', 'aliado');
        url.searchParams.set('action', action);

        Object.entries(params || {}).forEach(function ([key, value]) {
            if (value !== undefined && value !== null && String(value) !== '') {
                url.searchParams.set(key, String(value));
            }
        });

        return url.toString();
    };

    const requestJson = async function (url, options) {
        const response = await fetch(url, options || {});
        let data = null;

        try {
            data = await response.json();
        } catch (error) {
            data = {
                ok: false,
                mensaje: 'El servidor devolvió una respuesta no válida.'
            };
        }

        if (!response.ok && data && typeof data === 'object') {
            data.codigo_http = response.status;
        }

        return data || {
            ok: false,
            mensaje: 'No fue posible completar la operación.'
        };
    };

    const formatDateTime = function (value) {
        const source = String(value || '').trim();
        if (source === '') {
            return '—';
        }

        const normalized = source.replace('T', ' ');
        const datePart = normalized.slice(0, 10).split('-');
        const timePart = normalized.length >= 16
            ? normalized.slice(11, 16)
            : '';

        if (datePart.length !== 3) {
            return source;
        }

        return datePart[2] + '/' + datePart[1] + '/' + datePart[0] +
            (timePart ? ' · ' + timePart : '');
    };

    const toDateTimeLocal = function (value) {
        const source = String(value || '').trim();
        if (source.length < 16) {
            return '';
        }

        return source.slice(0, 16).replace(' ', 'T');
    };

    const nowForInput = function () {
        const now = new Date();
        now.setSeconds(0, 0);
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        return now.toISOString().slice(0, 16);
    };

    const channelLabel = function (value) {
        const channel = String(value || '').toUpperCase();

        if (channel === 'WHATSAPP_MANUAL') {
            return 'WhatsApp manual';
        }
        if (channel === 'WHATSAPP') {
            return 'WhatsApp';
        }
        return 'Correo';
    };

    const stateLabel = function (value) {
        const key = String(value || '').toUpperCase();
        return stateMeta[key]?.label || 'Seguimiento';
    };

    const setStatus = function (message, type) {
        if (!statusBox) {
            return;
        }

        statusBox.textContent = String(message || '');
        statusBox.classList.remove('d-none', 'is-success', 'is-error');
        statusBox.classList.add(type === 'success' ? 'is-success' : 'is-error');
    };

    const reset = function () {
        currentAllyId = 0;
        currentTrackingId = 0;
        loadingRequest = false;

        loading?.classList.remove('d-none');
        empty?.classList.add('d-none');
        content?.classList.add('d-none');
        statusBox?.classList.add('d-none');
        eventsRoot && (eventsRoot.innerHTML = '');

        if (form) {
            form.reset();
            const allyInput = form.querySelector('[name="seguimiento_id"]');
            const trackingInput = form.querySelector(
                '[name="seguimiento_convocatoria_id"]'
            );
            if (allyInput) {
                allyInput.value = '';
            }
            if (trackingInput) {
                trackingInput.value = '';
            }
        }

        if (nextInput) {
            nextInput.min = nowForInput();
        }

        if (saveButton) {
            saveButton.disabled = true;
            saveButton.innerHTML =
                '<i class="bi bi-check2-circle"></i> Guardar seguimiento';
        }
    };

    const syncStateUi = function () {
        if (!stateSelect) {
            return;
        }

        const state = String(stateSelect.value || '').toUpperCase();
        const terminal =
            state === 'DIFUSION_CONFIRMADA' ||
            state === 'NO_PARTICIPARA';

        if (stateHelp) {
            stateHelp.textContent =
                stateMeta[state]?.help ||
                'Registra únicamente la situación relevante de la conversación.';
        }

        if (nextWrap) {
            nextWrap.classList.toggle('is-disabled', terminal);
        }

        if (nextInput) {
            nextInput.disabled = terminal;
            if (terminal) {
                nextInput.value = '';
            }
        }
    };

    const renderEvents = function (items) {
        if (!eventsRoot) {
            return;
        }

        const events = Array.isArray(items) ? items.slice(0, 5) : [];

        if (events.length === 0) {
            eventsRoot.innerHTML =
                '<div class="aliados-followup-events-empty">Sin cambios registrados todavía.</div>';
            return;
        }

        eventsRoot.innerHTML = events.map(function (item) {
            const next = String(item.proximo_seguimiento_at || '').trim();
            const note = String(item.nota || '').trim();
            const author = String(item.usuario_nombre || '').trim();

            return (
                '<div class="aliados-followup-event">' +
                    '<span class="aliados-followup-event-dot"></span>' +
                    '<div>' +
                        '<div class="aliados-followup-event-top">' +
                            '<strong>' + escapeHtml(stateLabel(item.estado_nuevo)) + '</strong>' +
                            '<span>' + escapeHtml(formatDateTime(item.created_at)) + '</span>' +
                        '</div>' +
                        (note !== ''
                            ? '<p>' + escapeHtml(note) + '</p>'
                            : '') +
                        (next !== ''
                            ? '<small><i class="bi bi-clock"></i> Próximo contacto: ' +
                                escapeHtml(formatDateTime(next)) + '</small>'
                            : '') +
                        (author !== ''
                            ? '<small><i class="bi bi-person"></i> ' +
                                escapeHtml(author) + '</small>'
                            : '') +
                    '</div>' +
                '</div>'
            );
        }).join('');
    };

    const escapeHtml = function (value) {
        const element = document.createElement('div');
        element.textContent = String(value || '');
        return element.innerHTML;
    };

    const renderFollowup = function (data) {
        const ally = data.aliado || {};
        const tracking = data.seguimiento || null;

        if (context) {
            context.textContent =
                String(ally.nombre_entidad || 'Institución') +
                (ally.contacto_nombre
                    ? ' · ' + String(ally.contacto_nombre)
                    : '');
        }

        loading?.classList.add('d-none');

        if (!tracking || Number(tracking.seguimiento_convocatoria_id || 0) <= 0) {
            empty?.classList.remove('d-none');
            content?.classList.add('d-none');
            if (saveButton) {
                saveButton.disabled = true;
            }
            return;
        }

        currentTrackingId = Number(tracking.seguimiento_convocatoria_id || 0);

        empty?.classList.add('d-none');
        content?.classList.remove('d-none');

        if (convocatoria) {
            convocatoria.textContent =
                String(tracking.convocatoria_titulo || 'Convocatoria');
        }
        if (canal) {
            canal.textContent = channelLabel(tracking.canal);
        }
        if (envio) {
            envio.textContent = formatDateTime(tracking.enviado_at);
        }

        const allyInput = form?.querySelector('[name="seguimiento_id"]');
        const trackingInput = form?.querySelector(
            '[name="seguimiento_convocatoria_id"]'
        );

        if (allyInput) {
            allyInput.value = String(currentAllyId);
        }
        if (trackingInput) {
            trackingInput.value = String(currentTrackingId);
        }
        if (stateSelect) {
            stateSelect.value =
                String(tracking.estado || 'ESPERANDO_RESPUESTA');
        }
        if (noteInput) {
            noteInput.value = String(tracking.nota || '');
        }
        if (nextInput) {
            nextInput.min = nowForInput();
            nextInput.value = toDateTimeLocal(
                tracking.proximo_seguimiento_at
            );
        }

        syncStateUi();
        renderEvents(tracking.eventos || []);

        if (saveButton) {
            saveButton.disabled = false;
        }
    };

    const loadFollowup = async function (allyId) {
        const id = Number(allyId || 0);
        if (id <= 0 || loadingRequest) {
            return;
        }

        reset();
        currentAllyId = id;
        loadingRequest = true;
        modal.show();

        const data = await requestJson(
            endpoint('seguimientoConvocatoria', { id: currentAllyId }),
            {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            }
        );

        loadingRequest = false;

        if (!data.ok) {
            loading?.classList.add('d-none');
            empty?.classList.remove('d-none');
            if (empty) {
                empty.innerHTML =
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<strong>No fue posible cargar el seguimiento</strong>' +
                    '<span>' + escapeHtml(data.mensaje || '') + '</span>';
            }
            return;
        }

        renderFollowup(data);
    };

    const saveFollowup = async function () {
        if (
            !form ||
            !saveButton ||
            currentAllyId <= 0 ||
            currentTrackingId <= 0
        ) {
            return;
        }

        if (!form.reportValidity()) {
            return;
        }

        const original = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML =
            '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando…';

        const data = await requestJson(
            endpoint('guardarSeguimientoConvocatoria'),
            {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );

        if (!data.ok) {
            setStatus(
                data.mensaje || 'No fue posible guardar el seguimiento.',
                'error'
            );
            saveButton.disabled = false;
            saveButton.innerHTML = original;
            return;
        }

        setStatus(
            data.mensaje || 'Seguimiento actualizado correctamente.',
            'success'
        );

        if (data.seguimiento) {
            renderFollowup({
                aliado: {
                    nombre_entidad: context?.textContent || ''
                },
                seguimiento: data.seguimiento
            });
        }

        saveButton.innerHTML =
            '<i class="bi bi-check2-circle"></i> Guardado';

        window.setTimeout(function () {
            const url = new URL(window.location.href);
            url.searchParams.delete('abrir_seguimiento');
            window.history.replaceState({}, '', url.toString());
            window.location.reload();
        }, 700);
    };

    root.addEventListener('click', function (event) {
        const button = event.target.closest('[data-aliado-followup]');
        if (!button) {
            return;
        }

        event.preventDefault();
        loadFollowup(button.dataset.aliadoFollowup);
    });

    stateSelect?.addEventListener('change', syncStateUi);
    saveButton?.addEventListener('click', saveFollowup);

    const initialAllyId = Number(
        new URL(window.location.href).searchParams.get('abrir_seguimiento') || 0
    );

    if (initialAllyId > 0) {
        window.setTimeout(function () {
            loadFollowup(initialAllyId);
        }, 120);
    }
});
