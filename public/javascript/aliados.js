document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-aliados-module]');
    if (!root) {
        return;
    }

    const baseUrl = String(root.dataset.baseUrl || '');
    const fixedStateId = Number(root.dataset.estadoId || 0);
    const stateSelect = document.getElementById('aliados_estado');
    const municipalitySelect = document.getElementById('aliados_municipio');
    const analystSelect = document.getElementById('aliados_analista');
    const diffusionSelect = document.getElementById('aliados_difusion');
    const formalizationSelect = document.getElementById('aliados_formalizacion');
    const searchInput = document.getElementById('aliados_buscar');
    const filterForm = root.querySelector('[data-aliados-filters]');
    const clearFilters = root.querySelector('[data-aliados-clear]');
    const resultCount = root.querySelector('[data-aliados-result-count]');
    const allyRows = Array.from(root.querySelectorAll('[data-aliado-row]'));
    const municipalityGroups = Array.from(
        root.querySelectorAll('[data-aliado-municipio-group]')
    );
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
    const whatsappShareButton = shareModalElement
        ? shareModalElement.querySelector('[data-aliado-open-whatsapp]')
        : null;
    const whatsappShareStatus = whatsappShareButton
        ? whatsappShareButton.querySelector('small')
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
    let currentAllyWhatsappReady = false;
    let currentContactAllyId = 0;
    let contactsDirty = false;
    let currentContacts = new Map();
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

    const normalizeText = function (value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    };

    const updateFilterUrl = function () {
        const url = new URL(window.location.href);
        const values = {
            buscar: String(searchInput?.value || '').trim(),
            municipio_id: Number(municipalitySelect?.value || 0),
            analista_id: Number(analystSelect?.value || 0),
            difusion: String(diffusionSelect?.value || 'todos'),
            formalizacion: String(formalizationSelect?.value || 'todas')
        };

        if (fixedStateId > 0) {
            url.searchParams.set('estado_id', String(fixedStateId));
        }

        Object.entries(values).forEach(function ([key, value]) {
            const defaultValue =
                key === 'difusion'
                    ? 'todos'
                    : key === 'formalizacion'
                        ? 'todas'
                        : key === 'buscar'
                            ? ''
                            : 0;

            if (String(value) !== String(defaultValue) && String(value) !== '') {
                url.searchParams.set(key, String(value));
            } else {
                url.searchParams.delete(key);
            }
        });

        window.history.replaceState({}, '', url.toString());
    };

    const updateClearVisibility = function () {
        if (!clearFilters) {
            return;
        }

        const active =
            String(searchInput?.value || '').trim() !== '' ||
            Number(municipalitySelect?.value || 0) > 0 ||
            Number(analystSelect?.value || 0) > 0 ||
            String(diffusionSelect?.value || 'todos') !== 'todos' ||
            String(formalizationSelect?.value || 'todas') !== 'todas';

        clearFilters.classList.toggle('d-none', !active);
    };

    const matchesFormalization = function (row, filterValue) {
        if (filterValue === 'todas') {
            return true;
        }

        const source = String(row.dataset.formalizadoAt || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(source)) {
            return false;
        }

        const formalized = new Date(source + 'T00:00:00');
        const now = new Date();
        const today = new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate()
        );

        if (Number.isNaN(formalized.getTime())) {
            return false;
        }

        if (filterValue === '30' || filterValue === '90') {
            const days = Number(filterValue);
            const threshold = new Date(today);
            threshold.setDate(threshold.getDate() - (days - 1));
            return formalized >= threshold && formalized <= today;
        }

        if (filterValue === 'mes') {
            return (
                formalized.getFullYear() === today.getFullYear() &&
                formalized.getMonth() === today.getMonth()
            );
        }

        if (filterValue === 'anio') {
            return formalized.getFullYear() === today.getFullYear();
        }

        return true;
    };

    const updateMunicipalityGroups = function () {
        municipalityGroups.forEach(function (group) {
            const rows = Array.from(group.querySelectorAll('[data-aliado-row]'));
            const visibleRows = rows.filter(function (row) {
                return !row.classList.contains('d-none');
            }).length;
            const counter = group.querySelector('[data-group-count]');

            group.classList.toggle('d-none', visibleRows === 0);

            if (counter) {
                counter.textContent =
                    visibleRows +
                    (visibleRows === 1 ? ' aliado' : ' aliados');
            }
        });
    };

    const applyDirectoryFilters = function () {
        if (allyRows.length === 0) {
            updateFilterUrl();
            updateClearVisibility();
            return;
        }

        const search = normalizeText(searchInput?.value || '');
        const stateId =
            fixedStateId > 0
                ? fixedStateId
                : Number(stateSelect?.value || 0);
        const municipalityId = Number(municipalitySelect?.value || 0);
        const analystId = Number(analystSelect?.value || 0);
        const diffusion = String(diffusionSelect?.value || 'todos');
        const formalization =
            String(formalizationSelect?.value || 'todas');
        let visible = 0;

        allyRows.forEach(function (row) {
            const hasDiffusion = row.dataset.tieneDifusion === '1';
            const matchesDiffusion =
                diffusion === 'todos' ||
                (diffusion === 'con' && hasDiffusion) ||
                (diffusion === 'sin' && !hasDiffusion);

            const matches =
                (search === '' || normalizeText(row.dataset.search).includes(search)) &&
                (stateId === 0 || Number(row.dataset.estadoId || 0) === stateId) &&
                (municipalityId === 0 || Number(row.dataset.municipioId || 0) === municipalityId) &&
                (analystId === 0 || Number(row.dataset.analistaId || 0) === analystId) &&
                matchesDiffusion &&
                matchesFormalization(row, formalization);

            row.classList.toggle('d-none', !matches);

            if (matches) {
                visible++;
            }
        });

        updateMunicipalityGroups();

        if (resultCount) {
            resultCount.textContent =
                visible + (visible === 1 ? ' resultado' : ' resultados');
        }

        if (filteredEmpty) {
            filteredEmpty.classList.toggle('d-none', visible !== 0);
        }

        updateFilterUrl();
        updateClearVisibility();
    };

    const resetSendState = function () {
        currentConvocatorias = new Map();
        currentAllyWhatsappReady = false;

        if (whatsappShareButton) {
            whatsappShareButton.disabled = true;
            whatsappShareButton.classList.add('is-disabled');
        }
        if (whatsappShareStatus) {
            whatsappShareStatus.textContent = 'Requiere WhatsApp confirmado';
        }

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
        currentAllyWhatsappReady =
            String(ally.whatsapp_verificado || '').trim() !== '' ||
            Number(ally.tiene_whatsapp_confirmado_contacto || 0) === 1;

        if (whatsappShareButton) {
            whatsappShareButton.disabled = !currentAllyWhatsappReady;
            whatsappShareButton.classList.toggle(
                'is-disabled',
                !currentAllyWhatsappReady
            );
        }
        if (whatsappShareStatus) {
            whatsappShareStatus.textContent = currentAllyWhatsappReady
                ? 'Abrir conversación'
                : 'Requiere WhatsApp confirmado';
        }

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

    const resetContactForm = function () {
        if (!contactForm) {
            return;
        }

        contactForm.reset();

        const allyInput = contactForm.querySelector('[name="seguimiento_id"]');
        const idInput = contactForm.querySelector('[name="contacto_id"]');
        const originInput = contactForm.querySelector('[name="origen"]');
        const labelInput = contactForm.querySelector('[name="etiqueta"]');

        if (allyInput) {
            allyInput.value = currentContactAllyId > 0
                ? String(currentContactAllyId)
                : '';
        }
        if (idInput) {
            idInput.value = '0';
        }
        if (originInput) {
            originInput.value = 'CUENTA_CLAVE';
        }
        if (labelInput) {
            labelInput.value = 'Difusión';
        }

        if (contactEditorTitle) {
            contactEditorTitle.textContent = 'Agregar número de difusión';
        }
        if (contactEditorCancel) {
            contactEditorCancel.classList.add('d-none');
        }
        if (contactFormStatus) {
            contactFormStatus.classList.add('d-none');
            contactFormStatus.textContent = '';
        }
    };

    const setContactFormStatus = function (message, isError) {
        if (!contactFormStatus) {
            return;
        }

        contactFormStatus.textContent = String(message || '');
        contactFormStatus.classList.remove('d-none', 'is-error', 'is-success');
        contactFormStatus.classList.add(isError ? 'is-error' : 'is-success');
    };

    const contactKey = function (item, index) {
        if (Number(item.id || 0) > 0) {
            return 'id:' + String(item.id);
        }

        return 'source:' +
            String(item.origen || 'FUENTE') +
            ':' +
            String(item.numero_normalizado || item.numero || '') +
            ':' +
            String(index);
    };

    const renderContacts = function (items, canManage) {
        if (!contactsList) {
            return;
        }

        contactsList.innerHTML = '';
        currentContacts = new Map();

        const contacts = Array.isArray(items) ? items : [];

        if (contacts.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'aliados-history-loading';
            empty.innerHTML =
                '<i class="bi bi-telephone-x"></i><br>No hay números registrados para este aliado.';
            contactsList.appendChild(empty);
            return;
        }

        contacts.forEach(function (item, index) {
            const key = contactKey(item, index);
            currentContacts.set(key, item);

            const card = document.createElement('article');
            card.className = 'aliados-contact-item';

            const icon = document.createElement('span');
            icon.className =
                'aliados-contact-item-icon ' +
                (item.confirmado_whatsapp ? 'is-whatsapp' : '');
            const iconEl = document.createElement('i');
            iconEl.className = item.confirmado_whatsapp
                ? 'bi bi-whatsapp'
                : 'bi bi-telephone';
            icon.appendChild(iconEl);

            const copy = document.createElement('div');
            copy.className = 'aliados-contact-item-copy';

            const number = document.createElement('strong');
            number.textContent = String(item.numero || '');

            const meta = document.createElement('span');
            meta.textContent =
                String(item.etiqueta || 'Contacto') +
                ' · ' +
                String(item.origen_label || 'Contacto de difusión');

            const badges = document.createElement('div');
            badges.className = 'aliados-contact-badges';

            if (item.preferido_difusion) {
                const preferred = document.createElement('span');
                preferred.className = 'is-preferred';
                preferred.textContent = 'Preferido para difusión';
                badges.appendChild(preferred);
            }

            if (item.confirmado_whatsapp) {
                const confirmed = document.createElement('span');
                confirmed.className = 'is-confirmed';
                confirmed.textContent = 'WhatsApp confirmado';
                badges.appendChild(confirmed);
            } else {
                const unconfirmed = document.createElement('span');
                unconfirmed.className = 'is-unconfirmed';
                unconfirmed.textContent = 'WhatsApp no confirmado';
                badges.appendChild(unconfirmed);
            }

            copy.append(number, meta, badges);

            const actions = document.createElement('div');
            actions.className = 'aliados-contact-item-actions';

            if (canManage) {
                if (item.editable) {
                    const edit = document.createElement('button');
                    edit.type = 'button';
                    edit.className = 'btn aliados-btn-secondary';
                    edit.dataset.contactEdit = key;
                    edit.innerHTML = '<i class="bi bi-pencil"></i> Editar';

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'aliados-contact-remove';
                    remove.dataset.contactRemove = key;
                    remove.title = 'Retirar contacto de difusión';
                    remove.setAttribute('aria-label', 'Retirar contacto de difusión');
                    remove.innerHTML = '<i class="bi bi-trash3"></i>';

                    actions.append(edit, remove);
                } else {
                    const use = document.createElement('button');
                    use.type = 'button';
                    use.className = 'btn aliados-btn-secondary';
                    use.dataset.contactUse = key;
                    use.innerHTML =
                        '<i class="bi bi-plus-circle"></i> Usar para difusión';
                    actions.appendChild(use);
                }
            }

            card.append(icon, copy, actions);
            contactsList.appendChild(card);
        });
    };

    const populateContactEditor = function (item, fromSource) {
        if (!contactForm || !item) {
            return;
        }

        resetContactForm();

        const idInput = contactForm.querySelector('[name="contacto_id"]');
        const originInput = contactForm.querySelector('[name="origen"]');
        const numberInput = contactForm.querySelector('[name="numero"]');
        const labelInput = contactForm.querySelector('[name="etiqueta"]');
        const whatsappInput = contactForm.querySelector('[name="confirmado_whatsapp"]');
        const preferredInput = contactForm.querySelector('[name="preferido_difusion"]');

        if (idInput) {
            idInput.value = item.editable ? String(item.id || 0) : '0';
        }
        if (originInput) {
            originInput.value = String(item.origen || 'CUENTA_CLAVE');
        }
        if (numberInput) {
            numberInput.value = String(item.numero || '');
        }
        if (labelInput) {
            labelInput.value = fromSource
                ? 'Difusión'
                : String(item.etiqueta || 'Difusión');
        }
        if (whatsappInput) {
            whatsappInput.checked = Boolean(item.confirmado_whatsapp);
        }
        if (preferredInput) {
            preferredInput.checked = fromSource
                ? true
                : Boolean(item.preferido_difusion);
        }

        if (contactEditorTitle) {
            contactEditorTitle.textContent = fromSource
                ? 'Configurar número para difusión'
                : 'Editar contacto de difusión';
        }
        if (contactEditorCancel) {
            contactEditorCancel.classList.remove('d-none');
        }

        numberInput?.focus();
    };

    const loadContacts = async function (allyId) {
        const id = Number(allyId || 0);
        if (id <= 0 || !contactsList) {
            return;
        }

        currentContactAllyId = id;
        contactsDirty = false;
        resetContactForm();

        contactsList.innerHTML =
            '<div class="aliados-history-loading"><i class="bi bi-arrow-repeat"></i> Consultando contactos…</div>';

        if (contactsContext) {
            contactsContext.textContent =
                'Consultando los canales disponibles del aliado…';
        }

        contactsModal?.show();

        const data = await requestJson(
            endpoint('contactos', { id: id })
        );

        if (!data.ok) {
            contactsList.innerHTML = '';
            const error = document.createElement('div');
            error.className = 'aliados-history-loading';
            error.textContent = String(
                data.mensaje || 'No fue posible cargar los contactos.'
            );
            contactsList.appendChild(error);
            return;
        }

        const ally = data.aliado || {};
        if (contactsContext) {
            contactsContext.textContent =
                String(ally.nombre_entidad || 'Institución') +
                ' · ' +
                String(ally.estado_nombre || '');
        }

        renderContacts(data.contactos || [], Boolean(data.puede_gestionar));
    };

    const saveContact = async function (event) {
        event.preventDefault();

        if (!contactForm || currentContactAllyId <= 0) {
            return;
        }

        if (!contactForm.reportValidity()) {
            return;
        }

        const submitButton = contactForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        const formData = new FormData(contactForm);
        const whatsappCheckbox =
            contactForm.querySelector('[name="confirmado_whatsapp"]');
        const preferredCheckbox =
            contactForm.querySelector('[name="preferido_difusion"]');

        formData.set(
            'confirmado_whatsapp',
            whatsappCheckbox?.checked ? '1' : '0'
        );
        formData.set(
            'preferido_difusion',
            preferredCheckbox?.checked ? '1' : '0'
        );

        const data = await requestJson(
            endpoint('guardarContacto'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (!data.ok) {
            setContactFormStatus(
                data.mensaje || 'No fue posible guardar el contacto.',
                true
            );
            if (submitButton) {
                submitButton.disabled = false;
            }
            return;
        }

        contactsDirty = true;
        renderContacts(data.contactos || [], true);
        resetContactForm();
        setContactFormStatus(
            data.mensaje || 'Contacto guardado correctamente.',
            false
        );

        if (submitButton) {
            submitButton.disabled = false;
        }
    };

    const removeContact = async function (item) {
        if (!item || !item.editable || Number(item.id || 0) <= 0) {
            return;
        }

        const formData = new FormData();
        formData.set('seguimiento_id', String(currentContactAllyId));
        formData.set('contacto_id', String(item.id));

        const data = await requestJson(
            endpoint('eliminarContacto'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (!data.ok) {
            setContactFormStatus(
                data.mensaje || 'No fue posible retirar el contacto.',
                true
            );
            return;
        }

        contactsDirty = true;
        renderContacts(data.contactos || [], true);
        resetContactForm();
        setContactFormStatus(
            data.mensaje || 'Contacto retirado.',
            false
        );
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

        const contactsButton = event.target.closest('[data-aliado-contacts]');
        if (contactsButton) {
            loadContacts(contactsButton.dataset.aliadoContacts);
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

    whatsappShareButton?.addEventListener('click', function () {
        if (currentAllyId <= 0 || !currentAllyWhatsappReady) {
            return;
        }

        window.location.href =
            baseUrl +
            'index.php?controller=whatsapp&action=abrirAliado&seguimiento_id=' +
            encodeURIComponent(String(currentAllyId));
    });

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            applyDirectoryFilters();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(applyDirectoryFilters, 280);
        });
    }

    if (stateSelect) {
        stateSelect.addEventListener('change', function () {
            filterMunicipalities();
            applyDirectoryFilters();
        });
    }

    municipalitySelect?.addEventListener('change', applyDirectoryFilters);
    analystSelect?.addEventListener('change', applyDirectoryFilters);
    diffusionSelect?.addEventListener('change', applyDirectoryFilters);
    formalizationSelect?.addEventListener('change', applyDirectoryFilters);

    clearFilters?.addEventListener('click', function (event) {
        event.preventDefault();

        if (searchInput) {
            searchInput.value = '';
        }
        if (stateSelect) {
            stateSelect.value = '0';
        }
        if (municipalitySelect) {
            municipalitySelect.value = '0';
        }
        if (analystSelect) {
            analystSelect.value = '0';
        }
        if (diffusionSelect) {
            diffusionSelect.value = 'todos';
        }
        if (formalizationSelect) {
            formalizationSelect.value = 'todas';
        }

        filterMunicipalities();
        applyDirectoryFilters();
        searchInput?.focus();
    });

    if (contactForm) {
        contactForm.addEventListener('submit', saveContact);
    }

    contactEditorCancel?.addEventListener('click', resetContactForm);

    contactsList?.addEventListener('click', function (event) {
        const editButton = event.target.closest('[data-contact-edit]');
        if (editButton) {
            populateContactEditor(
                currentContacts.get(editButton.dataset.contactEdit),
                false
            );
            return;
        }

        const useButton = event.target.closest('[data-contact-use]');
        if (useButton) {
            populateContactEditor(
                currentContacts.get(useButton.dataset.contactUse),
                true
            );
            return;
        }

        const removeButton = event.target.closest('[data-contact-remove]');
        if (removeButton) {
            removeContact(
                currentContacts.get(removeButton.dataset.contactRemove)
            );
        }
    });

    if (shareModalElement) {
        shareModalElement.addEventListener('hidden.bs.modal', function () {
            currentAllyId = 0;
            resetSendState();
        });
    }

    if (contactsModalElement) {
        contactsModalElement.addEventListener('hidden.bs.modal', function () {
            currentContactAllyId = 0;
            currentContacts = new Map();
            resetContactForm();

            if (contactsDirty) {
                window.location.reload();
            }
        });
    }

    filterMunicipalities();
    applyDirectoryFilters();
});
