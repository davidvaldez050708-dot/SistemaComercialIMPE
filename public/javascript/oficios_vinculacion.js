(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoDetalleId = Number(parametros.get('id') || 0);
        let seguimientoActualId = 0;
        let estadoActual = null;

        const urlEstado = 'index.php?controller=oficioVinculacion&action=estado';
        const urlGenerar = 'index.php?controller=oficioVinculacion&action=generarBorrador';

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const mostrarToast = function (mensaje, esError) {
            if (!window.bootstrap) {
                return;
            }

            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.setAttribute('data-bs-delay', esError ? '5000' : '3600');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                    '<span>' + escapar(mensaje) + '</span>' +
                '</div>';
            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
            bootstrap.Toast.getOrCreateInstance(toast).show();
        };

        const normalizarAccionesBandeja = function () {
            document
                .querySelectorAll('[data-linkage-follow-row][data-stage="DATOS_VERIFICADOS"]')
                .forEach(function (fila) {
                    const proxima = fila.querySelector('[data-row-next-action]');

                    if (proxima) {
                        proxima.textContent = 'Generar oficio';
                    }
                });
        };

        const crearBloqueOffcanvas = function () {
            if (!offcanvas || offcanvas.querySelector('[data-work-oficio-section]')) {
                return;
            }

            const referencia = offcanvas.querySelector('[data-work-next-section]');

            if (!referencia) {
                return;
            }

            const seccion = document.createElement('section');
            seccion.className = 'linkage-work-section d-none';
            seccion.setAttribute('data-work-oficio-section', '');
            seccion.innerHTML =
                '<div class="linkage-work-section-title">' +
                    '<h3>Oficio institucional</h3>' +
                '</div>' +
                '<div class="linkage-work-contact-grid">' +
                    '<div>' +
                        '<span>Folio</span>' +
                        '<strong data-work-oficio-folio>—</strong>' +
                    '</div>' +
                    '<div>' +
                        '<span>Estado</span>' +
                        '<strong data-work-oficio-status>Listo para generar</strong>' +
                    '</div>' +
                '</div>' +
                '<div class="linkage-work-verify-row">' +
                    '<button type="button" class="btn btn-system-save linkage-work-small-button" data-work-generate-oficio>' +
                        '<i class="bi bi-file-earmark-text"></i>' +
                        ' Generar oficio' +
                    '</button>' +
                '</div>';

            referencia.insertAdjacentElement('afterend', seccion);
        };

        const obtenerBloqueDetalle = function () {
            if (!esDetalle || seguimientoDetalleId <= 0) {
                return null;
            }

            let bloque = document.querySelector('[data-detail-oficio-action]');

            if (bloque) {
                return bloque;
            }

            const titulos = Array.from(document.querySelectorAll('.panel-title'));
            const tituloOficios = titulos.find(function (titulo) {
                return titulo.textContent.trim().toLowerCase() === 'oficios';
            });

            if (!tituloOficios) {
                return null;
            }

            bloque = document.createElement('div');
            bloque.className = 'linkage-work-verify-row mb-3 d-none';
            bloque.setAttribute('data-detail-oficio-action', '');
            bloque.innerHTML =
                '<div>' +
                    '<span class="d-block text-muted small">Folio</span>' +
                    '<strong data-detail-oficio-folio>—</strong>' +
                    '<span class="d-block text-muted small mt-1" data-detail-oficio-status></span>' +
                '</div>' +
                '<button type="button" class="btn btn-system-save" data-detail-generate-oficio>' +
                    '<i class="bi bi-file-earmark-text me-2"></i>' +
                    'Generar oficio' +
                '</button>';
            tituloOficios.insertAdjacentElement('afterend', bloque);

            return bloque;
        };

        const textoFaltantes = function (estado) {
            const faltantes = Array.isArray(estado?.faltantes) ? estado.faltantes : [];

            if (faltantes.length === 0) {
                return '';
            }

            return 'Completa ' + faltantes.join(', ') + ' antes de generar el oficio.';
        };

        const actualizarProximaAccion = function (estado) {
            const proximaAccion = offcanvas?.querySelector('[data-work-next-action]');

            if (!proximaAccion || !estado) {
                return;
            }

            if (
                estado.estado_seguimiento === 'DATOS_VERIFICADOS' &&
                !String(estado.folio || '').trim()
            ) {
                proximaAccion.textContent = 'Generar oficio';
                return;
            }

            if (estado.estado_seguimiento === 'OFICIO_PREPARADO') {
                proximaAccion.textContent = 'Enviar oficio/correo';
            }
        };

        const actualizarFila = function (estado) {
            if (!estado || !estado.id) {
                return;
            }

            const botonTrabajo = document.querySelector(
                '[data-work-follow-id="' + Number(estado.id) + '"]'
            );
            const fila = botonTrabajo?.closest('[data-linkage-follow-row]');

            if (!fila) {
                return;
            }

            const tieneFolio = String(estado.folio || '').trim() !== '';
            const proxima = fila.querySelector('[data-row-next-action]');

            if (tieneFolio) {
                const folio = fila.querySelector('[data-row-folio]');
                if (folio) {
                    folio.textContent = estado.folio;
                }

                fila.dataset.search = (fila.dataset.search || '') + ' ' + estado.folio;
            }

            if (estado.estado_seguimiento === 'DATOS_VERIFICADOS' && !tieneFolio) {
                if (proxima) {
                    proxima.textContent = 'Generar oficio';
                }
                return;
            }

            if (estado.estado_seguimiento === 'OFICIO_PREPARADO') {
                fila.dataset.stage = 'OFICIO_PREPARADO';

                const etapa = fila.querySelector('[data-row-stage-label]');

                if (etapa) {
                    etapa.textContent = 'Oficio preparado';
                }

                if (proxima) {
                    proxima.textContent = 'Enviar oficio/correo';
                }

                const filtroEtapa = document.querySelector('[data-linkage-stage-filter]');
                filtroEtapa?.dispatchEvent(new Event('change'));
            }
        };

        const actualizarBloqueOffcanvas = function (estado) {
            crearBloqueOffcanvas();
            const seccion = offcanvas?.querySelector('[data-work-oficio-section]');

            if (!seccion) {
                return;
            }

            const datosVerificados = Number(estado?.datos_verificados || 0) === 1;
            const tieneFolio = String(estado?.folio || '').trim() !== '';
            const soloConsulta = Boolean(estado?.solo_consulta);
            const mostrar = datosVerificados || tieneFolio;
            seccion.classList.toggle('d-none', !mostrar);

            if (!mostrar) {
                return;
            }

            actualizarProximaAccion(estado);

            const folio = seccion.querySelector('[data-work-oficio-folio]');
            const status = seccion.querySelector('[data-work-oficio-status]');
            const boton = seccion.querySelector('[data-work-generate-oficio]');

            if (folio) {
                folio.textContent = tieneFolio ? estado.folio : 'Pendiente';
            }

            if (!boton || !status) {
                return;
            }

            if (tieneFolio) {
                status.textContent = 'Borrador preparado';
                boton.classList.add('d-none');
                return;
            }

            if (soloConsulta) {
                status.textContent = 'Pendiente de generación por el Analista responsable.';
                boton.classList.add('d-none');
                return;
            }

            const faltantes = textoFaltantes(estado);

            if (faltantes !== '') {
                status.textContent = faltantes;
                boton.disabled = true;
                boton.classList.remove('d-none');
                return;
            }

            if (estado.estado_seguimiento !== 'DATOS_VERIFICADOS') {
                status.textContent = 'El oficio no está disponible en esta etapa.';
                boton.disabled = true;
                boton.classList.remove('d-none');
                return;
            }

            status.textContent = 'Listo para generar';
            boton.disabled = !Boolean(estado.puede_generar);
            boton.classList.remove('d-none');
        };

        const actualizarBloqueDetalle = function (estado) {
            const bloque = obtenerBloqueDetalle();

            if (!bloque) {
                return;
            }

            const datosVerificados = Number(estado?.datos_verificados || 0) === 1;
            const tieneFolio = String(estado?.folio || '').trim() !== '';
            const soloConsulta = Boolean(estado?.solo_consulta);
            bloque.classList.toggle('d-none', !(datosVerificados || tieneFolio));

            if (!(datosVerificados || tieneFolio)) {
                return;
            }

            const folio = bloque.querySelector('[data-detail-oficio-folio]');
            const status = bloque.querySelector('[data-detail-oficio-status]');
            const boton = bloque.querySelector('[data-detail-generate-oficio]');

            if (folio) {
                folio.textContent = tieneFolio ? estado.folio : 'Pendiente';
            }

            if (!status || !boton) {
                return;
            }

            if (tieneFolio) {
                status.textContent = 'El oficio ya tiene folio asignado.';
                boton.classList.add('d-none');
                return;
            }

            if (soloConsulta) {
                status.textContent = 'Pendiente de generación por el Analista responsable.';
                boton.classList.add('d-none');
                return;
            }

            const faltantes = textoFaltantes(estado);
            status.textContent = faltantes || 'Listo para generar el oficio.';
            boton.disabled = !Boolean(estado.puede_generar);
            boton.classList.remove('d-none');
        };

        const consultarEstado = async function (seguimientoId) {
            if (!seguimientoId) {
                return null;
            }

            const respuesta = await fetch(
                urlEstado + '&seguimiento_id=' + encodeURIComponent(seguimientoId),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    cache: 'no-store'
                }
            );
            const datos = await respuesta.json();

            if (!datos.ok) {
                return null;
            }

            estadoActual = datos.estado || null;
            actualizarBloqueOffcanvas(estadoActual);
            actualizarBloqueDetalle(estadoActual);
            actualizarFila(estadoActual);

            return estadoActual;
        };

        const generarOficio = async function (seguimientoId, boton, desdeDetalle) {
            if (!seguimientoId || !boton || boton.disabled) {
                return;
            }

            const htmlOriginal = boton.innerHTML;
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Generando...';

            const formulario = new FormData();
            formulario.append('seguimiento_id', String(seguimientoId));

            try {
                const respuesta = await fetch(urlGenerar, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formulario
                });
                const datos = await respuesta.json();

                if (!datos.ok) {
                    throw new Error(datos.mensaje || 'No fue posible generar el oficio.');
                }

                estadoActual = datos.estado || estadoActual;
                actualizarBloqueOffcanvas(estadoActual);
                actualizarBloqueDetalle(estadoActual);
                actualizarFila(estadoActual);
                actualizarProximaAccion(estadoActual);

                const contadorVerificados = document.querySelector('[data-summary-count="datos_verificados"]');
                if (
                    contadorVerificados &&
                    estadoActual?.estado_seguimiento === 'OFICIO_PREPARADO'
                ) {
                    const actual = Number(contadorVerificados.textContent || 0);
                    contadorVerificados.textContent = String(Math.max(0, actual - 1));
                }

                mostrarToast(
                    (datos.existente ? 'Oficio existente: ' : 'Oficio preparado: ') +
                    String(datos.folio || ''),
                    false
                );

                if (desdeDetalle) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                }
            } catch (error) {
                console.error(error);
                mostrarToast(error.message || 'No fue posible generar el oficio.', true);
                boton.disabled = false;
                boton.innerHTML = htmlOriginal;
            }
        };

        normalizarAccionesBandeja();
        crearBloqueOffcanvas();

        document.addEventListener('click', function (event) {
            const botonTrabajo = event.target.closest('[data-work-follow]');

            if (botonTrabajo) {
                seguimientoActualId = Number(botonTrabajo.dataset.workFollowId || 0);
                window.setTimeout(function () {
                    consultarEstado(seguimientoActualId).catch(function (error) {
                        console.error(error);
                    });
                }, 80);
                return;
            }

            const botonGenerarTrabajo = event.target.closest('[data-work-generate-oficio]');

            if (botonGenerarTrabajo && seguimientoActualId > 0) {
                event.preventDefault();
                generarOficio(seguimientoActualId, botonGenerarTrabajo, false);
                return;
            }

            const botonGenerarDetalle = event.target.closest('[data-detail-generate-oficio]');

            if (botonGenerarDetalle && seguimientoDetalleId > 0) {
                event.preventDefault();
                generarOficio(seguimientoDetalleId, botonGenerarDetalle, true);
            }
        });

        const estadoVerificado = offcanvas?.querySelector('[data-work-verified-status]');

        if (estadoVerificado) {
            const observador = new MutationObserver(function () {
                if (seguimientoActualId > 0) {
                    window.setTimeout(function () {
                        consultarEstado(seguimientoActualId).catch(function (error) {
                            console.error(error);
                        });
                    }, 150);
                }
            });
            observador.observe(estadoVerificado, {
                childList: true,
                characterData: true,
                subtree: true
            });
        }

        offcanvas?.addEventListener('hidden.bs.offcanvas', function () {
            seguimientoActualId = 0;
            estadoActual = null;
            offcanvas.querySelector('[data-work-oficio-section]')?.classList.add('d-none');
        });

        if (esDetalle && seguimientoDetalleId > 0) {
            consultarEstado(seguimientoDetalleId).catch(function (error) {
                console.error(error);
            });
        }
    });
})();
