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
            '<small>Fuente: INEGI - Censo de Población y Vivienda (ITER 2020)</small>' +
            '<em>Consulta y actualiza automáticamente el perfil educativo oficial de todos los Estados.</em>' +
        '</span>';
    listaOpciones.appendChild(opcion);

    const panel = document.createElement('div');
    panel.className = 'data-power-import d-none';
    panel.setAttribute('data-profile-education-auto', '');
    panel.innerHTML =
        '<div class="data-power-import-heading">' +
            '<div>' +
                '<strong>Actualización automática del perfil educativo</strong>' +
                '<span>El sistema consultará directamente los datos abiertos de ITER 2020 publicados por INEGI para los 32 Estados.</span>' +
            '</div>' +
            '<i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>' +
        '</div>' +
        '<small class="data-power-import-help">' +
            'No necesitas descargar, filtrar ni subir archivos. Se consultan automáticamente las variables de escolaridad, asistencia y grupos de edad utilizadas por el bloque “Perfil educativo relacionado con la oferta académica”.' +
        '</small>' +
        '<div class="data-power-import-status d-none" data-profile-education-auto-status role="status"></div>';

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
    const estado = panel.querySelector('[data-profile-education-auto-status]');
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
        if (!estado) {
            return;
        }

        estado.textContent = '';
        estado.className = 'data-power-import-status d-none';
    };

    const hayOpcionNormal = function () {
        return opcionesExistentes.some(function (elemento) {
            return elemento.checked;
        });
    };

    const sincronizarBoton = function () {
        if (checkbox.checked) {
            botonPrincipal.disabled = ejecutando;
            return;
        }

        botonPrincipal.disabled = !hayOpcionNormal();
    };

    const activarModoAutomatico = function (activo) {
        panel.classList.toggle('d-none', !activo);

        opcionesExistentes.forEach(function (elemento) {
            if (activo && elemento.checked) {
                elemento.checked = false;
                elemento.dispatchEvent(new Event('change', { bubbles: true }));
            }

            elemento.disabled = activo || ejecutando;
        });

        if (!activo) {
            limpiarEstado();
        }

        sincronizarBoton();
    };

    checkbox.addEventListener('change', function () {
        activarModoAutomatico(checkbox.checked);
    });

    opcionesExistentes.forEach(function (elemento) {
        elemento.addEventListener('change', function () {
            if (!checkbox.checked) {
                sincronizarBoton();
            }
        });
    });

    botonPrincipal.addEventListener('click', async function (evento) {
        if (!checkbox.checked) {
            return;
        }

        evento.preventDefault();
        evento.stopImmediatePropagation();

        if (ejecutando) {
            return;
        }

        ejecutando = true;
        checkbox.disabled = true;
        opcionesExistentes.forEach(function (elemento) {
            elemento.disabled = true;
        });
        sincronizarBoton();

        if (etiquetaBoton) {
            etiquetaBoton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Consultando INEGI...';
        }

        mostrarEstado(
            'Consultando y validando el perfil educativo oficial de todos los Estados. Esto puede tardar unos minutos la primera vez.',
            'loading'
        );

        try {
            const respuesta = await fetch(
                window.location.pathname + '?controller=poblacionObjetivoEducativa&action=actualizarMasivo',
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                }
            );
            const resultado = await respuesta.json();

            if (!respuesta.ok || resultado.ok !== true) {
                throw new Error(
                    resultado.mensaje ||
                    'No fue posible actualizar automáticamente el perfil educativo.'
                );
            }

            const total = Number(resultado.datos?.total_estados || 0);
            mostrarEstado(
                'Perfil educativo actualizado automáticamente para ' + total + ' Estados desde INEGI.',
                'success'
            );
        } catch (error) {
            mostrarEstado(
                error.message || 'No fue posible actualizar automáticamente el perfil educativo.',
                'error'
            );
        } finally {
            ejecutando = false;
            checkbox.disabled = false;

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
        activarModoAutomatico(false);
    });
});
