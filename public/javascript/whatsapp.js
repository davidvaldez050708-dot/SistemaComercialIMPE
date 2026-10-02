document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-whatsapp-module]');
    if (!root) {
        return;
    }

    const baseUrl = String(root.dataset.baseUrl || '');
    let conversationId = Number(root.dataset.conversationId || 0);
    let lastMessageId = Number(root.dataset.lastMessageId || 0);
    let pollTimer = null;

    const messagesContainer = root.querySelector('[data-whatsapp-messages]');
    const emptyChat = root.querySelector('[data-whatsapp-chat-empty]');
    const sendForm = root.querySelector('[data-whatsapp-send-form]');
    const messageInput = root.querySelector('[data-whatsapp-message-input]');
    const sendButton = root.querySelector('[data-whatsapp-send-button]');
    const templateButton = root.querySelector('[data-whatsapp-test-template]');
    const windowNotice = root.querySelector('[data-whatsapp-window-notice]');
    const windowStatus = root.querySelector('[data-whatsapp-window-status]');
    const composerFeedback = root.querySelector('[data-whatsapp-composer-feedback]');
    const searchInput = root.querySelector('[data-whatsapp-search]');
    const conversationItems = Array.from(
        root.querySelectorAll('[data-whatsapp-conversation-item]')
    );
    const channelForm = document.querySelector('[data-whatsapp-channel-form]');
    const channelStatus = document.querySelector('[data-whatsapp-channel-status]');
    const channelEditorTitle = document.querySelector(
        '[data-whatsapp-channel-editor-title]'
    );
    const channelCancel = document.querySelector(
        '[data-whatsapp-channel-cancel]'
    );
    const channelEditButtons = Array.from(
        document.querySelectorAll('[data-whatsapp-channel-edit]')
    );
    const newConversationForm = document.querySelector(
        '[data-whatsapp-new-conversation-form]'
    );
    const newConversationStatus = document.querySelector(
        '[data-whatsapp-new-conversation-status]'
    );

    const endpoint = function (action, params) {
        const url = new URL(baseUrl + 'index.php', window.location.href);
        url.searchParams.set('controller', 'whatsapp');
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

    const normalize = function (value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    };

    const formatTime = function (value) {
        const source = String(value || '').trim();
        if (!source) {
            return '';
        }

        const match = source.match(/(?:T|\s)(\d{2}:\d{2})/);
        return match ? match[1] : '';
    };

    const setComposerFeedback = function (message, isError) {
        if (!composerFeedback) {
            return;
        }

        composerFeedback.textContent = String(message || '');
        composerFeedback.classList.remove('d-none', 'is-success', 'is-error');
        composerFeedback.classList.add(isError ? 'is-error' : 'is-success');

        window.setTimeout(function () {
            composerFeedback.classList.add('d-none');
        }, 4500);
    };

    const messageStatusIcon = function (status) {
        const value = String(status || '').toUpperCase();

        if (value === 'LEIDO' || value === 'ENTREGADO') {
            return 'bi-check2-all';
        }

        if (value === 'ENVIADO') {
            return 'bi-check2';
        }

        if (value === 'ERROR') {
            return 'bi-exclamation-circle';
        }

        return 'bi-clock';
    };

    const createMessage = function (message) {
        const outgoing =
            String(message.direccion || '').toUpperCase() === 'SALIENTE';

        const article = document.createElement('article');
        article.className =
            'whatsapp-message ' +
            (outgoing ? 'is-outgoing' : 'is-incoming');
        article.dataset.messageId = String(message.id || 0);

        const bubble = document.createElement('div');
        bubble.className = 'whatsapp-message-bubble';

        if (String(message.tipo || 'TEXT').toUpperCase() !== 'TEXT') {
            const type = document.createElement('span');
            type.className = 'whatsapp-message-type';
            type.textContent = String(message.tipo || '');
            bubble.appendChild(type);
        }

        const paragraph = document.createElement('p');
        paragraph.textContent = String(message.contenido || '');
        bubble.appendChild(paragraph);

        const meta = document.createElement('div');
        meta.className = 'whatsapp-message-meta';

        const time = document.createElement('time');
        time.textContent = formatTime(
            message.enviado_at || message.created_at || ''
        );
        meta.appendChild(time);

        if (outgoing) {
            const icon = document.createElement('i');
            icon.className =
                'bi ' +
                messageStatusIcon(message.estado) +
                (
                    String(message.estado || '').toUpperCase() === 'LEIDO'
                        ? ' is-read'
                        : ''
                );
            icon.title = String(message.estado || '');
            icon.dataset.messageStatusIcon = '';
            meta.appendChild(icon);
        }

        bubble.appendChild(meta);

        if (
            String(message.estado || '').toUpperCase() === 'ERROR' &&
            String(message.error_detalle || '').trim() !== ''
        ) {
            const error = document.createElement('small');
            error.className = 'whatsapp-message-error';
            error.textContent = String(message.error_detalle || '');
            bubble.appendChild(error);
        }

        article.appendChild(bubble);
        return article;
    };

    const updateOutgoingStatuses = function (items) {
        if (!messagesContainer || !Array.isArray(items)) {
            return;
        }

        items.forEach(function (item) {
            const id = Number(item.id || 0);
            if (id <= 0) {
                return;
            }

            const message = messagesContainer.querySelector(
                '[data-message-id="' + String(id) + '"]'
            );

            if (!message) {
                return;
            }

            const icon = message.querySelector(
                '[data-message-status-icon]'
            );

            if (icon) {
                const status = String(item.estado || '').toUpperCase();
                icon.className =
                    'bi ' +
                    messageStatusIcon(status) +
                    (status === 'LEIDO' ? ' is-read' : '');
                icon.title = status;
            }

            const bubble = message.querySelector('.whatsapp-message-bubble');
            let error = message.querySelector('.whatsapp-message-error');
            const detail = String(item.error_detalle || '').trim();

            if (detail !== '') {
                if (!error && bubble) {
                    error = document.createElement('small');
                    error.className = 'whatsapp-message-error';
                    bubble.appendChild(error);
                }

                if (error) {
                    error.textContent = detail;
                }
            } else if (error) {
                error.remove();
            }
        });
    };

    const scrollMessages = function () {
        if (!messagesContainer) {
            return;
        }

        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    };

    const appendMessages = function (messages) {
        if (!messagesContainer || !Array.isArray(messages)) {
            return;
        }

        if (messages.length > 0) {
            emptyChat?.remove();
        }

        messages.forEach(function (message) {
            const id = Number(message.id || 0);
            if (id <= lastMessageId) {
                return;
            }

            messagesContainer.appendChild(createMessage(message));
            lastMessageId = Math.max(lastMessageId, id);
        });

        if (messages.length > 0) {
            scrollMessages();
        }
    };

    const updateWindowState = function (isOpen) {
        if (windowStatus) {
            windowStatus.classList.toggle('is-open', Boolean(isOpen));
            windowStatus.classList.toggle('is-closed', !isOpen);
            windowStatus.innerHTML = '';

            const icon = document.createElement('i');
            icon.className =
                'bi ' + (isOpen ? 'bi-clock-history' : 'bi-lock');

            const text = document.createTextNode(
                isOpen
                    ? ' Ventana de 24 h abierta'
                    : ' Ventana cerrada'
            );

            windowStatus.append(icon, text);
        }

        windowNotice?.classList.toggle('d-none', Boolean(isOpen));

        if (messageInput) {
            messageInput.disabled = !isOpen;
            messageInput.placeholder = isOpen
                ? 'Escribe un mensaje…'
                : 'La ventana está cerrada';
        }

        if (sendButton) {
            sendButton.disabled = !isOpen;
        }
    };

    const pollMessages = async function () {
        if (conversationId <= 0) {
            return;
        }

        const data = await requestJson(
            endpoint('mensajes', {
                conversacion_id: conversationId,
                despues_de_id: lastMessageId
            })
        );

        if (!data.ok) {
            return;
        }

        appendMessages(data.mensajes || []);
        updateOutgoingStatuses(data.estados_salida || []);
        updateWindowState(Boolean(data.ventana_servicio_abierta));
    };

    const startPolling = function () {
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }

        if (conversationId <= 0) {
            return;
        }

        pollTimer = window.setInterval(pollMessages, 4000);
    };

    searchInput?.addEventListener('input', function () {
        const query = normalize(searchInput.value);

        conversationItems.forEach(function (item) {
            const matches =
                query === '' ||
                normalize(item.dataset.search || '').includes(query);

            item.classList.toggle('d-none', !matches);
        });
    });

    sendForm?.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (conversationId <= 0 || !messageInput || !sendButton) {
            return;
        }

        const text = String(messageInput.value || '').trim();

        if (text === '') {
            messageInput.focus();
            return;
        }

        const formData = new FormData();
        formData.set('conversacion_id', String(conversationId));
        formData.set('mensaje', text);

        sendButton.disabled = true;

        const data = await requestJson(
            endpoint('enviar'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (!data.ok) {
            setComposerFeedback(
                data.mensaje || 'No fue posible enviar el mensaje.',
                true
            );

            if (data.requiere_plantilla) {
                updateWindowState(false);
            } else {
                sendButton.disabled = false;
            }

            return;
        }

        messageInput.value = '';
        setComposerFeedback(
            data.mensaje || 'Mensaje enviado.',
            false
        );
        await pollMessages();

        if (!messageInput.disabled) {
            sendButton.disabled = false;
            messageInput.focus();
        }
    });

    templateButton?.addEventListener('click', async function () {
        if (conversationId <= 0) {
            return;
        }

        templateButton.disabled = true;
        const original = templateButton.innerHTML;
        templateButton.innerHTML =
            '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Enviando…';

        const formData = new FormData();
        formData.set('conversacion_id', String(conversationId));

        const data = await requestJson(
            endpoint('enviarPlantillaPrueba'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (!data.ok) {
            setComposerFeedback(
                data.mensaje || 'No fue posible enviar la plantilla.',
                true
            );
            templateButton.disabled = false;
            templateButton.innerHTML = original;
            return;
        }

        setComposerFeedback(
            data.mensaje || 'Plantilla enviada.',
            false
        );
        await pollMessages();
        templateButton.disabled = false;
        templateButton.innerHTML = original;
    });

    newConversationForm?.addEventListener(
        'submit',
        async function (event) {
            event.preventDefault();

            if (!newConversationForm.reportValidity()) {
                return;
            }

            const button = newConversationForm.querySelector(
                'button[type="submit"]'
            );

            if (button) {
                button.disabled = true;
            }

            const data = await requestJson(
                endpoint('crearConversacion'),
                {
                    method: 'POST',
                    body: new FormData(newConversationForm)
                }
            );

            if (!data.ok) {
                if (newConversationStatus) {
                    newConversationStatus.classList.remove(
                        'd-none',
                        'is-success',
                        'is-error'
                    );
                    newConversationStatus.classList.add('is-error');
                    newConversationStatus.textContent = String(
                        data.mensaje ||
                        'No fue posible abrir la conversación.'
                    );
                }

                if (button) {
                    button.disabled = false;
                }

                return;
            }

            if (newConversationStatus) {
                newConversationStatus.classList.remove(
                    'd-none',
                    'is-error'
                );
                newConversationStatus.classList.add('is-success');
                newConversationStatus.textContent =
                    'Conversación preparada. Abriendo chat…';
            }

            window.location.href = String(
                data.url ||
                endpoint('index', {
                    conversacion_id: data.conversacion_id
                })
            );
        }
    );

    const resetChannelForm = function () {
        if (!channelForm) {
            return;
        }

        channelForm.reset();

        const idInput = channelForm.querySelector('[name="cuenta_id"]');
        const activeInput = channelForm.querySelector('[name="activo"]');

        if (idInput) {
            idInput.value = '0';
        }
        if (activeInput) {
            activeInput.checked = true;
        }
        if (channelEditorTitle) {
            channelEditorTitle.textContent = 'Agregar canal';
        }
        channelCancel?.classList.add('d-none');

        if (channelStatus) {
            channelStatus.classList.add('d-none');
            channelStatus.classList.remove('is-success', 'is-error');
            channelStatus.textContent = '';
        }
    };

    const editChannel = function (button) {
        if (!channelForm || !button) {
            return;
        }

        const field = function (name) {
            return channelForm.querySelector('[name="' + name + '"]');
        };

        const idInput = field('cuenta_id');
        const nameInput = field('nombre');
        const phoneIdInput = field('phone_number_id');
        const numberInput = field('numero_mostrado');
        const userInput = field('usuario_id');
        const typeInput = field('tipo');
        const defaultInput = field('es_predeterminada');
        const activeInput = field('activo');

        if (idInput) idInput.value = String(button.dataset.id || '0');
        if (nameInput) nameInput.value = String(button.dataset.nombre || '');
        if (phoneIdInput) {
            phoneIdInput.value = String(button.dataset.phoneNumberId || '');
        }
        if (numberInput) {
            numberInput.value = String(button.dataset.numeroMostrado || '');
        }
        if (userInput) {
            userInput.value = String(button.dataset.usuarioId || '0');
        }
        if (typeInput) {
            typeInput.value = String(button.dataset.tipo || 'EMPRESARIAL');
        }
        if (defaultInput) {
            defaultInput.checked =
                String(button.dataset.predeterminada || '0') === '1';
        }
        if (activeInput) {
            activeInput.checked =
                String(button.dataset.activo || '0') === '1';
        }

        if (channelEditorTitle) {
            channelEditorTitle.textContent = 'Editar canal';
        }

        channelCancel?.classList.remove('d-none');

        if (channelStatus) {
            channelStatus.classList.add('d-none');
        }

        nameInput?.focus();
    };

    channelEditButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            editChannel(button);
        });
    });

    channelCancel?.addEventListener('click', resetChannelForm);

    channelForm?.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!channelForm.reportValidity()) {
            return;
        }

        const formData = new FormData(channelForm);
        const defaultInput =
            channelForm.querySelector('[name="es_predeterminada"]');
        const activeInput =
            channelForm.querySelector('[name="activo"]');
        const button = channelForm.querySelector('button[type="submit"]');

        formData.set(
            'es_predeterminada',
            defaultInput?.checked ? '1' : '0'
        );
        formData.set(
            'activo',
            activeInput?.checked ? '1' : '0'
        );

        if (button) {
            button.disabled = true;
        }

        const data = await requestJson(
            endpoint('guardarCuenta'),
            {
                method: 'POST',
                body: formData
            }
        );

        if (channelStatus) {
            channelStatus.classList.remove(
                'd-none',
                'is-success',
                'is-error'
            );
            channelStatus.classList.add(
                data.ok ? 'is-success' : 'is-error'
            );
            channelStatus.textContent = String(
                data.mensaje ||
                (
                    data.ok
                        ? 'Canal guardado.'
                        : 'No fue posible guardar el canal.'
                )
            );
        }

        if (button) {
            button.disabled = false;
        }

        if (data.ok) {
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        }
    });

    document.addEventListener('click', async function (event) {
        const copyButton = event.target.closest('[data-copy-text]');
        if (!copyButton) {
            return;
        }

        const value = String(copyButton.dataset.copyText || '');

        if (!value || !navigator.clipboard) {
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            const original = copyButton.innerHTML;
            copyButton.innerHTML =
                '<i class="bi bi-check2"></i> Copiado';

            window.setTimeout(function () {
                copyButton.innerHTML = original;
            }, 1600);
        } catch (error) {
            // El usuario puede copiar manualmente el callback mostrado.
        }
    });

    scrollMessages();
    startPolling();
});
