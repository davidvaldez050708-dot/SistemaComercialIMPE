(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.linkage-comment-form textarea').forEach(function (campo) {
            campo.setAttribute('rows', '3');
            campo.setAttribute('placeholder', 'Escribe una observación...');
            campo.style.minHeight = '82px';
            campo.style.fontSize = '13px';
            campo.style.lineHeight = '1.45';
        });

        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const textoCarga = 'Consultando ruta...';
        const rolActual = String(
            document.querySelector('.topbar-account-role')?.textContent || ''
        ).trim().toLowerCase();
        const esCuentaClave = rolActual.includes('cuenta clave');
        const urlObservacionKam =
            'index.php?controller=seguimientoObservacion&action=registrarTrabajo';
        let temporizadorRespaldo = null;
        let seguimientoObservacionId = 0;

        const mostrarAvisoCarga = function () {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', '2600');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-hourglass-split"></i>' +
                    '<span>Espera un momento mientras se actualiza la ruta.</span>' +
                '</div>';
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast).show();
        };

        const mostrarToastObservacion = function (mensaje, esError) {
            const contenedor = document.querySelector('.toast-container');

            if (!contenedor || !window.bootstrap) {
                return;
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', esError ? '4200' : '3000');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                    '<span></span>' +
                '</div>';
            toast.querySelector('span').textContent = mensaje;
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast).show();
        };

        const agregarObservacionAlPanel = function (observacion) {
            const lista = offcanvas.querySelector('[data-work-observation-list]');

            if (!lista || !observacion) {
                return;
            }

            lista.querySelector('.linkage-work-empty')?.remove();

            const articulo = document.createElement('article');
            const fecha = document.createElement('strong');
            const autor = document.createElement('span');
            const texto = document.createElement('p');

            fecha.textContent = String(observacion.fecha_label || 'Ahora');
            autor.textContent = String(observacion.autor || 'Cuenta Clave');
            texto.textContent = String(observacion.observacion || '');

            articulo.appendChild(fecha);
            articulo.appendChild(autor);
            articulo.appendChild(texto);
            lista.prepend(articulo);

            const articulos = lista.querySelectorAll('article');
            for (let indice = 2; indice < articulos.length; indice += 1) {
                articulos[indice].remove();
            }
        };

        const instalarFormularioObservacionKam = function () {
            if (!esCuentaClave) {
                return;
            }

            const lista = offcanvas.querySelector('[data-work-observation-list]');
            const seccion = lista?.closest('.linkage-work-section');
            const titulo = seccion?.querySelector('.linkage-work-section-title');

            if (!lista || !seccion || !titulo || seccion.querySelector('[data-work-kam-observation-form]')) {
                return;
            }

            const formulario = document.createElement('form');
            formulario.setAttribute('data-work-kam-observation-form', '');
            formulario.style.display = 'grid';
            formulario.style.gap = '8px';
            formulario.style.margin = '10px 0 12px';

            const campo = document.createElement('textarea');
            campo.className = 'form-control';
            campo.setAttribute('rows', '2');
            campo.setAttribute('maxlength', '2000');
            campo.setAttribute('placeholder', 'Escribe una observación...');
            campo.setAttribute('aria-label', 'Observación para el Analista');
            campo.style.minHeight = '64px';
            campo.style.fontSize = '12.5px';
            campo.style.lineHeight = '1.4';
            campo.style.resize = 'vertical';

            const pie = document.createElement('div');
            pie.style.display = 'flex';
            pie.style.alignItems = 'center';
            pie.style.justifyContent = 'space-between';
            pie.style.gap = '8px';

            const ayuda = document.createElement('small');
            ayuda.textContent = 'Se guarda en el expediente del Analista.';
            ayuda.style.color = '#6b7789';
            ayuda.style.fontSize = '10.5px';

            const boton = document.createElement('button');
            boton.type = 'submit';
            boton.className = 'btn btn-system-save';
            boton.innerHTML = '<i class="bi bi-send"></i><span>Guardar</span>';
            boton.style.minHeight = '34px';
            boton.style.padding = '7px 11px';
            boton.style.fontSize = '11px';

            pie.appendChild(ayuda);
            pie.appendChild(boton);
            formulario.appendChild(campo);
            formulario.appendChild(pie);
            titulo.insertAdjacentElement('afterend', formulario);

            formulario.addEventListener('submit', async function (event) {
                event.preventDefault();

                const observacion = String(campo.value || '').trim();
                const seguimientoId = Number(
                    seguimientoObservacionId ||
                    offcanvas.dataset.flowSeguimientoId ||
                    0
                );

                if (!seguimientoId) {
                    mostrarToastObservacion('No fue posible identificar el seguimiento.', true);
                    return;
                }

                if (observacion === '') {
                    campo.focus();
                    mostrarToastObservacion('Escribe una observación antes de guardar.', true);
                    return;
                }

                const textoOriginal = boton.innerHTML;
                const formData = new FormData();
                formData.set('seguimiento_id', String(seguimientoId));
                formData.set('observacion', observacion);

                boton.disabled = true;
                boton.innerHTML = '<span>Guardando...</span>';

                try {
                    const respuesta = await fetch(urlObservacionKam, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'fetch' },
                        body: formData
                    });
                    const datos = await respuesta.json();

                    if (!respuesta.ok || !datos.ok) {
                        throw new Error(
                            datos.mensaje || 'No fue posible guardar la observación.'
                        );
                    }

                    campo.value = '';
                    agregarObservacionAlPanel(datos.observacion);
                    mostrarToastObservacion(
                        datos.mensaje || 'Observación guardada en el expediente.',
                        false
                    );
                } catch (error) {
                    mostrarToastObservacion(
                        error.message || 'No fue posible guardar la observación.',
                        true
                    );
                } finally {
                    boton.disabled = false;
                    boton.innerHTML = textoOriginal;
                }
            });
        };

        instalarFormularioObservacionKam();

        const limpiarPendiente = function () {
            window.clearTimeout(temporizadorRespaldo);
            temporizadorRespaldo = null;
            offcanvas.removeAttribute('data-flow-ui-pending');
            offcanvas.removeAttribute('data-flow-ui-prepared');
            offcanvas.removeAttribute('data-flow-ui-fallback');
        };

        const restaurarFallback = function () {
            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
            const fallback = String(
                offcanvas.getAttribute('data-flow-ui-fallback') || ''
            ).trim();

            if (proximaAccion && fallback !== '') {
                proximaAccion.textContent = fallback;
            }

            limpiarPendiente();
        };

        const prepararBloqueRuta = function () {
            const bloque = offcanvas.querySelector('[data-work-flow-section]');

            if (!bloque) {
                offcanvas.setAttribute('data-flow-ui-prepared', '1');
                return;
            }

            const contador = bloque.querySelector('[data-flow-step-count]');
            const progreso = bloque.querySelector('[data-flow-progress]');
            const ventana = bloque.querySelector('[data-flow-window]');
            const titulo = bloque.querySelector('[data-flow-title]');
            const descripcion = bloque.querySelector('[data-flow-description]');
            const faltantes = bloque.querySelector('[data-flow-missing]');
            const acciones = bloque.querySelector('[data-flow-actions]');

            if (contador) {
                contador.textContent = 'Consultando...';
            }
            if (progreso) {
                progreso.style.width = '0%';
            }
            if (ventana) {
                ventana.innerHTML = '';
            }
            if (titulo) {
                titulo.textContent = textoCarga;
            }
            if (descripcion) {
                descripcion.textContent = 'Actualizando el estado real del seguimiento.';
            }
            if (faltantes) {
                faltantes.innerHTML = '';
                faltantes.classList.add('d-none');
            }
            if (acciones) {
                acciones.innerHTML = '';
                acciones.classList.add('has-single-action');
            }

            bloque.classList.remove('d-none');
            offcanvas.setAttribute('data-flow-ui-prepared', '1');
        };

        const iniciarCarga = function (seguimientoId) {
            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');

            window.clearTimeout(temporizadorRespaldo);
            offcanvas.setAttribute('data-flow-ui-pending', String(seguimientoId || '1'));
            offcanvas.setAttribute('data-flow-ui-prepared', '0');
            offcanvas.removeAttribute('data-flow-ui-fallback');

            if (proximaAccion) {
                proximaAccion.textContent = textoCarga;
            }

            window.setTimeout(prepararBloqueRuta, 0);

            temporizadorRespaldo = window.setTimeout(function () {
                if (offcanvas.hasAttribute('data-flow-ui-pending')) {
                    restaurarFallback();
                }
            }, 5000);
        };

        document.addEventListener('click', function (event) {
            const boton = event.target.closest('[data-work-follow]');

            if (!boton) {
                return;
            }

            seguimientoObservacionId = Number(
                boton.getAttribute('data-work-follow-id') || 0
            );
            iniciarCarga(seguimientoObservacionId);
        }, true);

        document.addEventListener('submit', function (event) {
            const formulario = event.target instanceof Element
                ? event.target.closest('[data-work-interaction-form]')
                : null;

            if (!formulario || !offcanvas.hasAttribute('data-flow-ui-pending')) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            mostrarAvisoCarga();
        }, true);

        const observador = new MutationObserver(function () {
            if (!offcanvas.hasAttribute('data-flow-ui-pending')) {
                return;
            }

            const proximaAccion = offcanvas.querySelector('[data-work-next-action]');
            const bloque = offcanvas.querySelector('[data-work-flow-section]');
            const titulo = bloque?.querySelector('[data-flow-title]');
            const contador = bloque?.querySelector('[data-flow-step-count]');
            const preparado = offcanvas.getAttribute('data-flow-ui-prepared') === '1';
            const tituloActual = String(titulo?.textContent || '').trim();
            const contadorActual = String(contador?.textContent || '').trim();
            const flujoResuelto = Boolean(
                preparado &&
                bloque &&
                !bloque.classList.contains('d-none') &&
                tituloActual !== '' &&
                tituloActual !== textoCarga &&
                /^Paso\s+\d+\s+de\s+\d+/i.test(contadorActual)
            );

            if (flujoResuelto) {
                if (proximaAccion) {
                    proximaAccion.textContent = tituloActual;
                }
                limpiarPendiente();
                return;
            }

            if (proximaAccion) {
                const valorActual = String(proximaAccion.textContent || '').trim();

                if (valorActual !== '' && valorActual !== textoCarga) {
                    offcanvas.setAttribute('data-flow-ui-fallback', valorActual);
                    proximaAccion.textContent = textoCarga;
                }
            }

            if (
                preparado &&
                bloque &&
                bloque.classList.contains('d-none') &&
                offcanvas.getAttribute('data-flow-ui-fallback')
            ) {
                restaurarFallback();
            }
        });

        observador.observe(offcanvas, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['class']
        });

        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            limpiarPendiente();
            seguimientoObservacionId = 0;
        });
    });
})();
