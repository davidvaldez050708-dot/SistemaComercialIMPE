(function () {
    'use strict';

    const usuarioId = Number(window.IMPE_CURRENT_USER_ID || 0);

    if (usuarioId <= 0 || typeof window.fetch !== 'function') {
        return;
    }

    const PREFIJO = 'impe:seguimiento:panel:v1:' + usuarioId + ':';
    const VIGENCIA_MS = 10 * 60 * 1000;
    const memoria = new Map();
    const enCurso = new Map();
    const fetchBase = window.fetch.bind(window);

    const clave = function (seguimientoId) {
        return PREFIJO + String(Number(seguimientoId) || 0);
    };

    const guardar = function (seguimientoId, datos) {
        seguimientoId = Number(seguimientoId || datos?.seguimiento?.id || 0);

        if (seguimientoId <= 0 || !datos?.ok || !datos?.seguimiento) {
            return;
        }

        const registro = {
            guardado_at: Date.now(),
            datos: datos
        };

        memoria.set(seguimientoId, registro);

        try {
            window.sessionStorage.setItem(clave(seguimientoId), JSON.stringify(registro));
        } catch (error) {
            // La caché en memoria sigue disponible durante esta página.
        }
    };

    const obtenerRegistro = function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);

        if (seguimientoId <= 0) {
            return null;
        }

        let registro = memoria.get(seguimientoId) || null;

        if (!registro) {
            try {
                const raw = window.sessionStorage.getItem(clave(seguimientoId));
                registro = raw ? JSON.parse(raw) : null;
            } catch (error) {
                registro = null;
            }
        }

        if (
            !registro ||
            !registro.datos ||
            !registro.datos.ok ||
            (Date.now() - Number(registro.guardado_at || 0)) > VIGENCIA_MS
        ) {
            memoria.delete(seguimientoId);
            try {
                window.sessionStorage.removeItem(clave(seguimientoId));
            } catch (error) {
                // Sin acción.
            }
            return null;
        }

        memoria.set(seguimientoId, registro);
        return registro;
    };

    const obtener = function (seguimientoId) {
        return obtenerRegistro(seguimientoId)?.datos || null;
    };

    const invalidar = function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);

        if (seguimientoId > 0) {
            memoria.delete(seguimientoId);
            try {
                window.sessionStorage.removeItem(clave(seguimientoId));
            } catch (error) {
                // Sin acción.
            }
            return;
        }

        memoria.clear();

        try {
            const borrar = [];
            for (let indice = 0; indice < window.sessionStorage.length; indice += 1) {
                const item = window.sessionStorage.key(indice);
                if (item && item.startsWith(PREFIJO)) {
                    borrar.push(item);
                }
            }
            borrar.forEach(function (item) {
                window.sessionStorage.removeItem(item);
            });
        } catch (error) {
            // Sin acción.
        }
    };

    const urlDe = function (entrada) {
        try {
            if (typeof entrada === 'string') {
                return new URL(entrada, window.location.href);
            }

            if (entrada && typeof entrada.url === 'string') {
                return new URL(entrada.url, window.location.href);
            }
        } catch (error) {
            return null;
        }

        return null;
    };

    const metodoDe = function (entrada, opciones) {
        return String(
            opciones?.method ||
            (entrada && typeof entrada.method === 'string' ? entrada.method : 'GET')
        ).toUpperCase();
    };

    const esConsultaPanel = function (url, metodo) {
        return Boolean(
            url &&
            metodo === 'GET' &&
            url.searchParams.get('controller') === 'seguimientoVinculacion' &&
            url.searchParams.get('action') === 'obtenerPanelTrabajo'
        );
    };

    const seguimientoIdDe = function (url, opciones) {
        let seguimientoId = Number(url?.searchParams.get('seguimiento_id') || 0);

        if (seguimientoId > 0) {
            return seguimientoId;
        }

        const body = opciones?.body;

        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            seguimientoId = Number(body.get('seguimiento_id') || 0);
        } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
            seguimientoId = Number(body.get('seguimiento_id') || 0);
        }

        return seguimientoId > 0 ? seguimientoId : 0;
    };

    const crearRespuesta = function (resultado) {
        return new Response(resultado.cuerpo, {
            status: resultado.status,
            statusText: resultado.statusText,
            headers: {
                'Content-Type': resultado.contentType || 'application/json; charset=utf-8'
            }
        });
    };

    const respuestaDesdeCache = function (datos) {
        return new Response(JSON.stringify(datos), {
            status: 200,
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'X-IMPE-Panel-Cache': 'HIT'
            }
        });
    };

    const solicitarRed = function (seguimientoId, entrada, opciones) {
        seguimientoId = Number(seguimientoId || 0);

        if (enCurso.has(seguimientoId)) {
            return enCurso.get(seguimientoId);
        }

        const peticion = (async function () {
            const respuesta = await fetchBase(entrada, opciones);
            const cuerpo = await respuesta.text();
            let datos = null;

            try {
                datos = JSON.parse(cuerpo);
            } catch (error) {
                datos = null;
            }

            if (respuesta.ok && datos?.ok && datos?.seguimiento) {
                guardar(seguimientoId, datos);
            }

            return {
                cuerpo: cuerpo,
                status: respuesta.status,
                statusText: respuesta.statusText,
                contentType: respuesta.headers.get('content-type') || 'application/json; charset=utf-8'
            };
        })().finally(function () {
            enCurso.delete(seguimientoId);
        });

        enCurso.set(seguimientoId, peticion);
        return peticion;
    };

    window.fetch = function (entrada, opciones) {
        const url = urlDe(entrada);
        const metodo = metodoDe(entrada, opciones);

        if (esConsultaPanel(url, metodo)) {
            const seguimientoId = seguimientoIdDe(url, opciones);
            const datosGuardados = obtener(seguimientoId);

            if (datosGuardados) {
                return Promise.resolve(respuestaDesdeCache(datosGuardados));
            }

            return solicitarRed(seguimientoId, entrada, opciones).then(crearRespuesta);
        }

        const respuesta = fetchBase(entrada, opciones);

        if (metodo !== 'GET') {
            const seguimientoId = seguimientoIdDe(url, opciones);

            return respuesta.then(function (resultado) {
                if (resultado.ok) {
                    invalidar(seguimientoId);
                }
                return resultado;
            });
        }

        return respuesta;
    };

    const urlPanel = function (seguimientoId) {
        return 'index.php?controller=seguimientoVinculacion&action=obtenerPanelTrabajo&seguimiento_id=' +
            encodeURIComponent(seguimientoId);
    };

    const precargar = async function (seguimientoId) {
        seguimientoId = Number(seguimientoId || 0);

        if (seguimientoId <= 0 || obtener(seguimientoId) || enCurso.has(seguimientoId)) {
            return;
        }

        try {
            await solicitarRed(
                seguimientoId,
                urlPanel(seguimientoId),
                {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );
        } catch (error) {
            // La apertura normal del panel conserva su manejo de errores.
        }
    };

    const estabilizarFila = function (fila, seguimientoId) {
        if (!fila || seguimientoId <= 0 || !window.MutationObserver) {
            return;
        }

        let restaurando = false;

        const restaurar = function () {
            if (restaurando) {
                return;
            }

            const cacheRuta = window.IMPE_SEGUIMIENTO_RUTA_CACHE;
            const flujo = cacheRuta?.obtener?.(seguimientoId) || null;

            if (flujo && !fila.dataset.flowTitle) {
                cacheRuta.aplicarFila?.(seguimientoId, flujo, false);
            }

            const etapaEsperada = String(fila.dataset.flowStageLabel || '').trim();
            const accionEsperada = String(fila.dataset.flowTitle || '').trim();
            const etapa = fila.querySelector('[data-row-stage-label]');
            const accion = fila.querySelector('[data-row-next-action]');
            let cambio = false;

            restaurando = true;

            if (
                etapa &&
                etapaEsperada !== '' &&
                String(etapa.textContent || '').trim() !== etapaEsperada
            ) {
                etapa.textContent = etapaEsperada;
                cambio = true;
            }

            if (etapa && etapaEsperada !== '') {
                etapa.dataset.routeStageReady = '1';
            }

            if (
                accion &&
                accionEsperada !== '' &&
                String(accion.textContent || '').trim() !== accionEsperada
            ) {
                accion.textContent = accionEsperada;
                cambio = true;
            }

            if (accion && accionEsperada !== '') {
                accion.dataset.routeNextReady = '1';
            }

            if (!cambio) {
                restaurando = false;
                return;
            }

            window.queueMicrotask(function () {
                restaurando = false;
            });
        };

        const observador = new MutationObserver(restaurar);
        observador.observe(fila, {
            attributes: true,
            attributeFilter: ['data-stage'],
            childList: true,
            characterData: true,
            subtree: true
        });
    };

    window.IMPE_SEGUIMIENTO_PANEL_CACHE = {
        obtener: obtener,
        guardar: guardar,
        invalidar: invalidar,
        precargar: precargar
    };

    document.addEventListener('DOMContentLoaded', function () {
        const filas = Array.from(document.querySelectorAll('[data-linkage-follow-row]'));
        const ids = filas.map(function (fila) {
            const seguimientoId = Number(
                fila.querySelector('[data-work-follow-id]')?.getAttribute('data-work-follow-id') || 0
            );

            estabilizarFila(fila, seguimientoId);
            return seguimientoId;
        }).filter(function (seguimientoId) {
            return seguimientoId > 0;
        });

        let indice = 0;
        const trabajadores = Math.min(2, ids.length);

        const siguiente = async function () {
            const posicion = indice++;

            if (posicion >= ids.length) {
                return;
            }

            await precargar(ids[posicion]);
            await siguiente();
        };

        for (let trabajador = 0; trabajador < trabajadores; trabajador += 1) {
            void siguiente();
        }

        document.addEventListener('impe:interaction-informative-saved', function (evento) {
            const seguimientoId = Number(
                evento?.detail?.seguimientoId ||
                document.getElementById('offcanvasSeguimientoTrabajo')?.dataset.flowSeguimientoId ||
                0
            );
            invalidar(seguimientoId);
        });
    });
})();
