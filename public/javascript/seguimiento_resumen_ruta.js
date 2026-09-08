(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const resumen = document.querySelector('.linkage-summary-grid');

        if (!resumen) {
            return;
        }

        const tarjetas = Array.from(
            resumen.querySelectorAll('.linkage-summary-card')
        );
        const filas = Array.from(
            document.querySelectorAll('[data-linkage-follow-row]')
        );

        if (tarjetas.length < 4) {
            return;
        }

        const configuracion = [
            {
                clave: 'en_seguimiento',
                etiqueta: 'En seguimiento',
                icono: 'bi-kanban',
                ayuda: 'Seguimientos activos del territorio.'
            },
            {
                clave: 'gestion_previa',
                etiqueta: 'Gestión previa',
                icono: 'bi-list-check',
                ayuda: 'Instituciones entre los pasos 1 y 10 de la ruta.'
            },
            {
                clave: 'reuniones_acuerdos',
                etiqueta: 'Reuniones y acuerdos',
                icono: 'bi-calendar-check',
                ayuda: 'Instituciones en los pasos 11 y 12 de la ruta.'
            },
            {
                clave: 'convenio',
                etiqueta: 'Convenio',
                icono: 'bi-file-earmark-check',
                ayuda: 'Instituciones en el paso 13 de la ruta.'
            }
        ];

        configuracion.forEach(function (item, indice) {
            const tarjeta = tarjetas[indice];
            const valor = tarjeta.querySelector('.metric-value');
            const etiqueta = tarjeta.querySelector('.metric-label');
            const icono = tarjeta.querySelector('.metric-icon i');

            tarjeta.setAttribute('data-route-summary-card', item.clave);
            tarjeta.title = item.ayuda;

            if (valor) {
                valor.removeAttribute('data-summary-count');
                valor.setAttribute('data-route-summary-count', item.clave);
                valor.textContent = indice === 0 ? String(filas.length) : '…';
            }

            if (etiqueta) {
                etiqueta.textContent = item.etiqueta;
            }

            if (icono) {
                icono.className = 'bi ' + item.icono;
            }
        });

        const valorMetrica = function (clave) {
            return resumen.querySelector(
                '[data-route-summary-count="' + clave + '"]'
            );
        };

        const estadoInternoFila = function (fila) {
            return String(
                fila.dataset.internalStage || fila.dataset.stage || ''
            ).trim().toUpperCase();
        };

        const estaDescartada = function (fila) {
            return String(fila.dataset.stage || '').trim().toUpperCase() === 'DESCARTADO' ||
                estadoInternoFila(fila) === 'DESCARTADO';
        };

        const recalcular = function (permitirParcial) {
            const activas = filas.filter(function (fila) {
                return !estaDescartada(fila);
            });
            const resueltas = activas.filter(function (fila) {
                const paso = Number(fila.dataset.flowStep || 0);
                return paso >= 1 && paso <= 13;
            });

            const total = valorMetrica('en_seguimiento');
            if (total) {
                total.textContent = String(activas.length);
            }

            if (!permitirParcial && resueltas.length < activas.length) {
                ['gestion_previa', 'reuniones_acuerdos', 'convenio'].forEach(function (clave) {
                    const elemento = valorMetrica(clave);
                    if (elemento) {
                        elemento.textContent = '…';
                    }
                });
                return;
            }

            const conteos = {
                gestion_previa: 0,
                reuniones_acuerdos: 0,
                convenio: 0
            };

            resueltas.forEach(function (fila) {
                const paso = Number(fila.dataset.flowStep || 0);

                if (paso >= 1 && paso <= 10) {
                    conteos.gestion_previa += 1;
                    return;
                }

                if (paso === 11 || paso === 12) {
                    conteos.reuniones_acuerdos += 1;
                    return;
                }

                if (paso === 13) {
                    conteos.convenio += 1;
                }
            });

            Object.keys(conteos).forEach(function (clave) {
                const elemento = valorMetrica(clave);
                if (elemento) {
                    elemento.textContent = String(conteos[clave]);
                }
            });
        };

        document.addEventListener('impe:flow-row-updated', function () {
            recalcular(false);
        });

        document.addEventListener('impe:flow-updated', function () {
            recalcular(false);
        });

        document.addEventListener('impe:interaction-informative-saved', function () {
            recalcular(false);
        });

        recalcular(false);

        window.setTimeout(function () {
            recalcular(true);
        }, 4500);
    });
})();
