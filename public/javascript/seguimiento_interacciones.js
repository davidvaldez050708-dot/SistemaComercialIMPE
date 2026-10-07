(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const formulario = document.querySelector('[data-work-interaction-form]');

        if (!formulario) {
            return;
        }

        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
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
        const urlInteraccionInformativa =
            'index.php?controller=seguimientoInteraccion&action=registrarInformativa';
        const urlResumenVerificacion =
            'index.php?controller=seguimientoVinculacion&action=resumenVerificacionTelefonicaHoy';
        const panelVerificacionDiaria = document.querySelector('[data-call-verification-daily]');
        const accionesConHorarioObligatorio = [
            'Volver a llamar',
            'Enviar WhatsApp',
            'Enviar oficio/correo'
        ];
        const accionesQueCierranRecordatorio = [
            'Verificar información de contacto',
            'Generar oficio'
        ];
        let motivoNoInteresPendiente = '';
        let esperandoDecisionNoInteres = false;
        let toastInteraccionDiferido = false;
        let proximaAccionEditadaManualmente = false;

        if (
            !selectorResultado ||
            !selectorProximaAccion ||
            !campoFechaProximaAccion ||
            !campoObservacion
        ) {
            return;
        }

        const contenedorProximaAccion = selectorProximaAccion.closest(
            '.col-12, .col-md-6'
        );
        const contenedorFechaProximaAccion = campoFechaProximaAccion.closest(
            '.col-12, .col-md-6'
        );
        const filaFormulario = formulario.querySelector('.row');
        const avisoInformativo = document.createElement('div');
        avisoInformativo.className = 'col-12 d-none';
        avisoInformativo.setAttribute('data-work-informative-interaction-note', '');
        avisoInformativo.innerHTML =
            '<div class="alert alert-light border mb-1 py-2 px-3 small">' +
                '<i class="bi bi-info-circle me-1"></i>' +
                '<strong>Registro informativo.</strong> ' +
                'Se guardará en el expediente sin modificar la ruta ni la próxima acción.' +
            '</div>';
        filaFormulario?.prepend(avisoInformativo);

        const mostrarToastLocal = function (mensaje, esError) {
            if (!contenedorToasts || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', esError ? '4200' : '3200');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
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

        const renderizarResumenVerificacion = function (resumen) {
            if (!panelVerificacionDiaria || !resumen) {
                return;
            }

            const meta = Math.max(1, Number(resumen.meta || 25));
            const efectivas = Math.max(0, Number(resumen.verificaciones_efectivas || 0));
            const realizadas = Math.max(0, Number(resumen.llamadas_realizadas || 0));
            const contacto = Math.max(0, Number(resumen.llamadas_con_contacto || 0));
            const pendientes = Math.max(
                0,
                Number(resumen.verificaciones_pendientes_vinculo || 0)
            );
            const restantes = Math.max(0, meta - efectivas);
            const porcentaje = Math.max(0, Math.min(100, (efectivas / meta) * 100));

            const nodoEfectivas = panelVerificacionDiaria.querySelector(
                '[data-call-verification-count]'
            );
            const nodoRealizadas = panelVerificacionDiaria.querySelector(
                '[data-call-total-count]'
            );
            const nodoContacto = panelVerificacionDiaria.querySelector(
                '[data-call-contact-count]'
            );
            const nodoProgreso = panelVerificacionDiaria.querySelector(
                '[data-call-verification-progress]'
            );
            const nodoRestantes = panelVerificacionDiaria.querySelector(
                '[data-call-verification-remaining]'
            );

            if (nodoEfectivas) {
                nodoEfectivas.textContent = String(efectivas);
            }
            if (nodoRealizadas) {
                nodoRealizadas.textContent = String(realizadas);
            }
            if (nodoContacto) {
                nodoContacto.textContent = String(contacto);
            }
            if (nodoProgreso) {
                nodoProgreso.style.width = porcentaje.toFixed(1) + '%';
            }
            if (nodoRestantes) {
                if (efectivas >= meta) {
                    nodoRestantes.textContent = 'Meta diaria alcanzada';
                } else if (pendientes > 0) {
                    nodoRestantes.textContent =
                        'Faltan ' + restantes +
                        ' · ' + pendientes +
                        (pendientes === 1
                            ? ' verificación pendiente de vincular'
                            : ' verificaciones pendientes de vincular');
                } else {
                    nodoRestantes.textContent =
                        'Faltan ' + restantes +
                        (restantes === 1 ? ' institución' : ' instituciones');
                }
            }

            panelVerificacionDiaria.classList.toggle(
                'is-complete',
                efectivas >= meta
            );
        };

        const cargarResumenVerificacion = async function () {
            if (!panelVerificacionDiaria) {
                return;
            }

            try {
                const respuesta = await fetch(urlResumenVerificacion, {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                });
                const datos = await respuesta.json();

                if (respuesta.ok && datos?.ok && datos?.resumen) {
                    renderizarResumenVerificacion(datos.resumen);
                }
            } catch (error) {
                console.debug(
                    'No fue posible actualizar la meta diaria de verificaciones.',
                    error
                );
            }
        };

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
            filaFormulario?.appendChild(contenedorMotivo);
        }

        const campoMotivoNoInteres = contenedorMotivo.querySelector(
            '[data-work-no-interest-reason]'
        );


        const contenedorContactoReferido = document.createElement('div');
        contenedorContactoReferido.className = 'col-12 d-none';
        contenedorContactoReferido.setAttribute('data-work-referred-contact-wrapper', '');
        contenedorContactoReferido.innerHTML =
            '<div class="linkage-referred-contact-box">' +
                '<div class="linkage-referred-contact-head">' +
                    '<strong>Nuevo contacto proporcionado</strong>' +
                    '<span>El teléfono original se conservará como teléfono fuente.</span>' +
                '</div>' +
                '<div class="row g-2">' +
                    '<div class="col-12 col-md-6">' +
                        '<label class="form-label" for="work_referred_phone">Nuevo teléfono de contacto</label>' +
                        '<input class="form-control" id="work_referred_phone" name="nuevo_telefono_contacto" maxlength="80" autocomplete="tel">' +
                    '</div>' +
                    '<div class="col-12 col-md-6">' +
                        '<label class="form-label" for="work_referred_email">Nuevo correo de contacto</label>' +
                        '<input class="form-control" id="work_referred_email" name="nuevo_correo_contacto" type="email" maxlength="180" autocomplete="email">' +
                    '</div>' +
                    '<div class="col-12 col-md-6">' +
                        '<label class="form-label" for="work_referred_name">Persona de contacto</label>' +
                        '<input class="form-control" id="work_referred_name" name="nuevo_contacto_nombre" maxlength="180">' +
                    '</div>' +
                    '<div class="col-12 col-md-6">' +
                        '<label class="form-label" for="work_referred_role">Cargo / Área</label>' +
                        '<input class="form-control" id="work_referred_role" name="nuevo_contacto_cargo" maxlength="150">' +
                    '</div>' +
                '</div>' +
            '</div>';

        if (contenedorObservacion) {
            contenedorObservacion.insertAdjacentElement('beforebegin', contenedorContactoReferido);
        } else {
            filaFormulario?.appendChild(contenedorContactoReferido);
        }

        const campoTelefonoReferido = contenedorContactoReferido.querySelector(
            '[name="nuevo_telefono_contacto"]'
        );
        const campoCorreoReferido = contenedorContactoReferido.querySelector(
            '[name="nuevo_correo_contacto"]'
        );
        const campoNombreReferido = contenedorContactoReferido.querySelector(
            '[name="nuevo_contacto_nombre"]'
        );
        const campoCargoReferido = contenedorContactoReferido.querySelector(
            '[name="nuevo_contacto_cargo"]'
        );

        const actualizarContactoReferido = function () {
            const resultado = String(selectorResultado.value || '').toUpperCase();
            const canal = String(
                formulario.querySelector('[name="canal"]')?.value || ''
            ).toUpperCase();
            const visible = resultado === 'CONTACTO_REFERIDO' && canal === 'LLAMADA';

            contenedorContactoReferido.classList.toggle('d-none', !visible);

            if (campoTelefonoReferido) {
                campoTelefonoReferido.required = false;
            }

            if (!visible) {
                [campoTelefonoReferido, campoCorreoReferido, campoNombreReferido, campoCargoReferido]
                    .forEach(function (campo) {
                        if (campo) {
                            campo.value = '';
                        }
                    });
            }
        };

        const bloqueEvidenciaVerificacion = formulario.querySelector(
            '[data-call-verification-evidence]'
        );
        const notaEvidenciaVerificacion = formulario.querySelector(
            '[data-call-verification-note]'
        );
        const checkTelefono = formulario.querySelector(
            '[name="verificacion_telefono_confirmado"]'
        );
        const checkCorreo = formulario.querySelector(
            '[name="verificacion_correo_confirmado"]'
        );
        const checkContacto = formulario.querySelector(
            '[name="verificacion_contacto_confirmado"]'
        );

        const actualizarEvidenciaVerificacion = function () {
            if (!bloqueEvidenciaVerificacion) {
                return;
            }

            const canal = String(
                formulario.querySelector('[name="canal"]')?.value || ''
            ).toUpperCase();
            const resultado = String(selectorResultado.value || '').toUpperCase();
            const resultadosConConversacion = [
                'CONTACTO_CORRECTO',
                'CONTACTO_REFERIDO',
                'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES',
                'NO_INTERESADO'
            ];
            const visible =
                canal === 'LLAMADA' &&
                resultadosConConversacion.includes(resultado);

            bloqueEvidenciaVerificacion.classList.toggle('d-none', !visible);

            const leerDatoVisible = function (selector) {
                const valor = String(
                    document.querySelector(selector)?.textContent || ''
                ).trim();
                return valor === '—' ? '' : valor;
            };
            const telefonoActual = leerDatoVisible('[data-work-phone]');
            const correoActual = leerDatoVisible('[data-work-email]');
            const contactoActual = leerDatoVisible('[data-work-contact-name]');

            [
                [checkTelefono, telefonoActual !== '', 'phone'],
                [checkCorreo, correoActual !== '', 'email'],
                [checkContacto, contactoActual !== '', 'contact']
            ].forEach(function (item) {
                const input = item[0];
                const disponible = item[1];
                const tipo = item[2];

                if (!input) {
                    return;
                }

                input.disabled = !disponible || !visible;
                if (input.disabled) {
                    input.checked = false;
                }

                const opcion = bloqueEvidenciaVerificacion.querySelector(
                    '[data-verification-option="' + tipo + '"]'
                );
                opcion?.classList.toggle('is-disabled', !disponible);
            });

            if (!visible) {
                [checkTelefono, checkCorreo, checkContacto].forEach(function (input) {
                    if (input) {
                        input.checked = false;
                    }
                });
            }

            if (notaEvidenciaVerificacion) {
                notaEvidenciaVerificacion.textContent =
                    resultado === 'CONTACTO_REFERIDO'
                        ? 'El nuevo teléfono o correo registrado funciona como evidencia. También puedes marcar datos actuales que hayan sido confirmados.'
                        : '“Contacto correcto” por sí solo no cuenta: marca únicamente los datos que realmente fueron confirmados durante la llamada.';
            }
        };

        const campoPersonaAtendio = formulario.querySelector(
            '[name="persona_atendio"]'
        );

        const limpiarValidacionesVerificacion = function () {
            [campoPersonaAtendio, campoTelefonoReferido, campoCorreoReferido].forEach(function (campo) {
                campo?.setCustomValidity('');
                campo?.classList.remove('is-invalid');
            });
        };

        const marcarCampoInvalido = function (campo, mensaje) {
            if (!campo) {
                return;
            }

            campo.setCustomValidity(mensaje);
            campo.classList.add('is-invalid');
            campo.focus({ preventScroll: true });
            campo.scrollIntoView({ behavior: 'smooth', block: 'center' });
            campo.reportValidity();
        };

        const validarRequisitosInteraccion = function () {
            limpiarValidacionesVerificacion();

            const canal = String(
                formulario.querySelector('[name="canal"]')?.value || ''
            ).toUpperCase();
            const resultado = String(selectorResultado.value || '').toUpperCase();

            if (canal !== 'LLAMADA') {
                return true;
            }

            if (resultado === 'CONTACTO_REFERIDO') {
                const telefonoNuevo = String(campoTelefonoReferido?.value || '').trim();
                const correoNuevo = String(campoCorreoReferido?.value || '').trim();

                if (telefonoNuevo === '' && correoNuevo === '') {
                    const mensaje =
                        'Captura al menos el nuevo teléfono o correo proporcionado por la institución.';
                    mostrarToastLocal(mensaje, true);
                    marcarCampoInvalido(campoTelefonoReferido || campoCorreoReferido, mensaje);
                    return false;
                }
            }

            const resultadosConConversacion = [
                'CONTACTO_CORRECTO',
                'CONTACTO_REFERIDO',
                'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES',
                'NO_INTERESADO'
            ];

            if (!resultadosConConversacion.includes(resultado)) {
                return true;
            }

            const tieneEvidencia =
                Boolean(checkTelefono?.checked) ||
                Boolean(checkCorreo?.checked) ||
                Boolean(checkContacto?.checked) ||
                (
                    resultado === 'CONTACTO_REFERIDO' &&
                    (
                        String(campoTelefonoReferido?.value || '').trim() !== '' ||
                        String(campoCorreoReferido?.value || '').trim() !== ''
                    )
                );

            const persona = String(campoPersonaAtendio?.value || '').trim();

            if (tieneEvidencia && persona === '') {
                const mensaje =
                    'Indica quién atendió la llamada para que esta verificación pueda contar en la meta diaria.';
                mostrarToastLocal(mensaje, true);
                marcarCampoInvalido(campoPersonaAtendio, mensaje);
                return false;
            }

            return true;
        };

        campoPersonaAtendio?.addEventListener('input', function () {
            campoPersonaAtendio.setCustomValidity('');
            campoPersonaAtendio.classList.remove('is-invalid');
        });
        campoTelefonoReferido?.addEventListener('input', function () {
            campoTelefonoReferido.setCustomValidity('');
            campoTelefonoReferido.classList.remove('is-invalid');
        });
        campoCorreoReferido?.addEventListener('input', function () {
            campoCorreoReferido.setCustomValidity('');
            campoCorreoReferido.classList.remove('is-invalid');
        });

        const pasoRutaActual = function () {
            return Number(offcanvas?.dataset.flowStep || 0);
        };

        const esRutaAvanzada = function () {
            return pasoRutaActual() >= 8;
        };

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
            if (esRutaAvanzada()) {
                selectorProximaAccion.value = '';
                selectorProximaAccion.disabled = true;
                campoFechaProximaAccion.value = '';
                campoFechaProximaAccion.disabled = true;
                campoFechaProximaAccion.required = false;
                actualizarEtiquetaFecha(false);
                return;
            }

            const accion = String(selectorProximaAccion.value || '');
            const resultado = String(selectorResultado.value || '');
            const sinAccion = accion === '';
            const resultadoManual = resultado === 'OTRO';
            const fechaObligatoria = accionesConHorarioObligatorio.includes(accion);
            const cierraRecordatorio = accionesQueCierranRecordatorio.includes(accion);

            if (cierraRecordatorio) {
                campoFechaProximaAccion.value = '';
                campoFechaProximaAccion.disabled = true;
                campoFechaProximaAccion.required = false;
                actualizarEtiquetaFecha(false);
                return;
            }

            campoFechaProximaAccion.disabled = sinAccion && !resultadoManual;
            campoFechaProximaAccion.required = fechaObligatoria;
            actualizarEtiquetaFecha(fechaObligatoria);

            if (sinAccion && !resultadoManual) {
                campoFechaProximaAccion.value = '';
            }
        };

        const aplicarModoRuta = function () {
            const avanzada = esRutaAvanzada();
            avisoInformativo.classList.toggle('d-none', !avanzada);
            contenedorProximaAccion?.classList.toggle('d-none', avanzada);
            contenedorFechaProximaAccion?.classList.toggle('d-none', avanzada);

            if (avanzada) {
                selectorProximaAccion.value = '';
                selectorProximaAccion.disabled = true;
                campoFechaProximaAccion.value = '';
                campoFechaProximaAccion.disabled = true;
                campoFechaProximaAccion.required = false;
                actualizarEtiquetaFecha(false);
            } else {
                selectorProximaAccion.disabled = false;
            }
        };

        const aplicarResultadoInteraccion = function () {
            const resultado = String(selectorResultado.value || '');
            let accionSugerida = '';
            let conservarSeleccionManual = false;

            switch (resultado) {
                case 'SIN_RESPUESTA':
                case 'OCUPADO':
                    accionSugerida = 'Volver a llamar';
                    break;
                case 'NUMERO_INCORRECTO':
                    accionSugerida = 'Investigar nuevo contacto';
                    break;
                case 'CONTACTO_INCORRECTO':
                    accionSugerida = 'Confirmar contacto de RH';
                    break;
                case 'CONTACTO_CORRECTO':
                case 'CONTACTO_REFERIDO':
                case 'SOLICITO_INFORMACION':
                    /*
                     * La ruta operativa decide el siguiente paso real. Si el
                     * contacto todavía no está verificado, no adelantamos una
                     * acción de verificación: primero pueden faltar persona,
                     * cargo o correo.
                     */
                    accionSugerida = contactoEstaVerificado()
                        ? 'Generar oficio'
                        : '';
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

            if (
                !conservarSeleccionManual &&
                !esRutaAvanzada() &&
                !proximaAccionEditadaManualmente
            ) {
                selectorProximaAccion.value = accionSugerida;
            }

            const noInteresado = resultado === 'NO_INTERESADO';
            contenedorMotivo.classList.toggle('d-none', !noInteresado);

            if (campoMotivoNoInteres) {
                campoMotivoNoInteres.required = noInteresado;

                if (!noInteresado) {
                    campoMotivoNoInteres.value = '';
                }
            }

            if (esRutaAvanzada()) {
                selectorProximaAccion.value = '';
                selectorProximaAccion.disabled = true;
                campoFechaProximaAccion.value = '';
                campoFechaProximaAccion.disabled = true;
                campoFechaProximaAccion.required = false;
                actualizarEtiquetaFecha(false);
                return;
            }

            selectorProximaAccion.disabled = noInteresado;

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

        const agregarActividadInformativa = function (interaccion) {
            const lista = document.querySelector('[data-work-activity-list]');

            if (!lista || !interaccion) {
                return;
            }

            lista.querySelector('.linkage-work-empty')?.remove();

            const articulo = document.createElement('article');
            const fecha = document.createElement('strong');
            const meta = document.createElement('span');
            const notas = document.createElement('p');

            fecha.textContent = String(interaccion.fecha_label || 'Ahora');
            meta.textContent =
                String(interaccion.canal_label || 'Interacción') + ' · ' +
                String(interaccion.resultado_label || 'Otro');
            notas.textContent = String(interaccion.notas || '');

            articulo.appendChild(fecha);
            articulo.appendChild(meta);

            if (notas.textContent !== '') {
                articulo.appendChild(notas);
            }

            lista.prepend(articulo);

            while (lista.children.length > 3) {
                lista.lastElementChild?.remove();
            }
        };

        const registrarInteraccionInformativa = async function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();

            if (!validarRequisitosInteraccion()) {
                return;
            }

            if (!formulario.checkValidity()) {
                formulario.reportValidity();
                return;
            }

            const botonGuardar = formulario.querySelector('button[type="submit"]');
            const textoOriginal = botonGuardar?.textContent || 'Guardar interacción';
            const formData = new FormData(formulario);
            const resultado = String(selectorResultado.value || '');

            formData.delete('proxima_accion');
            formData.delete('proxima_accion_at');

            if (resultado === 'NO_INTERESADO') {
                const motivo = String(campoMotivoNoInteres?.value || '').trim();

                if (motivo === '') {
                    campoMotivoNoInteres?.reportValidity();
                    campoMotivoNoInteres?.focus();
                    return;
                }

                const observacion = String(formData.get('observacion') || '').trim();
                formData.set(
                    'observacion',
                    observacion !== ''
                        ? observacion + '\nMotivo de no interés: ' + motivo
                        : 'Motivo de no interés: ' + motivo
                );
            }

            if (botonGuardar) {
                botonGuardar.disabled = true;
                botonGuardar.textContent = 'Guardando...';
            }

            try {
                const respuesta = await fetch(urlInteraccionInformativa, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formData
                });
                const datos = await respuesta.json();

                if (!respuesta.ok || !datos.ok) {
                    throw new Error(
                        datos.mensaje || 'No fue posible registrar la interacción.'
                    );
                }

                agregarActividadInformativa(datos.interaccion);
                formulario.classList.add('d-none');
                formulario.reset();
                aplicarModoRuta();
                aplicarResultadoInteraccion();
                const origenLlamada = String(
                    formData.get('origen_llamada') || ''
                ).toUpperCase();

                if (origenLlamada !== 'ZADARMA') {
                    mostrarToastLocal(
                        datos.mensaje ||
                        'Interacción registrada en el expediente sin modificar la ruta.',
                        false
                    );
                }

                document.dispatchEvent(new CustomEvent('impe:interaction-informative-saved', {
                    detail: {
                        seguimientoId: Number(formData.get('seguimiento_id') || 0),
                        interaccionId: Number(datos.interaccion?.id || 0)
                    }
                }));
            } catch (error) {
                mostrarToastLocal(
                    error.message || 'No fue posible registrar la interacción.',
                    true
                );
            } finally {
                if (botonGuardar) {
                    botonGuardar.disabled = false;
                    botonGuardar.textContent = textoOriginal;
                }
            }
        };

        selectorResultado.addEventListener('change', function () {
            proximaAccionEditadaManualmente = false;
            actualizarContactoReferido();
            actualizarEvidenciaVerificacion();
            aplicarResultadoInteraccion();
        });

        formulario.querySelector('[name="canal"]')?.addEventListener('change', function () {
            actualizarContactoReferido();
            actualizarEvidenciaVerificacion();
        });

        selectorProximaAccion.addEventListener('change', function () {
            proximaAccionEditadaManualmente = true;
            actualizarFechaSegunAccion();
        });

        formulario.addEventListener('reset', function () {
            proximaAccionEditadaManualmente = false;
            window.setTimeout(function () {
                aplicarModoRuta();
                actualizarContactoReferido();
                actualizarEvidenciaVerificacion();
                aplicarResultadoInteraccion();
            }, 0);
        });

        botonAbrirInteraccion?.addEventListener('click', function () {
            window.setTimeout(function () {
                aplicarModoRuta();
                actualizarContactoReferido();
                actualizarEvidenciaVerificacion();
                aplicarResultadoInteraccion();
            }, 0);
        });

        document.addEventListener('impe:flow-updated', function () {
            aplicarModoRuta();
            actualizarContactoReferido();
            actualizarEvidenciaVerificacion();
            aplicarResultadoInteraccion();
        });

        formulario.addEventListener('submit', function (event) {
            if (esRutaAvanzada()) {
                registrarInteraccionInformativa(event);
                return;
            }

            if (!validarRequisitosInteraccion()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

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
                mostrarToastLocal('Interacción registrada correctamente.', false);
            }

            motivoNoInteresPendiente = '';
            esperandoDecisionNoInteres = false;
            toastInteraccionDiferido = false;

            if (campoMotivoDescarte) {
                campoMotivoDescarte.readOnly = false;
            }
        });

        document.addEventListener('impe:telephony-call-linked', function () {
            window.setTimeout(cargarResumenVerificacion, 120);
        });
        document.addEventListener('impe:interaction-exact-id-ready', function () {
            window.setTimeout(cargarResumenVerificacion, 120);
        });
        document.addEventListener('impe:interaction-informative-saved', function () {
            window.setTimeout(cargarResumenVerificacion, 120);
        });

        aplicarModoRuta();
        actualizarEvidenciaVerificacion();
        aplicarResultadoInteraccion();
        cargarResumenVerificacion();
    });
})();
