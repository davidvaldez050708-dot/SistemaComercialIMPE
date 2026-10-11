/* Paginación local del catálogo completo recibido en buscarCandidatos.
 * No consulta DENUE, no altera registros y preserva el orden del servidor. */
(function (raiz) {
    'use strict';

    const MAXIMO_CANDIDATOS = 300;

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

    const api = {
        crearPagina: crearPagina,
        crearFirma: crearFirma
    };

    if (raiz) {
        raiz.SeguimientoCandidatosPaginacion = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})(typeof window !== 'undefined' ? window : null);
