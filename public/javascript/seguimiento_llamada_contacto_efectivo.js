(function () {
    'use strict';

    const MARCADOR_CONTACTO = '[CONTACTO_EFECTIVO]';
    const MARCADOR_SIN_CONTACTO = '[SIN_CONTACTO_EFECTIVO]';

    const limpiarMarcadores = function (valor) {
        return String(valor || '')
            .replaceAll(MARCADOR_CONTACTO, '')
            .replaceAll(MARCADOR_SIN_CONTACTO, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    };

    const inicializarFormulario = function () {
        const formulario = document.querySelector('[data-work-interaction-form]');
        if (!formulario || formulario.dataset.contactoEfectivoReady === '1') {
            return false;
        }

        const canal = formulario.querySelector('[name="canal"]');
        const resultado = formulario.querySelector('[name="resultado"]');
        const observacion = formulario.querySelector('[name="observacion"]');

        if (!canal || !resultado || !observacion) {
            return false;
        }

        formulario.dataset.contactoEfectivoReady = '1';

        const campoResultado = resultado.closest('[class*="col-"]') || resultado.parentElement;
        const campo = document.createElement('div');
        campo.className = campoResultado && typeof campoResultado.className === 'string' && campoResultado.className.trim() !== ''
            ? campoResultado.className
            : 'col-md-6';
        campo.classList.add('d-none');
        campo.setAttribute('data-call-contact-field', '');
        campo.innerHTML =
            '<label class="form-label" for="callContactoEfectivo">¿Se logró hablar con una persona?</label>' +
            '<select class="form-select" id="callContactoEfectivo" data-call-contact-select>' +
                '<option value="">Selecciona una opción…</option>' +
                '<option value="SI">Sí, hubo contacto efectivo</option>' +
                '<option value="NO">No, no se logró contacto</option>' +
            '</select>' +
            '<div class="form-text">Se solicita cuando el resultado es “Otro”, para que el resumen de llamadas sea correcto.</div>';

        if (campoResultado && campoResultado.parentElement) {
            campoResultado.insertAdjacentElement('afterend', campo);
        } else {
            formulario.querySelector('.row')?.appendChild(campo);
        }

        const selector = campo.querySelector('[data-call-contact-select]');

        const actualizarVisibilidad = function () {
            const esLlamada = String(canal.value || '').toUpperCase() === 'LLAMADA';
            const esOtro = String(resultado.value || '').toUpperCase() === 'OTRO';
            const visible = esLlamada && esOtro;

            campo.classList.toggle('d-none', !visible);
            selector.required = visible;

            if (!visible) {
                selector.value = '';
                selector.setCustomValidity('');
            }
        };

        canal.addEventListener('change', actualizarVisibilidad);
        resultado.addEventListener('change', actualizarVisibilidad);
        selector.addEventListener('change', function () {
            selector.setCustomValidity('');
        });

        // Se usa captura en window para colocar el marcador antes de que el
        // controlador existente serialice el formulario. Después se restaura la
        // observación visible para que el usuario nunca vea datos técnicos.
        window.addEventListener('submit', function (event) {
            if (event.target !== formulario) {
                return;
            }

            const esLlamada = String(canal.value || '').toUpperCase() === 'LLAMADA';
            const esOtro = String(resultado.value || '').toUpperCase() === 'OTRO';

            if (!esLlamada || !esOtro) {
                return;
            }

            if (!selector.value) {
                event.preventDefault();
                event.stopImmediatePropagation();
                selector.setCustomValidity('Indica si se logró hablar con una persona.');
                selector.reportValidity();
                return;
            }

            const observacionOriginal = observacion.value;
            const notasLimpias = limpiarMarcadores(observacionOriginal);
            const marcador = selector.value === 'SI'
                ? MARCADOR_CONTACTO
                : MARCADOR_SIN_CONTACTO;

            observacion.value = [marcador, notasLimpias]
                .filter(Boolean)
                .join('\n');

            window.setTimeout(function () {
                observacion.value = observacionOriginal;
            }, 0);
        }, true);

        formulario.addEventListener('reset', function () {
            window.setTimeout(function () {
                selector.value = '';
                actualizarVisibilidad();
            }, 0);
        });

        actualizarVisibilidad();
        return true;
    };

    const inicializarResumenExpediente = function () {
        const parametros = new URLSearchParams(window.location.search);
        const seguimientoId = Number(parametros.get('id') || 0);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle' &&
            seguimientoId > 0;

        if (!esDetalle) {
            return;
        }

        let observer = null;
        let actualizando = false;

        const recalcular = async function () {
            const destino = document.querySelector('[data-call-summary-contacted]');
            if (!destino || actualizando) {
                return;
            }

            actualizando = true;
            try {
                const response = await fetch(
                    'prueba_telefonia/api/llamadas_seguimiento.php?seguimiento_id=' + encodeURIComponent(seguimientoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }
                );
                const data = await response.json();

                if (!response.ok || !data.ok) {
                    return;
                }

                const llamadas = Array.isArray(data.llamadas) ? data.llamadas : [];
                const contactadas = llamadas.filter(function (llamada) {
                    return llamada.contacto_efectivo === true;
                }).length;

                destino.textContent = String(contactadas);
            } catch (error) {
                console.debug('No fue posible actualizar el resumen de contacto telefónico.', error);
            } finally {
                actualizando = false;
            }
        };

        let intentos = 0;
        const esperarModulo = function () {
            const historial = document.querySelector('[data-call-history-list]');
            const resumen = document.querySelector('[data-call-summary-contacted]');

            if ((!historial || !resumen) && intentos < 30) {
                intentos += 1;
                window.setTimeout(esperarModulo, 100);
                return;
            }

            if (!historial || !resumen) {
                return;
            }

            void recalcular();

            if (window.MutationObserver) {
                observer = new MutationObserver(function () {
                    window.setTimeout(function () {
                        void recalcular();
                    }, 80);
                });
                observer.observe(historial, { childList: true, subtree: false });
            }
        };

        esperarModulo();
    };

    const iniciar = function () {
        if (!inicializarFormulario()) {
            let intentos = 0;
            const timer = window.setInterval(function () {
                intentos += 1;
                if (inicializarFormulario() || intentos >= 30) {
                    window.clearInterval(timer);
                }
            }, 100);
        }

        inicializarResumenExpediente();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
