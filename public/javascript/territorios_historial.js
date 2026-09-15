(function () {
    'use strict';

    const LIMITE_INICIAL = 5;
    const SELECTOR_LISTA = '.territory-activity-list';

    function inicializarLista(lista) {
        if (!lista || lista.dataset.historyExpandable === '1') {
            return;
        }

        lista.dataset.historyExpandable = '1';

        const eventos = Array.from(lista.children).filter(function (elemento) {
            return elemento.classList.contains('territory-activity-item');
        });

        if (eventos.length <= LIMITE_INICIAL) {
            return;
        }

        const adicionales = eventos.slice(LIMITE_INICIAL);
        adicionales.forEach(function (evento) {
            evento.hidden = true;
        });

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className =
            'btn btn-sm btn-link px-0 mt-2 text-decoration-none fw-semibold territory-history-toggle';
        boton.setAttribute('aria-expanded', 'false');

        const etiqueta = document.createElement('span');
        etiqueta.textContent = 'Mostrar más (' + adicionales.length + ')';

        const icono = document.createElement('i');
        icono.className = 'bi bi-chevron-down ms-1';
        icono.setAttribute('aria-hidden', 'true');

        boton.appendChild(etiqueta);
        boton.appendChild(icono);

        boton.addEventListener('click', function () {
            const expandido = boton.getAttribute('aria-expanded') === 'true';
            const mostrar = !expandido;

            adicionales.forEach(function (evento) {
                evento.hidden = !mostrar;
            });

            boton.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
            etiqueta.textContent = mostrar
                ? 'Mostrar menos'
                : 'Mostrar más (' + adicionales.length + ')';
            icono.className = mostrar
                ? 'bi bi-chevron-up ms-1'
                : 'bi bi-chevron-down ms-1';
        });

        lista.insertAdjacentElement('afterend', boton);
    }

    function inicializarHistoriales(raiz) {
        const contexto = raiz || document;

        contexto.querySelectorAll(SELECTOR_LISTA).forEach(function (lista) {
            inicializarLista(lista);
        });
    }

    function iniciar() {
        inicializarHistoriales(document);

        const contenedorDetalle = document.getElementById('territorioDetalleContenido');

        if (!contenedorDetalle) {
            return;
        }

        const observador = new MutationObserver(function () {
            inicializarHistoriales(contenedorDetalle);
        });

        observador.observe(contenedorDetalle, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
