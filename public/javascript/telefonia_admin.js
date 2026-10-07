(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('[data-telephony-admin]');
        const configModalEl = document.getElementById('modalTelefoniaExtension');
        const releaseModalEl = document.getElementById('modalTelefoniaLiberar');

        if (
            !root ||
            !configModalEl ||
            !releaseModalEl ||
            !window.bootstrap
        ) {
            return;
        }

        const configModal =
            bootstrap.Modal.getOrCreateInstance(configModalEl);
        const releaseModal =
            bootstrap.Modal.getOrCreateInstance(releaseModalEl);

        const configForm =
            configModalEl.querySelector('[data-telephony-config-form]');
        const userId =
            configModalEl.querySelector('[data-telephony-user-id]');
        const userLabel =
            configModalEl.querySelector('[data-telephony-modal-user]');
        const extension =
            configModalEl.querySelector('[data-telephony-extension]');
        const callerId =
            configModalEl.querySelector('[data-telephony-caller-id]');
        const outgoing =
            configModalEl.querySelector('[data-telephony-outgoing]');
        const incoming =
            configModalEl.querySelector('[data-telephony-incoming]');
        const active =
            configModalEl.querySelector('[data-telephony-active]');
        const activeHelp =
            configModalEl.querySelector('[data-telephony-active-help]');

        const releaseUserId =
            releaseModalEl.querySelector(
                '[data-telephony-release-user-id]'
            );
        const releaseTitle =
            releaseModalEl.querySelector(
                '[data-telephony-release-title]'
            );

        if (
            !configForm ||
            !userId ||
            !extension ||
            !callerId ||
            !outgoing ||
            !incoming ||
            !active
        ) {
            return;
        }

        const bool = function (value) {
            return String(value || '') === '1';
        };

        const cargarConfig = function (data) {
            const usuarioActivo = bool(data.userActive);

            userId.value = String(data.userId || '');
            extension.value = String(data.extension || '');
            callerId.value = String(data.callerId || '');
            outgoing.checked = bool(data.outgoing);
            incoming.checked = bool(data.incoming);
            active.checked = usuarioActivo && bool(data.active);
            active.disabled = !usuarioActivo;

            if (userLabel) {
                userLabel.textContent =
                    String(data.userName || 'Usuario') +
                    ' · ' +
                    String(data.userRole || 'Rol');
            }

            if (activeHelp) {
                activeHelp.textContent = usuarioActivo
                    ? 'El usuario podrá usar esta identidad telefónica.'
                    : 'El usuario está inactivo; la extensión no puede activarse.';
            }
        };

        document.querySelectorAll('[data-telephony-configure]')
            .forEach(function (button) {
                button.addEventListener('click', function () {
                    cargarConfig(button.dataset);
                    configModal.show();
                    window.setTimeout(function () {
                        extension.focus();
                    }, 180);
                });
            });

        document.querySelectorAll('[data-telephony-release]')
            .forEach(function (button) {
                button.addEventListener('click', function () {
                    if (releaseUserId) {
                        releaseUserId.value =
                            String(button.dataset.userId || '');
                    }

                    if (releaseTitle) {
                        releaseTitle.textContent =
                            'Liberar extensión ' +
                            String(button.dataset.extension || '') +
                            ' de ' +
                            String(button.dataset.userName || 'este usuario');
                    }

                    releaseModal.show();
                });
            });

        configForm.addEventListener('submit', function (event) {
            const ext = String(extension.value || '').trim();

            if (!/^\d{3,6}$/.test(ext)) {
                event.preventDefault();
                extension.setCustomValidity(
                    'Ingresa una extensión de 3 a 6 dígitos.'
                );
                extension.reportValidity();
                return;
            }

            extension.setCustomValidity('');

            if (
                active.checked &&
                !outgoing.checked &&
                !incoming.checked
            ) {
                event.preventDefault();
                outgoing.setCustomValidity(
                    'Una extensión activa debe permitir llamadas salientes, entrantes o ambas.'
                );
                outgoing.reportValidity();
                return;
            }

            outgoing.setCustomValidity('');
        });

        [outgoing, incoming, active, extension].forEach(function (input) {
            input.addEventListener('change', function () {
                outgoing.setCustomValidity('');
                extension.setCustomValidity('');
            });
        });

        const reopenUser = Number(root.dataset.reopenUser || 0);

        if (reopenUser > 0) {
            const button = document.querySelector(
                '[data-telephony-configure][data-user-id="' +
                reopenUser +
                '"]'
            );

            if (button) {
                const data = Object.assign({}, button.dataset, {
                    extension:
                        String(root.dataset.reopenExtension || ''),
                    callerId:
                        String(root.dataset.reopenCallerId || ''),
                    outgoing:
                        String(root.dataset.reopenOutgoing || '0'),
                    incoming:
                        String(root.dataset.reopenIncoming || '0'),
                    active:
                        String(root.dataset.reopenActive || '0')
                });

                cargarConfig(data);
                configModal.show();
            }
        }
    });
})();
