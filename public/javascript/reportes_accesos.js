(function () {
    'use strict';

    const controladoresReporte = new Set([
        'reporte',
        'dataTerritorialReporte',
        'seguimientoVinculacionReporte'
    ]);

    function construirUrl(baseParams) {
        const url = new URL(window.location.href);
        url.search = '';
        url.hash = '';

        Object.entries(baseParams).forEach(function ([clave, valor]) {
            url.searchParams.set(clave, String(valor));
        });

        return url.toString();
    }

    function sincronizarModuloReportesSidebar() {
        const parametros = new URLSearchParams(window.location.search);
        const controladorActual = parametros.get('controller') || '';

        if (!controladoresReporte.has(controladorActual)) {
            return;
        }

        document.querySelectorAll('.admin-sidebar').forEach(function (sidebar) {
            const enlaceReportes = sidebar.querySelector(
                'a.sidebar-link[href*="controller=reporte"][href*="action=index"]'
            );

            if (!enlaceReportes) {
                return;
            }

            sidebar.querySelectorAll('.sidebar-link.active').forEach(function (enlace) {
                enlace.classList.remove('active');
            });

            enlaceReportes.classList.add('active');
        });
    }

    function agregarAccesoReporteTerritorial() {
        const parametros = new URLSearchParams(window.location.search);

        if (parametros.get('controller') !== 'dataTerritorial') {
            return;
        }

        const estadoId = parseInt(parametros.get('estado_id') || '0', 10);
        const contenedor = document.querySelector('.data-state-map');

        if (!contenedor || !Number.isInteger(estadoId) || estadoId <= 0) {
            return;
        }

        if (contenedor.querySelector('[data-territorial-report-link]')) {
            return;
        }

        const enlace = document.createElement('a');
        enlace.className = 'btn btn-system-save';
        enlace.href = construirUrl({
            controller: 'dataTerritorialReporte',
            action: 'index',
            estado_id: estadoId,
            generar: 1
        });
        enlace.setAttribute('data-territorial-report-link', '');

        const icono = document.createElement('i');
        icono.className = 'bi bi-file-earmark-bar-graph me-2';
        icono.setAttribute('aria-hidden', 'true');

        enlace.appendChild(icono);
        enlace.appendChild(document.createTextNode('Generar reporte'));
        contenedor.appendChild(enlace);
    }

    function crearEstadoPendiente(panelFiltro, vistaReporte) {
        let estadoPendiente = document.querySelector('[data-report-pending-state]');

        if (estadoPendiente) {
            return estadoPendiente;
        }

        estadoPendiente = document.createElement('section');
        estadoPendiente.className = 'dashboard-panel data-empty-state report-empty-state report-pending-state d-none';
        estadoPendiente.setAttribute('data-report-pending-state', '');

        const icono = document.createElement('span');
        icono.innerHTML = '<i class="bi bi-file-earmark-bar-graph"></i>';

        const titulo = document.createElement('strong');
        titulo.setAttribute('data-report-pending-title', '');

        const descripcion = document.createElement('p');
        descripcion.setAttribute('data-report-pending-copy', '');

        estadoPendiente.appendChild(icono);
        estadoPendiente.appendChild(titulo);
        estadoPendiente.appendChild(descripcion);

        if (vistaReporte && vistaReporte.parentNode) {
            vistaReporte.parentNode.insertBefore(estadoPendiente, vistaReporte);
        } else if (panelFiltro && panelFiltro.parentNode) {
            panelFiltro.insertAdjacentElement('afterend', estadoPendiente);
        }

        return estadoPendiente;
    }

    function moverExportacionAlReporte(vistaReporte) {
        if (!vistaReporte) {
            return null;
        }

        const botonExportar = document.querySelector(
            '.report-territorial-module a[href*="controller=dataTerritorialReporte"][href*="action=exportarPdf"]'
        );
        const encabezado = vistaReporte.querySelector('.report-preview-header');

        if (!botonExportar || !encabezado) {
            return botonExportar;
        }

        let acciones = encabezado.querySelector('.report-preview-actions');

        if (!acciones) {
            acciones = document.createElement('div');
            acciones.className = 'report-preview-actions';

            const badge = encabezado.querySelector('.report-preview-badge');
            if (badge) {
                acciones.appendChild(badge);
            }

            encabezado.appendChild(acciones);
        }

        botonExportar.classList.add('report-export-action');
        const icono = botonExportar.querySelector('i');
        if (icono) {
            icono.classList.remove('me-2');
        }

        acciones.appendChild(botonExportar);
        return botonExportar;
    }

    function inicializarFlujoReporteTerritorial() {
        const parametros = new URLSearchParams(window.location.search);

        if (parametros.get('controller') !== 'dataTerritorialReporte') {
            return;
        }

        const formulario = document.querySelector('.report-filter-form');
        const selector = document.getElementById('reporte_territorio');
        const panelFiltro = document.querySelector('.report-filter-panel');
        const vistaReporte = document.querySelector('.report-preview');

        if (!formulario || !selector || !panelFiltro) {
            return;
        }

        const botonGenerar = formulario.querySelector('button[type="submit"]');
        const estadoGenerado = vistaReporte
            ? String(parametros.get('estado_id') || '')
            : '';
        const opcionPlaceholder = selector.querySelector('option[value=""]');
        const botonExportar = moverExportacionAlReporte(vistaReporte);
        const estadoPendiente = vistaReporte
            ? crearEstadoPendiente(panelFiltro, vistaReporte)
            : null;

        if (vistaReporte && opcionPlaceholder) {
            opcionPlaceholder.disabled = true;
        }

        function sincronizarBotonGenerar() {
            if (botonGenerar) {
                botonGenerar.disabled = selector.value === '';
            }
        }

        function mostrarReporteActual() {
            if (vistaReporte) {
                vistaReporte.classList.remove('d-none');
            }

            if (botonExportar) {
                botonExportar.classList.remove('d-none');
            }

            if (estadoPendiente) {
                estadoPendiente.classList.add('d-none');
            }
        }

        function mostrarTerritorioPendiente() {
            if (!vistaReporte || !estadoPendiente) {
                return;
            }

            const estadoSeleccionado = String(selector.value || '');

            if (estadoSeleccionado !== '' && estadoSeleccionado === estadoGenerado) {
                mostrarReporteActual();
                return;
            }

            vistaReporte.classList.add('d-none');

            if (botonExportar) {
                botonExportar.classList.add('d-none');
            }

            const opcion = selector.options[selector.selectedIndex];
            const nombreTerritorio = opcion ? opcion.textContent.trim() : '';
            const titulo = estadoPendiente.querySelector('[data-report-pending-title]');
            const descripcion = estadoPendiente.querySelector('[data-report-pending-copy]');

            if (titulo) {
                titulo.textContent = nombreTerritorio !== ''
                    ? nombreTerritorio + ' seleccionado'
                    : 'Selecciona un territorio para preparar el reporte.';
            }

            if (descripcion) {
                descripcion.textContent = nombreTerritorio !== ''
                    ? 'Genera el reporte para consultar sus datos, cálculos y comparaciones territoriales.'
                    : 'Selecciona un territorio y genera el reporte para consultar su información.';
            }

            estadoPendiente.classList.remove('d-none');
        }

        selector.addEventListener('change', function () {
            sincronizarBotonGenerar();
            mostrarTerritorioPendiente();
        });

        sincronizarBotonGenerar();
        mostrarTerritorioPendiente();
    }

    function inicializar() {
        sincronizarModuloReportesSidebar();
        agregarAccesoReporteTerritorial();
        inicializarFlujoReporteTerritorial();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar, { once: true });
    } else {
        inicializar();
    }
})();