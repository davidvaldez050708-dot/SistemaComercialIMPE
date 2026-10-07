(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const modalElement = document.getElementById('modalCorreoMarketing');
        const botonRedactar = document.querySelector('[data-marketing-compose]');

        if (!modalElement || !botonRedactar || !window.bootstrap) {
            return;
        }

        const form = modalElement.querySelector('[data-marketing-mail-form]');
        const destinatario = modalElement.querySelector('[data-marketing-mail-to]');
        const destinatarioResumen = modalElement.querySelector('[data-marketing-mail-recipient]');
        const asunto = modalElement.querySelector('[data-marketing-mail-subject]');
        const cuerpo = modalElement.querySelector('[data-marketing-mail-body]');
        const archivos = modalElement.querySelector('[data-marketing-mail-files]');
        const listaArchivos = modalElement.querySelector('[data-marketing-mail-files-list]');
        const error = modalElement.querySelector('[data-marketing-mail-error]');
        const botonEnviar = modalElement.querySelector('[data-marketing-mail-send]');
        const botonBorrador = modalElement.querySelector('[data-marketing-mail-draft]');
        const tabsCorreo = Array.from(
            document.querySelectorAll('[data-correo-tab]')
        );
        const filasCorreo = Array.from(
            document.querySelectorAll('[data-correo-row]')
        );
        const filaFiltroVacio = document.querySelector(
            '[data-correo-filter-empty]'
        );
        const contadorResultados = document.querySelector(
            '[data-correo-results-count]'
        );
        const filtroBuscar = document.getElementById('correo_marketing_buscar');
        const filtroEstado = document.getElementById('correo_marketing_estado');
        const filtroTipo = document.getElementById('correo_marketing_tipo');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const modalDetalleElement =
            document.getElementById('modalCorreoMarketingDetalle');
        const modalDetalle = modalDetalleElement
            ? bootstrap.Modal.getOrCreateInstance(modalDetalleElement)
            : null;
        const urlEnviar = 'index.php?controller=correoMarketing&action=enviar';
        const urlGuardarBorrador =
            'index.php?controller=correoMarketing&action=guardarBorrador';
        const urlVer = 'index.php?controller=correoMarketing&action=ver';
        const urlRecuperarAdjunto =
            'index.php?controller=correoMarketing&action=recuperarAdjunto';
        let enviando = false;
        let correoDetalleActual = null;

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor == null ? '' : valor);
            return div.innerHTML;
        };

        const mostrarError = function (mensaje) {
            if (!error) {
                return;
            }

            error.textContent = String(
                mensaje || 'No fue posible enviar el correo.'
            );
            error.classList.remove('d-none');
        };

        const limpiarError = function () {
            if (!error) {
                return;
            }

            error.textContent = '';
            error.classList.add('d-none');
        };

        const mostrarToast = function (mensaje, esError) {
            let contenedor = document.querySelector('.toast-container');

            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                contenedor.style.zIndex = '1095';
                document.body.appendChild(contenedor);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' +
                (esError ? ' system-toast-error' : '');
            toast.setAttribute('role', esError ? 'alert' : 'status');
            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi ' +
                        (esError
                            ? 'bi-exclamation-circle'
                            : 'bi-check2-circle') +
                    '"></i>' +
                    '<span></span>' +
                '</div>';

            toast.querySelector('span').textContent = String(
                mensaje ||
                (esError
                    ? 'No fue posible enviar el correo.'
                    : 'Correo enviado correctamente.')
            );

            contenedor.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: esError ? 4500 : 3000
            }).show();
        };

        const renderizarArchivos = function () {
            if (!listaArchivos || !archivos) {
                return;
            }

            const seleccionados = Array.from(archivos.files || []);
            listaArchivos.classList.toggle(
                'd-none',
                seleccionados.length === 0
            );

            listaArchivos.innerHTML = seleccionados.length === 0
                ? ''
                : seleccionados.map(function (archivo) {
                    const mb = Number(archivo.size || 0) / (1024 * 1024);
                    const tamano = mb >= 1
                        ? mb.toFixed(1) + ' MB'
                        : Math.max(
                            1,
                            Math.round(Number(archivo.size || 0) / 1024)
                        ) + ' KB';

                    return (
                        '<span>' +
                            '<i class="bi bi-paperclip"></i>' +
                            '<strong>' + escapar(archivo.name) + '</strong>' +
                            '<small>' + escapar(tamano) + '</small>' +
                        '</span>'
                    );
                }).join('');
        };

        const actualizarDestinatario = function () {
            if (!destinatarioResumen || !destinatario) {
                return;
            }

            const valor = String(destinatario.value || '').trim();
            destinatarioResumen.textContent =
                valor || 'Escribe un correo electrónico';
        };

        const limpiarFormulario = function () {
            form?.reset();
            limpiarError();
            actualizarDestinatario();
            renderizarArchivos();

            if (botonEnviar) {
                botonEnviar.disabled = false;
                botonEnviar.innerHTML =
                    '<i class="bi bi-send me-2"></i>Enviar correo';
            }

            if (botonBorrador) {
                botonBorrador.disabled = false;
                botonBorrador.innerHTML =
                    '<i class="bi bi-file-earmark-arrow-down me-2"></i>' +
                    'Guardar borrador';
            }
        };

        const validarArchivos = function () {
            const seleccionados = Array.from(archivos?.files || []);

            if (seleccionados.length > 8) {
                return 'Puedes adjuntar como máximo 8 archivos.';
            }

            if (seleccionados.some(function (archivo) {
                return Number(archivo.size || 0) > 12 * 1024 * 1024;
            })) {
                return 'Cada archivo debe pesar como máximo 12 MB.';
            }

            const total = seleccionados.reduce(function (acumulado, archivo) {
                return acumulado + Number(archivo.size || 0);
            }, 0);

            if (total > 20 * 1024 * 1024) {
                return 'Los archivos adjuntos no pueden superar 20 MB en total.';
            }

            return '';
        };

        const normalizarTexto = function (valor) {
            return String(valor == null ? '' : valor)
                .toLocaleLowerCase('es-MX')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim();
        };

        const aplicarFiltros = function () {
            const buscar = normalizarTexto(filtroBuscar?.value || '');
            const estado = String(filtroEstado?.value || '').toLowerCase();
            const tipo = String(filtroTipo?.value || '').toLowerCase();
            let visibles = 0;

            filasCorreo.forEach(function (fila) {
                const estadoFila = String(
                    fila.getAttribute('data-correo-estado') || ''
                ).toLowerCase();
                const tipoFila = String(
                    fila.getAttribute('data-correo-tipo') || ''
                ).toLowerCase();
                const busquedaFila = normalizarTexto(
                    fila.getAttribute('data-correo-busqueda') || ''
                );

                const coincideEstado =
                    estado === '' || estadoFila === estado;
                const coincideTipo =
                    tipo === '' || tipoFila === tipo;
                const coincideBusqueda =
                    buscar === '' || busquedaFila.indexOf(buscar) !== -1;

                const visible =
                    coincideEstado &&
                    coincideTipo &&
                    coincideBusqueda;

                fila.classList.toggle('d-none', !visible);

                if (visible) {
                    visibles++;
                }
            });

            filaFiltroVacio?.classList.toggle('d-none', visibles !== 0);

            if (contadorResultados) {
                contadorResultados.textContent =
                    visibles + (visibles === 1 ? ' resultado' : ' resultados');
            }
        };

        const activarTab = function (tabCodigo) {
            const mapaEstados = {
                todos: '',
                enviados: 'enviado',
                borradores: 'borrador'
            };

            const codigo = Object.prototype.hasOwnProperty.call(
                mapaEstados,
                tabCodigo
            ) ? tabCodigo : 'todos';

            tabsCorreo.forEach(function (tab) {
                const activo =
                    tab.getAttribute('data-correo-tab') === codigo;
                tab.classList.toggle('is-active', activo);
                tab.setAttribute(
                    'aria-selected',
                    activo ? 'true' : 'false'
                );
            });

            if (filtroEstado) {
                filtroEstado.value = mapaEstados[codigo];
            }

            sessionStorage.setItem('correoMarketingTab', codigo);
            aplicarFiltros();
        };

        tabsCorreo.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activarTab(
                    tab.getAttribute('data-correo-tab') || 'todos'
                );
            });
        });

        filtroBuscar?.addEventListener('input', aplicarFiltros);
        filtroTipo?.addEventListener('change', aplicarFiltros);
        filtroEstado?.addEventListener('change', function () {
            const mapaTabs = {
                '': 'todos',
                enviado: 'enviados',
                borrador: 'borradores'
            };

            activarTab(
                mapaTabs[String(filtroEstado.value || '').toLowerCase()] ||
                'todos'
            );
        });

        const tabInicial = sessionStorage.getItem('correoMarketingTab');
        activarTab(
            ['todos', 'enviados', 'borradores'].includes(tabInicial)
                ? tabInicial
                : 'todos'
        );

        const limpiarDetalle = function () {
            correoDetalleActual = null;

            if (!modalDetalleElement) {
                return;
            }

            const errorDetalle = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-error]'
            );
            const cargando = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-loading]'
            );
            const contenido = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-content]'
            );
            const adjuntosSeccion = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments-section]'
            );
            const adjuntosLista = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments]'
            );

            errorDetalle?.classList.add('d-none');
            if (errorDetalle) {
                errorDetalle.textContent = '';
            }

            cargando?.classList.remove('d-none');
            contenido?.classList.add('d-none');
            adjuntosSeccion?.classList.add('d-none');

            if (adjuntosLista) {
                adjuntosLista.innerHTML = '';
            }
        };

        const renderizarDetalle = function (correo) {
            if (!modalDetalleElement) {
                return;
            }

            correoDetalleActual = correo;

            const estadoCodigo = String(
                correo.estado_codigo || ''
            ).toLowerCase();
            const esBorrador = estadoCodigo === 'borrador';

            const tituloDetalle = modalDetalleElement.querySelector(
                '#modalCorreoMarketingDetalleTitulo'
            );
            const subtituloDetalle = tituloDetalle
                ?.closest('.system-form-modal-header')
                ?.querySelector('.system-form-modal-subtitle');
            const etiquetaFecha = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-date-label]'
            );

            if (tituloDetalle) {
                tituloDetalle.textContent = esBorrador
                    ? 'Borrador de correo'
                    : (
                        estadoCodigo === 'enviado'
                            ? 'Correo enviado'
                            : 'Correo pendiente'
                    );
            }

            if (subtituloDetalle) {
                subtituloDetalle.textContent = esBorrador
                    ? 'Consulta el contenido guardado en este borrador.'
                    : 'Consulta el contenido del correo registrado.';
            }

            if (etiquetaFecha) {
                etiquetaFecha.textContent = esBorrador
                    ? 'FECHA DE GUARDADO'
                    : (
                        estadoCodigo === 'enviado'
                            ? 'FECHA DE ENVÍO'
                            : 'FECHA DE REGISTRO'
                    );
            }

            const asignarTexto = function (selector, valor) {
                const nodo = modalDetalleElement.querySelector(selector);
                if (nodo) {
                    nodo.textContent = String(
                        valor == null || valor === '' ? '—' : valor
                    );
                }
            };

            asignarTexto(
                '[data-marketing-mail-detail-to]',
                correo.destinatario || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-name]',
                correo.destinatario_nombre || ''
            );
            asignarTexto(
                '[data-marketing-mail-detail-date]',
                correo.fecha_envio || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-status]',
                correo.estado || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-subject]',
                correo.asunto || '—'
            );
            asignarTexto(
                '[data-marketing-mail-detail-body]',
                correo.cuerpo || '—'
            );

            const proveedor = String(correo.proveedor || '').trim();
            asignarTexto(
                '[data-marketing-mail-detail-provider]',
                esBorrador
                    ? 'Borrador guardado en el sistema'
                    : (
                        proveedor !== ''
                            ? 'Enviado mediante ' + proveedor
                            : 'Correo registrado en el sistema'
                    )
            );

            const firma = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-signature]'
            );
            firma?.classList.toggle(
                'd-none',
                !Boolean(correo.firma_incluida)
            );

            const adjuntos = Array.isArray(correo.adjuntos)
                ? correo.adjuntos
                : [];
            const adjuntosSeccion = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments-section]'
            );
            const adjuntosLista = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-attachments]'
            );

            if (adjuntosLista) {
                adjuntosLista.innerHTML = '';

                const formatearTamano = function (bytes) {
                    const valor = Number(bytes || 0);

                    if (valor <= 0) {
                        return '';
                    }

                    if (valor >= 1024 * 1024) {
                        return (valor / (1024 * 1024)).toFixed(1) + ' MB';
                    }

                    return Math.max(1, Math.round(valor / 1024)) + ' KB';
                };

                const iconoArchivo = function (adjunto) {
                    const nombre = String(adjunto.nombre || '').toLowerCase();
                    const mime = String(adjunto.mime || '').toLowerCase();

                    if (adjunto.es_imagen || mime.indexOf('image/') === 0) {
                        return 'bi-file-earmark-image';
                    }

                    if (adjunto.es_pdf || mime === 'application/pdf') {
                        return 'bi-file-earmark-pdf';
                    }

                    if (/\.(doc|docx)$/.test(nombre)) {
                        return 'bi-file-earmark-word';
                    }

                    if (/\.(xls|xlsx|csv)$/.test(nombre)) {
                        return 'bi-file-earmark-excel';
                    }

                    if (/\.(ppt|pptx)$/.test(nombre)) {
                        return 'bi-file-earmark-slides';
                    }

                    return 'bi-file-earmark';
                };

                adjuntos.forEach(function (adjuntoOriginal) {
                    const adjunto = typeof adjuntoOriginal === 'string'
                        ? {
                            nombre: adjuntoOriginal,
                            disponible: false,
                            es_imagen: false,
                            es_pdf: /\.pdf$/i.test(adjuntoOriginal)
                        }
                        : (adjuntoOriginal || {});

                    const item = document.createElement('article');
                    item.className = 'correo-marketing-attachment-card';

                    if (
                        adjunto.es_imagen &&
                        adjunto.disponible &&
                        adjunto.url_inline
                    ) {
                        const enlaceImagen = document.createElement('a');
                        enlaceImagen.className =
                            'correo-marketing-attachment-preview';
                        enlaceImagen.href = String(adjunto.url_inline);
                        enlaceImagen.target = '_blank';
                        enlaceImagen.rel = 'noopener';

                        const imagen = document.createElement('img');
                        imagen.src = String(adjunto.url_inline);
                        imagen.alt = String(
                            adjunto.nombre || 'Imagen adjunta'
                        );
                        imagen.loading = 'lazy';

                        enlaceImagen.appendChild(imagen);
                        item.appendChild(enlaceImagen);
                    } else {
                        const visual = document.createElement('div');
                        visual.className =
                            'correo-marketing-attachment-file-icon';

                        const icono = document.createElement('i');
                        icono.className =
                            'bi ' + iconoArchivo(adjunto);

                        visual.appendChild(icono);
                        item.appendChild(visual);
                    }

                    const informacion = document.createElement('div');
                    informacion.className =
                        'correo-marketing-attachment-info';

                    const nombre = document.createElement('strong');
                    nombre.textContent = String(
                        adjunto.nombre || 'Archivo adjunto'
                    );

                    const detalle = document.createElement('small');
                    const partesDetalle = [];
                    const tamano = formatearTamano(adjunto.tamano);

                    if (String(adjunto.mime || '').trim() !== '') {
                        partesDetalle.push(String(adjunto.mime));
                    }

                    if (tamano !== '') {
                        partesDetalle.push(tamano);
                    }

                    detalle.textContent = partesDetalle.length > 0
                        ? partesDetalle.join(' · ')
                        : (
                            adjunto.disponible
                                ? 'Archivo enviado'
                                : 'Archivo de un envío anterior'
                        );

                    informacion.appendChild(nombre);
                    informacion.appendChild(detalle);

                    const acciones = document.createElement('div');
                    acciones.className =
                        'correo-marketing-attachment-actions';

                    if (adjunto.disponible) {
                        if (
                            (adjunto.es_imagen || adjunto.es_pdf) &&
                            adjunto.url_inline
                        ) {
                            const ver = document.createElement('a');
                            ver.className =
                                'btn btn-system-light btn-sm';
                            ver.href = String(adjunto.url_inline);
                            ver.target = '_blank';
                            ver.rel = 'noopener';
                            ver.innerHTML =
                                '<i class="bi bi-eye"></i>' +
                                '<span>Ver</span>';
                            acciones.appendChild(ver);
                        }

                        if (adjunto.url_descarga) {
                            const descargar = document.createElement('a');
                            descargar.className =
                                'btn btn-system-light btn-sm';
                            descargar.href = String(adjunto.url_descarga);
                            descargar.innerHTML =
                                '<i class="bi bi-download"></i>' +
                                '<span>Descargar</span>';
                            acciones.appendChild(descargar);
                        }
                    } else {
                        const legacy = document.createElement('span');
                        legacy.className =
                            'correo-marketing-attachment-legacy';
                        legacy.textContent =
                            'Archivo histórico sin copia local';
                        acciones.appendChild(legacy);

                        if (
                            correoDetalleActual &&
                            Number(correoDetalleActual.id || 0) > 0
                        ) {
                            const recuperar = document.createElement('button');
                            recuperar.type = 'button';
                            recuperar.className =
                                'btn btn-system-light btn-sm';
                            recuperar.innerHTML =
                                '<i class="bi bi-arrow-clockwise"></i>' +
                                '<span>Recuperar archivo</span>';

                            recuperar.addEventListener(
                                'click',
                                function () {
                                    const input = document.createElement(
                                        'input'
                                    );
                                    input.type = 'file';
                                    input.className = 'd-none';

                                    const extension = String(
                                        adjunto.nombre || ''
                                    ).split('.').pop().toLowerCase();

                                    const acceptMap = {
                                        pdf: '.pdf',
                                        doc: '.doc',
                                        docx: '.docx',
                                        xls: '.xls',
                                        xlsx: '.xlsx',
                                        ppt: '.ppt',
                                        pptx: '.pptx',
                                        txt: '.txt',
                                        csv: '.csv',
                                        png: '.png',
                                        jpg: '.jpg',
                                        jpeg: '.jpeg'
                                    };

                                    if (acceptMap[extension]) {
                                        input.accept =
                                            acceptMap[extension];
                                    }

                                    document.body.appendChild(input);

                                    input.addEventListener(
                                        'change',
                                        async function () {
                                            const archivo =
                                                input.files &&
                                                input.files[0]
                                                    ? input.files[0]
                                                    : null;

                                            if (!archivo) {
                                                input.remove();
                                                return;
                                            }

                                            recuperar.disabled = true;
                                            const original =
                                                recuperar.innerHTML;
                                            recuperar.innerHTML =
                                                '<span class="spinner-border ' +
                                                'spinner-border-sm" ' +
                                                'aria-hidden="true"></span>' +
                                                '<span>Recuperando...</span>';

                                            try {
                                                const datos =
                                                    new FormData();
                                                datos.append(
                                                    'correo_id',
                                                    String(
                                                        correoDetalleActual.id
                                                    )
                                                );
                                                datos.append(
                                                    'nombre_esperado',
                                                    String(
                                                        adjunto.nombre || ''
                                                    )
                                                );
                                                datos.append(
                                                    'archivo',
                                                    archivo
                                                );

                                                const respuesta =
                                                    await fetch(
                                                        urlRecuperarAdjunto,
                                                        {
                                                            method: 'POST',
                                                            body: datos,
                                                            headers: {
                                                                'X-Requested-With':
                                                                    'fetch'
                                                            }
                                                        }
                                                    );

                                                const json =
                                                    await respuesta.json();

                                                if (
                                                    !respuesta.ok ||
                                                    !json.ok
                                                ) {
                                                    throw new Error(
                                                        json.mensaje ||
                                                        'No fue posible recuperar el archivo.'
                                                    );
                                                }

                                                mostrarToast(
                                                    json.mensaje ||
                                                    'Archivo recuperado correctamente.',
                                                    false
                                                );

                                                await abrirDetalle(
                                                    Number(
                                                        correoDetalleActual.id
                                                    )
                                                );
                                            } catch (errorRecuperar) {
                                                console.error(
                                                    errorRecuperar
                                                );
                                                mostrarToast(
                                                    errorRecuperar.message ||
                                                    'No fue posible recuperar el archivo.',
                                                    true
                                                );
                                            } finally {
                                                recuperar.disabled = false;
                                                recuperar.innerHTML =
                                                    original;
                                                input.remove();
                                            }
                                        },
                                        { once: true }
                                    );

                                    input.click();
                                }
                            );

                            acciones.appendChild(recuperar);
                        }
                    }

                    informacion.appendChild(acciones);
                    item.appendChild(informacion);
                    adjuntosLista.appendChild(item);
                });
            }

            adjuntosSeccion?.classList.toggle(
                'd-none',
                adjuntos.length === 0
            );

            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-loading]')
                ?.classList.add('d-none');
            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-content]')
                ?.classList.remove('d-none');
        };

        const mostrarErrorDetalle = function (mensaje) {
            if (!modalDetalleElement) {
                return;
            }

            const errorDetalle = modalDetalleElement.querySelector(
                '[data-marketing-mail-detail-error]'
            );

            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-loading]')
                ?.classList.add('d-none');
            modalDetalleElement
                .querySelector('[data-marketing-mail-detail-content]')
                ?.classList.add('d-none');

            if (errorDetalle) {
                errorDetalle.textContent = String(
                    mensaje || 'No fue posible cargar el correo.'
                );
                errorDetalle.classList.remove('d-none');
            }
        };

        const abrirDetalle = async function (correoId) {
            if (!modalDetalleElement || !modalDetalle || correoId <= 0) {
                return;
            }

            limpiarDetalle();
            modalDetalle.show();

            try {
                const respuesta = await fetch(
                    urlVer + '&id=' + encodeURIComponent(correoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );

                const json = await respuesta.json();

                if (!respuesta.ok || !json.ok || !json.correo) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible cargar el correo.'
                    );
                }

                renderizarDetalle(json.correo);
            } catch (errorDetalle) {
                console.error(errorDetalle);
                mostrarErrorDetalle(
                    errorDetalle.message ||
                    'No fue posible comunicarse con el sistema.'
                );
            }
        };

        document.addEventListener('click', function (event) {
            const botonVer = event.target.closest(
                '[data-marketing-mail-view]'
            );

            if (!botonVer) {
                return;
            }

            event.preventDefault();

            abrirDetalle(
                Number(botonVer.getAttribute('data-mail-id') || 0)
            );
        });

        modalDetalleElement
            ?.querySelector('[data-marketing-mail-detail-copy]')
            ?.addEventListener('click', async function () {
                if (!correoDetalleActual) {
                    return;
                }

                const textoCorreo = [
                    'Para: ' + String(
                        correoDetalleActual.destinatario || ''
                    ),
                    'Asunto: ' + String(
                        correoDetalleActual.asunto || ''
                    ),
                    '',
                    String(correoDetalleActual.cuerpo || '')
                ].join('\n');

                try {
                    await navigator.clipboard.writeText(textoCorreo);
                    mostrarToast(
                        'Contenido del correo copiado.',
                        false
                    );
                } catch (errorCopiar) {
                    console.error(errorCopiar);
                    mostrarToast(
                        'No fue posible copiar el contenido.',
                        true
                    );
                }
            });

        botonRedactar.addEventListener('click', function () {
            limpiarFormulario();
            modal.show();

            modalElement.addEventListener(
                'shown.bs.modal',
                function enfocar() {
                    modalElement.removeEventListener(
                        'shown.bs.modal',
                        enfocar
                    );
                    destinatario?.focus();
                }
            );
        });

        destinatario?.addEventListener('input', actualizarDestinatario);
        archivos?.addEventListener('change', renderizarArchivos);

        botonBorrador?.addEventListener('click', async function () {
            if (enviando) {
                return;
            }

            limpiarError();

            const errorArchivos = validarArchivos();
            if (errorArchivos !== '') {
                mostrarError(errorArchivos);
                return;
            }

            const datos = new FormData(form);
            const htmlOriginal = botonBorrador.innerHTML;

            enviando = true;
            botonBorrador.disabled = true;

            if (botonEnviar) {
                botonEnviar.disabled = true;
            }

            botonBorrador.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" ' +
                'aria-hidden="true"></span>Guardando...';

            try {
                const respuesta = await fetch(urlGuardarBorrador, {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });

                let json = null;

                try {
                    json = await respuesta.json();
                } catch (errorJson) {
                    throw new Error(
                        'El servidor no devolvió una respuesta válida.'
                    );
                }

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible guardar el borrador.'
                    );
                }

                modal.hide();
                mostrarToast(
                    json.mensaje || 'Borrador guardado correctamente.',
                    false
                );
                sessionStorage.setItem(
                    'correoMarketingTab',
                    'borradores'
                );

                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
            } catch (errorBorrador) {
                console.error(errorBorrador);
                mostrarError(
                    errorBorrador.message ||
                    'No fue posible guardar el borrador.'
                );
            } finally {
                enviando = false;
                botonBorrador.disabled = false;
                botonBorrador.innerHTML = htmlOriginal;

                if (botonEnviar) {
                    botonEnviar.disabled = false;
                }
            }
        });

        form?.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (enviando) {
                return;
            }

            limpiarError();

            const correo = String(destinatario?.value || '').trim();
            const asuntoValor = String(asunto?.value || '').trim();
            const cuerpoValor = String(cuerpo?.value || '').trim();

            if (correo === '') {
                mostrarError('Ingresa el correo del destinatario.');
                destinatario?.focus();
                return;
            }

            if (asuntoValor === '') {
                mostrarError('Escribe el asunto del correo.');
                asunto?.focus();
                return;
            }

            if (cuerpoValor === '') {
                mostrarError('Escribe el mensaje antes de enviarlo.');
                cuerpo?.focus();
                return;
            }

            const errorArchivos = validarArchivos();
            if (errorArchivos !== '') {
                mostrarError(errorArchivos);
                return;
            }

            const datos = new FormData(form);
            const htmlOriginal = botonEnviar?.innerHTML || '';

            enviando = true;

            if (botonEnviar) {
                botonEnviar.disabled = true;
                botonEnviar.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" ' +
                    'aria-hidden="true"></span>Enviando...';
            }

            try {
                const respuesta = await fetch(urlEnviar, {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                });

                let json = null;

                try {
                    json = await respuesta.json();
                } catch (errorJson) {
                    throw new Error(
                        'El servidor no devolvió una respuesta válida.'
                    );
                }

                if (!respuesta.ok || !json.ok) {
                    throw new Error(
                        json.mensaje ||
                        'No fue posible enviar el correo.'
                    );
                }

                modal.hide();
                mostrarToast(
                    json.mensaje || 'Correo enviado correctamente.',
                    false
                );
                sessionStorage.setItem(
                    'correoMarketingTab',
                    'enviados'
                );

                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
            } catch (errorEnvio) {
                console.error(errorEnvio);
                mostrarError(
                    errorEnvio.message ||
                    'No fue posible comunicarse con el sistema.'
                );
            } finally {
                enviando = false;

                if (botonEnviar) {
                    botonEnviar.disabled = false;
                    botonEnviar.innerHTML = htmlOriginal;
                }
            }
        });
    });
})();
