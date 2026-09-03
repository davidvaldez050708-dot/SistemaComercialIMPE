(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esDetalle =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const nav = document.querySelector('.linkage-detail-tabs');

        if (!esDetalle || !nav) {
            return;
        }

        document.body.classList.add('linkage-expediente-enhanced');

        const normalizar = function (valor) {
            return String(valor || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toLowerCase();
        };

        const clavesPorEtiqueta = {
            'resumen': 'resumen',
            'contacto y validacion': 'contacto',
            'interacciones': 'interacciones',
            'oficio y correos': 'oficios',
            'agenda': 'agenda',
            'reunion': 'reunion',
            'convenio': 'convenio'
        };

        const configuracionVacia = {
            agenda: {
                icono: 'bi-calendar3',
                titulo: 'Agenda',
                texto: 'Todavía no hay información de agenda registrada para este seguimiento.'
            },
            reunion: {
                icono: 'bi-camera-video',
                titulo: 'Reunión',
                texto: 'Todavía no hay información de reunión registrada para este seguimiento.'
            },
            convenio: {
                icono: 'bi-file-earmark-text',
                titulo: 'Convenio',
                texto: 'Todavía no hay información de convenio registrada para este seguimiento.'
            }
        };

        const tabs = Array.from(nav.querySelectorAll('span'));
        const secciones = Array.from(
            document.querySelectorAll('section.dashboard-panel.linkage-detail-panel')
        );

        if (tabs.length === 0 || secciones.length === 0) {
            return;
        }

        const encontrarSeccion = function (predicado) {
            return secciones.find(predicado) || null;
        };

        const seccionResumen = encontrarSeccion(function (seccion) {
            return Boolean(seccion.querySelector('.linkage-detail-header'));
        });
        const seccionInformacion = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'informacion encontrada';
        });
        const seccionVerificados = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'datos verificados';
        });
        const seccionInteracciones = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.panel-title');
            return normalizar(titulo?.textContent) === 'historial de interacciones';
        });
        const seccionOficios = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.panel-title');
            return normalizar(titulo?.textContent) === 'oficios';
        });
        const seccionObservaciones = encontrarSeccion(function (seccion) {
            const titulo = seccion.querySelector('.users-list-header h2');
            return normalizar(titulo?.textContent) === 'observaciones del cuenta clave';
        });

        const shell = document.createElement('div');
        shell.className = 'linkage-expediente-tabs-shell';
        shell.setAttribute('aria-label', 'Navegación del expediente');

        const botonAnterior = document.createElement('button');
        botonAnterior.type = 'button';
        botonAnterior.className = 'linkage-expediente-tab-arrow';
        botonAnterior.setAttribute('aria-label', 'Ver secciones anteriores');
        botonAnterior.innerHTML = '<i class="bi bi-chevron-left"></i>';

        const botonSiguiente = document.createElement('button');
        botonSiguiente.type = 'button';
        botonSiguiente.className = 'linkage-expediente-tab-arrow';
        botonSiguiente.setAttribute('aria-label', 'Ver secciones siguientes');
        botonSiguiente.innerHTML = '<i class="bi bi-chevron-right"></i>';

        nav.parentNode.insertBefore(shell, nav);
        shell.appendChild(botonAnterior);
        shell.appendChild(nav);
        shell.appendChild(botonSiguiente);

        nav.setAttribute('role', 'tablist');
        nav.setAttribute('aria-label', 'Secciones del expediente');

        const contenido = document.createElement('div');
        contenido.className = 'linkage-expediente-content';

        const primeraSeccion = secciones[0];
        primeraSeccion.parentNode.insertBefore(contenido, primeraSeccion);

        const paneles = {};
        const crearPanel = function (clave) {
            const panel = document.createElement('div');
            panel.className = 'linkage-expediente-pane';
            panel.dataset.expedientePane = clave;
            panel.setAttribute('role', 'tabpanel');
            panel.hidden = true;
            contenido.appendChild(panel);
            paneles[clave] = panel;
            return panel;
        };

        ['resumen', 'contacto', 'interacciones', 'oficios', 'agenda', 'reunion', 'convenio']
            .forEach(crearPanel);

        const mover = function (seccion, clave) {
            if (!seccion || !paneles[clave]) {
                return;
            }

            seccion.classList.add('linkage-expediente-section');
            paneles[clave].appendChild(seccion);
        };

        mover(seccionResumen, 'resumen');
        mover(seccionObservaciones, 'resumen');
        mover(seccionInformacion, 'contacto');
        mover(seccionVerificados, 'contacto');
        mover(seccionInteracciones, 'interacciones');
        mover(seccionOficios, 'oficios');

        Object.keys(configuracionVacia).forEach(function (clave) {
            const configuracion = configuracionVacia[clave];
            const vacio = document.createElement('section');
            vacio.className = 'dashboard-panel linkage-detail-panel linkage-expediente-empty';
            vacio.innerHTML =
                '<div class="linkage-expediente-empty-content">' +
                    '<span class="linkage-expediente-empty-icon">' +
                        '<i class="bi ' + configuracion.icono + '"></i>' +
                    '</span>' +
                    '<h3>' + configuracion.titulo + '</h3>' +
                    '<p>' + configuracion.texto + '</p>' +
                '</div>';
            paneles[clave].appendChild(vacio);
        });

        const limitarHistorial = function () {
            if (!seccionInteracciones) {
                return;
            }

            const lista = seccionInteracciones.querySelector('.linkage-history-list');
            const items = lista
                ? Array.from(lista.querySelectorAll('.linkage-history-item'))
                : [];
            const limite = 6;

            if (!lista || items.length <= limite) {
                return;
            }

            items.slice(limite).forEach(function (item) {
                item.classList.add('is-history-hidden');
            });

            const pie = document.createElement('div');
            pie.className = 'linkage-history-more';

            const resumen = document.createElement('span');
            resumen.textContent = 'Mostrando ' + limite + ' de ' + items.length + ' interacciones';

            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn btn-system-light';
            boton.innerHTML =
                '<i class="bi bi-chevron-down me-1"></i>' +
                'Ver ' + (items.length - limite) + ' más';

            let expandido = false;
            boton.addEventListener('click', function () {
                expandido = !expandido;

                items.slice(limite).forEach(function (item) {
                    item.classList.toggle('is-history-hidden', !expandido);
                });

                resumen.textContent = expandido
                    ? 'Mostrando las ' + items.length + ' interacciones'
                    : 'Mostrando ' + limite + ' de ' + items.length + ' interacciones';
                boton.innerHTML = expandido
                    ? '<i class="bi bi-chevron-up me-1"></i>Ver menos'
                    : '<i class="bi bi-chevron-down me-1"></i>Ver ' +
                        (items.length - limite) + ' más';
            });

            pie.appendChild(resumen);
            pie.appendChild(boton);
            seccionInteracciones.appendChild(pie);
        };

        limitarHistorial();

        tabs.forEach(function (tab, indice) {
            const etiqueta = normalizar(tab.textContent);
            const clave = clavesPorEtiqueta[etiqueta] || 'resumen';

            tab.dataset.expedienteTab = clave;
            tab.id = 'expediente-tab-' + clave;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', 'expediente-panel-' + clave);
            tab.setAttribute('aria-selected', 'false');
            tab.tabIndex = indice === 0 ? 0 : -1;

            if (paneles[clave]) {
                paneles[clave].id = 'expediente-panel-' + clave;
                paneles[clave].setAttribute('aria-labelledby', tab.id);
            }
        });

        const activar = function (clave, actualizarHash) {
            if (!paneles[clave]) {
                clave = 'resumen';
            }

            tabs.forEach(function (tab) {
                const activo = tab.dataset.expedienteTab === clave;
                tab.classList.toggle('active', activo);
                tab.setAttribute('aria-selected', activo ? 'true' : 'false');
                tab.tabIndex = activo ? 0 : -1;

                if (activo) {
                    tab.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'center'
                    });
                }
            });

            Object.keys(paneles).forEach(function (panelClave) {
                paneles[panelClave].hidden = panelClave !== clave;
            });

            if (actualizarHash) {
                history.replaceState(null, '', '#exp-' + clave);
            }
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activar(tab.dataset.expedienteTab || 'resumen', true);
            });

            tab.addEventListener('keydown', function (event) {
                const indice = tabs.indexOf(tab);
                let destino = null;

                if (event.key === 'ArrowRight') {
                    destino = tabs[(indice + 1) % tabs.length];
                } else if (event.key === 'ArrowLeft') {
                    destino = tabs[(indice - 1 + tabs.length) % tabs.length];
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activar(tab.dataset.expedienteTab || 'resumen', true);
                    return;
                }

                if (destino) {
                    event.preventDefault();
                    destino.focus();
                    activar(destino.dataset.expedienteTab || 'resumen', true);
                }
            });
        });

        const desplazarTabs = function (direccion) {
            nav.scrollBy({
                left: direccion * Math.max(240, Math.round(nav.clientWidth * 0.72)),
                behavior: 'smooth'
            });
        };

        botonAnterior.addEventListener('click', function () {
            desplazarTabs(-1);
        });
        botonSiguiente.addEventListener('click', function () {
            desplazarTabs(1);
        });

        const hash = String(window.location.hash || '').replace('#exp-', '');
        activar(paneles[hash] ? hash : 'resumen', false);
    });
})();
