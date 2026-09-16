(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esExpediente =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';

        if (!esExpediente) {
            return;
        }

        let framePendiente = 0;

        const folioDisponible = function (valor) {
            const folio = String(valor || '').trim();

            return folio !== '' &&
                folio !== '—' &&
                folio.toLowerCase() !== 'pendiente';
        };

        const ordenarNavegacion = function () {
            const nav = document.querySelector(
                '.linkage-expediente-section-nav, .linkage-detail-tabs'
            );

            if (!nav) {
                return;
            }

            const tabLlamadas = nav.querySelector(
                '[data-expediente-tab="llamadas"]'
            );
            const tabOficios = nav.querySelector(
                '[data-expediente-tab="oficios"]'
            );

            /*
             * Llamadas forma parte del trabajo de contacto. Debe permanecer
             * junto a Actividad y antes de Oficio y correos.
             */
            if (
                tabLlamadas &&
                tabOficios &&
                tabLlamadas.nextElementSibling !== tabOficios
            ) {
                nav.insertBefore(tabLlamadas, tabOficios);
            }

            const contenido = document.querySelector('.linkage-expediente-content');
            const paneLlamadas = contenido?.querySelector(
                '[data-expediente-pane="llamadas"]'
            );
            const paneOficios = contenido?.querySelector(
                '[data-expediente-pane="oficios"]'
            );

            if (
                paneLlamadas &&
                paneOficios &&
                paneLlamadas.nextElementSibling !== paneOficios
            ) {
                contenido.insertBefore(paneLlamadas, paneOficios);
            }
        };

        const encontrarSeccionOficios = function () {
            const pane = document.querySelector(
                '[data-expediente-pane="oficios"]'
            );

            if (pane) {
                const seccionPane = pane.querySelector(
                    'section.dashboard-panel.linkage-detail-panel'
                );

                if (seccionPane) {
                    return seccionPane;
                }
            }

            return Array.from(
                document.querySelectorAll(
                    'section.dashboard-panel.linkage-detail-panel'
                )
            ).find(function (seccion) {
                const titulo = seccion.querySelector('.panel-title');
                return String(titulo?.textContent || '')
                    .trim()
                    .toLowerCase() === 'oficios';
            }) || null;
        };

        const refinarEstadoVacio = function (vacio) {
            if (!vacio || vacio.dataset.oficioEmptyRefined === '1') {
                return;
            }

            vacio.dataset.oficioEmptyRefined = '1';
            vacio.classList.add('linkage-oficio-empty-refined');
            vacio.innerHTML =
                '<span class="linkage-oficio-empty-icon">' +
                    '<i class="bi bi-file-earmark-text"></i>' +
                '</span>' +
                '<strong class="linkage-oficio-empty-title">Aún no hay un oficio</strong>' +
                '<p class="linkage-oficio-empty-copy" data-oficio-empty-copy>' +
                    'Este seguimiento todavía no cuenta con un oficio generado.' +
                '</p>';
        };

        const agruparAcciones = function (bloque) {
            if (!bloque) {
                return;
            }

            bloque.classList.add('linkage-detail-oficio-actions-compact');

            let meta = Array.from(bloque.children).find(function (elemento) {
                return elemento.tagName === 'DIV' &&
                    !elemento.hasAttribute('data-detail-oficio-buttons');
            });

            if (meta) {
                meta.classList.add('linkage-detail-oficio-meta');
            }

            let acciones = bloque.querySelector(
                ':scope > [data-detail-oficio-buttons]'
            );

            if (!acciones) {
                acciones = document.createElement('div');
                acciones.className = 'linkage-detail-oficio-buttons';
                acciones.setAttribute('data-detail-oficio-buttons', '');
                bloque.appendChild(acciones);
            }

            Array.from(bloque.children).forEach(function (elemento) {
                if (
                    elemento === meta ||
                    elemento === acciones ||
                    !elemento.matches('button, a')
                ) {
                    return;
                }

                acciones.appendChild(elemento);
            });
        };

        const refinarOficios = function () {
            const seccion = encontrarSeccionOficios();

            if (!seccion) {
                return;
            }

            seccion.classList.add('linkage-oficio-refined');

            const bloque = seccion.querySelector('[data-detail-oficio-action]');
            const vacio = seccion.querySelector('.linkage-empty-message');

            if (vacio) {
                refinarEstadoVacio(vacio);
            }

            if (bloque) {
                agruparAcciones(bloque);
            }

            const folio = bloque?.querySelector(
                '[data-detail-oficio-folio]'
            )?.textContent || '';
            const tieneRegistro = Boolean(
                seccion.querySelector('.linkage-table tbody tr')
            );
            const tieneOficio = folioDisponible(folio) || tieneRegistro;

            if (!tieneOficio) {
                seccion.classList.add('is-oficio-empty');
                seccion.classList.remove('has-oficio');

                if (!vacio) {
                    return;
                }

                vacio.classList.remove('d-none');

                if (bloque && bloque.parentElement !== vacio) {
                    vacio.appendChild(bloque);
                }

                const botonGenerar = bloque?.querySelector(
                    '[data-detail-generate-oficio]'
                );
                const puedeGenerar = Boolean(
                    botonGenerar &&
                    !botonGenerar.disabled &&
                    !botonGenerar.classList.contains('d-none')
                );
                const descripcion = vacio.querySelector(
                    '[data-oficio-empty-copy]'
                );

                if (descripcion) {
                    descripcion.textContent = puedeGenerar
                        ? 'Los datos necesarios ya están listos. Genera el oficio cuando corresponda para continuar el proceso.'
                        : 'Cuando el seguimiento llegue a esta etapa, aquí podrás generar y gestionar el oficio institucional.';
                }

                if (botonGenerar) {
                    botonGenerar.classList.toggle(
                        'linkage-oficio-action-unavailable',
                        !puedeGenerar
                    );
                }

                return;
            }

            seccion.classList.remove('is-oficio-empty');
            seccion.classList.add('has-oficio');
            vacio?.classList.add('d-none');

            if (!bloque) {
                return;
            }

            const tabla = seccion.querySelector('.table-responsive');

            if (tabla) {
                if (bloque.parentElement !== seccion || bloque.nextElementSibling !== tabla) {
                    seccion.insertBefore(bloque, tabla);
                }
                return;
            }

            if (bloque.parentElement !== seccion) {
                const titulo = seccion.querySelector('.panel-title');
                titulo?.insertAdjacentElement('afterend', bloque);
            }
        };

        const aplicarRefinamientos = function () {
            ordenarNavegacion();
            refinarOficios();
        };

        const programarRefinamientos = function () {
            window.cancelAnimationFrame(framePendiente);
            framePendiente = window.requestAnimationFrame(aplicarRefinamientos);
        };

        aplicarRefinamientos();
        window.setTimeout(aplicarRefinamientos, 80);
        window.setTimeout(aplicarRefinamientos, 220);

        if (window.MutationObserver) {
            const observador = new MutationObserver(programarRefinamientos);
            observador.observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['class', 'disabled']
            });
        }
    });
})();
