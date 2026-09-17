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

    function agregarModuloReportesSidebar() {
        const parametros = new URLSearchParams(window.location.search);
        const controladorActual = parametros.get('controller') || '';
        const estaEnReportes = controladoresReporte.has(controladorActual);
        const secciones = document.querySelectorAll('.admin-sidebar .sidebar-section');

        secciones.forEach(function (seccion) {
            const titulo = seccion.querySelector('.sidebar-section-title');

            if (!titulo || titulo.textContent.trim().toUpperCase() !== 'VINCULACIÓN') {
                return;
            }

            const tieneModuloOrigen = seccion.querySelector(
                'a[href*="controller=dataTerritorial"], a[href*="controller=seguimientoVinculacion"]'
            );

            if (!tieneModuloOrigen || seccion.querySelector('[data-sidebar-reportes]')) {
                return;
            }

            if (estaEnReportes) {
                seccion.querySelectorAll('.sidebar-link.active').forEach(function (enlace) {
                    enlace.classList.remove('active');
                });
            }

            const enlace = document.createElement('a');
            enlace.className = 'sidebar-link' + (estaEnReportes ? ' active' : '');
            enlace.href = construirUrl({ controller: 'reporte', action: 'index' });
            enlace.setAttribute('data-sidebar-reportes', '');

            const icono = document.createElement('i');
            icono.className = 'bi bi-file-earmark-bar-graph';
            icono.setAttribute('aria-hidden', 'true');

            enlace.appendChild(icono);
            enlace.appendChild(document.createTextNode('Reportes'));
            seccion.appendChild(enlace);
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

    function inicializar() {
        agregarModuloReportesSidebar();
        agregarAccesoReporteTerritorial();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar, { once: true });
    } else {
        inicializar();
    }
})();
