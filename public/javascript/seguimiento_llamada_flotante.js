(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const rolId =
            Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const phone =
            window.IMPE_TELEPHONY_PERSISTENT || null;
        const offcanvas =
            document.getElementById(
                'offcanvasSeguimientoTrabajo'
            );

        if (
            rolId !== 4 ||
            !window.bootstrap ||
            !phone
        ) {
            return;
        }

        const modalId =
            'modalLlamadaVinculacion';
        let modalEl = null;
        let toggleButton = null;
        let initialized = false;

        const callIsActive = function () {
            const state =
                phone.getState();

            return Boolean(
                state?.active &&
                String(
                    state?.context?.type || ''
                ) === 'VINCULACION'
            );
        };

        const minimize = function () {
            if (
                !modalEl ||
                !callIsActive()
            ) {
                return;
            }

            modalEl.dataset
                .allowPersistentHide = '1';

            const instance =
                bootstrap.Modal.getInstance(
                    modalEl
                ) ||
                bootstrap.Modal
                    .getOrCreateInstance(
                        modalEl,
                        {
                            backdrop: 'static',
                            keyboard: false
                        }
                    );

            instance.hide();
        };

        const restore = function () {
            if (!modalEl) {
                return;
            }

            const state =
                phone.getState();

            if (
                !state?.active &&
                state?.phase !== 'finished'
            ) {
                return;
            }

            document.dispatchEvent(
                new CustomEvent(
                    'impe:telephony-restore-call',
                    { detail: state }
                )
            );
        };

        const initialize = function (element) {
            if (
                initialized ||
                !element
            ) {
                return;
            }

            modalEl = element;

            const header =
                modalEl.querySelector(
                    '.modal-header'
                );
            const closeButton =
                header?.querySelector(
                    '.btn-close'
                );

            if (!header) {
                return;
            }

            initialized = true;

            const actions =
                document.createElement('div');
            actions.className =
                'linkage-call-window-actions';

            toggleButton =
                document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className =
                'linkage-call-window-toggle';
            toggleButton.setAttribute(
                'aria-label',
                'Minimizar llamada'
            );
            toggleButton.setAttribute(
                'title',
                'Minimizar y seguir trabajando'
            );
            toggleButton.innerHTML =
                '<i class="bi bi-dash-lg" aria-hidden="true"></i>' +
                '<span>Minimizar</span>';

            actions.appendChild(
                toggleButton
            );

            if (closeButton) {
                actions.appendChild(
                    closeButton
                );
            }

            header.appendChild(
                actions
            );

            toggleButton.addEventListener(
                'click',
                minimize
            );

            modalEl.addEventListener(
                'shown.bs.modal',
                function () {
                    document.body.classList.add(
                        'impe-call-modal-expanded'
                    );
                }
            );

            modalEl.addEventListener(
                'hidden.bs.modal',
                function () {
                    document.body.classList.remove(
                        'impe-call-modal-expanded'
                    );
                }
            );
        };

        const locateModal = function () {
            const existing =
                document.getElementById(
                    modalId
                );

            if (existing) {
                initialize(existing);
                return true;
            }

            return false;
        };

        if (!locateModal()) {
            const observer =
                new MutationObserver(
                    function () {
                        if (locateModal()) {
                            observer.disconnect();
                        }
                    }
                );

            observer.observe(
                document.body,
                {
                    childList: true,
                    subtree: true
                }
            );
        }

        /*
         * El listener histórico de Twilio corta la llamada al cerrar el
         * panel lateral. Para Zadarma persistente detenemos solamente ese
         * listener: el audio vive en el host independiente y continúa.
         */
        offcanvas?.addEventListener(
            'hidden.bs.offcanvas',
            function (event) {
                if (!callIsActive()) {
                    return;
                }

                event.stopImmediatePropagation();

                if (
                    modalEl?.classList
                        .contains('show')
                ) {
                    minimize();
                }
            },
            true
        );

        document.addEventListener(
            'impe:telephony-restore-call',
            function () {
                if (
                    modalEl &&
                    !modalEl.classList
                        .contains('show')
                ) {
                    const instance =
                        bootstrap.Modal.getInstance(
                            modalEl
                        ) ||
                        bootstrap.Modal
                            .getOrCreateInstance(
                                modalEl,
                                {
                                    backdrop: 'static',
                                    keyboard: false
                                }
                            );

                    instance.show();
                }
            }
        );
    });
})();
