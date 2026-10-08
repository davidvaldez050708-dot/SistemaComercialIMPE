(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-telephony-dialer]');
        if (!root) return;

        const api = window.IMPE_TELEPHONY_PERSISTENT;
        const numberInput = root.querySelector('[data-telephony-dial-number]');
        const callButton = root.querySelector('[data-telephony-dial-call]');
        const hangupButton = root.querySelector('[data-telephony-dial-hangup]');
        const newButton = root.querySelector('[data-telephony-dial-new]');
        const callStatus = root.querySelector('[data-telephony-dial-status]');
        const contactForm = root.querySelector('[data-telephony-contact-form]');
        const contactName = root.querySelector('[data-telephony-contact-name]');
        const contactNumber = root.querySelector('[data-telephony-contact-number]');
        const contactList = root.querySelector('[data-telephony-contacts-list]');
        const contactEmpty = root.querySelector('[data-telephony-contacts-empty]');
        const contactFeedback = root.querySelector('[data-telephony-contact-status]');
        const saveButton = root.querySelector('[data-telephony-contact-save]');
        const hasAssignedExtension = root.dataset.canCall === '1';

        let ready = false;
        let dialing = false;
        let saving = false;

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

            callButton.disabled = !ready || dialing || active;
            hangupButton.disabled = !active;
            newButton.hidden = !ownFinished;

            if (active) {
                say(state.message || 'Llamada en curso…');
            } else if (ownFinished) {
                say(state.message || 'Llamada finalizada. Puedes marcar otro número.');
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

        newButton.addEventListener('click', function () {
            const state = api?.getState?.() || {};
            if (state.active) return;
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

        root.querySelector('[data-telephony-contact-from-dialer]')
            .addEventListener('click', function () {
                contactNumber.value = numberInput.value;
                contactName.focus();
                sayContact(
                    contactNumber.value
                        ? 'Completa el nombre y guarda el número.'
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
                sayContact('Escribe un nombre de al menos 2 caracteres.', true);
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

        if (!api || typeof api.probe !== 'function') {
            say('El motor telefónico no está disponible en este perfil.', true);
            return;
        }

        void api.probe().then(function (info) {
            ready = hasAssignedExtension && Boolean(info?.permite_salientes);
            say(
                ready
                    ? 'Listo para marcar. Autoriza el micrófono cuando el navegador lo solicite.'
                    : 'Solicita al administrador una extensión activa con llamadas salientes.',
                !ready
            );
            renderCall();
        }).catch(function (error) {
            say(error.message || 'No fue posible conectar el marcador.', true);
        });

        api.subscribe(renderCall);
        renderCall();
        updateEmpty();
    });
})();
