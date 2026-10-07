(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas =
            document.getElementById('offcanvasSeguimientoTrabajo');
        const rolId =
            Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const phone =
            window.IMPE_TELEPHONY_PERSISTENT || null;

        if (
            !offcanvas ||
            rolId !== 4 ||
            !window.bootstrap ||
            !phone ||
            typeof window.fetch !== 'function'
        ) {
            return;
        }

        const vincularUrl =
            'prueba_telefonia/api/vincular_interaccion_zadarma.php';

        let extension = '';
        let phoneReady = false;
        let activeCall = false;
        let currentPhone = '';
        let currentSeguimientoId = 0;
        let currentInstitution = '';
        let pendingMetadata = null;
        let awaitingInteractionSave = false;
        let linkingMetadata = false;
        let pendingInteractionFeedback = null;
        let modal = null;
        let els = null;
        let lastFinishedToken = '';

        window.IMPE_ZADARMA_TELEPHONY_READY = false;

        const mostrarToast = function (mensaje, esError) {
            const contenedor =
                document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className =
                'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute(
                'role',
                esError ? 'alert' : 'status'
            );
            toast.setAttribute(
                'aria-live',
                esError ? 'assertive' : 'polite'
            );
            toast.setAttribute(
                'aria-atomic',
                'true'
            );
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                    (esError
                        ? 'bi-exclamation-circle'
                        : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';

            toast.querySelector('span').textContent =
                String(mensaje || '');
            contenedor.appendChild(toast);

            const instancia =
                new bootstrap.Toast(toast, {
                    autohide: true,
                    delay: esError ? 4800 : 3400
                });

            toast.addEventListener(
                'hidden.bs.toast',
                function () {
                    toast.remove();
                }
            );
            instancia.show();
        };

        const formatDuration = function (seconds) {
            const total =
                Math.max(0, Number(seconds) || 0);
            const minutes =
                Math.floor(total / 60);
            const secs = total % 60;

            return String(minutes).padStart(2, '0') +
                ':' +
                String(secs).padStart(2, '0');
        };

        const normalizarDestino = function (valor) {
            const original =
                String(valor || '').trim();
            let digits =
                original.replace(/\D+/g, '');

            if (
                digits.length === 13 &&
                digits.startsWith('521')
            ) {
                digits =
                    '52' + digits.slice(3);
            }

            if (digits.length === 10) {
                return '+52' + digits;
            }

            if (
                digits.length === 12 &&
                digits.startsWith('52')
            ) {
                return '+' + digits;
            }

            if (
                original.startsWith('+') &&
                digits.length >= 8 &&
                digits.length <= 15
            ) {
                return '+' + digits;
            }

            return '';
        };

        const fechaLocalInput = function (valor) {
            if (!valor) {
                return '';
            }

            const texto =
                String(valor).trim();
            const coincidencia = texto.match(
                /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/
            );

            if (coincidencia) {
                return coincidencia[1] +
                    'T' +
                    coincidencia[2];
            }

            const fecha = new Date(valor);

            if (
                Number.isNaN(
                    fecha.getTime()
                )
            ) {
                return '';
            }

            const pad = function (numero) {
                return String(numero).padStart(2, '0');
            };

            return (
                fecha.getFullYear() +
                '-' +
                pad(fecha.getMonth() + 1) +
                '-' +
                pad(fecha.getDate()) +
                'T' +
                pad(fecha.getHours()) +
                ':' +
                pad(fecha.getMinutes())
            );
        };

        const statusLabel = function (status) {
            const labels = {
                loading: 'Preparando teléfono…',
                ready: 'Teléfono listo',
                dialing: 'Marcando…',
                ringing: 'Timbrando…',
                'in-progress': 'Llamada en curso',
                finishing: 'Finalizando…',
                completed: 'Llamada finalizada',
                busy: 'Línea ocupada',
                'no-answer': 'Sin respuesta',
                failed: 'Llamada fallida',
                canceled: 'Llamada cancelada',
                interrupted: 'Telefonía interrumpida',
                error: 'Telefonía no disponible'
            };

            return (
                labels[String(status || '')] ||
                'Procesando llamada…'
            );
        };

        const stateDuration = function (telephonyState) {
            let duration =
                Math.max(
                    0,
                    Number(
                        telephonyState?.duration || 0
                    )
                );
            const answeredAt =
                Number(
                    telephonyState?.answeredAtMs || 0
                );

            if (
                telephonyState?.active &&
                answeredAt > 0
            ) {
                duration = Math.max(
                    duration,
                    Math.floor(
                        (
                            Date.now() -
                            answeredAt
                        ) / 1000
                    )
                );
            }

            return duration;
        };

        const createModal = function () {
            let modalEl =
                document.getElementById(
                    'modalLlamadaVinculacion'
                );

            if (!modalEl) {
                modalEl =
                    document.createElement('div');
                modalEl.className = 'modal fade';
                modalEl.id =
                    'modalLlamadaVinculacion';
                modalEl.tabIndex = -1;
                modalEl.setAttribute(
                    'aria-hidden',
                    'true'
                );
                modalEl.innerHTML =
                    '<div class="modal-dialog modal-dialog-centered linkage-call-dialog">' +
                        '<div class="modal-content linkage-call-modal">' +
                            '<div class="modal-header border-0 pb-0">' +
                                '<div>' +
                                    '<span class="linkage-call-eyebrow">LLAMADA INSTITUCIONAL</span>' +
                                    '<h5 class="modal-title" data-call-institution>Institución</h5>' +
                                '</div>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="linkage-call-number" data-call-number>—</div>' +
                                '<div class="linkage-call-origin" data-call-origin>' +
                                    '<i class="bi bi-building-check" aria-hidden="true"></i>' +
                                    '<span>Desde: <strong data-call-extension>Extensión —</strong> · Telefonía IP</span>' +
                                '</div>' +
                                '<div class="linkage-call-state">' +
                                    '<span data-call-status>Preparando teléfono…</span>' +
                                    '<strong data-call-timer>00:00</strong>' +
                                '</div>' +
                                '<div class="linkage-call-actions">' +
                                    '<button type="button" class="btn btn-system-save" data-call-start disabled>' +
                                        '<i class="bi bi-telephone"></i> Llamar' +
                                    '</button>' +
                                    '<button type="button" class="btn btn-system-light" data-call-mute disabled>' +
                                        '<i class="bi bi-mic-mute"></i> Silenciar' +
                                    '</button>' +
                                    '<button type="button" class="btn btn-outline-danger" data-call-hangup disabled>' +
                                        '<i class="bi bi-telephone-x"></i> Colgar' +
                                    '</button>' +
                                '</div>' +
                                '<div class="linkage-call-result d-none" data-call-result>' +
                                    '<i class="bi bi-check2-circle"></i>' +
                                    '<div><strong>Llamada finalizada</strong><span data-call-result-text>Registra el resultado para continuar la ruta.</span></div>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer border-0 pt-0">' +
                                '<button type="button" class="btn btn-system-save d-none" data-call-register>' +
                                    '<i class="bi bi-journal-check"></i> Registrar resultado' +
                                '</button>' +
                                '<button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';

                document.body.appendChild(modalEl);
            }

            modalEl.dataset.callProvider =
                'ZADARMA';

            els = {
                modal: modalEl,
                institution:
                    modalEl.querySelector(
                        '[data-call-institution]'
                    ),
                number:
                    modalEl.querySelector(
                        '[data-call-number]'
                    ),
                extension:
                    modalEl.querySelector(
                        '[data-call-extension]'
                    ),
                status:
                    modalEl.querySelector(
                        '[data-call-status]'
                    ),
                timer:
                    modalEl.querySelector(
                        '[data-call-timer]'
                    ),
                start:
                    modalEl.querySelector(
                        '[data-call-start]'
                    ),
                mute:
                    modalEl.querySelector(
                        '[data-call-mute]'
                    ),
                hangup:
                    modalEl.querySelector(
                        '[data-call-hangup]'
                    ),
                result:
                    modalEl.querySelector(
                        '[data-call-result]'
                    ),
                resultText:
                    modalEl.querySelector(
                        '[data-call-result-text]'
                    ),
                register:
                    modalEl.querySelector(
                        '[data-call-register]'
                    )
            };

            modal =
                bootstrap.Modal.getOrCreateInstance(
                    modalEl,
                    {
                        backdrop: 'static',
                        keyboard: false
                    }
                );

            if (
                modalEl.dataset
                    .zadarmaEventsReady !== '1'
            ) {
                modalEl.dataset
                    .zadarmaEventsReady = '1';

                modalEl.addEventListener(
                    'hide.bs.modal',
                    function (event) {
                        if (
                            activeCall &&
                            modalEl.dataset
                                .allowPersistentHide !== '1'
                        ) {
                            event.preventDefault();
                            mostrarToast(
                                'Minimiza la llamada para seguir trabajando sin cortarla.',
                                true
                            );
                        }
                    }
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
                        delete modalEl.dataset
                            .allowPersistentHide;
                    }
                );

                els.start.addEventListener(
                    'click',
                    makeCall
                );

                els.mute.addEventListener(
                    'click',
                    function () {
                        phone.toggleMute();
                    }
                );

                els.hangup.addEventListener(
                    'click',
                    function () {
                        if (!activeCall) {
                            return;
                        }

                        els.status.textContent =
                            'Finalizando…';
                        els.hangup.disabled = true;
                        phone.hangup();
                    }
                );

                els.register.addEventListener(
                    'click',
                    abrirRegistroResultado
                );
            }
        };

        const resetModal = function () {
            if (!els) {
                return;
            }

            activeCall = false;
            pendingMetadata = null;
            els.timer.textContent = '00:00';
            els.status.textContent =
                phoneReady
                    ? 'Teléfono listo'
                    : 'Preparando teléfono…';
            els.start.disabled = !phoneReady;
            els.mute.disabled = true;
            els.mute.innerHTML =
                '<i class="bi bi-mic-mute"></i> Silenciar';
            els.hangup.disabled = true;
            els.result.classList.add('d-none');
            els.register.classList.add('d-none');
        };

        const buildFinishedText = function (
            metadata
        ) {
            const duration =
                Math.max(
                    0,
                    Number(
                        metadata?.duration || 0
                    )
                );
            const status =
                String(metadata?.status || '');
            const recordingState =
                String(
                    metadata?.recording_state ||
                    'none'
                );

            if (duration > 0) {
                return (
                    'Conversación: ' +
                    formatDuration(duration) +
                    (
                        recordingState ===
                        'available'
                            ? '. La grabación ya fue confirmada por el proveedor de telefonía.'
                            : '. La grabación se está procesando en el proveedor de telefonía.'
                    )
                );
            }

            if (status === 'busy') {
                return (
                    'La línea estaba ocupada. Registra el resultado para conservar el intento.'
                );
            }

            if (status === 'no-answer') {
                return (
                    'No hubo respuesta. Registra el resultado para conservar el intento.'
                );
            }

            return (
                'La llamada terminó. Registra el resultado para conservar la gestión.'
            );
        };

        const applyState = function (
            telephonyState
        ) {
            if (!telephonyState) {
                return;
            }

            extension =
                String(
                    telephonyState.extension ||
                    extension ||
                    ''
                );

            const context =
                telephonyState.context || {};
            const isLinkage =
                String(context.type || '') ===
                    'VINCULACION';

            if (
                isLinkage &&
                Number(context.seguimientoId || 0) > 0
            ) {
                currentSeguimientoId =
                    Number(context.seguimientoId);
                currentPhone =
                    String(
                        telephonyState.destination ||
                        currentPhone ||
                        ''
                    );
                currentInstitution =
                    String(
                        telephonyState.institution ||
                        currentInstitution ||
                        'Institución'
                    );
            }

            if (!els) {
                return;
            }

            if (
                isLinkage &&
                currentSeguimientoId > 0
            ) {
                els.institution.textContent =
                    currentInstitution ||
                    'Institución';
                els.number.textContent =
                    currentPhone || '—';
            }

            els.extension.textContent =
                extension
                    ? 'Extensión ' + extension
                    : 'Extensión —';

            if (
                telephonyState.active &&
                isLinkage
            ) {
                activeCall = true;
                pendingMetadata = null;

                els.status.textContent =
                    statusLabel(
                        telephonyState.status ||
                        telephonyState.phase
                    );
                els.timer.textContent =
                    formatDuration(
                        stateDuration(
                            telephonyState
                        )
                    );
                els.start.disabled = true;
                els.hangup.disabled =
                    String(
                        telephonyState.status ||
                        ''
                    ) === 'finishing';
                els.mute.disabled =
                    String(
                        telephonyState.status ||
                        ''
                    ) !== 'in-progress';
                els.mute.innerHTML =
                    telephonyState.muted
                        ? '<i class="bi bi-mic"></i> Activar micrófono'
                        : '<i class="bi bi-mic-mute"></i> Silenciar';
                els.result.classList.add(
                    'd-none'
                );
                els.register.classList.add(
                    'd-none'
                );
                return;
            }

            if (
                telephonyState.phase ===
                    'finished' &&
                isLinkage &&
                telephonyState.finalMetadata
            ) {
                activeCall = false;

                const token =
                    String(
                        telephonyState.callToken ||
                        telephonyState
                            .finalMetadata
                            ?.call_token ||
                        ''
                    );

                pendingMetadata =
                    Object.assign(
                        {},
                        telephonyState
                            .finalMetadata,
                        {
                            seguimiento_id:
                                currentSeguimientoId,
                            call_token: token
                        }
                    );
                lastFinishedToken = token;

                els.status.textContent =
                    statusLabel(
                        pendingMetadata.status
                    );
                els.timer.textContent =
                    formatDuration(
                        pendingMetadata.duration
                    );
                els.start.disabled =
                    !phoneReady;
                els.mute.disabled = true;
                els.hangup.disabled = true;
                els.resultText.textContent =
                    buildFinishedText(
                        pendingMetadata
                    );
                els.result.classList.remove(
                    'd-none'
                );
                els.register.classList.remove(
                    'd-none'
                );
                return;
            }

            if (
                !telephonyState.active &&
                telephonyState.phase !==
                    'finished'
            ) {
                activeCall = false;
                els.hangup.disabled = true;
                els.mute.disabled = true;
            }
        };

        const ensureReady = function (openHost) {
            return phone.prepare({
                openHost: Boolean(openHost)
            }).then(function (info) {
                extension =
                    String(
                        info.extension ||
                        extension ||
                        ''
                    );
                phoneReady = true;
                window
                    .IMPE_ZADARMA_TELEPHONY_READY =
                    true;

                if (els) {
                    els.extension.textContent =
                        extension
                            ? 'Extensión ' +
                                extension
                            : 'Extensión —';

                    if (
                        !activeCall &&
                        !pendingMetadata
                    ) {
                        els.status.textContent =
                            'Teléfono listo';
                        els.start.disabled =
                            false;
                    }
                }

                return info;
            });
        };

        async function makeCall() {
            if (
                !phoneReady ||
                activeCall ||
                !currentPhone
            ) {
                return;
            }

            try {
                activeCall = true;
                pendingMetadata = null;
                els.status.textContent =
                    'Marcando…';
                els.start.disabled = true;
                els.mute.disabled = true;
                els.hangup.disabled = false;

                await phone.startCall({
                    destination: currentPhone,
                    institution:
                        currentInstitution,
                    context: {
                        type: 'VINCULACION',
                        seguimientoId:
                            currentSeguimientoId,
                        originUrl:
                            phone
                                .currentUrlWithoutPhoneParams()
                    }
                });
            } catch (error) {
                activeCall = false;
                els.start.disabled =
                    !phoneReady;
                els.hangup.disabled = true;
                els.status.textContent =
                    'No se pudo iniciar la llamada';

                mostrarToast(
                    error.message ||
                    'No fue posible iniciar la llamada con el proveedor de telefonía.',
                    true
                );
            }
        }

        const abrirLlamada = function () {
            const estadoActual =
                phone.getState();

            if (estadoActual?.active) {
                mostrarToast(
                    'Ya existe una llamada activa. Usa el control flotante para continuarla o finalizarla.',
                    true
                );
                phone.openContext(false);
                return;
            }

            if (
                estadoActual?.phase === 'finished' &&
                estadoActual?.finalMetadata
            ) {
                mostrarToast(
                    'Primero registra el resultado de la llamada anterior.',
                    true
                );
                phone.openContext(true);
                return;
            }

            const telefonoPanel =
                String(
                    offcanvas.querySelector(
                        '[data-work-phone]'
                    )?.textContent || ''
                ).trim();
            const telefono =
                normalizarDestino(
                    telefonoPanel
                );
            const seguimientoId =
                Number(
                    offcanvas.dataset
                        .flowSeguimientoId || 0
                );

            if (!telefono) {
                mostrarToast(
                    'No hay un teléfono válido para realizar la llamada.',
                    true
                );
                return;
            }

            if (seguimientoId <= 0) {
                mostrarToast(
                    'No se pudo identificar el seguimiento activo.',
                    true
                );
                return;
            }

            if (!els) {
                createModal();
            }

            currentPhone = telefono;
            currentSeguimientoId =
                seguimientoId;
            currentInstitution =
                String(
                    offcanvas.querySelector(
                        '[data-work-title]'
                    )?.textContent ||
                    'Institución'
                ).trim();

            pendingMetadata = null;
            awaitingInteractionSave = false;
            resetModal();

            els.institution.textContent =
                currentInstitution ||
                'Institución';
            els.number.textContent =
                currentPhone;
            els.extension.textContent =
                extension
                    ? 'Extensión ' + extension
                    : 'Extensión —';
            els.status.textContent =
                'Preparando teléfono…';
            modal.show();

            /*
             * Se abre el host dentro del gesto del usuario para que
             * el navegador permita mantener el WebRTC fuera de esta página.
             */
            ensureReady(true).catch(
                function (error) {
                    els.status.textContent =
                        'Telefonía no disponible';
                    els.start.disabled = true;

                    mostrarToast(
                        error.message ||
                        'No fue posible iniciar la telefonía WebRTC.',
                        true
                    );
                }
            );
        };

        const agregarResumenTecnico =
            function (formulario, metadata) {
                let aviso =
                    formulario.querySelector(
                        '[data-twilio-call-summary]'
                    );

                if (!aviso) {
                    aviso =
                        document.createElement(
                            'div'
                        );
                    aviso.className = 'col-12';
                    aviso.setAttribute(
                        'data-twilio-call-summary',
                        ''
                    );
                    formulario
                        .querySelector('.row')
                        ?.prepend(aviso);
                }

                const status =
                    String(
                        metadata.status || ''
                    );
                const label =
                    status === 'completed'
                        ? 'Completada'
                        : (
                            status ===
                            'no-answer'
                                ? 'Sin respuesta'
                                : (
                                    status ===
                                    'busy'
                                        ? 'Ocupado'
                                        : 'Finalizada'
                                )
                        );
                const recordingState =
                    String(
                        metadata.recording_state ||
                        'none'
                    );
                const recordingText =
                    recordingState ===
                    'available'
                        ? ' · grabación disponible.'
                        : (
                            recordingState ===
                            'processing'
                                ? ' · grabación procesándose.'
                                : '.'
                        );

                aviso.dataset
                    .callDurationSeconds =
                    String(
                        Math.max(
                            0,
                            Number(
                                metadata
                                    .duration || 0
                            )
                        )
                    );
                aviso.dataset
                    .callRecordingState =
                    recordingState;
                aviso.innerHTML =
                    '<div class="alert alert-light border mb-1 py-2 px-3 small">' +
                        '<i class="bi bi-telephone-fill me-1"></i>' +
                        '<strong>Llamada real vinculada.</strong> ' +
                        label +
                        ' · ' +
                        formatDuration(
                            metadata.duration
                        ) +
                        recordingText +
                    '</div>';
            };

        function abrirRegistroResultado() {
            if (!pendingMetadata) {
                return;
            }

            const metadata =
                pendingMetadata;

            if (modal) {
                const modalEl =
                    document.getElementById(
                        'modalLlamadaVinculacion'
                    );

                if (modalEl) {
                    modalEl.dataset
                        .allowPersistentHide = '1';
                }

                modal.hide();
            }

            const boton =
                offcanvas.querySelector(
                    '[data-work-toggle-interaction]'
                );
            const formulario =
                offcanvas.querySelector(
                    '[data-work-interaction-form]'
                );

            if (!formulario) {
                mostrarToast(
                    'No se encontró el formulario para registrar el resultado.',
                    true
                );
                return;
            }

            if (
                formulario.classList
                    .contains('d-none') &&
                boton &&
                !boton.disabled
            ) {
                boton.click();
            }

            window.setTimeout(
                function () {
                    const canal =
                        formulario.querySelector(
                            '[name="canal"]'
                        );
                    const resultado =
                        formulario.querySelector(
                            '[name="resultado"]'
                        );
                    const fechaInicio =
                        formulario.querySelector(
                            '[name="fecha_inicio"]'
                        );

                    let origenLlamada =
                        formulario.querySelector(
                            '[name="origen_llamada"]'
                        );

                    if (!origenLlamada) {
                        origenLlamada =
                            document.createElement(
                                'input'
                            );
                        origenLlamada.type =
                            'hidden';
                        origenLlamada.name =
                            'origen_llamada';
                        formulario.appendChild(
                            origenLlamada
                        );
                    }

                    origenLlamada.value =
                        'ZADARMA';

                    if (canal) {
                        canal.value = 'LLAMADA';
                        canal.dispatchEvent(
                            new Event(
                                'change',
                                { bubbles: true }
                            )
                        );
                    }

                    if (
                        fechaInicio &&
                        metadata.start_time
                    ) {
                        const value =
                            fechaLocalInput(
                                metadata.start_time
                            );

                        if (value) {
                            fechaInicio.value =
                                value;
                        }
                    }

                    if (resultado) {
                        let empty =
                            resultado.querySelector(
                                'option[value=""]'
                            );

                        if (!empty) {
                            empty =
                                document.createElement(
                                    'option'
                                );
                            empty.value = '';
                            empty.textContent =
                                'Selecciona el resultado…';
                            resultado.prepend(
                                empty
                            );
                        }

                        if (
                            metadata.status ===
                            'no-answer'
                        ) {
                            resultado.value =
                                'SIN_RESPUESTA';
                        } else if (
                            metadata.status ===
                            'busy'
                        ) {
                            resultado.value =
                                'OCUPADO';
                        } else {
                            resultado.value = '';
                        }

                        resultado.dispatchEvent(
                            new Event(
                                'change',
                                { bubbles: true }
                            )
                        );
                    }

                    agregarResumenTecnico(
                        formulario,
                        metadata
                    );
                    formulario.classList
                        .remove('d-none');
                    formulario.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                },
                100
            );
        }

        const sleep = function (ms) {
            return new Promise(
                function (resolve) {
                    window.setTimeout(
                        resolve,
                        ms
                    );
                }
            );
        };

        const vincularMetadata =
            async function (attempt) {
                if (
                    !pendingMetadata ||
                    linkingMetadata
                ) {
                    return;
                }

                linkingMetadata = true;

                const metadata =
                    pendingMetadata;
                const formData =
                    new FormData();

                formData.set(
                    'seguimiento_id',
                    String(
                        metadata
                            .seguimiento_id
                    )
                );
                formData.set(
                    'pbx_call_id',
                    String(
                        metadata
                            .pbx_call_id || ''
                    )
                );
                formData.set(
                    'destination',
                    String(
                        metadata.to ||
                        currentPhone ||
                        ''
                    )
                );
                formData.set(
                    'since',
                    String(
                        metadata
                            .requested_at || 0
                    )
                );
                formData.set(
                    'duration_client',
                    String(
                        Math.max(
                            0,
                            Number(
                                metadata
                                    .duration || 0
                            )
                        )
                    )
                );

                try {
                    const response =
                        await fetch(
                            vincularUrl,
                            {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With':
                                        'fetch'
                                },
                                credentials:
                                    'same-origin',
                                body: formData
                            }
                        );
                    const data =
                        await response.json();

                    if (
                        [404, 409].includes(
                            response.status
                        ) &&
                        attempt < 24
                    ) {
                        linkingMetadata = false;
                        await sleep(1000);
                        void vincularMetadata(
                            attempt + 1
                        );
                        return;
                    }

                    if (
                        !response.ok ||
                        !data.ok
                    ) {
                        throw new Error(
                            data.mensaje ||
                            'No fue posible vincular los datos técnicos de la llamada.'
                        );
                    }

                    const token =
                        String(
                            metadata
                                .call_token ||
                            lastFinishedToken ||
                            ''
                        );

                    pendingMetadata = null;
                    awaitingInteractionSave =
                        false;

                    const feedback =
                        pendingInteractionFeedback ||
                        {};
                    let finalMessage =
                        'Llamada registrada · ' +
                        String(
                            feedback
                                .resultadoLabel ||
                            'resultado guardado'
                        ) +
                        '.';

                    if (
                        data.verificacion_efectiva ===
                        true
                    ) {
                        finalMessage =
                            data
                                .institucion_ya_contabilizada_hoy ===
                            true
                                ? 'Verificación válida · esta institución ya fue contabilizada hoy. La llamada quedó guardada en el expediente.'
                                : 'Llamada registrada y verificada · esta institución cuenta en la meta de hoy.';
                    } else if (
                        feedback
                            .tieneEvidencia ===
                            true &&
                        data.hubo_respuesta !==
                            true
                    ) {
                        finalMessage =
                            'Llamada registrada · no contabilizó como verificación porque el proveedor de telefonía no confirmó una respuesta.';
                    } else if (
                        feedback
                            .tieneEvidencia !==
                            true
                    ) {
                        finalMessage =
                            'Llamada registrada · ' +
                            String(
                                feedback
                                    .resultadoLabel ||
                                'resultado guardado'
                            ) +
                            ' · no contabilizó como verificación porque no se confirmó ningún dato.';
                    }

                    pendingInteractionFeedback =
                        null;
                    phone.clearFinished(token);
                    mostrarToast(
                        finalMessage,
                        false
                    );

                    const detail = {
                        seguimientoId:
                            currentSeguimientoId,
                        interaccionId:
                            Number(
                                data
                                    .interaccion_id ||
                                0
                            ),
                        provider: 'ZADARMA'
                    };

                    document.dispatchEvent(
                        new CustomEvent(
                            'impe:telephony-call-linked',
                            { detail: detail }
                        )
                    );
                    document.dispatchEvent(
                        new CustomEvent(
                            'impe:twilio-call-linked',
                            { detail: detail }
                        )
                    );
                } catch (error) {
                    awaitingInteractionSave =
                        false;
                    mostrarToast(
                        error.message ||
                        'La interacción se guardó, pero no fue posible vincular los datos técnicos de la llamada.',
                        true
                    );
                } finally {
                    linkingMetadata = false;
                }
            };

        document.addEventListener(
            'submit',
            function (event) {
                const formulario =
                    event.target;

                if (
                    !(
                        formulario instanceof
                        HTMLFormElement
                    ) ||
                    !formulario.matches(
                        '[data-work-interaction-form]'
                    ) ||
                    !pendingMetadata
                ) {
                    return;
                }

                const seguimientoForm =
                    Number(
                        formulario
                            .querySelector(
                                '[name="seguimiento_id"]'
                            )
                            ?.value || 0
                    );

                if (
                    seguimientoForm !==
                    Number(
                        pendingMetadata
                            .seguimiento_id
                    )
                ) {
                    return;
                }

                awaitingInteractionSave =
                    true;

                const resultado =
                    String(
                        formulario
                            .querySelector(
                                '[name="resultado"]'
                            )
                            ?.value || ''
                    ).toUpperCase();

                const labels = {
                    CONTACTADO:
                        'Contacto correcto',
                    CONTACTO_CORRECTO:
                        'Contacto correcto',
                    CONTACTO_REFERIDO:
                        'Me proporcionaron otro contacto',
                    SOLICITO_INFORMACION:
                        'Solicitó información',
                    SOLICITO_LLAMAR_DESPUES:
                        'Solicitó volver a llamar',
                    NO_INTERESADO:
                        'No interesado',
                    CONTACTO_INCORRECTO:
                        'Contacto incorrecto',
                    NUMERO_INCORRECTO:
                        'Número incorrecto',
                    SIN_RESPUESTA:
                        'Sin respuesta',
                    BUZON_VOZ:
                        'Buzón de voz',
                    FUERA_SERVICIO:
                        'Fuera del área / fuera de servicio',
                    OCUPADO:
                        'Ocupado',
                    OTRO:
                        'Otro'
                };

                const evidence =
                    Boolean(
                        formulario.querySelector(
                            '[name="verificacion_telefono_confirmado"]'
                        )?.checked
                    ) ||
                    Boolean(
                        formulario.querySelector(
                            '[name="verificacion_correo_confirmado"]'
                        )?.checked
                    ) ||
                    Boolean(
                        formulario.querySelector(
                            '[name="verificacion_contacto_confirmado"]'
                        )?.checked
                    ) ||
                    (
                        resultado ===
                        'CONTACTO_REFERIDO' &&
                        (
                            String(
                                formulario
                                    .querySelector(
                                        '[name="nuevo_telefono_contacto"]'
                                    )
                                    ?.value || ''
                            ).trim() !== '' ||
                            String(
                                formulario
                                    .querySelector(
                                        '[name="nuevo_correo_contacto"]'
                                    )
                                    ?.value || ''
                            ).trim() !== ''
                        )
                    );

                pendingInteractionFeedback = {
                    resultado: resultado,
                    resultadoLabel:
                        labels[resultado] ||
                        'Llamada registrada',
                    tieneEvidencia:
                        evidence
                };
            },
            true
        );

        document.addEventListener(
            'impe:interaction-exact-id-ready',
            function (event) {
                if (
                    !awaitingInteractionSave ||
                    !pendingMetadata
                ) {
                    return;
                }

                if (
                    Number(
                        event.detail
                            ?.seguimientoId || 0
                    ) ===
                    Number(
                        pendingMetadata
                            .seguimiento_id
                    )
                ) {
                    window.setTimeout(
                        function () {
                            void vincularMetadata(0);
                        },
                        120
                    );
                }
            }
        );

        document.addEventListener(
            'impe:interaction-informative-saved',
            function (event) {
                if (
                    !awaitingInteractionSave ||
                    !pendingMetadata
                ) {
                    return;
                }

                if (
                    Number(
                        event.detail
                            ?.seguimientoId || 0
                    ) ===
                    Number(
                        pendingMetadata
                            .seguimiento_id
                    )
                ) {
                    window.setTimeout(
                        function () {
                            void vincularMetadata(0);
                        },
                        180
                    );
                }
            }
        );

        const toastContainer =
            document.querySelector(
                '.toast-container'
            );

        if (
            toastContainer &&
            window.MutationObserver
        ) {
            const observer =
                new MutationObserver(
                    function (mutations) {
                        if (
                            !awaitingInteractionSave ||
                            !pendingMetadata
                        ) {
                            return;
                        }

                        const success =
                            mutations.some(
                                function (mutation) {
                                    return Array
                                        .from(
                                            mutation
                                                .addedNodes
                                        )
                                        .some(
                                            function (
                                                node
                                            ) {
                                                return (
                                                    node instanceof
                                                        HTMLElement &&
                                                    String(
                                                        node
                                                            .textContent ||
                                                        ''
                                                    ).includes(
                                                        'Interacción registrada'
                                                    )
                                                );
                                            }
                                        );
                                }
                            );

                        if (success) {
                            window.setTimeout(
                                function () {
                                    void vincularMetadata(0);
                                },
                                180
                            );
                        }
                    }
                );

            observer.observe(
                toastContainer,
                { childList: true }
            );
        }

        document.addEventListener(
            'click',
            function (event) {
                const button =
                    event.target.closest(
                        '[data-work-call-button]'
                    );

                if (
                    !button ||
                    button.disabled
                ) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                abrirLlamada();
            },
            true
        );

        document.addEventListener(
            'impe:telephony-restore-call',
            function (event) {
                const telephonyState =
                    event.detail ||
                    phone.getState();

                if (
                    String(
                        telephonyState
                            ?.context
                            ?.type || ''
                    ) !== 'VINCULACION'
                ) {
                    return;
                }

                if (!els) {
                    createModal();
                }

                applyState(
                    telephonyState
                );
                modal.show();
            }
        );

        document.addEventListener(
            'impe:telephony-register-result',
            function (event) {
                const telephonyState =
                    event.detail ||
                    phone.getState();

                if (
                    telephonyState
                        ?.phase !==
                        'finished' ||
                    !telephonyState
                        ?.finalMetadata
                ) {
                    return;
                }

                if (!els) {
                    createModal();
                }

                applyState(
                    telephonyState
                );

                window.setTimeout(
                    abrirRegistroResultado,
                    80
                );
            }
        );

        phone.subscribe(
            function (telephonyState) {
                applyState(
                    telephonyState
                );
            }
        );

        const probe = function () {
            if (
                window.location.protocol !==
                'https:'
            ) {
                window
                    .IMPE_ZADARMA_TELEPHONY_READY =
                    false;
                return;
            }

            phone.probe()
                .then(function (info) {
                    extension =
                        String(
                            info.extension ||
                            ''
                        );
                    phoneReady = true;
                    window
                        .IMPE_ZADARMA_TELEPHONY_READY =
                        true;

                    if (
                        els &&
                        !activeCall &&
                        !pendingMetadata
                    ) {
                        els.extension
                            .textContent =
                            'Extensión ' +
                            extension;
                        els.status
                            .textContent =
                            'Teléfono listo';
                        els.start.disabled =
                            false;
                    }
                })
                .catch(function (error) {
                    phoneReady = false;
                    window
                        .IMPE_ZADARMA_TELEPHONY_READY =
                        false;
                    console.info(
                        'Telefonía Zadarma no disponible para este usuario.',
                        error
                    );
                });
        };

        probe();
    });
})();
