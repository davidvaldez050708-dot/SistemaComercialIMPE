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

        if (atencion?.querySelector('.analyst-empty-state')) {
            atencion.classList.add('is-empty');
        }

        const vacioProximos = proximos?.querySelector('.analyst-empty-state');
        if (!vacioProximos) {
            return;
        }

        proximos.classList.add('is-empty');

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

    const refinarActividad = function (tablero) {
        tablero.querySelectorAll('.analyst-activity-copy').forEach(function (copia) {
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
    };

    const refinarTerritorios = function (tablero) {
        tablero.querySelectorAll('.analyst-territory-item').forEach(function (item) {
            const nombre = item.querySelector('div > strong');
            const conteo = item.querySelector('div > span');
            const icono = item.querySelector('.analyst-territory-icon');

            if (!nombre || !icono || icono.dataset.stateImageReady === '1') {
                return;
            }

            const slug = archivoEstado(nombre.textContent);
            if (!slug) {
                return;
            }

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

            if (conteo) {
                conteo.classList.add('analyst-territory-count');
                const total = parseInt(String(conteo.textContent || '').trim(), 10) || 0;
                item.classList.toggle('is-active', total > 0);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        refinarEstadoJornada(tablero);
        refinarVacios(tablero);
        refinarActividad(tablero);
        refinarTerritorios(tablero);
    });
})();
