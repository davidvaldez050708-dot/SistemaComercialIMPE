(function () {
    'use strict';

    const reemplazos = [
        [/\[AVANZAR_CONVENIO\]/g, '· Avanzar a convenio'],
        [/\[REQUIERE_SEGUIMIENTO\]/g, '· Requiere seguimiento'],
        [/\[NO_INTERESADO\]/g, '· No interesado'],
        [/\bAVANZAR_CONVENIO\b/g, 'Avanzar a convenio'],
        [/\bREQUIERE_SEGUIMIENTO\b/g, 'Requiere seguimiento'],
        [/\bNO_INTERESADO\b/g, 'No interesado']
    ];

    const humanizar = function (valor) {
        let resultado = String(valor == null ? '' : valor);

        reemplazos.forEach(function (regla) {
            resultado = resultado.replace(regla[0], regla[1]);
        });

        return resultado;
    };

    const nodoIgnorado = function (nodo) {
        const padre = nodo?.parentElement;

        if (!padre) {
            return true;
        }

        return Boolean(
            padre.closest('script, style, textarea, code, pre, [data-technical-code]')
        );
    };

    const humanizarNodoTexto = function (nodo) {
        if (!nodo || nodo.nodeType !== Node.TEXT_NODE || nodoIgnorado(nodo)) {
            return;
        }

        const actual = String(nodo.nodeValue || '');
        const limpio = humanizar(actual);

        if (limpio !== actual) {
            nodo.nodeValue = limpio;
        }
    };

    const humanizarArbol = function (raiz) {
        if (!raiz) {
            return;
        }

        if (raiz.nodeType === Node.TEXT_NODE) {
            humanizarNodoTexto(raiz);
            return;
        }

        if (!(raiz instanceof Element) && raiz !== document.body) {
            return;
        }

        const walker = document.createTreeWalker(
            raiz,
            NodeFilter.SHOW_TEXT
        );
        let nodo = walker.nextNode();

        while (nodo) {
            humanizarNodoTexto(nodo);
            nodo = walker.nextNode();
        }
    };

    const iniciar = function () {
        if (!document.body) {
            return;
        }

        humanizarArbol(document.body);

        const observador = new MutationObserver(function (mutaciones) {
            mutaciones.forEach(function (mutacion) {
                if (mutacion.type === 'characterData') {
                    humanizarNodoTexto(mutacion.target);
                    return;
                }

                mutacion.addedNodes.forEach(function (nodo) {
                    humanizarArbol(nodo);
                });
            });
        });

        observador.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
