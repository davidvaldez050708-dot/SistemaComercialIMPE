(function () {
    'use strict';

    function esReporteSeguimiento() {
        const parametros = new URLSearchParams(window.location.search);
        return parametros.get('controller') === 'seguimientoVinculacionReporte' &&
            parametros.get('modal') !== '1' &&
            parametros.get('generar') === '1';
    }

    function normalizarTexto(valor) {
        return String(valor || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function prepararResumenMientrasCarga(intento) {
        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        const resumen = root?.querySelector('[data-operational-summary]');

        if (!resumen) {
            if (intento < 30) {
                window.setTimeout(function () {
                    prepararResumenMientrasCarga(intento + 1);
                }, 10);
            }
            return;
        }

        resumen.querySelectorAll('.seguimiento-report-summary-card').forEach(function (tarjeta) {
            const etiqueta = tarjeta.querySelector('.metric-label');
            const valor = tarjeta.querySelector('.metric-value');
            const detalle = tarjeta.querySelector('.seguimiento-report-summary-detail');
            const texto = normalizarTexto(etiqueta?.textContent || '');

            if (!valor) {
                return;
            }

            if (
                texto.includes('interacciones registradas') ||
                texto.includes('interacciones por seguimiento')
            ) {
                valor.textContent = '—';
                tarjeta.classList.add('is-analytics-pending');

                if (detalle) {
                    detalle.textContent = 'Calculando actividad…';
                }
            }
        });
    }

    function limpiarEstadoCargaCuandoTermine() {
        const root = document.querySelector('section[aria-labelledby="titulo-reporte-seguimiento"]');
        if (!root) {
            return;
        }

        const observer = new MutationObserver(function () {
            root.querySelectorAll('.seguimiento-report-summary-card.is-analytics-pending').forEach(function (tarjeta) {
                const etiqueta = normalizarTexto(tarjeta.querySelector('.metric-label')?.textContent || '');
                const valor = tarjeta.querySelector('.metric-value')?.textContent.trim() || '';

                if (
                    valor !== '—' &&
                    (
                        etiqueta.includes('interacciones de contacto') ||
                        etiqueta.includes('interacciones por seguimiento')
                    )
                ) {
                    tarjeta.classList.remove('is-analytics-pending');
                }
            });

            if (!root.querySelector('.seguimiento-report-summary-card.is-analytics-pending')) {
                observer.disconnect();
            }
        });

        observer.observe(root, {
            subtree: true,
            childList: true,
            characterData: true
        });
    }

    function iniciar() {
        if (!esReporteSeguimiento()) {
            return;
        }

        window.setTimeout(function () {
            prepararResumenMientrasCarga(0);
            limpiarEstadoCargaCuandoTermine();
        }, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
