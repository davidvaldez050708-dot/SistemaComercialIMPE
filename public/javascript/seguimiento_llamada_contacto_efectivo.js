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
        const personaAtendio = formulario.querySelector('[name="persona_atendio"]');

        if (!canal || !resultado || !observacion) {
            return false;
        }

        formulario.dataset.contactoEfectivoReady = '1';

        const campoPersona = personaAtendio
            ? (personaAtendio.closest('[class*="col-"]') || personaAtendio.parentElement)
            : null;
        const campoResultado = resultado.closest('[class*="col-"]') || resultado.parentElement;
        const campo = document.createElement('div');
        campo.className = 'col-12 d-none call-contact-effective-field';
        campo.setAttribute('data-call-contact-field', '');
        campo.innerHTML =
            '<div class="call-contact-effective-box" data-call-contact-box>' +
                '<span class="form-label call-contact-effective-title">¿Se logró hablar con una persona?</span>' +
                '<div class="call-contact-effective-options" role="group" aria-label="Contacto efectivo">' +
                    '<button type="button" class="call-contact-effective-option" data-call-contact-option="SI" aria-pressed="false">' +
                        '<i class="bi bi-person-check" aria-hidden="true"></i>' +
                        '<span>Sí, hubo contacto</span>' +
                    '</button>' +
                    '<button type="button" class="call-contact-effective-option" data-call-contact-option="NO" aria-pressed="false">' +
                        '<i class="bi bi-person-x" aria-hidden="true"></i>' +
                        '<span>No hubo contacto</span>' +
                    '</button>' +
                '</div>' +
                '<div class="call-contact-effective-error" data-call-contact-error hidden>Selecciona una opción.</div>' +
            '</div>';

        if (campoPersona && campoPersona.parentElement) {
            campoPersona.insertAdjacentElement('afterend', campo);
        } else if (campoResultado && campoResultado.parentElement) {
            campoResultado.parentElement.appendChild(campo);
        } else {
            formulario.querySelector('.row')?.appendChild(campo);
        }

        const caja = campo.querySelector('[data-call-contact-box]');
        const opciones = Array.from(campo.querySelectorAll('[data-call-contact-option]'));
        const error = campo.querySelector('[data-call-contact-error]');
        let valorContacto = '';

        const limpiarError = function () {
            caja?.classList.remove('is-invalid');
            if (error) {
                error.hidden = true;
            }
        };

        const seleccionar = function (valor) {
            valorContacto = String(valor || '').toUpperCase();
            opciones.forEach(function (boton) {
                const activo = boton.dataset.callContactOption === valorContacto;
                boton.classList.toggle('is-selected', activo);
                boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
            limpiarError();
        };

        opciones.forEach(function (boton) {
            boton.addEventListener('click', function () {
                const valor = String(boton.dataset.callContactOption || '').toUpperCase();
                seleccionar(valorContacto === valor ? '' : valor);
            });
        });

        const actualizarVisibilidad = function () {
            const esLlamada = String(canal.value || '').toUpperCase() === 'LLAMADA';
            const esOtro = String(resultado.value || '').toUpperCase() === 'OTRO';
            const visible = esLlamada && esOtro;

            campo.classList.toggle('d-none', !visible);

            if (!visible) {
                seleccionar('');
            }
        };

        canal.addEventListener('change', actualizarVisibilidad);
        resultado.addEventListener('change', actualizarVisibilidad);

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

            if (!valorContacto) {
                event.preventDefault();
                event.stopImmediatePropagation();
                caja?.classList.add('is-invalid');
                if (error) {
                    error.hidden = false;
                }
                opciones[0]?.focus();
                return;
            }

            const observacionOriginal = observacion.value;
            const notasLimpias = limpiarMarcadores(observacionOriginal);
            const marcador = valorContacto === 'SI'
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
                seleccionar('');
                actualizarVisibilidad();
            }, 0);
        });

        actualizarVisibilidad();
        return true;
    };

    const procesarRegistroVisible = function (registro) {
        if (!(registro instanceof Element)) {
            return;
        }

        let tipoContacto = '';
        const bloquesTexto = Array.from(registro.querySelectorAll('p'));

        bloquesTexto.forEach(function (bloque) {
            const textoOriginal = String(bloque.textContent || '');

            if (textoOriginal.includes(MARCADOR_CONTACTO)) {
                tipoContacto = 'SI';
            } else if (textoOriginal.includes(MARCADOR_SIN_CONTACTO)) {
                tipoContacto = 'NO';
            }

            if (
                !textoOriginal.includes(MARCADOR_CONTACTO) &&
                !textoOriginal.includes(MARCADOR_SIN_CONTACTO)
            ) {
                return;
            }

            const textoLimpio = limpiarMarcadores(textoOriginal);
            if (textoLimpio) {
                bloque.textContent = textoLimpio;
            } else {
                bloque.remove();
            }
        });

        if (!tipoContacto) {
            return;
        }

        const etiqueta = tipoContacto === 'SI' ? 'Contacto efectivo' : 'Sin contacto';
        const meta = registro.matches('.linkage-history-item')
            ? registro.querySelector('div > span')
            : registro.querySelector(':scope > span');

        if (!meta) {
            return;
        }

        const textoMeta = String(meta.textContent || '')
            .replace(/\s*·\s*(Contacto efectivo|Sin contacto)\s*$/i, '')
            .trim();

        meta.textContent = textoMeta ? textoMeta + ' · ' + etiqueta : etiqueta;
        meta.dataset.contactoEfectivoPresentado = tipoContacto;
    };

    const corregirMarcadoresVisibles = function (raiz) {
        if (!raiz) {
            return;
        }

        const selector = '[data-work-activity-list] article, .linkage-history-item';
        const registros = [];

        if (raiz instanceof Element && raiz.matches(selector)) {
            registros.push(raiz);
        }

        if (typeof raiz.querySelectorAll === 'function') {
            registros.push(...raiz.querySelectorAll(selector));
        }

        registros.forEach(procesarRegistroVisible);
    };

    const inicializarPresentacionMarcadores = function () {
        corregirMarcadoresVisibles(document);

        if (!window.MutationObserver || !document.body) {
            return;
        }

        const observer = new MutationObserver(function (mutaciones) {
            mutaciones.forEach(function (mutacion) {
                mutacion.addedNodes.forEach(function (nodo) {
                    if (nodo instanceof Element) {
                        corregirMarcadoresVisibles(nodo);
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
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

        inicializarPresentacionMarcadores();
        inicializarResumenExpediente();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
