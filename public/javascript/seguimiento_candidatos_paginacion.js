/* Paginación local del catálogo completo recibido en buscarCandidatos.
 * No consulta DENUE, no altera registros y preserva el orden del servidor. */
(function (raiz) {
    'use strict';

    const MAXIMO_CANDIDATOS = 300; // Sólo cachés locales heredadas; no limita consultarPagina.

    const crearPagina = function (lista, total, pagina, limite) {
        if (!Array.isArray(lista) ||
            !Number.isInteger(total) || total < 1 || total > MAXIMO_CANDIDATOS ||
            lista.length !== total || !Number.isInteger(pagina) ||
            !Number.isInteger(limite) || pagina < 1 || limite < 1 ||
            pagina > Math.ceil(total / limite)) {
            return null;
        }

        const inicio = (pagina - 1) * limite;
        return {
            candidatos: lista.slice(inicio, inicio + limite),
            pagina: pagina,
            resultados_por_pagina: limite,
            total_resultados: total,
            hay_mas: inicio + limite < total
        };
    };

    const crearFirma = function (estadoId, municipioId, tipo, termino) {
        return [estadoId, municipioId, tipo, termino].map(function (valor) {
            return String(valor ?? '').trim();
        }).join('|');
    };

    // Completa una página visible recorriendo las páginas reales pendientes de DENUE.
    // La siguiente página visible se consulta bajo demanda; no se declara completo el catálogo.
    const consultarPagina = async function (url, opciones, progreso, cliente = fetch) {
        let fallos = 0;
        let revisados = -1;
        while (true) {
            if (opciones.signal?.aborted) throw new DOMException('Consulta cancelada', 'AbortError');
            let respuesta, datos;
            try {
                respuesta = await cliente(url, opciones);
                if (!(respuesta.headers.get('content-type') || '').includes('application/json')) {
                    throw new Error('No fue posible completar la consulta. La búsqueda conserva su avance.');
                }
                datos = await respuesta.json();
            } catch (error) {
                if (error.name === 'AbortError' || opciones.signal?.aborted) throw error;
                if (++fallos > 2) throw error;
                progreso('Recuperando la conexión con DENUE…');
                await esperar(500 * fallos, opciones.signal);
                continue;
            }
            if (!respuesta.ok || !datos.ok) {
                if (datos.reintentable === true && datos.codigo !== 'HTTP_429' && ++fallos <= 2) {
                    progreso('Reintentando la consulta pendiente de DENUE…');
                    await esperar(500 * fallos, opciones.signal);
                    continue;
                }
                throw new Error(datos.mensaje || 'No fue posible completar la consulta. La búsqueda conserva su avance.');
            }
            fallos = 0;
            if (!datos.denue_continuar) return datos;
            const actual = Number(datos.denue_revisados);
            if (!Number.isSafeInteger(actual) || actual <= revisados) {
                throw new Error('La consulta no pudo avanzar. Se conservaron los resultados ya consultados.');
            }
            revisados = actual;
            progreso('Buscando coincidencias… ' + actual + ' registros de DENUE revisados.');
        }
    };

    const esperar = function (ms, signal) {
        return new Promise(function (resolve, reject) {
            const cancelar = function () {
                clearTimeout(timer);
                signal?.removeEventListener('abort', cancelar);
                reject(new DOMException('Consulta cancelada', 'AbortError'));
            };
            const timer = setTimeout(function () {
                signal?.removeEventListener('abort', cancelar);
                resolve();
            }, ms);
            signal?.addEventListener('abort', cancelar, {once:true});
            if (signal?.aborted) cancelar();
        });
    };

    const api = {
        crearPagina: crearPagina,
        crearFirma: crearFirma,
        consultarPagina: consultarPagina
    };

    if (raiz) {
        raiz.SeguimientoCandidatosPaginacion = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : null);
