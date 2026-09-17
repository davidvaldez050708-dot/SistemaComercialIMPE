(function () {
    'use strict';

    function esReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte';
    }

    function crearAyudaCompacta(formulario) {
        const textosAyuda = Array.from(formulario.querySelectorAll('.form-text'));

        if (textosAyuda.length === 0 || formulario.querySelector('[data-report-help-compact]')) {
            return;
        }

        textosAyuda.forEach(function (nodo) {
            nodo.classList.add('d-none');
        });

        const ayuda = document.createElement('div');
        ayuda.className = 'seguimiento-report-help';
        ayuda.setAttribute('data-report-help-compact', '');

        const icono = document.createElement('i');
        icono.className = 'bi bi-info-circle';
        icono.setAttribute('aria-hidden', 'true');

        const texto = document.createElement('span');
        texto.textContent = 'El periodo se aplica sobre la fecha de inicio registrada en cada seguimiento. El tipo de actividad utiliza el canal de la última interacción registrada.';

        ayuda.appendChild(icono);
        ayuda.appendChild(texto);

        const acciones = formulario.querySelector('[data-report-actions-refined]');
        if (acciones) {
            formulario.insertBefore(ayuda, acciones);
        } else {
            formulario.appendChild(ayuda);
        }
    }

    function refinarBotones(formulario) {
        const limpiar = Array.from(formulario.querySelectorAll('a.btn')).find(function (enlace) {
            return enlace.textContent.trim().toLowerCase().includes('limpiar filtros');
        });
        const generar = formulario.querySelector('button[type="submit"]');

        if (limpiar) {
            limpiar.classList.remove('btn-secondary');
            limpiar.classList.add('btn-system-cancel', 'seguimiento-report-action');
            limpiar.querySelector('i')?.classList.remove('me-2');
        }

        if (generar) {
            generar.classList.remove('btn-system-primary');
            generar.classList.add('btn-system-save', 'seguimiento-report-action', 'seguimiento-report-generate');
            generar.querySelector('i')?.classList.remove('me-2');
        }

        const acciones = limpiar?.parentElement || generar?.parentElement;
        if (acciones) {
            acciones.classList.add('seguimiento-report-actions');
            acciones.setAttribute('data-report-actions-refined', '');
        }
    }

    function refinarEncabezado(panel) {
        const encabezado = panel.querySelector(':scope > .d-flex.align-items-start');
        if (!encabezado) {
            return;
        }

        encabezado.classList.add('seguimiento-report-filter-heading');

        const copia = encabezado.querySelector('div');
        const titulo = copia?.querySelector('.panel-title');

        if (copia && titulo && !copia.querySelector('.report-eyebrow')) {
            const etiqueta = document.createElement('span');
            etiqueta.className = 'report-eyebrow';
            etiqueta.textContent = 'SEGUIMIENTO DE VINCULACIÓN';
            copia.insertBefore(etiqueta, titulo);
        }
    }

    function refinarRegreso(formulario) {
        const parametros = new URLSearchParams(window.location.search);
        const origen = parametros.get('origen');
        const volver = document.querySelector('.linkage-back-link');

        if (origen !== 'reportes') {
            return;
        }

        if (volver) {
            volver.href = window.location.pathname + '?controller=reporte&action=index';
            volver.innerHTML = '<i class="bi bi-arrow-left"></i> Volver a Reportes';
        }

        if (!formulario.querySelector('input[name="origen"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'origen';
            input.value = 'reportes';
            formulario.appendChild(input);
        }

        const limpiar = Array.from(formulario.querySelectorAll('a')).find(function (enlace) {
            return enlace.textContent.trim().toLowerCase().includes('limpiar filtros');
        });

        if (limpiar) {
            const url = new URL(limpiar.href, window.location.origin);
            url.searchParams.set('origen', 'reportes');
            limpiar.href = url.toString();
        }
    }

    function refinarTopbar() {
        const titulo = document.querySelector('.admin-topbar .page-title');
        const subtitulo = document.querySelector('.admin-topbar .page-subtitle');

        if (titulo) {
            titulo.textContent = 'Reportes';
        }

        if (subtitulo) {
            subtitulo.textContent = 'Seguimiento de vinculación';
        }
    }

    function refinarExportacion() {
        document.querySelectorAll('a[href*="action=exportarPdf"]').forEach(function (enlace) {
            enlace.classList.add('seguimiento-report-export');
            enlace.querySelector('i')?.classList.remove('me-2');
        });
    }

    function refinarColumnasModal(formulario) {
        const fila = formulario.querySelector('.modal-body > .row');
        if (!fila) {
            return;
        }

        fila.classList.remove('g-3');
        fila.classList.add('gx-3', 'gy-2', 'seguimiento-report-modal-grid');

        Array.from(fila.children).forEach(function (columna) {
            if (!(columna instanceof HTMLElement)) {
                return;
            }

            columna.classList.remove('col-xl-3', 'col-xl-4');
            columna.classList.add('col-12', 'col-md-6', 'col-lg-4');
        });
    }

    function inicializarModal(formulario) {
        document.body.classList.add('seguimiento-reportes-page', 'seguimiento-reportes-modal-page');
        formulario.classList.add('seguimiento-report-filter-form', 'seguimiento-report-modal-form');

        refinarColumnasModal(formulario);
        refinarBotones(formulario);
        crearAyudaCompacta(formulario);
    }

    function inicializarPagina(formulario) {
        const panel = formulario.closest('section.dashboard-panel');

        if (!panel) {
            return;
        }

        document.body.classList.add('seguimiento-reportes-page');
        formulario.classList.add('seguimiento-report-filter-form');
        panel.classList.add('seguimiento-report-filter-panel');

        refinarEncabezado(panel);
        refinarBotones(formulario);
        crearAyudaCompacta(formulario);
        refinarRegreso(formulario);
        refinarTopbar();
        refinarExportacion();
    }

    function inicializar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        const formulario = document.querySelector('form[data-report-form]');
        if (!formulario) {
            return;
        }

        const parametros = new URLSearchParams(window.location.search);
        if (parametros.get('modal') === '1') {
            inicializarModal(formulario);
            return;
        }

        inicializarPagina(formulario);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar, { once: true });
    } else {
        inicializar();
    }
})();
