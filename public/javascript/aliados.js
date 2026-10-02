document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-aliados-module]');
    if (!root) {
        return;
    }

    const baseUrl = String(root.dataset.baseUrl || '');
    const stateSelect = document.getElementById('aliados_estado');
    const municipalitySelect = document.getElementById('aliados_municipio');
    const analystSelect = document.getElementById('aliados_analista');
    const searchInput = document.getElementById('aliados_buscar');
    const filterForm = root.querySelector('[data-aliados-filters]');
    const clearFilters = root.querySelector('[data-aliados-clear]');
    const resultCount = root.querySelector('[data-aliados-result-count]');
    const allyRows = Array.from(root.querySelectorAll('[data-aliado-row]'));
    const tableWrap = root.querySelector('[data-aliados-table-wrap]');
    const filteredEmpty = root.querySelector('[data-aliados-filter-empty]');

    const shareModalElement = document.getElementById('modalAliadoCompartir');
    const historyModalElement = document.getElementById('modalAliadoHistorial');
    const contactsModalElement = document.getElementById('modalAliadoContactos');
    const shareModal = shareModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(shareModalElement)
        : null;
    const historyModal = historyModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(historyModalElement)
        : null;
    const contactsModal = contactsModalElement && window.bootstrap
        ? bootstrap.Modal.getOrCreateInstance(contactsModalElement)
        : null;

    const shareForm = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-share-form]')
        : null;
    const shareContext = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-share-context]')
        : null;
    const convocatoriaSelect = document.getElementById('aliado_convocatoria_id');
    const asuntoInput = document.getElementById('aliado_asunto');
    const mensajeInput = document.getElementById('aliado_mensaje');
    const sendButton = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-send-button]')
        : null;
    const sendStatus = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-send-status]')
        : null;
    const resendAlert = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-reenvio-alert]')
        : null;
    const resendMessage = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-reenvio-message]')
        : null;
    const preview = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-convocatoria-preview]')
        : null;
    const previewImage = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-convocatoria-image]')
        : null;
    const previewTitle = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-convocatoria-title]')
        : null;
    const previewPeriod = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-convocatoria-period]')
        : null;

    const historyContext = historyModalElement
        ? historyModalElement.querySelector('[data-aliado-history-context]')
        : null;
    const historyList = historyModalElement
        ? historyModalElement.querySelector('[data-aliado-history-list]')
        : null;

    const contactsContext = contactsModalElement
        ? contactsModalElement.querySelector('[data-aliado-contacts-context]')
        : null;
    const contactsList = contactsModalElement
        ? contactsModalElement.querySelector('[data-aliado-contact-list]')
        : null;
    const contactForm = contactsModalElement
        ? contactsModalElement.querySelector('[data-aliado-contact-form]')
        : null;
    const contactEditorTitle = contactsModalElement
        ? contactsModalElement.querySelector('[data-contact-editor-title]')
        : null;
    const contactEditorCancel = contactsModalElement
        ? contactsModalElement.querySelector('[data-contact-editor-cancel]')
        : null;
    const contactFormStatus = contactsModalElement
        ? contactsModalElement.querySelector('[data-contact-form-status]')
        : null;

    let currentAllyId = 0;
    let currentContactAllyId = 0;
    let contactsDirty = false;
    let currentConvocatorias = new Map();
    let searchTimer = null;

    const endpoint = function (action, params) {
        const url = new URL(
            baseUrl + 'index.php',
            window.location.href
        );
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

    const formatDate = function (value) {
        const source = String(value || '').trim();
        if (!source) {
            return '—';
        }

        const parts = source.slice(0, 10).split('-');
        if (parts.length !== 3) {
            return source;
        }

        return parts[2] + '/' + parts[1] + '/' + parts[0];
    };

    const formatDateTime = function (value) {
        const source = String(value || '').trim();
        if (!source) {
            return '—';
        }

        const date = formatDate(source);
        const time = source.length >= 16 ? source.slice(11, 16) : '';
        return time ? date + ' · ' + time : date;
    };

    const resetSendState = function () {
        currentConvocatorias = new Map();

        if (shareForm) {
            shareForm.reset();
            const allyInput = shareForm.querySelector('[name="seguimiento_id"]');
            const resendInput = shareForm.querySelector('[name="confirmar_reenvio"]');

            if (allyInput) {
                allyInput.value = currentAllyId > 0 ? String(currentAllyId) : '';
            }
            if (resendInput) {
                resendInput.value = '0';
            }
        }

        if (convocatoriaSelect) {
            convocatoriaSelect.innerHTML =
                '<option value="">Selecciona una convocatoria</option>';
            convocatoriaSelect.disabled = true;
        }

        if (preview) {
            preview.classList.add('d-none');
        }
        if (previewImage) {
            previewImage.removeAttribute('src');
            previewImage.alt = '';
        }
        if (previewTitle) {
            previewTitle.textContent = '—';
        }
        if (previewPeriod) {
            previewPeriod.textContent = '—';
        }

        if (resendAlert) {
            resendAlert.classList.add('d-none');
        }
        if (resendMessage) {
            resendMessage.textContent = '';
        }

        if (sendStatus) {
            sendStatus.classList.add('d-none');
            sendStatus.classList.remove('is-success', 'is-error');
            sendStatus.textContent = '';
        }

        if (sendButton) {
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="bi bi-send"></i> Enviar por correo';
        }
    };

    const setStatus = function (message, type) {
        if (!sendStatus) {
            return;
        }

        sendStatus.textContent = String(message || '');
        sendStatus.classList.remove('d-none', 'is-success', 'is-error');
        sendStatus.classList.add(type === 'success' ? 'is-success' : 'is-error');
    };

    const populateConvocatorias = function (items) {
        currentConvocatorias = new Map();

        if (!convocatoriaSelect) {
            return;
        }

        convocatoriaSelect.innerHTML =
            '<option value="">Selecciona una convocatoria</option>';

        (Array.isArray(items) ? items : []).forEach(function (item) {
            const id = Number(item.id || 0);
            if (id <= 0) {
                return;
            }

            currentConvocatorias.set(id, item);

            const option = document.createElement('option');
            option.value = String(id);
            option.textContent =
                String(item.titulo || 'Convocatoria') +
                ' · hasta ' +
                formatDate(item.fecha_termino);
            convocatoriaSelect.appendChild(option);
        });

        convocatoriaSelect.disabled = currentConvocatorias.size === 0;
    };

    const renderPreview = function (item) {
        if (!preview) {
            return;
        }

        if (!item) {
            preview.classList.add('d-none');
            return;
        }

        preview.classList.remove('d-none');

        if (previewTitle) {
            previewTitle.textContent = String(item.titulo || 'Convocatoria');
        }
        if (previewPeriod) {
            previewPeriod.textContent =
                'Vigencia: ' +
                formatDate(item.fecha_inicio) +
                ' - ' +
                formatDate(item.fecha_termino);
        }

        if (previewImage) {
            const image = String(item.imagen || '').replace(/^\/+/, '');

            if (image) {
                previewImage.src = baseUrl + image;
                previewImage.alt =
                    'Convocatoria ' + String(item.titulo || '');
                previewImage.classList.remove('d-none');
            } else {
                previewImage.removeAttribute('src');
                previewImage.alt = '';
                previewImage.classList.add('d-none');
            }
        }
    };

    const loadShare = async function (allyId) {
        currentAllyId = Number(allyId || 0);
        resetSendState();

        if (currentAllyId <= 0) {
            return;
        }

        if (shareContext) {
            shareContext.textContent = 'Consultando convocatorias vigentes…';
        }

        if (shareModal) {
            shareModal.show();
        }

        const data = await requestJson(
            endpoint('prepararEnvio', { id: currentAllyId })
        );

        if (!data.ok) {
            if (shareContext) {
                shareContext.textContent =
                    String(data.mensaje || 'No fue posible cargar el aliado.');
            }
            setStatus(data.mensaje || 'No fue posible cargar la información.', 'error');
            return;
        }

        const ally = data.aliado || {};
        const name = String(ally.nombre_entidad || 'Institución');
        const email = String(ally.correo_contacto || '');

        if (shareContext) {
            shareContext.textContent =
                name + (email ? ' · ' + email : '');
        }

        populateConvocatorias(data.convocatorias || []);

        if (currentConvocatorias.size === 0) {
            setStatus(
                'No hay convocatorias activas y vigentes para el territorio de este aliado.',
                'error'
            );
        }
    };

    const loadDraft = async function () {
        if (!convocatoriaSelect || currentAllyId <= 0) {
            return;
        }

        const resendInput = shareForm
            ? shareForm.querySelector('[name="confirmar_reenvio"]')
            : null;
        if (resendInput) {
            resendInput.value = '0';
        }
        if (sendButton) {
            sendButton.innerHTML =
                '<i class="bi bi-send"></i> Enviar por correo';
        }

        const convocatoriaId = Number(convocatoriaSelect.value || 0);

        if (preview) {
            preview.classList.add('d-none');
        }
        if (asuntoInput) {
            asuntoInput.value = '';
        }
        if (mensajeInput) {
            mensajeInput.value = '';
        }
        if (sendButton) {
            sendButton.disabled = true;
        }
        if (resendAlert) {
            resendAlert.classList.add('d-none');
        }

        if (convocatoriaId <= 0) {
            return;
        }

        renderPreview(currentConvocatorias.get(convocatoriaId));

        const data = await requestJson(
            endpoint('borradorEnvio', {
                id: currentAllyId,
                convocatoria_id: convocatoriaId
            })
        );

        if (!data.ok) {
            setStatus(
                data.mensaje || 'No fue posible preparar el mensaje.',
                'error'
            );
            return;
        }

        const draft = data.borrador || {};

        if (asuntoInput) {
            asuntoInput.value = String(draft.asunto || '');
        }
        if (mensajeInput) {
            mensajeInput.value = String(draft.cuerpo || '');
        }

        if (sendStatus) {
            sendStatus.classList.add('d-none');
        }

        if (sendButton) {
            sendButton.disabled = false;
        }
    };

    const sendConvocatoria = async function () {
        if (!shareForm || !sendButton) {
            return;
        }

        if (!shareForm.reportValidity()) {
            return;
        }

        sendButton.disabled = true;
        const originalLabel = sendButton.innerHTML;
        sendButton.innerHTML =
            '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Enviando…';

        const formData = new FormData(shareForm);

        const data = await requestJson(
            endpoint('enviarConvocatoria'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (data.ok) {
            setStatus(
                data.mensaje || 'Convocatoria enviada correctamente.',
                'success'
            );
            sendButton.innerHTML =
                '<i class="bi bi-check2-circle"></i> Enviado';

            window.setTimeout(function () {
                window.location.reload();
            }, 900);
            return;
        }

        if (data.requiere_confirmacion) {
            const resendInput =
                shareForm.querySelector('[name="confirmar_reenvio"]');

            if (resendInput) {
                resendInput.value = '1';
            }

            if (resendMessage) {
                resendMessage.textContent = String(data.mensaje || '');
            }
            if (resendAlert) {
                resendAlert.classList.remove('d-none');
            }

            sendButton.disabled = false;
            sendButton.innerHTML =
                '<i class="bi bi-arrow-repeat"></i> Enviar nuevamente';
            return;
        }

        setStatus(
            data.mensaje || 'No fue posible enviar la convocatoria.',
            'error'
        );
        sendButton.disabled = false;
        sendButton.innerHTML = originalLabel;
    };

    const createHistoryItem = function (item) {
        const article = document.createElement('article');
        article.className = 'aliados-history-item';

        const icon = document.createElement('span');
        icon.className = 'aliados-history-icon';
        const iconElement = document.createElement('i');
        iconElement.className =
            String(item.canal || '').toUpperCase() === 'WHATSAPP'
                ? 'bi bi-whatsapp'
                : 'bi bi-envelope-check';
        icon.appendChild(iconElement);

        const copy = document.createElement('div');
        copy.className = 'aliados-history-copy';

        const title = document.createElement('strong');
        title.textContent = String(
            item.convocatoria_titulo || 'Convocatoria'
        );

        const meta = document.createElement('span');
        meta.textContent =
            String(item.canal || 'CORREO') +
            ' · ' +
            formatDateTime(item.enviado_at);

        const author = document.createElement('small');
        const authorName = String(item.enviado_por_nombre || '').trim();
        author.textContent =
            (authorName ? 'Enviado por ' + authorName + ' · ' : '') +
            String(item.destinatario || '');

        copy.append(title, meta, author);

        const status = document.createElement('span');
        const sent = String(item.estado_envio || '').toUpperCase() === 'ENVIADO';
        status.className =
            'aliados-history-status ' +
            (sent ? 'is-sent' : 'is-error');
        status.textContent = sent ? 'Enviado' : 'Error';

        article.append(icon, copy, status);

        return article;
    };

    const loadHistory = async function (allyId) {
        const id = Number(allyId || 0);
        if (id <= 0 || !historyList) {
            return;
        }

        historyList.innerHTML =
            '<div class="aliados-history-loading"><i class="bi bi-arrow-repeat"></i> Consultando historial…</div>';

        if (historyContext) {
            historyContext.textContent = 'Historial del aliado seleccionado.';
        }

        if (historyModal) {
            historyModal.show();
        }

        const data = await requestJson(
            endpoint('historial', { id: id })
        );

        historyList.innerHTML = '';

        if (!data.ok) {
            const error = document.createElement('div');
            error.className = 'aliados-history-loading';
            error.textContent =
                String(data.mensaje || 'No fue posible cargar el historial.');
            historyList.appendChild(error);
            return;
        }

        const ally = data.aliado || {};

        if (historyContext) {
            historyContext.textContent =
                String(ally.nombre_entidad || 'Institución') +
                ' · ' +
                String(ally.estado_nombre || '');
        }

        const items = Array.isArray(data.historial)
            ? data.historial
            : [];

        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'aliados-history-loading';
            empty.innerHTML =
                '<i class="bi bi-inbox"></i><br>Este aliado todavía no tiene convocatorias compartidas.';
            historyList.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            historyList.appendChild(createHistoryItem(item));
        });
    };

    const filterMunicipalities = function () {
        if (!stateSelect || !municipalitySelect) {
            return;
        }

        const selectedState = Number(stateSelect.value || 0);
        const currentMunicipality = Number(municipalitySelect.value || 0);
        let currentStillVisible = currentMunicipality === 0;

        Array.from(municipalitySelect.options).forEach(function (option) {
            if (Number(option.value || 0) === 0) {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const stateId = Number(option.dataset.estadoId || 0);
            const visible = selectedState === 0 || stateId === selectedState;

            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && Number(option.value || 0) === currentMunicipality) {
                currentStillVisible = true;
            }
        });

        if (!currentStillVisible) {
            municipalitySelect.value = '0';
        }
    };

    root.addEventListener('click', function (event) {
        const shareButton = event.target.closest('[data-aliado-share]');
        if (shareButton) {
            loadShare(shareButton.dataset.aliadoShare);
            return;
        }

        const historyButton = event.target.closest('[data-aliado-history]');
        if (historyButton) {
            loadHistory(historyButton.dataset.aliadoHistory);
        }
    });

    if (convocatoriaSelect) {
        convocatoriaSelect.addEventListener('change', loadDraft);
    }

    if (sendButton) {
        sendButton.addEventListener('click', sendConvocatoria);
    }

    if (stateSelect) {
        stateSelect.addEventListener('change', filterMunicipalities);
    }

    if (shareModalElement) {
        shareModalElement.addEventListener('hidden.bs.modal', function () {
            currentAllyId = 0;
            resetSendState();
        });
    }

    filterMunicipalities();
});
