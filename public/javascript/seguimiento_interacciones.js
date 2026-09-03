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
        const campoObservacion = formulario.querySelector('[name="observacion"]');
        const botonAbrirInteraccion = document.querySelector('[data-work-toggle-interaction]');
        const modalDescarte = document.getElementById('modalDescartarSeguimiento');
        const campoMotivoDescarte = document.querySelector('[data-work-discard-reason-input]');
        let motivoNoInteresPendiente = '';

        if (
            !selectorResultado ||
            !selectorProximaAccion ||
            !campoFechaProximaAccion ||
            !campoObservacion
        ) {
            return;
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

        agregarOpcionSiFalta('Investigar nuevo contacto', 'Investigar nuevo contacto');
        agregarOpcionSiFalta(
            'Verificar información de contacto',
            'Verificar información de contacto'
        );

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

        const actualizarFechaSegunAccion = function () {
            const accion = String(selectorProximaAccion.value || '');
            const sinAccion = accion === '';

            campoFechaProximaAccion.disabled = sinAccion;
            campoFechaProximaAccion.required = accion === 'Volver a llamar';

            if (sinAccion) {
                campoFechaProximaAccion.value = '';
            }
        };

        const aplicarResultadoInteraccion = function () {
            const resultado = String(selectorResultado.value || '');
            let accionSugerida = '';

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
                        ? 'Preparar oficio'
                        : 'Verificar información de contacto';
                    break;
                case 'SOLICITO_LLAMAR_DESPUES':
                    accionSugerida = 'Volver a llamar';
                    break;
                case 'NO_INTERESADO':
                case 'OTRO':
                default:
                    accionSugerida = '';
                    break;
            }

            selectorProximaAccion.value = accionSugerida;

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
                return;
            }

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
            if (selectorResultado.value !== 'NO_INTERESADO') {
                motivoNoInteresPendiente = '';
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

            const observacionOriginal = campoObservacion.value;
            const observacionLimpia = observacionOriginal.trim();
            const lineaMotivo = 'Motivo de no interés: ' + motivo;

            campoObservacion.value = observacionLimpia !== ''
                ? observacionLimpia + '\n' + lineaMotivo
                : lineaMotivo;

            queueMicrotask(function () {
                campoObservacion.value = observacionOriginal;
            });
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
            motivoNoInteresPendiente = '';

            if (campoMotivoDescarte) {
                campoMotivoDescarte.readOnly = false;
            }
        });

        aplicarResultadoInteraccion();
    });
})();
