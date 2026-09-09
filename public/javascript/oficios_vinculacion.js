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
        let contextoGeneracion = null;

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

        const actualizarAccionEliminarPlantilla = function (modal) {
            const selector = modal.querySelector('#oficioSavedTemplate');
            const boton = modal.querySelector('[data-oficio-delete-template]');

            if (!selector || !boton) {
                return;
            }

            const puedeEliminar = modal.dataset.puedeEliminarPlantillas === '1';
            boton.classList.toggle('d-none', !puedeEliminar || !selector.value);
        };

        const obtenerModalEliminarPlantilla = function () {
            let modal = document.getElementById('modalEliminarPlantillaOficio');

            if (modal) {
                return modal;
            }

            modal = document.createElement('div');
            modal.id = 'modalEliminarPlantillaOficio';
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML =
                '<div class="modal-dialog modal-dialog-centered system-confirm-dialog">' +
                    '<div class="modal-content system-form-modal">' +
                        '<div class="modal-header">' +
                            '<div><h2 class="modal-title fs-5">Eliminar plantilla</h2></div>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<p class="confirm-text mb-2">¿Eliminar esta plantilla?</p>' +
                            '<p class="text-muted mb-0">La plantilla dejará de estar disponible para generar nuevos oficios.</p>' +
                        '</div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>' +
                            '<button type="button" class="btn btn-danger" data-oficio-delete-confirm>Eliminar plantilla</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            return modal;
        };

        const confirmarEliminacionPlantilla = function (modalPrincipal) {
            return new Promise(function (resolve) {
                const modalConfirmacion = obtenerModalEliminarPlantilla();
                const instanciaPrincipal = bootstrap.Modal.getOrCreateInstance(modalPrincipal);
                const instanciaConfirmacion = bootstrap.Modal.getOrCreateInstance(modalConfirmacion);
                const botonConfirmar = modalConfirmacion.querySelector('[data-oficio-delete-confirm]');
                let confirmado = false;

                const confirmar = function () {
                    confirmado = true;
                    instanciaConfirmacion.hide();
                };

                botonConfirmar.addEventListener('click', confirmar);
                modalConfirmacion.addEventListener('hidden.bs.modal', function () {
                    botonConfirmar.removeEventListener('click', confirmar);
                    instanciaPrincipal.show();
                    resolve(confirmado);
                }, { once: true });
                modalPrincipal.addEventListener('hidden.bs.modal', function () {
                    instanciaConfirmacion.show();
                }, { once: true });

                instanciaPrincipal.hide();
            });
        };

        const obtenerModalPlantillas = function () {
            let elemento = document.getElementById('modalSeleccionarOficio');
            if (elemento) return elemento;

            elemento = document.createElement('div');
            elemento.id = 'modalSeleccionarOficio';
            elemento.className = 'modal fade';
            elemento.tabIndex = -1;
            elemento.setAttribute('aria-hidden', 'true');
            elemento.innerHTML =
                '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
                    '<div class="modal-header"><h2 class="modal-title fs-5">Seleccionar oficio</h2>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>' +
                    '<div class="modal-body"><p>Selecciona el formato de oficio que deseas utilizar.</p>' +
                    '<div class="list-group mb-3" data-oficio-template-list></div>' +
                    '<div class="d-none" data-oficio-custom-file>' +
                        '<label class="form-label" for="oficioSavedTemplate">Plantillas guardadas</label>' +
                        '<div class="d-flex gap-2 mb-2">' +
                            '<select class="form-select" id="oficioSavedTemplate"><option value="">Seleccionar una plantilla...</option></select>' +
                            '<button type="button" class="btn btn-outline-danger d-none flex-shrink-0" data-oficio-delete-template aria-label="Eliminar plantilla seleccionada" title="Eliminar plantilla"><i class="bi bi-trash"></i></button>' +
                        '</div>' +
                        '<div class="form-text mb-2" data-oficio-library-status role="status"></div>' +
                        '<button type="button" class="btn btn-secondary btn-sm mb-3" data-oficio-add>+ Agregar plantilla</button>' +
                        '<div class="border rounded p-3 mb-3 d-none" data-oficio-add-form>' +
                            '<label class="form-label" for="oficioLibraryName">Nombre de la plantilla</label>' +
                            '<input class="form-control mb-2" id="oficioLibraryName" maxlength="150">' +
                            '<label class="form-label" for="oficioLibraryFile">Archivo DOCX</label>' +
                            '<input class="form-control mb-2" id="oficioLibraryFile" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">' +
                            '<div class="form-text mb-2">Archivo DOCX de hasta 20 MB.</div>' +
                            '<div class="d-flex gap-2 justify-content-end"><button type="button" class="btn btn-secondary btn-sm" data-oficio-add-cancel>Cancelar</button>' +
                            '<button type="button" class="btn btn-system-save btn-sm" data-oficio-add-save>Guardar plantilla</button></div>' +
                        '</div>' +
                        '<div class="text-muted text-center mb-2">o seleccionar archivo desde mi equipo</div>' +
                        '<label class="form-label" for="oficioTemplateFile">Archivo de plantilla</label>' +
                        '<input class="form-control" id="oficioTemplateFile" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">' +
                        '<div class="form-text">Archivo DOCX de hasta 20 MB.</div>' +
                    '</div></div>' +
                    '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>' +
                    '<button type="button" class="btn btn-system-save" data-oficio-use-selected>Generar</button></div>' +
                '</div></div>';
            document.body.appendChild(elemento);

            const selector = elemento.querySelector('#oficioSavedTemplate');
            const manual = elemento.querySelector('#oficioTemplateFile');
            const bloqueAlta = elemento.querySelector('[data-oficio-add-form]');
            selector.addEventListener('change', function () {
                if (selector.value) manual.value = '';
                actualizarAccionEliminarPlantilla(elemento);
            });
            manual.addEventListener('change', function () {
                if (manual.files.length) selector.value = '';
                actualizarAccionEliminarPlantilla(elemento);
            });
            elemento.querySelector('[data-oficio-add]').addEventListener('click', function () {
                bloqueAlta.classList.remove('d-none');
                elemento.querySelector('#oficioLibraryName').focus();
            });
            elemento.querySelector('[data-oficio-add-cancel]').addEventListener('click', function () {
                bloqueAlta.classList.add('d-none');
            });
            elemento.querySelector('#oficioLibraryFile').addEventListener('change', function () {
                const nombre = elemento.querySelector('#oficioLibraryName');
                if (!nombre.value.trim() && this.files[0]) nombre.value = this.files[0].name.replace(/\.docx$/i, '').slice(0, 150);
            });
            elemento.querySelector('[data-oficio-add-save]').addEventListener('click', async function () {
                const archivo = elemento.querySelector('#oficioLibraryFile').files[0];
                const nombre = elemento.querySelector('#oficioLibraryName').value.trim();
                if (!archivo || !nombre) { mostrarToast('Indica el nombre y selecciona un DOCX.', true); return; }
                const formulario = new FormData();
                formulario.append('nombre', nombre);
                formulario.append('archivo', archivo, archivo.name);
                this.disabled = true;
                const generar = elemento.querySelector('[data-oficio-use-selected]');
                generar.disabled = true;
                try {
                    const respuesta = await fetch('index.php?controller=oficioVinculacion&action=subirPlantilla', {
                        method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: formulario
                    });
                    const datos = await respuesta.json();
                    if (!datos.ok) throw new Error(datos.mensaje || 'No fue posible guardar la plantilla.');
                    await cargarBiblioteca(elemento, datos.plantilla_id);
                    manual.value = '';
                    bloqueAlta.classList.add('d-none');
                    elemento.querySelector('#oficioLibraryName').value = '';
                    elemento.querySelector('#oficioLibraryFile').value = '';
                    mostrarToast('Plantilla guardada correctamente.', false);
                } catch (error) { mostrarToast(error.message, true); }
                finally { this.disabled = false; generar.disabled = !Boolean(estadoActual?.puede_generar); }
            });
            elemento.querySelector('[data-oficio-delete-template]').addEventListener('click', async function () {
                const plantillaId = Number(selector.value || 0);

                if (!plantillaId) {
                    return;
                }

                const confirmado = await confirmarEliminacionPlantilla(elemento);

                if (!confirmado) {
                    return;
                }

                const formulario = new FormData();
                formulario.append('plantilla_id', String(plantillaId));
                this.disabled = true;

                try {
                    const respuesta = await fetch('index.php?controller=oficioVinculacion&action=eliminarPlantilla', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'fetch' },
                        body: formulario
                    });
                    const datos = await respuesta.json();

                    if (!datos.ok) {
                        throw new Error(datos.mensaje || 'No fue posible eliminar la plantilla.');
                    }

                    await cargarBiblioteca(elemento);
                    mostrarToast('Plantilla eliminada correctamente.', false);
                } catch (error) {
                    mostrarToast(error.message, true);
                } finally {
                    this.disabled = false;
                    actualizarAccionEliminarPlantilla(elemento);
                }
            });

            elemento.addEventListener('change', function (event) {
                if (event.target.name !== 'oficio_plantilla') return;
                elemento.querySelector('[data-oficio-custom-file]').classList.toggle(
                    'd-none',
                    event.target.value !== 'personalizada'
                );
            });
            elemento.querySelector('[data-oficio-use-selected]').addEventListener('click', function () {
                const seleccion = elemento.querySelector('input[name="oficio_plantilla"]:checked');
                if (!seleccion || !contextoGeneracion) return;
                const archivo = elemento.querySelector('#oficioTemplateFile')?.files?.[0] || null;
                const plantillaId = Number(selector.value || 0);
                if (seleccion.value === 'personalizada' && !archivo && !plantillaId) {
                    mostrarToast('Selecciona una plantilla de oficio.', true);
                    return;
                }
                bootstrap.Modal.getOrCreateInstance(elemento).hide();
                generarOficio(
                    contextoGeneracion.seguimientoId,
                    contextoGeneracion.boton,
                    contextoGeneracion.desdeDetalle,
                    seleccion.value,
                    archivo,
                    plantillaId
                );
            });
            return elemento;
        };

        const cargarBiblioteca = async function (modal, seleccionId) {
            const estado = modal.querySelector('[data-oficio-library-status]');
            estado.textContent = 'Cargando plantillas...';
            const respuesta = await fetch('index.php?controller=oficioVinculacion&action=plantillas', {
                headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store'
            });
            const datos = await respuesta.json();
            if (!datos.ok) throw new Error(datos.mensaje || 'No fue posible consultar las plantillas.');
            const selector = modal.querySelector('#oficioSavedTemplate');
            selector.replaceChildren(new Option('Seleccionar una plantilla...', ''));
            datos.plantillas.forEach(function (item) { selector.add(new Option(item.nombre, String(item.id))); });
            selector.value = seleccionId ? String(seleccionId) : '';
            modal.dataset.puedeEliminarPlantillas = datos.puede_eliminar ? '1' : '0';
            estado.textContent = datos.plantillas.length ? '' : 'Aún no hay plantillas guardadas.';
            modal.querySelector('[data-oficio-add]').classList.toggle('d-none', !datos.puede_subir);
            actualizarAccionEliminarPlantilla(modal);
        };

        const prepararOpcionesPlantilla = function () {
            const modal = obtenerModalPlantillas();
            const lista = modal.querySelector('[data-oficio-template-list]');
            const opciones = [
                { id: 'predeterminada', nombre: 'Oficio predeterminado', descripcion: 'Formato actual del sistema' },
                { id: 'personalizada', nombre: 'Usar plantilla personalizada', descripcion: 'Seleccionar documento desde mi equipo' }
            ];
            lista.innerHTML = opciones.map(function (item, indice) {
                return '<label class="list-group-item d-flex gap-2 align-items-start">' +
                    '<input class="form-check-input mt-1" type="radio" name="oficio_plantilla" value="' + item.id + '" ' + (indice === 0 ? 'checked' : '') + '>' +
                    '<span><strong class="d-block">' + escapar(item.nombre) + '</strong>' +
                    '<small class="text-muted">' + escapar(item.descripcion) + '</small></span></label>';
            }).join('');
            modal.querySelector('[data-oficio-custom-file]').classList.add('d-none');
            modal.querySelector('#oficioTemplateFile').value = '';
            modal.querySelector('#oficioSavedTemplate').replaceChildren(new Option('Seleccionar una plantilla...', ''));
            modal.dataset.puedeEliminarPlantillas = '0';
            actualizarAccionEliminarPlantilla(modal);
            modal.querySelector('[data-oficio-add-form]').classList.add('d-none');
            modal.querySelector('[data-oficio-use-selected]').disabled = !Boolean(estadoActual?.puede_generar);
        };

        const abrirSeleccionPlantilla = async function (seguimientoId, boton, desdeDetalle) {
            contextoGeneracion = { seguimientoId: seguimientoId, boton: boton, desdeDetalle: desdeDetalle };
            prepararOpcionesPlantilla();
            bootstrap.Modal.getOrCreateInstance(obtenerModalPlantillas()).show();
            try { await cargarBiblioteca(obtenerModalPlantillas()); }
            catch (error) { obtenerModalPlantillas().querySelector('[data-oficio-library-status]').textContent = error.message; }
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
                boton.classList.toggle('d-none', !Boolean(estado?.puede_administrar_plantillas));
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
                boton.classList.toggle('d-none', !Boolean(estado?.puede_administrar_plantillas));
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

        const generarOficio = async function (seguimientoId, boton, desdeDetalle, tipoPlantilla, archivoPlantilla, plantillaId) {
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
            formulario.append('tipo_plantilla', tipoPlantilla === 'personalizada' ? 'personalizada' : 'predeterminada');
            if (tipoPlantilla === 'personalizada' && archivoPlantilla) {
                formulario.append('archivo_plantilla', archivoPlantilla, archivoPlantilla.name);
            } else if (tipoPlantilla === 'personalizada' && plantillaId) {
                formulario.append('plantilla_id', String(plantillaId));
            }

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
                abrirSeleccionPlantilla(seguimientoActualId, botonGenerarTrabajo, false);
                return;
            }

            const botonGenerarDetalle = event.target.closest('[data-detail-generate-oficio]');

            if (botonGenerarDetalle && seguimientoDetalleId > 0) {
                event.preventDefault();
                abrirSeleccionPlantilla(seguimientoDetalleId, botonGenerarDetalle, true);
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
