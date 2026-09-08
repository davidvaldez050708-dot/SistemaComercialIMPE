document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalActualizarInformacionEstados');
    const listaOpciones = modal?.querySelector('[data-official-options="mass"]');
    const botonPrincipal = document.getElementById('botonActualizarInformacionEstados');

    if (!modal || !listaOpciones || !botonPrincipal) {
        return;
    }

    if (listaOpciones.querySelector('[data-profile-education-mass-option]')) {
        return;
    }

    const opcion = document.createElement('label');
    opcion.className = 'data-official-option-card';
    opcion.innerHTML =
        '<input class="form-check-input" type="checkbox" data-profile-education-mass-option>' +
        '<span>' +
            '<strong>Perfil educativo</strong>' +
            '<small>Fuente: INEGI - Censo de Población y Vivienda (ITER/SCITEL)</small>' +
            '<em>Actualiza la base oficial de secundaria como máxima escolaridad para todos los Estados.</em>' +
        '</span>';
    listaOpciones.appendChild(opcion);

    const panel = document.createElement('div');
    panel.className = 'data-power-import d-none';
    panel.setAttribute('data-profile-education-mass-import', '');
    panel.innerHTML =
        '<div class="data-power-import-heading">' +
            '<div>' +
                '<strong>Archivo general del perfil educativo</strong>' +
                '<span>Carga la información oficial de ITER/SCITEL para actualizar todos los Estados en una sola operación.</span>' +
            '</div>' +
            '<i class="bi bi-mortarboard" aria-hidden="true"></i>' +
        '</div>' +
        '<label class="data-power-import-file" for="archivoPerfilEducativoMasivo">' +
            '<span>Seleccionar XLSX o ZIP</span>' +
            '<input type="file" id="archivoPerfilEducativoMasivo" accept=".xlsx,.zip,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip" data-profile-education-mass-file>' +
        '</label>' +
        '<small class="data-power-import-help">' +
            'Puedes cargar un XLSX que contenga las 32 entidades o un ZIP con los XLSX estatales de ITER/SCITEL. ' +
            'Antes de guardar, el sistema verificará que estén cubiertos todos los Estados activos. ' +
            '<a href="https://www.inegi.org.mx/app/scitel/Default?ev=6" target="_blank" rel="noopener noreferrer">Abrir SCITEL en INEGI</a>' +
        '</small>' +
        '<div class="data-power-import-status d-none" data-profile-education-mass-status role="status"></div>' +
        '<div class="data-power-import-preview d-none" data-profile-education-mass-preview>' +
            '<div><span>Archivo</span><strong data-profile-education-mass-preview-file>—</strong></div>' +
            '<div><span>Cobertura requerida</span><strong>Todos los Estados activos</strong></div>' +
            '<div><span>Indicador base</span><strong>P15SEC_CO / P_15YMAS</strong></div>' +
            '<div><span>Actualización</span><strong>General</strong></div>' +
            '<p>El archivo se validará por completo antes de comenzar a guardar información.</p>' +
        '</div>';

    const cuadrillaAlcance = modal.querySelector('.data-official-scope-grid');

    if (cuadrillaAlcance) {
        cuadrillaAlcance.insertAdjacentElement('beforebegin', panel);
        const valores = cuadrillaAlcance.querySelectorAll('strong');

        if (valores.length >= 2) {
            const actuales = parseInt(valores[1].textContent || '0', 10);
            valores[1].textContent = String(Number.isFinite(actuales) ? actuales + 1 : 6);
        }
    } else {
        listaOpciones.insertAdjacentElement('afterend', panel);
    }

    const checkbox = opcion.querySelector('[data-profile-education-mass-option]');
    const archivo = panel.querySelector('[data-profile-education-mass-file]');
    const estado = panel.querySelector('[data-profile-education-mass-status]');
    const preview = panel.querySelector('[data-profile-education-mass-preview]');
    const previewArchivo = panel.querySelector('[data-profile-education-mass-preview-file]');
    const opcionesExistentes = Array.from(
        listaOpciones.querySelectorAll('[data-official-option]')
    );
    const etiquetaBoton = botonPrincipal.querySelector('[data-mass-update-start-label]');
    const htmlBotonOriginal = etiquetaBoton?.innerHTML || '';
    let ejecutando = false;

    const mostrarEstado = function (mensaje, tipo) {
        if (!estado) {
            return;
        }

        estado.textContent = mensaje;
        estado.className = 'data-power-import-status';

        if (tipo) {
            estado.classList.add('is-' + tipo);
        }
    };

    const limpiarEstado = function () {
        if (estado) {
            estado.textContent = '';
            estado.className = 'data-power-import-status d-none';
        }

        preview?.classList.add('d-none');
    };

    const archivoValido = function () {
        const seleccionado = archivo?.files?.[0] || null;

        if (!seleccionado) {
            return false;
        }

        const nombre = seleccionado.name.toLowerCase();
        return nombre.endsWith('.xlsx') || nombre.endsWith('.zip');
    };

    const sincronizarBoton = function () {
        if (!checkbox.checked) {
            botonPrincipal.disabled = !opcionesExistentes.some(function (elemento) {
                return elemento.checked;
            });
            return;
        }

        botonPrincipal.disabled = ejecutando || !archivoValido();
    };

    const activarModoPerfil = function (activo) {
        panel.classList.toggle('d-none', !activo);

        opcionesExistentes.forEach(function (elemento) {
            if (activo && elemento.checked) {
                elemento.checked = false;
                elemento.dispatchEvent(new Event('change', { bubbles: true }));
            }

            elemento.disabled = activo || ejecutando;
        });

        if (!activo) {
            if (archivo) {
                archivo.value = '';
            }
            limpiarEstado();
        }

        sincronizarBoton();
    };

    checkbox.addEventListener('change', function () {
        activarModoPerfil(checkbox.checked);
    });

    archivo?.addEventListener('change', function () {
        limpiarEstado();
        const seleccionado = archivo.files?.[0] || null;

        if (!seleccionado) {
            sincronizarBoton();
            return;
        }

        if (!archivoValido()) {
            mostrarEstado('El archivo debe estar en formato XLSX o ZIP.', 'error');
            sincronizarBoton();
            return;
        }

        if (previewArchivo) {
            previewArchivo.textContent = seleccionado.name;
        }
        preview?.classList.remove('d-none');
        sincronizarBoton();
    });

    botonPrincipal.addEventListener('click', async function (evento) {
        if (!checkbox.checked) {
            return;
        }

        evento.preventDefault();
        evento.stopImmediatePropagation();

        const seleccionado = archivo?.files?.[0] || null;

        if (!seleccionado || !archivoValido() || ejecutando) {
            mostrarEstado('Selecciona un archivo XLSX o ZIP válido.', 'error');
            sincronizarBoton();
            return;
        }

        ejecutando = true;
        checkbox.disabled = true;
        archivo.disabled = true;
        opcionesExistentes.forEach(function (elemento) {
            elemento.disabled = true;
        });
        sincronizarBoton();

        if (etiquetaBoton) {
            etiquetaBoton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Validando y actualizando...';
        }

        mostrarEstado(
            'Validando cobertura de Estados y variables P15SEC_CO / P_15YMAS antes de guardar...',
            'loading'
        );

        const datos = new FormData();
        datos.append('archivo_perfil_educativo', seleccionado);

        try {
            const respuesta = await fetch(
                window.location.pathname + '?controller=poblacionObjetivoEducativa&action=actualizarMasivo',
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: datos
                }
            );
            const resultado = await respuesta.json();

            if (!respuesta.ok || resultado.ok !== true) {
                throw new Error(
                    resultado.mensaje ||
                    'No fue posible actualizar el perfil educativo.'
                );
            }

            const total = Number(resultado.datos?.total_estados || 0);
            mostrarEstado(
                resultado.mensaje ||
                    ('Perfil educativo actualizado para ' + total + ' Estados.'),
                'success'
            );

            if (previewArchivo) {
                previewArchivo.textContent = seleccionado.name + ' · ' + total + ' Estados actualizados';
            }
        } catch (error) {
            mostrarEstado(
                error.message || 'No fue posible actualizar el perfil educativo.',
                'error'
            );
        } finally {
            ejecutando = false;
            checkbox.disabled = false;
            archivo.disabled = false;

            if (etiquetaBoton) {
                etiquetaBoton.innerHTML = htmlBotonOriginal;
            }

            opcionesExistentes.forEach(function (elemento) {
                elemento.disabled = checkbox.checked;
            });
            sincronizarBoton();
        }
    }, true);

    modal.addEventListener('hidden.bs.modal', function () {
        if (ejecutando) {
            return;
        }

        checkbox.checked = false;
        checkbox.disabled = false;
        opcionesExistentes.forEach(function (elemento) {
            elemento.disabled = false;
        });
        activarModoPerfil(false);
    });
});
