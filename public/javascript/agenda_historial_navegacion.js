(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const enlace = event.target.closest('.agenda-nav-button, .agenda-today-button');

        if (!enlace) {
            return;
        }

        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            enlace.target === '_blank'
        ) {
            return;
        }

        const destino = enlace.href;
        if (!destino) {
            return;
        }

        event.preventDefault();
        window.location.replace(destino);
    }, true);
})();
