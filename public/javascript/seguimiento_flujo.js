(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const urlEstado = 'index.php?controller=seguimientoFlujo&action=estado';
        let seguimientoActualId = 0;
        let temporizadorConsulta = null;
        let consultando = false;

        const crearBloque = function () {
            let bloque = offcanvas.querySelector('[data-work-flow-section]');

            if (bloque) {
                return bloque;
            }

            const referencia = offcanvas.querySelector('[data-work-next-section]');

            if (!referencia) {
                return null;
            }

            bloque = document.createElement('section');
            bloque.className = 'linkage-work-section d-none';
            bloque.setAttribute('data-work-flow-section', '');
            bloque.innerHTML =
                '<div class="linkage-flow-heading">' +
                    '<h3>Ruta de vinculación</h3>' +
                    '<span class="linkage-flow-step-count" data-flow-step-count>—</span>' +
                '</div>' +
                '<div class="linkage-flow-progress" aria-hidden="true">' +
                    '<span data-flow-progress></span>' +
                '</div>' +
                '<div class="linkage-flow-window" data-flow-window></div>' +
                '<div class="linkage-flow-current">' +
                    '<span>Paso actual</span>' +
                    '<h4 data-flow-title>Consultando ruta...</h4>' +
                    '<p data-flow-description></p>' +
                    '<div class="linkage-flow-missing d-none" data-flow-missing></div>' +
                '</div>' +
                '<div class="linkage-flow-actions has-single-action" data-flow-actions></div>';

            referencia.insertAdjacentElement('afterend', bloque);
            return bloque;
        };

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const etiquetaNodo = function (tipo) {
            if (tipo === 'anterior') {
                return 'Anterior';
            }
            if (tipo === 'actual') {
                return 'Actual';
            }
            return 'Siguiente';
        };

        const renderizarNodo = function (paso, tipo) {
            if (!paso) {
                return '';
            }

            const clases = ['linkage-flow-node'];

            if (tipo === 'actual') {
                clases.push('is-current');
            }
            if (tipo === 'anterior') {
                clases.push('is-complete');
            }

            return '<div class="' + clases.join(' ') + '">' +
                '<span>' + etiquetaNodo(tipo) + '</span>' +
                '<strong>' + escapar(paso.titulo || '—') + '</strong>' +
            '</div>';
        };

        const crearBotonAccion = function (accion, principal) {
            if (!accion || !accion.codigo) {
                return '';
            }

            const clase = principal ? 'btn btn-system-save' : 'btn btn-system-light';
            const icono = escapar(accion.icono || 'bi-arrow-right');
            const etiqueta = escapar(accion.etiqueta || 'Continuar');
            const codigo = escapar(accion.codigo);

            return '<button type="button" class="' + clase + '" data-flow-action="' + codigo + '">' +
                '<i class="bi ' + icono + '"></i>' +
                '<span>' + etiqueta + '</span>' +
            '</button>';
        };

        const renderizar = function (flujo) {
            const bloque = crearBloque();

            if (!bloque || !flujo) {
                return;
            }

            bloque.classList.remove('d-none');

            const contador = bloque.querySelector('[data-flow-step-count]');
            const progreso = bloque.querySelector('[data-flow-progress]');
            const ventana = bloque.querySelector('[data-flow-window]');
            const titulo = bloque.querySelector('[data-flow-title]');
            const descripcion = bloque.querySelector('[data-flow-description]');
            const faltantes = bloque.querySelector('[data-flow-missing]');
            const acciones = bloque.querySelector('[data-flow-actions]');

            if (contador) {
                contador.textContent =
                    'Paso ' + Number(flujo.paso_actual || 0) +
                    ' de ' + Number(flujo.total_pasos || 0);
            }

            if (progreso) {
                progreso.style.width = Math.max(0, Math.min(100, Number(flujo.porcentaje || 0))) + '%';
            }

            if (ventana) {
                ventana.innerHTML =
                    renderizarNodo(flujo.ventana?.anterior, 'anterior') +
                    renderizarNodo(flujo.ventana?.actual, 'actual') +
                    renderizarNodo(flujo.ventana?.siguiente, 'siguiente');
            }

            if (titulo) {
                titulo.textContent = String(flujo.titulo || 'Próxima acción');
            }

            if (descripcion) {
                descripcion.textContent = String(flujo.descripcion || '');
            }

            const listaFaltantes = Array.isArray(flujo.faltantes)
                ? flujo.faltantes.filter(Boolean)
                : [];

            if (faltantes) {
                faltantes.classList.toggle('d-none', listaFaltantes.length === 0);
                faltantes.innerHTML = listaFaltantes.length === 0
                    ? ''
                    : '<span class="linkage-flow-missing-label">Falta:</span>' +
                        listaFaltantes.map(function (item) {
                            return '<span class="linkage-flow-chip">' + escapar(item) + '</span>';
                        }).join('');
            }

            if (acciones) {
                acciones.innerHTML =
                    crearBotonAccion(flujo.accion_principal, true) +
                    crearBotonAccion(flujo.accion_secundaria, false);
                acciones.classList.toggle(
                    'has-single-action',
                    !flujo.accion_secundaria || !flujo.accion_secundaria.codigo
                );
            }

            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
            if (proximaAccion && flujo.titulo) {
                proximaAccion.textContent = flujo.titulo;
            }

            const botonTrabajo = document.querySelector(
                '[data-work-follow-id="' + Number(flujo.seguimiento_id || 0) + '"]'
            );
            const fila = botonTrabajo?.closest('[data-linkage-follow-row]');
            const proximaFila = fila?.querySelector('[data-row-next-action]');

            if (proximaFila && flujo.titulo) {
                proximaFila.textContent = flujo.titulo;
            }
        };

        const consultar = async function () {
            if (!seguimientoActualId || consultando) {
                return;
            }

            consultando = true;

            try {
                const respuesta = await fetch(
                    urlEstado + '&seguimiento_id=' + encodeURIComponent(seguimientoActualId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );

                if (respuesta.status === 403) {
                    offcanvas.querySelector('[data-work-flow-section]')?.classList.add('d-none');
                    return;
                }

                const datos = await respuesta.json();

                if (!datos.ok || !datos.flujo) {
                    return;
                }

                renderizar(datos.flujo);
            } catch (error) {
                console.error(error);
            } finally {
                consultando = false;
            }
        };

        const programarConsulta = function (demora) {
            window.clearTimeout(temporizadorConsulta);
            temporizadorConsulta = window.setTimeout(consultar, demora || 180);
        };

        const mostrarAviso = function (mensaje) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast system-toast-error';
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast, { autohide: true, delay: 4200 }).show();
        };

        const abrirInteraccionLlamada = function () {
            const boton = offcanvas.querySelector('[data-work-toggle-interaction]');

            if (!boton || boton.disabled) {
                mostrarAviso('La interacción no está disponible en este seguimiento.');
                return;
            }

            boton.click();

            window.setTimeout(function () {
                const formulario = offcanvas.querySelector('[data-work-interaction-form]');
                const canal = formulario?.querySelector('[name="canal"]');

                if (canal) {
                    canal.value = 'LLAMADA_IP';
                    canal.dispatchEvent(new Event('change', { bubbles: true }));
                }

                formulario?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        };

        const ejecutarAccion = function (codigo) {
            const acciones = {
                COMPLETAR_DATOS: '[data-work-toggle-contact]',
                VERIFICAR_CONTACTO: '[data-work-verify-contact]',
                GENERAR_OFICIO: '[data-work-generate-oficio]',
                GENERAR_PDF: '[data-work-preview-oficio]',
                PREPARAR_CORREO: '[data-work-mail-oficio]',
                PROGRAMAR_ENVIO: '[data-schedule-open]',
                REPROGRAMAR_ENVIO: '[data-schedule-open]',
                REGISTRAR_INTERACCION: '[data-work-toggle-interaction]'
            };

            if (codigo === 'REGISTRAR_LLAMADA') {
                abrirInteraccionLlamada();
                return;
            }

            const selector = acciones[codigo];
            const boton = selector ? offcanvas.querySelector(selector) : null;

            if (!boton || boton.disabled || boton.classList.contains('d-none')) {
                mostrarAviso('Esta acción todavía no está disponible en el panel.');
                programarConsulta(260);
                return;
            }

            boton.click();

            window.setTimeout(function () {
                const formulario = codigo === 'COMPLETAR_DATOS'
                    ? offcanvas.querySelector('[data-work-contact-form]')
                    : (codigo === 'REGISTRAR_INTERACCION'
                        ? offcanvas.querySelector('[data-work-interaction-form]')
                        : null);
                formulario?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        };

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoActualId = Number(
                    botonTrabajo.getAttribute('data-work-follow-id') || 0
                );
                crearBloque();
                programarConsulta(260);
                return;
            }

            const botonFlujo = event.target.closest('[data-flow-action]');

            if (botonFlujo && botonFlujo.closest('[data-work-flow-section]')) {
                event.preventDefault();
                ejecutarAccion(String(botonFlujo.getAttribute('data-flow-action') || ''));
            }
        });

        const observador = new MutationObserver(function (mutaciones) {
            if (!seguimientoActualId) {
                return;
            }

            const cambioExterno = mutaciones.some(function (mutacion) {
                const objetivo = mutacion.target instanceof Element
                    ? mutacion.target
                    : mutacion.target.parentElement;

                return objetivo && !objetivo.closest('[data-work-flow-section]');
            });

            if (cambioExterno) {
                programarConsulta(420);
            }
        });

        observador.observe(offcanvas, {
            childList: true,
            subtree: true,
            characterData: true
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
            window.clearTimeout(temporizadorConsulta);
            offcanvas.querySelector('[data-work-flow-section]')?.classList.add('d-none');
        });
    });
})();
