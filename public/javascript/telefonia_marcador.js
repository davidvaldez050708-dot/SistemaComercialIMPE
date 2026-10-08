(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-telephony-dialer]');
        if (!root) return;

        const api = window.IMPE_TELEPHONY_PERSISTENT;
        const numberInput = root.querySelector('[data-telephony-dial-number]');
        const callButton = root.querySelector('[data-telephony-dial-call]');
        const hangupButton = root.querySelector('[data-telephony-dial-hangup]');
        const muteButton = root.querySelector('[data-telephony-dial-mute]');
        const muteLabel = root.querySelector('[data-telephony-dial-mute-label]');
        const muteIcon = root.querySelector('[data-telephony-dial-mute-icon]');
        const newButton = root.querySelector('[data-telephony-dial-new]');
        const afterCall = root.querySelector('[data-telephony-aftercall]');
        const saveAfterCall = root.querySelector('[data-telephony-aftercall-save]');
        const warning = root.querySelector('[data-telephony-assignment-warning]');
        const identity = root.querySelector('[data-telephony-extension-identity]');
        const salesStatus = document.querySelector('[data-telephony-sales-extension-status]');
        const salesCaption = document.querySelector('[data-telephony-sales-extension-caption]');
        const callStatus = root.querySelector('[data-telephony-dial-status]');
        const contactForm = root.querySelector('[data-telephony-contact-form]');
        const contactName = root.querySelector('[data-telephony-contact-name]');
        const contactNumber = root.querySelector('[data-telephony-contact-number]');
        const contactList = root.querySelector('[data-telephony-contacts-list]');
        const contactEmpty = root.querySelector('[data-telephony-contacts-empty]');
        const contactFeedback = root.querySelector('[data-telephony-contact-status]');
        const saveButton = root.querySelector('[data-telephony-contact-save]');
        const resultBlock = root.querySelector('[data-sales-result-block]');
        const resultSelect = root.querySelector('[data-sales-result-select]');
        const resultButton = root.querySelector('[data-sales-result-submit]');
        const resultFeedback = root.querySelector('[data-sales-result-feedback]');
        const historyTable = root.querySelector('.telephony-history-table');
        const classifiedCalls = new Map();
        historyTable?.querySelectorAll('[data-sales-history-result-form]').forEach(function (form) {
            const code = form.querySelector('[data-sales-history-result-select]')?.value || '';
            if (code) classifiedCalls.set(form.dataset.pbxCallId, code);
        });
        let savingResult = false;
        let ready = false;
        let dialing = false;
        let saving = false;
        let checkingPhone = false;
        let extensionAssigned = root.dataset.canCall === '1';

        function say(text, error) {
            callStatus.textContent = String(text || '');
            callStatus.classList.toggle('is-error', Boolean(error));
        }

        function sayContact(text, error) {
            contactFeedback.textContent = String(text || '');
            contactFeedback.classList.toggle('is-error', Boolean(error));
        }

        function normalizeNumber(value) {
            const raw = String(value || '').trim();
            if (!/^\+?[\d\s().-]+$/.test(raw)) {
                throw new Error('Escribe un teléfono con dígitos y, si aplica, prefijo internacional.');
            }

            let digits = raw.replace(/\D/g, '');
            if (digits.length === 10) digits = '52' + digits;

            if (!/^[1-9][0-9]{10,14}$/.test(digits)) {
                throw new Error('Usa diez dígitos de México o un número internacional válido.');
            }

            return digits;
        }

        function renderCall() {
            const state = api?.getState?.() || {};
            const active = Boolean(state.active);
            const phase = String(state.phase || '');
            const ownFinished = phase === 'finished' &&
                String(state.context?.type || '').toUpperCase() === 'DIALER';

            const pbxId = String(state.finalMetadata?.pbx_call_id || state.pbxCallId || '');
            const canClassify = ownFinished && /^out_[a-fA-F0-9]{32,64}$/.test(pbxId);
            const pendingResult = canClassify && !classifiedCalls.has(pbxId);

            callButton.disabled = !ready || dialing || active || ownFinished;
            hangupButton.disabled = !active;
            const canMute = active && String(state.status || '') === 'in-progress';
            muteButton.disabled = !canMute;
            const muted = Boolean(state.muted);
            muteButton.setAttribute('aria-pressed', muted ? 'true' : 'false');
            muteLabel.textContent = muted ? 'Activar micrófono' : 'Silenciar';
            muteIcon.className = muted ? 'bi bi-mic' : 'bi bi-mic-mute';
            newButton.hidden = !ownFinished;
            newButton.disabled = pendingResult || savingResult;
            resultBlock.hidden = !pendingResult;
            afterCall.hidden = !ownFinished ||
                !String(state.destination || numberInput.value || '').trim();

            if (active) {
                say(muted ? 'Micrófono silenciado' : (state.message || 'Llamada en curso…'));
            } else if (ownFinished) {
                if (!canClassify) {
                    say('La llamada aún no tiene identificador verificable. Actualiza el historial para clasificarla.');
                } else {
                    say(pendingResult
                        ? 'Registra el resultado de esta llamada para continuar.'
                        : 'Resultado registrado. Puedes iniciar otra llamada y actualizar el historial.');
                }
            } else if (phase === 'error') {
                say(state.message || 'No fue posible realizar la llamada.', true);
            }
        }

        root.querySelectorAll('[data-dial-key]').forEach(function (button) {
            button.addEventListener('click', function () {
                const key = String(button.dataset.dialKey || '');
                if (key === '⌫') {
                    numberInput.value = numberInput.value.slice(0, -1);
                } else if (key === '+') {
                    if (!numberInput.value) numberInput.value = '+';
                } else if (/^\d$/.test(key)) {
                    numberInput.value += key;
                }
                numberInput.focus();
            });
        });

        root.querySelector('[data-telephony-dialer-form]').addEventListener(
            'submit',
            function (event) {
                event.preventDefault();
                if (dialing || !ready || !api) return;

                let destination;
                try {
                    destination = normalizeNumber(numberInput.value);
                } catch (error) {
                    say(error.message, true);
                    return;
                }

                dialing = true;
                renderCall();
                // Reutilizamos el host WebRTC actual, sin procesos de venta.
                void api.startCall({
                    destination: destination,
                    institution: '',
                    context: {type: 'DIALER'}
                }).then(function () {
                    say('Solicitud de llamada enviada. Esperando a Zadarma…');
                }).catch(function (error) {
                    say(error.message || 'No fue posible iniciar la llamada.', true);
                }).finally(function () {
                    dialing = false;
                    renderCall();
                });
            }
        );

        hangupButton.addEventListener('click', function () {
            api?.hangup?.();
            say('Finalizando llamada…');
        });

        muteButton.addEventListener('click', function () {
            if (!muteButton.disabled) {
                api?.toggleMute?.();
            }
        });

        saveAfterCall.addEventListener('click', function () {
            const state = api?.getState?.() || {};
            if (state.active || String(state.context?.type || '').toUpperCase() !== 'DIALER') {
                return;
            }
            contactNumber.value = String(state.destination || numberInput.value || '');
            sayContact('Escribe el nombre del prospecto y pulsa Guardar número.');
            contactName.focus();
        });

        newButton.addEventListener('click', function () {
            const state = api?.getState?.() || {};
            if (state.active || newButton.disabled) return;
            if (String(state.context?.type || '').toUpperCase() === 'DIALER') {
                api?.clearFinished?.();
            }
            numberInput.value = '';
            say('Listo para marcar.');
            renderCall();
            numberInput.focus();
        });

        function updateEmpty() {
            contactEmpty.hidden =
                contactList.querySelectorAll('[data-telephony-contact-row]').length > 0;
        }

        function createContactRow(contact) {
            const row = document.createElement('article');
            row.className = 'telephony-contact-row';
            row.setAttribute('data-telephony-contact-row', '');
            row.dataset.contactId = String(contact.id);
            row.dataset.contactNumber = String(contact.telefono);

            const details = document.createElement('div');
            details.className = 'telephony-contact-name';
            const name = document.createElement('strong');
            name.setAttribute('data-contact-name', '');
            name.textContent = String(contact.nombre);
            const phone = document.createElement('small');
            phone.setAttribute('data-contact-display-number', '');
            phone.textContent = String(contact.telefono);
            details.append(name, phone);

            const actions = document.createElement('div');
            actions.className = 'telephony-contact-actions';
            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'btn btn-system-light';
            use.setAttribute('data-telephony-contact-use', '');
            use.textContent = 'Usar número';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'telephony-contact-remove';
            remove.setAttribute('data-telephony-contact-remove', '');
            remove.setAttribute('aria-label', 'Eliminar teléfono guardado');
            const icon = document.createElement('i');
            icon.className = 'bi bi-trash';
            icon.setAttribute('aria-hidden', 'true');
            remove.appendChild(icon);
            actions.append(use, remove);
            row.append(details, actions);
            return row;
        }

        function upsertContact(contact) {
            let existing = null;
            contactList.querySelectorAll('[data-telephony-contact-row]').forEach(
                function (row) {
                    if (row.dataset.contactId === String(contact.id)) {
                        existing = row;
                    }
                }
            );

            const row = createContactRow(contact);
            if (existing) {
                existing.replaceWith(row);
            } else {
                contactList.prepend(row);
            }
            updateEmpty();
        }

        async function postContact(url, fields) {
            const body = new URLSearchParams();
            body.set('csrf_token', String(root.dataset.csrfToken || ''));
            Object.entries(fields).forEach(function (entry) {
                body.set(entry[0], String(entry[1]));
            });

            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            });

            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('No se recibió una respuesta válida. Revisa tu sesión.');
            }
            if (!response.ok || !result.ok) {
                throw new Error(result.mensaje || 'La operación no pudo completarse.');
            }
            return result;
        }

        async function guardarClasificacion(pbxId, resultado) {
            if (!/^out_[a-fA-F0-9]{32,64}$/.test(pbxId) || !resultado) {
                throw new Error('Selecciona un resultado válido de una llamada finalizada.');
            }
            const response = await postContact(root.dataset.resultSaveUrl, {
                pbx_call_id: pbxId,
                resultado: resultado
            });
            classifiedCalls.set(pbxId, resultado);
            return response.registro || {};
        }

        function reflejarResultadoEnFila(form, codigo, registro) {
            if (!form) return;
            const select = form.querySelector('[data-sales-history-result-select]');
            if (select) select.value = codigo;
            const row = form.closest('tr');
            const label = form.parentElement?.querySelector('[data-sales-result-badge]');
            if (label) {
                label.textContent = registro.etiqueta || codigo;
                label.classList.add('is-classified');
            }

            // Si se registró buzón u otro resultado sin contacto humano,
            // detener cualquier audio que ya estuviera abierto en la página.
            if (codigo !== 'CONVERSACION_PERSONA' && row) {
                const next = row.nextElementSibling;
                if (next?.hasAttribute('data-sales-recording-row')) {
                    const audio = next.querySelector('audio');
                    if (audio) {
                        audio.pause();
                        audio.removeAttribute('src');
                        audio.load();
                    }
                    next.remove();
                }
                const cell = row.querySelector('.telephony-recording-cell');
                if (cell) {
                    cell.textContent = 'No aplica';
                }
            }
        }

        resultButton.addEventListener('click', function () {
            const state = api?.getState?.() || {};
            const pbxId = String(state.finalMetadata?.pbx_call_id || state.pbxCallId || '');
            if (state.active || savingResult) return;
            const codigo = resultSelect.value;
            if (!codigo) {
                resultFeedback.textContent = 'Selecciona qué ocurrió durante la llamada.';
                return;
            }
            savingResult = true;
            resultButton.disabled = true;
            resultFeedback.textContent = 'Registrando el resultado…';
            void guardarClasificacion(pbxId, codigo).then(function (registro) {
                resultFeedback.textContent = 'Resultado registrado. Actualiza el historial para ver los cambios.';
                historyTable?.querySelectorAll('[data-sales-history-result-form]').forEach(function (form) {
                    if (form.dataset.pbxCallId === pbxId) {
                        reflejarResultadoEnFila(form, codigo, registro);
                    }
                });
            }).catch(function (error) {
                resultFeedback.textContent = error.message ||
                    'No fue posible registrar el resultado.';
            }).finally(function () {
                savingResult = false;
                resultButton.disabled = false;
                renderCall();
            });
        });

        historyTable?.addEventListener('submit', function (event) {
            const form = event.target.closest('[data-sales-history-result-form]');
            if (!form) return;
            event.preventDefault();
            if (form.dataset.saving === '1') return;
            const select = form.querySelector('[data-sales-history-result-select]');
            const boton = form.querySelector('[data-sales-history-result-submit]');
            const feedback = form.parentElement?.querySelector('[data-sales-history-feedback]');
            if (!select?.value) {
                if (feedback) feedback.textContent = 'Selecciona un resultado.';
                return;
            }
            form.dataset.saving = '1';
            if (boton) boton.disabled = true;
            if (feedback) feedback.textContent = 'Guardando…';
            void guardarClasificacion(form.dataset.pbxCallId || '', select.value)
                .then(function (registro) {
                    reflejarResultadoEnFila(form, select.value, registro);
                    if (feedback) feedback.textContent =
                        'Guardado. Actualiza para reflejar la grabación.';
                    renderCall();
                }).catch(function (error) {
                    if (feedback) feedback.textContent = error.message;
                }).finally(function () {
                    form.dataset.saving = '0';
                    if (boton) boton.disabled = false;
                });
        });

        root.querySelector('[data-telephony-contact-from-dialer]')
            .addEventListener('click', function () {
                contactNumber.value = numberInput.value;
                contactName.focus();
                sayContact(
                    contactNumber.value
                        ? 'Escribe el nombre del prospecto para guardar su teléfono.'
                        : 'Primero escribe un número en el marcador.'
                );
            });

        contactForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (saving) return;

            try {
                normalizeNumber(contactNumber.value);
            } catch (error) {
                sayContact(error.message, true);
                return;
            }
            if (contactName.value.trim().length < 2) {
                sayContact('Escribe el nombre del prospecto (mínimo 2 caracteres).', true);
                return;
            }

            saving = true;
            saveButton.disabled = true;
            sayContact('Guardando teléfono…');
            void postContact(root.dataset.contactAddUrl, {
                nombre: contactName.value.trim(),
                telefono: contactNumber.value.trim()
            }).then(function (result) {
                upsertContact(result.contacto);
                contactForm.reset();
                sayContact('Teléfono guardado. Puedes seleccionarlo para marcar.');
            }).catch(function (error) {
                sayContact(error.message, true);
            }).finally(function () {
                saving = false;
                saveButton.disabled = false;
            });
        });

        contactList.addEventListener('click', function (event) {
            const button = event.target.closest('button');
            const row = event.target.closest('[data-telephony-contact-row]');
            if (!button || !row) return;

            if (button.hasAttribute('data-telephony-contact-use')) {
                numberInput.value = row.dataset.contactNumber || '';
                say('Número seleccionado. Pulsa Llamar para iniciar la llamada.');
                numberInput.focus();
                return;
            }

            if (!button.hasAttribute('data-telephony-contact-remove')) return;
            if (!window.confirm('¿Eliminar este teléfono de tu agenda personal?')) return;

            button.disabled = true;
            void postContact(root.dataset.contactDeleteUrl, {
                contacto_id: row.dataset.contactId
            }).then(function () {
                row.remove();
                updateEmpty();
                sayContact('Teléfono eliminado.');
            }).catch(function (error) {
                button.disabled = false;
                sayContact(error.message, true);
            });
        });

        function applyExtension(info) {
            const extension = String(info?.extension || '').trim();
            extensionAssigned = /^\d{3,6}$/.test(extension);
            ready = extensionAssigned && Boolean(info?.permite_salientes);
            root.dataset.canCall = ready ? '1' : '0';

            if (identity) {
                identity.textContent = extensionAssigned
                    ? 'Extensión ' + extension : 'Sin extensión asignada';
            }
            if (salesStatus) {
                salesStatus.textContent = extensionAssigned
                    ? 'Extensión ' + extension : 'Extensión pendiente';
            }
            if (salesCaption) {
                salesCaption.textContent = ready
                    ? 'Teléfono disponible para realizar llamadas'
                    : 'Solicita la configuración al administrador';
            }
            if (warning) {
                warning.hidden = ready;
            }

            const state = api?.getState?.() || {};
            if (!state.active && state.phase !== 'finished') {
                if (ready) {
                    say('Listo para marcar. Autoriza el micrófono cuando el navegador lo solicite.');
                } else if (warning && !warning.hidden) {
                    // El mensaje amarillo explica el pendiente sin duplicar avisos.
                    say('');
                } else {
                    say('La extensión no tiene llamadas salientes habilitadas.', true);
                }
            }
            renderCall();
        }

        function verifyExtension(forceRefresh) {
            if (!api || typeof api.probe !== 'function' || checkingPhone) return;
            if (api?.getState?.()?.active) return;
            checkingPhone = true;
            void api.probe(Boolean(forceRefresh)).then(function (info) {
                applyExtension(info);
            }).catch(function (error) {
                ready = false;
                extensionAssigned = false;
                root.dataset.canCall = '0';
                if (salesStatus) salesStatus.textContent = 'Extensión pendiente';
                if (salesCaption) salesCaption.textContent = 'Solicita la configuración al administrador';
                if (identity) identity.textContent = 'Sin extensión asignada';
                if (warning && !warning.hidden) {
                    say('');
                } else {
                    say(error.message || 'No fue posible consultar la extensión.', true);
                }
                renderCall();
            }).finally(function () {
                checkingPhone = false;
            });
        }

        if (!api || typeof api.probe !== 'function') {
            say('El motor telefónico no está disponible en este perfil.', true);
            return;
        }

        api.subscribe(renderCall);
        renderCall();
        updateEmpty();
        verifyExtension(true);
        // Sin recargar el Inicio, detecta una extensión asignada por el administrador.
        window.setInterval(function () {
            if (!ready && !checkingPhone) verifyExtension(true);
        }, 30000);
        window.addEventListener('focus', function () {
            if (!ready) verifyExtension(true);
        });
    });
})();
