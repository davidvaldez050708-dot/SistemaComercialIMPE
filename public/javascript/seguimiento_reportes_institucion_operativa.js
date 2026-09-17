(function () {
    'use strict';

    function normalizarTexto(valor) {
        return String(valor || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function esReporteIndividual() {
        const parametros = new URLSearchParams(window.location.search);
        const institucionId = Number(parametros.get('institucion_id') || 0);

        return parametros.get('controller') === 'seguimientoVinculacionReporte' &&
            parametros.get('modal') !== '1' &&
            parametros.get('generar') === '1' &&
            Number.isInteger(institucionId) &&
            institucionId > 0;
    }

    function indiceColumna(tabla, nombre) {
        const buscado = normalizarTexto(nombre);
        return Array.from(tabla.querySelectorAll('thead th')).findIndex(function (th) {
            return normalizarTexto(th.textContent) === buscado;
        });
    }

    function buscarDato(panel, etiquetaBuscada) {
        const buscado = normalizarTexto(etiquetaBuscada);
        const cajas = Array.from(panel.querySelectorAll('[data-institution-focus-grid] > div > div'));

        for (const caja of cajas) {
            const etiqueta = caja.querySelector('small');
            const valor = caja.querySelector('strong');
            if (etiqueta && valor && normalizarTexto(etiqueta.textContent) === buscado) {
                return { caja: caja, etiqueta: etiqueta, valor: valor };
            }
        }

        return null;
    }

    function valorFlujo(flujo, ruta, respaldo) {
        let actual = flujo;

        for (const clave of ruta) {
            if (!actual || typeof actual !== 'object' || !(clave in actual)) {
                return respaldo;
            }
            actual = actual[clave];
        }

        const texto = String(actual || '').trim();
        return texto !== '' ? texto : respaldo;
    }

    function actualizarPanel(panel, flujo) {
        panel.classList.add('seguimiento-report-institution-focus');

        const etapa = valorFlujo(
            flujo,
            ['ventana', 'actual', 'titulo'],
            valorFlujo(flujo, ['titulo'], 'Etapa no disponible')
        );
        const accion = valorFlujo(
            flujo,
            ['accion_principal', 'etiqueta'],
            valorFlujo(flujo, ['titulo'], '—')
        );
        const pasoActual = Number(flujo && flujo.paso_actual || 0);
        const totalPasos = Number(flujo && flujo.total_pasos || 0);

        const estatus = buscarDato(panel, 'Estatus actual');
        if (estatus) {
            estatus.etiqueta.textContent = 'Etapa de vinculación';
            estatus.valor.textContent = etapa;
            estatus.caja.classList.add('seguimiento-report-institution-key');
        }

        const proxima = buscarDato(panel, 'Próxima acción');
        if (proxima) {
            proxima.etiqueta.textContent = 'Acción actual';
            proxima.valor.textContent = accion;
            proxima.caja.classList.add('seguimiento-report-institution-key');
        }

        const subtitulo = panel.querySelector('.seguimiento-report-section-heading .page-subtitle');
        if (subtitulo) {
            subtitulo.textContent = 'Lectura operativa basada en la ruta actual de vinculación.';
        }

        const heading = panel.querySelector('.seguimiento-report-section-heading > div');
        if (heading && pasoActual > 0 && totalPasos > 0 && !heading.querySelector('[data-institution-flow-step]')) {
            const paso = document.createElement('span');
            paso.className = 'seguimiento-report-institution-step';
            paso.setAttribute('data-institution-flow-step', '');
            paso.textContent = 'Paso ' + pasoActual + ' de ' + totalPasos;
            heading.appendChild(paso);
        }

        return { etapa: etapa, accion: accion };
    }

    function actualizarDetalle(root, datos) {
        const tabla = root.querySelector('.seguimiento-report-detail-panel table');
        const fila = tabla && tabla.querySelector('tbody tr');
        if (!tabla || !fila) {
            return;
        }

        const indiceEstatus = indiceColumna(tabla, 'Estatus');
        if (indiceEstatus >= 0 && fila.children[indiceEstatus]) {
            tabla.querySelectorAll('thead th')[indiceEstatus].textContent = 'Etapa operativa';
            fila.children[indiceEstatus].textContent = datos.etapa;
        }

        const indiceAccion = indiceColumna(tabla, 'Próxima acción');
        if (indiceAccion >= 0 && fila.children[indiceAccion]) {
            tabla.querySelectorAll('thead th')[indiceAccion].textContent = 'Acción actual';
            fila.children[indiceAccion].textContent = datos.accion;
            fila.children[indiceAccion].classList.remove('is-loading');
        }
    }

    function cargarRuta(root, panel, seguimientoId) {
        const url = new URL('index.php', window.location.href);
        url.searchParams.set('controller', 'seguimientoFlujo');
        url.searchParams.set('action', 'estado');
        url.searchParams.set('seguimiento_id', String(seguimientoId));

        fetch(url.toString(), {
            headers: { 'X-Requested-With': 'fetch' },
            cache: 'no-store'
        })
            .then(function (respuesta) {
                if (!respuesta.ok) {
                    throw new Error('No fue posible consultar la ruta operativa.');
                }
                return respuesta.json();
            })
            .then(function (datos) {
                if (!datos || !datos.ok || !datos.flujo) {
                    throw new Error('La ruta operativa no está disponible.');
                }

                const lectura = actualizarPanel(panel, datos.flujo);
                actualizarDetalle(root, lectura);
            })
            .catch(function () {
                panel.classList.add('seguimiento-report-institution-focus');
                const subtitulo = panel.querySelector('.seguimiento-report-section-heading .page-subtitle');
                if (subtitulo) {
                    subtitulo.textContent = 'No fue posible sincronizar la ruta operativa en este momento.';
                }
            });
    }

    function esperarSincronizacion(root, panel, seguimientoId, intento) {
        const pendientes = root.querySelectorAll('.seguimiento-report-next-action-cell.is-loading').length;
        if (pendientes > 0 && intento < 40) {
            window.setTimeout(function () {
                esperarSincronizacion(root, panel, seguimientoId, intento + 1);
            }, 100);
            return;
        }

        cargarRuta(root, panel, seguimientoId);
    }

    function aplicar(intento) {
        if (!esReporteIndividual()) {
            return;
        }

        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        const panel = root && root.querySelector('[data-institution-focus]');
        const ids = Array.isArray(window.IMPE_REPORTE_SEGUIMIENTO_IDS)
            ? window.IMPE_REPORTE_SEGUIMIENTO_IDS
                .map(function (id) { return Number(id); })
                .filter(function (id) { return Number.isInteger(id) && id > 0; })
            : [];

        if ((!root || !panel) && intento < 30) {
            window.setTimeout(function () {
                aplicar(intento + 1);
            }, 100);
            return;
        }

        if (!root || !panel || ids.length === 0 || panel.hasAttribute('data-institution-operational-ready')) {
            return;
        }

        panel.setAttribute('data-institution-operational-ready', '');
        panel.classList.add('seguimiento-report-institution-focus');
        esperarSincronizacion(root, panel, ids[0], 0);
    }

    function iniciar() {
        window.setTimeout(function () {
            aplicar(0);
        }, 80);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
