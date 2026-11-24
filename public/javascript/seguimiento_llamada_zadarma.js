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


        // Diseño encapsulado: no modifica los selectores ni otros avisos.
        if (!document.getElementById('impe-call-linked-note-style')) {
            const css = document.createElement('style');
            css.id = 'impe-call-linked-note-style';
            css.textContent = `
              .impe-call-linked-note {display:flex;align-items:flex-start;gap:12px;padding:15px 16px;
                border:1px solid #d9e4f3;border-radius:13px;background:#f5f8fd;color:#21355a;
                font:inherit;line-height:1.45;box-shadow:0 1px 2px rgba(26,54,93,.025)}
              .impe-call-linked-note__icon {display:grid;place-items:center;flex:0 0 35px;
                height:35px;border-radius:10px;background:#e7f4ee;color:#16815a;font-size:17px}
              .impe-call-linked-note__body {display:flex;flex-direction:column;gap:3px;min-width:0}
              .impe-call-linked-note__body strong {font-size:13px;font-weight:750;color:#223b70}
              .impe-call-linked-note__body span {font-size:13px;color:#536687;overflow-wrap:anywhere}
            `;
            document.head.appendChild(css);
        }

        if (!document.getElementById('impe-call-result-rules-style')) {
            const style = document.createElement('style');
            style.id = 'impe-call-result-rules-style';
            style.textContent = `
              #offcanvasSeguimientoTrabajo .impe-call-result-card {display:flex;align-items:flex-start;gap:10px;
                padding:12px 14px;margin:0 0 4px;border:1px solid #dce5f3;border-radius:12px;
                background:#f5f8fd;color:#20365f;box-sizing:border-box;min-width:0}
              #offcanvasSeguimientoTrabajo .impe-call-result-icon {display:grid;place-items:center;
                flex:0 0 30px;height:30px;border-radius:9px;background:#e7eefb;color:#2c4289;font-size:16px}
              #offcanvasSeguimientoTrabajo .impe-call-result-body {flex:1;min-width:0}
              #offcanvasSeguimientoTrabajo .impe-call-result-body strong {display:block;
                font-size:13px;font-weight:750;line-height:1.4;color:#243b70}
              #offcanvasSeguimientoTrabajo .impe-call-result-body p {margin:4px 0 0;
                font-size:12px;font-weight:400;line-height:1.55;color:#60718d;overflow-wrap:anywhere}
              #offcanvasSeguimientoTrabajo .impe-call-result-retry {display:inline-flex;align-items:center;
                justify-content:center;min-height:32px;max-width:100%;margin-top:9px;padding:6px 11px;
                border:1px solid #cbd8ee;border-radius:8px;background:#fff;color:#2c4289;
                font-family:inherit;font-size:12px;font-weight:700;line-height:1.4;cursor:pointer}
              #offcanvasSeguimientoTrabajo .impe-call-result-retry:hover {background:#edf2fc;border-color:#a7bbe1}
              #offcanvasSeguimientoTrabajo .impe-call-result-retry:focus-visible {outline:3px solid #c5d5f6;outline-offset:2px}
              #offcanvasSeguimientoTrabajo .impe-call-result-retry:disabled {opacity:.6;cursor:wait}
              #offcanvasSeguimientoTrabajo .impe-call-result-retry[hidden] {display:none}
              #offcanvasSeguimientoTrabajo .impe-call-result-card[data-state="error"] {background:#fff9f0;border-color:#ecd7b7}
              #offcanvasSeguimientoTrabajo .impe-call-result-card[data-state="error"] .impe-call-result-icon {background:#f9ecd8;color:#926321}
            `;
            document.head.appendChild(style);
        }

        const vincularUrl =
            'prueba_telefonia/api/vincular_interaccion_zadarma.php';
        // El resultado se valida ANTES de crear la interacción, no sólo al vincularla.
        const reglasPorFormulario = new WeakMap();
        const resultadosVerificables = ['CONTACTO_CORRECTO','CONTACTO_REFERIDO',
            'SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'];
        function aplicarReglasResultado(form, captura) {
            const selector = form.querySelector('[name="resultado"]');
            if (!selector) return;
            for (const option of selector.options) {
                if (!option.value) continue;
                option.disabled = captura.originales.get(option) === true || !captura.reglas || !captura.reglas.opciones.includes(option.value);
            }
            if (selector.selectedOptions[0]?.disabled) selector.value = '';
            const verifica = captura.reglas?.permite_verificar === true &&
                resultadosVerificables.includes(selector.value);
            for (const field of form.querySelectorAll('[name^="verificacion_"]')) {
                if (field.type !== 'checkbox') continue;
                if (!verifica) { field.checked = false; field.disabled = true; }
            }
        }
        function prepararResultadoConTelefonia(form, metadata) {
            const anterior = reglasPorFormulario.get(form);
            if (anterior) {
                anterior.cancelada = true; clearTimeout(anterior.timer);
                for (const [option,disabled] of anterior.originales) option.disabled = disabled;
            }
            const originales = new Map(Array.from(form.querySelectorAll('[name="resultado"] option')).map(option=>[option,option.disabled]));
            const captura = {metadata, originales, reglas:null, cancelada:false, intentos:0};
            reglasPorFormulario.set(form, captura);
            let refInput = form.querySelector('[name="llamada_pbx_id"]');
            if (!refInput) {
                refInput = document.createElement('input'); refInput.type = 'hidden';
                refInput.name = 'llamada_pbx_id'; form.appendChild(refInput);
            }
            refInput.value = String(metadata.pbx_call_id || '');
            let notice = form.querySelector('[data-call-result-rules]');
            if (!notice) {
                notice = document.createElement('div'); notice.className = 'col-12';
                notice.setAttribute('data-call-result-rules',''); notice.setAttribute('role','status');
                const card = document.createElement('div'); card.className = 'impe-call-result-card';
                const icon = document.createElement('span'); icon.className = 'impe-call-result-icon';
                icon.setAttribute('aria-hidden','true');
                const body = document.createElement('div'); body.className = 'impe-call-result-body';
                const title = document.createElement('strong');
                const text = document.createElement('p');
                const retry = document.createElement('button'); retry.type = 'button';
                retry.className = 'impe-call-result-retry'; retry.textContent = '↻ Volver a comprobar';
                retry.setAttribute('aria-label','Volver a consultar el resultado de esta llamada');
                retry.title = 'Consulta la misma llamada; no inicia otra.'; retry.hidden = true;
                body.append(title,text,retry); card.append(icon,body); notice.append(card);
                (form.querySelector('.row') || form).prepend(notice);
            }
            const text = notice.querySelector('p'), retry = notice.querySelector('button');
            const card = notice.querySelector('.impe-call-result-card');
            const title = notice.querySelector('strong'), icon = notice.querySelector('.impe-call-result-icon');
            const submit = form.querySelector('button[type="submit"]');
            const vigente = () => reglasPorFormulario.get(form) === captura && !captura.cancelada;
            async function comprobar() {
                if (!vigente() || captura.consultando) return;
                clearTimeout(captura.timer); captura.consultando = true;
                captura.reglas = null; aplicarReglasResultado(form, captura);
                if (submit) submit.disabled = true;
                card.dataset.state = 'pending'; title.textContent = 'Confirmando llamada…'; icon.textContent = '…';
                retry.disabled = true; text.textContent = 'Espera un momento mientras se confirma el resultado para guardar.';
                const controller = new AbortController();
                const timeout = setTimeout(()=>controller.abort(),10000);
                try {
                    let ref = String(metadata.pbx_call_id || '');
                    if (!/^out_[a-fA-F0-9]{32,64}$/.test(ref)) {
                        // Sólo recuperar el marcado exacto, con checkpoint y ventana temporal.
                        const key = typeof lastDialKey === 'string' ? lastDialKey : '';
                        if (!key || !metadata.checkpoint_ready || localStorage.getItem(key) !== String(metadata.call_token) ||
                            Date.now()/1000 - Number(metadata.finished_at || 0) > 30) {
                            throw Error('El identificador de la llamada está pendiente. Vuelve a comprobar en unos segundos.');
                        }
                        const params = new URLSearchParams({destination:metadata.to || '',since:String(metadata.requested_at || 0),
                            after_id:String(metadata.after_id || 0),final:'1'});
                        const res = await fetch('prueba_telefonia/api/zadarma_estado_llamada.php?'+params,
                            {credentials:'same-origin',cache:'no-store',signal:controller.signal});
                        const data = await res.json();
                        const start = Date.parse(data?.call?.start_time || '')/1000;
                        ref = String(data?.call?.pbx_call_id || '');
                        if (!res.ok || !data.ok || !/^out_[a-fA-F0-9]{32,64}$/.test(ref) || !Number.isFinite(start) ||
                            start < Number(metadata.requested_at)-2 || start > Number(metadata.finished_at)+2 ||
                            localStorage.getItem(key) !== String(metadata.call_token)) {
                            throw Error('Todavía no se identifica esta llamada con certeza. Vuelve a comprobar.');
                        }
                        if (!vigente()) return;
                        metadata.pbx_call_id = ref;
                    }
                    const params = new URLSearchParams({seguimiento_id:String(metadata.seguimiento_id),pbx_call_id:ref});
                    const response = await fetch('prueba_telefonia/api/resultado_saliente_analista.php?'+params,
                        {credentials:'same-origin',cache:'no-store',signal:controller.signal});
                    const data = await response.json();
                    if (!vigente()) return;
                    if (!response.ok || !data.ok) throw Error(data.mensaje || 'No se pudo confirmar la llamada.');
                    if (data.reglas?.pbx_call_id !== ref || !Array.isArray(data.reglas.opciones)) throw Error('La confirmación no corresponde a esta llamada.');
                    captura.reglas = data.reglas; refInput.value = ref;
                    const selector = form.querySelector('[name="resultado"]');
                    if (data.reglas.opciones.includes('OCUPADO') && !selector.querySelector('option[value="OCUPADO"]')) {
                        selector.add(new Option('Línea ocupada','OCUPADO'));
                    }
                    if (data.reglas.opciones.length === 1) selector.value = data.reglas.opciones[0];
                    aplicarReglasResultado(form, captura);
                    selector.dispatchEvent(new Event('change',{bubbles:true}));
                    card.dataset.state = 'confirmed'; title.textContent = data.reglas.etiqueta; icon.textContent = '✓';
                    retry.hidden = true;
                    text.textContent = data.reglas.permite_verificar
                        ? 'Selecciona lo ocurrido: conversación, buzón u otra respuesta.'
                        : 'No se confirmó conversación. Registra el intento sin verificar datos.';
                } catch (error) {
                    if (!vigente()) return;
                    card.dataset.state = 'error'; title.textContent = 'Confirmación pendiente'; icon.textContent = '!';
                    retry.hidden = false;
                    text.textContent = error.name === 'AbortError' ? 'La comprobación tardó demasiado. Vuelve a comprobar.' : error.message;
                    if (++captura.intentos < 8) captura.timer = setTimeout(comprobar,2000);
                } finally {
                    clearTimeout(timeout); captura.consultando = false;
                    if (vigente()) { retry.disabled = false; if (submit) submit.disabled = !captura.reglas; }
                }
            }
            retry.onclick = comprobar;
            void comprobar();
        }
        document.addEventListener('change', function (event) {
            const form = event.target.closest?.('[data-work-interaction-form]');
            const captura = form && reglasPorFormulario.get(form);
            if (captura) aplicarReglasResultado(form,captura);
        });
        document.addEventListener('submit', function (event) {
            const form = event.target, captura = reglasPorFormulario.get(form);
            if (!captura || captura.cancelada) return;
            const seleccion = form.querySelector('[name="resultado"]')?.value || '';
            if (!captura.reglas || !captura.reglas.opciones.includes(seleccion)) {
                event.preventDefault(); event.stopImmediatePropagation();
                mostrarToast('Comprueba la llamada y selecciona un resultado compatible antes de guardar.',true);
            } else captura.enviada = true;
        },true);
        for (const name of ['impe:interaction-exact-id-ready','impe:interaction-informative-saved']) {
            document.addEventListener(name,function(event) {
                const form = offcanvas.querySelector('[data-work-interaction-form]');
                const captura = form && reglasPorFormulario.get(form);
                if (!captura?.enviada || Number(event.detail?.seguimientoId) !== Number(captura.metadata.seguimiento_id)) return;
                captura.cancelada = true; clearTimeout(captura.timer); reglasPorFormulario.delete(form);
                for (const [option,disabled] of captura.originales) option.disabled = disabled;
                form.querySelector('[data-call-result-rules]')?.remove();
                form.querySelector('[name="llamada_pbx_id"]')?.remove();
                const origen = form.querySelector('[name="origen_llamada"]');
                if (origen?.value === 'ZADARMA') origen.value = 'MANUAL';
            });
        }
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
                    pendingInteractionId > 0
                        ? 'Resultado guardado. Espera a que termine la vinculación con Zadarma.'
                        : 'Primero registra el resultado de la llamada anterior.',
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
                // Mensaje visual consistente con las tarjetas del CRM.
                // El estado de grabación es informativo hasta confirmación del proveedor.
                aviso.innerHTML =
                    '<div class="impe-call-linked-note" role="status">' +
                        '<span class="impe-call-linked-note__icon" aria-hidden="true">' +
                            '<span aria-hidden="true">☎</span></span>' +
                        '<div class="impe-call-linked-note__body">' +
                            '<strong>Llamada registrada</strong>' +
                            '<span>' + label + ' · ' + formatDuration(metadata.duration) +
                                recordingText + '</span>' +
                        '</div>' +
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
                    prepararResultadoConTelefonia(formulario, metadata);
                    document.dispatchEvent(new CustomEvent('impe:call-result-form-opened', {
                        detail:{form:formulario,ids:[metadata.pbx_call_id,metadata.call_token,lastFinishedToken].filter(Boolean)}
                    }));
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
                    // El resultado técnico fue vinculado: despejar sólo el
                    // resumen de ESTA llamada, sin modificar los selectores.
                    const form = offcanvas.querySelector('[data-work-interaction-form]');
                    const notice = form?.querySelector('[data-telephony-call-summary]');
                    if (notice) notice.remove();
                    void window.IMPE_CALL_DRAFTS?.remove(usuarioId,
                        [metadata.pbx_call_id,metadata.call_token,token].filter(Boolean),
                        form?.querySelector('[name="csrf_token"]')?.value||'').catch(()=>{});
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
