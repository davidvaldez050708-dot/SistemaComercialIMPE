(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const offcanvas = document.getElementById('offcanvasSeguimientoTrabajo');

        if (!offcanvas) {
            return;
        }

        const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);
        const esAdministrador = rolId === 1;
        let seguimientoActualId = 0;

        const pasos = [
            [1, 'Seguimiento iniciado'],
            [2, 'Investigación de datos'],
            [3, 'Contacto y validación'],
            [4, 'Datos verificados'],
            [5, 'Oficio preparado'],
            [6, 'PDF generado'],
            [7, 'Oficio / correo enviado'],
            [8, 'Esperando respuesta'],
            [9, 'Respuesta recibida'],
            [10, 'Seguimiento por correo'],
            [11, 'Reunión agendada'],
            [12, 'Reunión y acuerdos'],
            [13, 'Convenio']
        ];

        const obtenerEtiquetaPaso = function (numero) {
            const paso = pasos.find(function (item) {
                return Number(item[0]) === Number(numero);
            });

            return paso ? paso[1] : 'En seguimiento';
        };

        const asegurarTarjetaRuta = function () {
            let tarjeta = offcanvas.querySelector('[data-work-route-card]');

            if (tarjeta) {
                return tarjeta;
            }

            const siguiente = offcanvas.querySelector('[data-work-next-section]');
            tarjeta = document.createElement('section');
            tarjeta.className = 'linkage-work-section linkage-work-route-card';
            tarjeta.setAttribute('data-work-route-card', '');
            tarjeta.innerHTML =
                '<div class="linkage-work-route-head">' +
                    '<span>ETAPA DE RUTA</span>' +
                    '<strong class="linkage-work-route-step" data-work-route-step>Paso — de 13</strong>' +
                '</div>' +
                '<div class="linkage-work-route-title" data-work-route-title>Consultando ruta...</div>' +
                '<div class="linkage-work-route-progress" aria-hidden="true">' +
                    '<span data-work-route-progress></span>' +
                '</div>';

            if (siguiente && siguiente.parentNode) {
                siguiente.parentNode.insertBefore(tarjeta, siguiente);
            } else {
                offcanvas.querySelector('.offcanvas-body')?.prepend(tarjeta);
            }

            return tarjeta;
        };

        const renderizarRuta = function (pasoActual, titulo) {
            asegurarTarjetaRuta();

            const paso = Math.max(0, Math.min(13, Number(pasoActual) || 0));
            const total = 13;
            const etiqueta = paso > 0 ? obtenerEtiquetaPaso(paso) : 'Consultando ruta...';
            const textoTitulo = String(titulo || '').trim();
            const pasoElemento = offcanvas.querySelector('[data-work-route-step]');
            const tituloElemento = offcanvas.querySelector('[data-work-route-title]');
            const progreso = offcanvas.querySelector('[data-work-route-progress]');

            if (pasoElemento) {
                pasoElemento.textContent = paso > 0
                    ? 'Paso ' + paso + ' de ' + total
                    : 'Paso — de ' + total;
            }

            if (tituloElemento) {
                tituloElemento.textContent = etiqueta;
                tituloElemento.title = textoTitulo !== '' && textoTitulo !== etiqueta
                    ? 'Próxima acción: ' + textoTitulo
                    : '';
            }

            if (progreso) {
                progreso.style.width = paso > 0
                    ? Math.round((paso / total) * 100) + '%'
                    : '0%';
            }
        };

        const renderizarDesdeFila = function (seguimientoId) {
            const boton = document.querySelector(
                '[data-work-follow-id="' + Number(seguimientoId) + '"]'
            );
            const fila = boton?.closest('[data-linkage-follow-row]');

            if (!fila) {
                renderizarRuta(0, '');
                return;
            }

            renderizarRuta(
                Number(fila.dataset.flowStep || 0),
                String(fila.dataset.flowTitle || '')
            );
        };

        const consultarRuta = async function (seguimientoId) {
            seguimientoId = Number(seguimientoId || 0);

            if (seguimientoId <= 0) {
                return;
            }

            seguimientoActualId = seguimientoId;
            renderizarDesdeFila(seguimientoId);

            try {
                const respuesta = await fetch(
                    'index.php?controller=seguimientoFlujo&action=estado&seguimiento_id=' +
                    encodeURIComponent(seguimientoId),
                    {
                        headers: {
                            'X-Requested-With': 'fetch'
                        },
                        cache: 'no-store'
                    }
                );

                if (!respuesta.ok) {
                    return;
                }

                const datos = await respuesta.json();

                if (!datos?.ok || !datos?.flujo) {
                    return;
                }

                renderizarRuta(
                    Number(datos.flujo.paso_actual || 0),
                    String(datos.flujo.titulo || '')
                );
            } catch (error) {
                // La fila conserva una representación útil si la consulta falla.
            }
        };

        const aplicarModoAdministrador = function () {
            if (!esAdministrador) {
                return;
            }

            document.body.classList.add('impe-seguimiento-readonly');

            document.querySelectorAll('[data-work-follow]').forEach(function (boton) {
                boton.title = 'Consultar seguimiento';
                boton.setAttribute('aria-label', 'Consultar seguimiento');

                const icono = boton.querySelector('i');
                const texto = boton.querySelector('span');

                if (icono) {
                    icono.className = 'bi bi-eye';
                }

                if (texto) {
                    texto.textContent = 'Ver';
                }
            });

            const etiquetaPanel = offcanvas.querySelector('.linkage-work-header > div > span');
            if (etiquetaPanel) {
                etiquetaPanel.textContent = 'Vista de seguimiento';
            }
        };

        asegurarTarjetaRuta();
        aplicarModoAdministrador();

        document.addEventListener('click', function (evento) {
            const boton = evento.target.closest('[data-work-follow]');

            if (!boton) {
                return;
            }

            const seguimientoId = Number(boton.dataset.workFollowId || 0);
            if (seguimientoId > 0) {
                consultarRuta(seguimientoId);
            }
        }, true);

        document.addEventListener('impe:flow-row-updated', function (evento) {
            const detalle = evento.detail || {};
            const seguimientoId = Number(detalle.seguimientoId || 0);

            if (seguimientoId <= 0 || seguimientoId !== seguimientoActualId) {
                return;
            }

            renderizarRuta(
                Number(detalle.pasoActual || 0),
                String(detalle.titulo || '')
            );
        });

        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            aplicarModoAdministrador();

            if (seguimientoActualId > 0) {
                consultarRuta(seguimientoActualId);
            }
        });
    });
})();
