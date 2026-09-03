(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const formulario = document.querySelector('[data-work-interaction-form]');

        if (!formulario) {
            return;
        }

        const selectorResultado = formulario.querySelector('[name="resultado"]');
        const selectorProximaAccion = formulario.querySelector('[name="proxima_accion"]');
        const campoFechaProximaAccion = formulario.querySelector('[name="proxima_accion_at"]');
        const etiquetaFechaProximaAccion = formulario.querySelector('label[for="work_next_action_date"]');
        const campoObservacion = formulario.querySelector('[name="observacion"]');
        const botonAbrirInteraccion = document.querySelector('[data-work-toggle-interaction]');
        const modalDescarte = document.getElementById('modalDescartarSeguimiento');
        const campoMotivoDescarte = document.querySelector('[data-work-discard-reason-input]');
        const panelDescartado = document.querySelector('[data-work-discarded-panel]');
        const contenedorToasts = document.querySelector('.toast-container');
        const accionesConHorarioObligatorio = [
            'Volver a llamar',
            'Enviar WhatsApp',
            'Enviar oficio/correo'
        ];
        let motivoNoInteresPendiente = '';
        let esperandoDecisionNoInteres = false;
        let toastInteraccionDiferido = false;

        if (
            !selectorResultado ||
            !selectorProximaAccion ||
            !campoFechaProximaAccion ||
            !campoObservacion
        ) {
            return;
        }

        const mostrarToastLocal = function (mensaje) {
            if (!contenedorToasts || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', '3200');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedorToasts.appendChild(toast);

            const instancia = new bootstrap.Toast(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            instancia.show();
        };

        if (contenedorToasts && window.MutationObserver) {
            const observadorToasts = new MutationObserver(function (mutaciones) {
                mutaciones.forEach(function (mutacion) {
                    mutacion.addedNodes.forEach(function (nodo) {
                        if (!(nodo instanceof HTMLElement)) {
                            return;
                        }

                        const textoToast = String(nodo.textContent || '').trim();

                        if (
                            esperandoDecisionNoInteres &&
                            textoToast.includes('Interacción registrada correctamente.')
                        ) {
                            toastInteraccionDiferido = true;
                            nodo.remove();
                        }
                    });
                });
            });

            observadorToasts.observe(contenedorToasts, { childList: true });
        }

        const agregarOpcionSiFalta = function (valor, etiqueta) {
            const existe = Array.from(selectorProximaAccion.options).some(function (opcion) {
                return opcion.value === valor;
            });

            if (existe) {
                return;
            }

            const opcion = document.createElement('option');
            opcion.value = valor;
            opcion.textContent = etiqueta;
            selectorProximaAccion.appendChild(opcion);
        };

        Array.from(selectorProximaAccion.options).forEach(function (opcion) {
            if (opcion.value === 'Preparar oficio') {
                opcion.remove();
            }
        });

        agregarOpcionSiFalta('Investigar nuevo contacto', 'Investigar nuevo contacto');
        agregarOpcionSiFalta(
            'Verificar información de contacto',
            'Verificar información de contacto'
        );
        agregarOpcionSiFalta('Generar oficio', 'Generar oficio');

        const contenedorObservacion = campoObservacion.closest('.col-12');
        const contenedorMotivo = document.createElement('div');
        contenedorMotivo.className = 'col-12 d-none';
        contenedorMotivo.setAttribute('data-work-no-interest-reason-wrapper', '');
        contenedorMotivo.innerHTML =
            '<label class="form-label" for="work_no_interest_reason">' +
                'Motivo de no interés *' +
            '</label>' +
            '<textarea ' +
                'class="form-control" ' +
                'id="work_no_interest_reason" ' +
                'rows="2" ' +
                'maxlength="255" ' +
                'placeholder="Indica brevemente por qué la institución no está interesada..." ' +
                'data-work-no-interest-reason></textarea>';

        if (contenedorObservacion) {
            contenedorObservacion.insertAdjacentElement('afterend', contenedorMotivo);
        } else {
            formulario.querySelector('.row')?.appendChild(contenedorMotivo);
        }

        const campoMotivoNoInteres = contenedorMotivo.querySelector(
            '[data-work-no-interest-reason]'
        );

        const contactoEstaVerificado = function () {
            const estado = String(
                document.querySelector('[data-work-verified-status]')?.textContent || ''
            ).toLowerCase();
            const botonVerificacion = String(
                document.querySelector('[data-work-verify-contact]')?.textContent || ''
            ).toLowerCase();

            return estado.includes('datos verificados') ||
                botonVerificacion.includes('información verificada');
        };

        const actualizarEtiquetaFecha = function (obligatoria) {
            if (!etiquetaFechaProximaAccion) {
                return;
            }

            etiquetaFechaProximaAccion.textContent = obligatoria
                ? 'Fecha próxima acción *'
                : 'Fecha próxima acción';
        };

        const actualizarFechaSegunAccion = function () {
            const accion = String(selectorProximaAccion.value || '');
            const resultado = String(selectorResultado.value || '');
            const sinAccion = accion === '';
            const resultadoManual = resultado === 'OTRO';
            const fechaObligatoria = accionesConHorarioObligatorio.includes(accion);

            campoFechaProximaAccion.disabled = sinAccion && !resultadoManual;
            campoFechaProximaAccion.required = fechaObligatoria;
            actualizarEtiquetaFecha(fechaObligatoria);

            if (sinAccion && !resultadoManual) {
                campoFechaProximaAccion.value = '';
            }
        };

        const aplicarResultadoInteraccion = function () {
            const resultado = String(selectorResultado.value || '');
            let accionSugerida = '';
            let conservarSeleccionManual = false;

            switch (resultado) {
                case 'SIN_RESPUESTA':
                    accionSugerida = 'Volver a llamar';
                    break;
                case 'NUMERO_INCORRECTO':
                    accionSugerida = 'Investigar nuevo contacto';
                    break;
                case 'CONTACTO_INCORRECTO':
                    accionSugerida = 'Confirmar contacto de RH';
                    break;
                case 'CONTACTO_CORRECTO':
                case 'SOLICITO_INFORMACION':
                    accionSugerida = contactoEstaVerificado()
                        ? 'Generar oficio'
                        : 'Verificar información de contacto';
                    break;
                case 'SOLICITO_LLAMAR_DESPUES':
                    accionSugerida = 'Volver a llamar';
                    break;
                case 'NO_INTERESADO':
                    accionSugerida = '';
                    break;
                case 'OTRO':
                    conservarSeleccionManual = true;
                    break;
                default:
                    accionSugerida = '';
                    break;
            }

            if (!conservarSeleccionManual) {
                selectorProximaAccion.value = accionSugerida;
            }

            const noInteresado = resultado === 'NO_INTERESADO';
            selectorProximaAccion.disabled = noInteresado;
            contenedorMotivo.classList.toggle('d-none', !noInteresado);

            if (campoMotivoNoInteres) {
                campoMotivoNoInteres.required = noInteresado;

                if (!noInteresado) {
                    campoMotivoNoInteres.value = '';
                }
            }

            if (noInteresado) {
                campoFechaProximaAccion.value = '';
                campoFechaProximaAccion.disabled = true;
                campoFechaProximaAccion.required = false;
                actualizarEtiquetaFecha(false);
                return;
            }

            selectorProximaAccion.disabled = false;
            actualizarFechaSegunAccion();
        };

        selectorResultado.addEventListener('change', aplicarResultadoInteraccion);
        selectorProximaAccion.addEventListener('change', actualizarFechaSegunAccion);

        formulario.addEventListener('reset', function () {
            window.setTimeout(aplicarResultadoInteraccion, 0);
        });

        botonAbrirInteraccion?.addEventListener('click', function () {
            window.setTimeout(aplicarResultadoInteraccion, 0);
        });

        formulario.addEventListener('submit', function (event) {
            const accion = String(selectorProximaAccion.value || '');

            if (
                accionesConHorarioObligatorio.includes(accion) &&
                String(campoFechaProximaAccion.value || '').trim() === ''
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                campoFechaProximaAccion.reportValidity();
                campoFechaProximaAccion.focus();
                return;
            }

            if (selectorResultado.value !== 'NO_INTERESADO') {
                motivoNoInteresPendiente = '';
                esperandoDecisionNoInteres = false;
                toastInteraccionDiferido = false;
                return;
            }

            const motivo = String(campoMotivoNoInteres?.value || '').trim();

            if (motivo === '') {
                event.preventDefault();
                event.stopImmediatePropagation();
                campoMotivoNoInteres?.reportValidity();
                campoMotivoNoInteres?.focus();
                return;
            }

            motivoNoInteresPendiente = motivo;
            esperandoDecisionNoInteres = true;
            toastInteraccionDiferido = false;

            const observacionOriginal = campoObservacion.value;
            const observacionLimpia = observacionOriginal.trim();
            const lineaMotivo = 'Motivo de no interés: ' + motivo;

            campoObservacion.value = observacionLimpia !== ''
                ? observacionLimpia + '\n' + lineaMotivo
                : lineaMotivo;

            window.setTimeout(function () {
                campoObservacion.value = observacionOriginal;
            }, 0);
        }, true);

        modalDescarte?.addEventListener('show.bs.modal', function () {
            if (!campoMotivoDescarte || motivoNoInteresPendiente === '') {
                return;
            }

            campoMotivoDescarte.value = motivoNoInteresPendiente;
            campoMotivoDescarte.readOnly = true;
            campoMotivoDescarte.dispatchEvent(new Event('input', { bubbles: true }));
        });

        modalDescarte?.addEventListener('hidden.bs.modal', function () {
            const seguimientoQuedoDescartado = panelDescartado &&
                !panelDescartado.classList.contains('d-none');

            if (
                esperandoDecisionNoInteres &&
                toastInteraccionDiferido &&
                !seguimientoQuedoDescartado
            ) {
                mostrarToastLocal('Interacción registrada correctamente.');
            }

            motivoNoInteresPendiente = '';
            esperandoDecisionNoInteres = false;
            toastInteraccionDiferido = false;

            if (campoMotivoDescarte) {
                campoMotivoDescarte.readOnly = false;
            }
        });

        aplicarResultadoInteraccion();
    });
})();
