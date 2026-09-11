(function () {
    'use strict';

    const normalizarNombreEstado = function (valor) {
        return String(valor || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    };

    const archivoEstado = function (nombre) {
        const normalizado = normalizarNombreEstado(nombre);
        const especiales = {
            'ciudad de mexico': 'ciudad-de-mexico',
            'estado de mexico': 'estado-de-mexico',
            'mexico': 'estado-de-mexico',
            'coahuila de zaragoza': 'coahuila',
            'michoacan': 'michoacán',
            'michoacan de ocampo': 'michoacán',
            'nuevo leon': 'nuevo-leon',
            'queretaro': 'queretaro',
            'san luis potosi': 'san-luis-potosi',
            'veracruz de ignacio de la llave': 'veracruz',
            'yucatan': 'yucatan'
        };

        if (especiales[normalizado]) {
            return especiales[normalizado];
        }

        return normalizado
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    };

    const crearEnlaceCabecera = function (panel, texto, href) {
        const cabecera = panel?.querySelector('.analyst-panel-heading');
        if (!cabecera || cabecera.querySelector('.analyst-panel-link')) {
            return;
        }

        const enlace = document.createElement('a');
        enlace.className = 'analyst-panel-link';
        enlace.href = href;
        enlace.innerHTML = texto + ' <i class="bi bi-arrow-right"></i>';
        cabecera.appendChild(enlace);
    };

    const refinarEstadoJornada = function (tablero) {
        const estado = tablero.querySelector('.analyst-welcome-status');
        if (!estado) {
            return;
        }

        const titulo = estado.querySelector('strong');
        const icono = estado.querySelector('.analyst-welcome-status-icon i');
        const estaAlDia = String(titulo?.textContent || '')
            .toLowerCase()
            .includes('al día');

        estado.classList.toggle('is-clear', estaAlDia);
        estado.classList.toggle('is-attention', !estaAlDia);

        if (icono) {
            icono.className = 'bi ' + (estaAlDia
                ? 'bi-check2-circle'
                : 'bi-exclamation-circle');
        }
    };

    const refinarVacios = function (tablero) {
        const atencion = tablero.querySelector('.analyst-attention-panel');
        const proximos = tablero.querySelector('.analyst-upcoming-panel');
        const atencionVacia = Boolean(atencion?.querySelector('.analyst-empty-state'));
        const proximosVacio = Boolean(proximos?.querySelector('.analyst-empty-state'));

        if (atencionVacia) {
            atencion.classList.add('is-empty');
        }

        if (proximosVacio) {
            proximos.classList.add('is-empty');
        }

        const fila = atencion?.closest('.row');
        if (fila && atencionVacia && proximosVacio) {
            fila.classList.add('analyst-priority-row', 'is-all-empty');
        }

        const vacioProximos = proximos?.querySelector('.analyst-empty-state');
        if (!vacioProximos) {
            return;
        }

        const contenido = vacioProximos.querySelector('div');
        if (!contenido || contenido.querySelector('.analyst-empty-cta')) {
            return;
        }

        const enlace = document.createElement('a');
        enlace.className = 'analyst-empty-cta';
        enlace.href = 'index.php?controller=agendaReunion&action=index';
        enlace.innerHTML = 'Abrir agenda <i class="bi bi-arrow-right"></i>';
        contenido.appendChild(enlace);
    };

    const refinarMetricas = function (tablero) {
        const seguimientoUrl = 'index.php?controller=seguimientoVinculacion&action=index';
        const agendaUrl = 'index.php?controller=agendaReunion&action=index';

        tablero.querySelectorAll('.analyst-metric-card').forEach(function (tarjeta) {
            const etiqueta = String(tarjeta.querySelector('.metric-label')?.textContent || '')
                .trim()
                .toLowerCase();
            const href = etiqueta.includes('reuniones') ? agendaUrl : seguimientoUrl;

            tarjeta.classList.add('is-actionable');
            tarjeta.tabIndex = 0;
            tarjeta.setAttribute('role', 'link');
            tarjeta.setAttribute('aria-label', 'Abrir ' + (etiqueta || 'detalle'));

            const abrir = function () {
                window.location.href = href;
            };

            tarjeta.addEventListener('click', abrir);
            tarjeta.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    abrir();
                }
            });
        });
    };

    const refinarActividad = function (tablero) {
        const panel = tablero.querySelector('.analyst-activity-panel');
        if (!panel) {
            return;
        }

        crearEnlaceCabecera(
            panel,
            'Ver seguimiento',
            'index.php?controller=seguimientoVinculacion&action=index'
        );

        panel.querySelectorAll('.analyst-activity-copy').forEach(function (copia) {
            if (copia.querySelector('.analyst-activity-meta')) {
                return;
            }

            const detalle = copia.querySelector(':scope > span');
            if (!detalle) {
                return;
            }

            const partes = String(detalle.textContent || '')
                .split('·')
                .map(function (parte) { return parte.trim(); })
                .filter(Boolean);

            if (partes.length === 0) {
                return;
            }

            const meta = document.createElement('div');
            meta.className = 'analyst-activity-meta';

            const canal = document.createElement('span');
            canal.className = 'analyst-activity-channel';
            canal.textContent = partes.shift();
            meta.appendChild(canal);

            if (partes.length > 0) {
                const resultado = document.createElement('span');
                resultado.className = 'analyst-activity-result';
                resultado.textContent = partes.join(' · ');
                meta.appendChild(resultado);
            }

            detalle.replaceWith(meta);
        });

        const vistos = new Map();
        const elementos = Array.from(panel.querySelectorAll('.analyst-activity-item'));

        elementos.forEach(function (item) {
            const institucion = String(item.querySelector('strong')?.textContent || '').trim();
            const canal = String(item.querySelector('.analyst-activity-channel')?.textContent || '').trim();
            const resultado = String(item.querySelector('.analyst-activity-result')?.textContent || '').trim();
            const hora = String(item.querySelector('time')?.textContent || '').trim();
            const firma = [institucion, canal, resultado, hora].join('|').toLowerCase();

            if (!vistos.has(firma)) {
                vistos.set(firma, item);
                return;
            }

            const principal = vistos.get(firma);
            const meta = principal.querySelector('.analyst-activity-meta');
            if (meta) {
                let contador = meta.querySelector('.analyst-activity-count');
                if (!contador) {
                    contador = document.createElement('span');
                    contador.className = 'analyst-activity-count';
                    contador.dataset.total = '1';
                    meta.appendChild(contador);
                }

                const total = Number(contador.dataset.total || 1) + 1;
                contador.dataset.total = String(total);
                contador.textContent = total + ' registros';
            }

            item.remove();
        });

        Array.from(panel.querySelectorAll('.analyst-activity-item'))
            .slice(5)
            .forEach(function (item) {
                item.classList.add('is-dashboard-hidden');
            });
    };

    const refinarTerritorios = function (tablero) {
        const panel = tablero.querySelector('.analyst-territory-panel');
        const lista = panel?.querySelector('.analyst-territory-list');
        if (!panel || !lista) {
            return;
        }

        const elementos = Array.from(lista.querySelectorAll('.analyst-territory-item'));
        let seguimientosActivos = 0;

        elementos.forEach(function (item) {
            const nombre = item.querySelector('div > strong');
            const conteo = item.querySelector('div > span');
            const icono = item.querySelector('.analyst-territory-icon');

            if (!nombre || !icono) {
                return;
            }

            const total = parseInt(String(conteo?.textContent || '').trim(), 10) || 0;
            item.dataset.activeCount = String(total);
            item.dataset.principal = item.querySelector('.analyst-territory-primary') ? '1' : '0';
            seguimientosActivos += total;

            if (icono.dataset.stateImageReady !== '1') {
                const slug = archivoEstado(nombre.textContent);
                if (slug) {
                    icono.dataset.stateImageReady = '1';
                    icono.classList.add('has-state-image');

                    const imagen = document.createElement('img');
                    imagen.className = 'analyst-territory-state-image';
                    imagen.src = 'public/img/estados/' + encodeURIComponent(slug) + '.png';
                    imagen.alt = 'Mapa de ' + String(nombre.textContent || '').trim();
                    imagen.loading = 'lazy';
                    imagen.decoding = 'async';

                    const respaldo = document.createElement('i');
                    respaldo.className = 'bi bi-geo-alt analyst-territory-fallback';
                    respaldo.setAttribute('aria-hidden', 'true');

                    imagen.addEventListener('error', function () {
                        icono.classList.add('is-fallback');
                    }, { once: true });

                    icono.replaceChildren(imagen, respaldo);
                }
            }

            if (conteo) {
                conteo.classList.add('analyst-territory-count');
                item.classList.toggle('is-active', total > 0);
            }
        });

        elementos.sort(function (a, b) {
            const activos = Number(b.dataset.activeCount || 0) - Number(a.dataset.activeCount || 0);
            if (activos !== 0) {
                return activos;
            }

            const principal = Number(b.dataset.principal || 0) - Number(a.dataset.principal || 0);
            if (principal !== 0) {
                return principal;
            }

            const nombreA = String(a.querySelector('strong')?.textContent || '');
            const nombreB = String(b.querySelector('strong')?.textContent || '');
            return nombreA.localeCompare(nombreB, 'es');
        });

        elementos.forEach(function (item, indice) {
            lista.appendChild(item);
            item.classList.toggle('is-dashboard-hidden', indice >= 5);
        });

        const cabecera = panel.querySelector('.analyst-panel-heading');
        if (cabecera && !cabecera.querySelector('.analyst-territory-summary')) {
            const resumen = document.createElement('span');
            resumen.className = 'analyst-territory-summary';
            resumen.textContent = seguimientosActivos + ' ' +
                (seguimientosActivos === 1 ? 'seguimiento activo' : 'seguimientos activos') +
                ' · ' + elementos.length + ' territorios';
            cabecera.appendChild(resumen);
        }

        if (elementos.length > 5 && !panel.querySelector('.analyst-territory-footer')) {
            const pie = document.createElement('div');
            pie.className = 'analyst-territory-footer';
            pie.innerHTML = '<a href="index.php?controller=territorio&action=index">' +
                'Ver todos mis territorios <i class="bi bi-arrow-right"></i></a>';
            panel.appendChild(pie);
        }
    };

    const refinarAvanceMensual = function (tablero) {
        const panel = tablero.querySelector('.analyst-progress-panel');
        const grid = panel?.querySelector('.analyst-progress-grid');
        if (!panel || !grid || panel.querySelector('.analyst-progress-insight')) {
            return;
        }

        const pasos = Array.from(grid.querySelectorAll('.analyst-progress-step'));
        if (pasos.length === 0) {
            return;
        }

        const valores = pasos.map(function (paso) {
            return Number(String(paso.querySelector('strong')?.textContent || '0').trim()) || 0;
        });
        const etiquetas = pasos.map(function (paso) {
            return String(paso.querySelector(':scope > span:last-child')?.textContent || '').trim();
        });
        const totalInteracciones = Number(
            String(panel.querySelector('.analyst-interaction-total strong')?.textContent || '0').trim()
        ) || 0;

        let ultimoIndice = -1;
        valores.forEach(function (valor, indice) {
            if (valor > 0) {
                ultimoIndice = indice;
            }
        });

        const insight = document.createElement('div');
        insight.className = 'analyst-progress-insight';
        const icono = document.createElement('span');
        icono.className = 'analyst-progress-insight-icon';
        icono.innerHTML = '<i class="bi bi-graph-up-arrow"></i>';

        const texto = document.createElement('span');
        if (ultimoIndice >= 0) {
            texto.innerHTML = 'La actividad de este mes ya registra avances hasta <strong>' +
                etiquetas[ultimoIndice].toLowerCase() + '</strong>.';
        } else if (totalInteracciones > 0) {
            texto.textContent = 'Hay actividad registrada este mes, pero aún no se reflejan avances en los hitos principales.';
        } else {
            texto.textContent = 'Aún no hay actividad suficiente para mostrar una tendencia mensual.';
        }

        insight.append(icono, texto);
        panel.appendChild(insight);
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        refinarEstadoJornada(tablero);
        refinarVacios(tablero);
        refinarMetricas(tablero);
        refinarActividad(tablero);
        refinarTerritorios(tablero);
        refinarAvanceMensual(tablero);
    });
})();
