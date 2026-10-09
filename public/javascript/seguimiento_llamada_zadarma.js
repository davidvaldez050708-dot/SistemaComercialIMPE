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
        const usuarioId =
            Number(window.IMPE_CURRENT_USER_ID || 0);
        const pendingLinkKey =
            'impe:zadarma:pending-link:' +
            String(usuarioId || 0);

        const guardarVinculoPendiente = function (
            seguimientoId,
            interaccionId,
            callToken
        ) {
            if (
                usuarioId <= 0 ||
                Number(seguimientoId || 0) <= 0 ||
                Number(interaccionId || 0) <= 0
            ) {
                return;
            }

            try {
                localStorage.setItem(
                    pendingLinkKey,
                    JSON.stringify({
                        seguimientoId:
                            Number(seguimientoId),
                        interaccionId:
                            Number(interaccionId),
                        callToken:
                            String(callToken || ''),
                        savedAt: Date.now()
                    })
                );
            } catch (error) {
                // El vínculo sigue disponible en memoria durante esta vista.
            }
        };

        const leerVinculoPendiente = function () {
            if (usuarioId <= 0) {
                return null;
            }

            try {
                const raw =
                    localStorage.getItem(
                        pendingLinkKey
                    );
                const data =
                    raw ? JSON.parse(raw) : null;

                if (
                    !data ||
                    Number(data.seguimientoId || 0) <= 0 ||
                    Number(data.interaccionId || 0) <= 0
                ) {
                    return null;
                }

                /*
                 * Un vínculo técnico no debe quedar bloqueando llamadas por
                 * tiempo indefinido si el navegador se cerró abruptamente.
                 */
                if (
                    Date.now() -
                        Number(data.savedAt || 0) >
                    6 * 60 * 60 * 1000
                ) {
                    localStorage.removeItem(
                        pendingLinkKey
                    );
                    return null;
                }

                return data;
            } catch (error) {
                return null;
            }
        };

        const limpiarVinculoPendiente =
            function () {
                try {
                    localStorage.removeItem(
                        pendingLinkKey
                    );
                } catch (error) {
                    // No bloquea la finalización de la llamada.
                }
            };

        let extension = '';
        let phoneReady = false;
        let activeCall = false;
        let currentPhone = '';
        let currentSeguimientoId = 0;
        let currentInstitution = '';
        let pendingMetadata = null;
        let pendingInteractionId = 0;
        let awaitingInteractionSave = false;
        let linkingMetadata = false;
        let pendingInteractionFeedback = null;
        let lastFinishedToken = '';
        let telefonoFlotanteOculto = false;

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

            if (
                telephonyState.active &&
                isLinkage
            ) {
                activeCall = true;
                pendingMetadata = null;
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
                telefonoFlotanteOculto = false;

                const vinculoGuardado =
                    leerVinculoPendiente();

                if (
                    vinculoGuardado &&
                    Number(
                        vinculoGuardado
                            .seguimientoId || 0
                    ) === currentSeguimientoId &&
                    (
                        String(
                            vinculoGuardado
                                .callToken || ''
                        ) === '' ||
                        token === '' ||
                        String(
                            vinculoGuardado
                                .callToken || ''
                        ) === token
                    )
                ) {
                    pendingInteractionId =
                        Number(
                            vinculoGuardado
                                .interaccionId || 0
                        );
                    awaitingInteractionSave =
                        pendingInteractionId > 0;
                }

                return;
            }

            if (
                !telephonyState.active &&
                telephonyState.phase !==
                    'finished'
            ) {
                activeCall = false;
            }
        };

        const abrirLlamada = function () {
            const estadoActual =
                phone.getState();

            if (estadoActual?.active) {
                mostrarToast(
                    'Ya existe una llamada activa. Continúa desde el teléfono flotante.',
                    true
                );

                if (
                    typeof phone.expand ===
                    'function'
                ) {
                    phone.expand();
                }
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
            pendingInteractionId = 0;
            awaitingInteractionSave = false;
            limpiarVinculoPendiente();

            /*
             * El primer clic solo prepara el teléfono flotante. La llamada
             * real comienza cuando el usuario confirma con "Llamar" dentro
             * de la tarjeta, igual que en el modal anterior.
             */
            void phone.stageCall({
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
            }).then(function () {
                if (
                    typeof phone.expand ===
                    'function'
                ) {
                    phone.expand();
                }
            }).catch(function (error) {
                mostrarToast(
                    error.message ||
                    'No fue posible preparar la llamada.',
                    true
                );
            });
        };

        const agregarResumenTecnico =
            function (formulario, metadata) {
                let aviso =
                    formulario.querySelector(
                        '[data-telephony-call-summary]'
                    );

                if (!aviso) {
                    aviso =
                        document.createElement(
                            'div'
                        );
                    aviso.className = 'col-12';
                    aviso.setAttribute(
                        'data-telephony-call-summary',
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
                document.dispatchEvent(
                    new Event('impe:telephony-registration-unavailable')
                );
                return;
            }

            const metadata =
                pendingMetadata;

            const boton =
                offcanvas.querySelector(
                    '[data-work-toggle-interaction]'
                );
            const formulario =
                offcanvas.querySelector(
                    '[data-work-interaction-form]'
                );

            if (!formulario) {
                document.dispatchEvent(
                    new Event('impe:telephony-registration-unavailable')
                );
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

        const ocultarTelefonoTrasInteraccion = function () {
            if (
                !pendingMetadata ||
                telefonoFlotanteOculto
            ) {
                return;
            }

            telefonoFlotanteOculto = true;

            const token =
                String(
                    pendingMetadata
                        .call_token ||
                    lastFinishedToken ||
                    ''
                );

            phone.clearFinished(token);
        };

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

                if (pendingInteractionId <= 0) {
                    linkingMetadata = false;

                    if (attempt < 24) {
                        await sleep(500);
                        void vincularMetadata(attempt + 1);
                    } else {
                        mostrarToast(
                            'La interacción se guardó, pero no fue posible identificarla para vincular la llamada. Recarga el seguimiento antes de continuar.',
                            true
                        );
                    }
                    return;
                }

                formData.set(
                    'seguimiento_id',
                    String(
                        metadata
                            .seguimiento_id
                    )
                );
                formData.set(
                    'interaccion_id',
                    String(pendingInteractionId)
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
                formData.set(
                    'lookup_attempt',
                    String(
                        Math.max(
                            0,
                            Number(attempt || 0)
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
                        (
                            [404, 409].includes(response.status) ||
                            response.status >= 500
                        ) &&
                        attempt < 24
                    ) {
                        linkingMetadata = false;
                        await sleep(
                            response.status >= 500
                                ? 1500
                                : 1000
                        );
                        void vincularMetadata(
                            attempt + 1
                        );
                        return;
                    }

                    if (
                        !response.ok ||
                        !data.ok
                    ) {
                        const errorVinculo =
                            new Error(
                                data.mensaje ||
                                'No fue posible vincular los datos técnicos de la llamada.'
                            );
                        errorVinculo.status =
                            response.status;
                        throw errorVinculo;
                    }

                    const token =
                        String(
                            metadata
                                .call_token ||
                            lastFinishedToken ||
                            ''
                        );

                    pendingMetadata = null;
                    pendingInteractionId = 0;
                    awaitingInteractionSave =
                        false;
                    limpiarVinculoPendiente();

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
                } catch (error) {
                    if (
                        attempt < 10 &&
                        Number(error?.status || 0) === 0
                    ) {
                        linkingMetadata = false;
                        await sleep(1500);
                        void vincularMetadata(
                            attempt + 1
                        );
                        return;
                    }

                    awaitingInteractionSave =
                        false;
                    mostrarToast(
                        error.message ||
                        'La interacción se guardó, pero no fue posible vincular los datos técnicos de la llamada. El estado de la llamada se conserva para reintentar.',
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

                /*
                 * El estado final de la llamada se conserva hasta que el
                 * backend confirme el vínculo técnico. Esto permite recuperar
                 * la llamada si el proveedor o la red tardan unos segundos.
                 */
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
                    pendingInteractionId =
                        Number(
                            event.detail
                                ?.interaccionId || 0
                        );

                    if (pendingInteractionId > 0) {
                        guardarVinculoPendiente(
                            Number(
                                pendingMetadata
                                    .seguimiento_id || 0
                            ),
                            pendingInteractionId,
                            String(
                                pendingMetadata
                                    .call_token || ''
                            )
                        );
                    }

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
                    const exactId =
                        Number(
                            event.detail
                                ?.interaccionId || 0
                        );

                    if (exactId > 0) {
                        pendingInteractionId = exactId;
                        guardarVinculoPendiente(
                            Number(
                                pendingMetadata
                                    .seguimiento_id || 0
                            ),
                            pendingInteractionId,
                            String(
                                pendingMetadata
                                    .call_token || ''
                            )
                        );
                    }

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
                            /*
                             * Compatibilidad con respuestas antiguas que solo
                             * mostraban toast. No se descarta el estado final:
                             * se conserva hasta confirmar el vínculo técnico.
                             */
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

                applyState(
                    telephonyState
                );

                if (
                    typeof phone.expand ===
                    'function'
                ) {
                    phone.expand();
                }
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

        window.setTimeout(
            function () {
                if (
                    pendingMetadata &&
                    pendingInteractionId > 0 &&
                    awaitingInteractionSave
                ) {
                    void vincularMetadata(0);
                }
            },
            250
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
