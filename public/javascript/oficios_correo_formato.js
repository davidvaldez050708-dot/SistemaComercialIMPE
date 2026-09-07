(function () {
    'use strict';

    const normalizarEncabezado = function (texto) {
        texto = String(texto || '').replace(/\r\n/g, '\n');
        const presente = 'P R E S E N T E';
        const indice = texto.indexOf(presente);

        if (indice <= 0) {
            return texto;
        }

        const encabezado = texto
            .slice(0, indice)
            .split(/\n+/)
            .map(function (linea) {
                return linea.trim();
            })
            .filter(Boolean);

        if (encabezado.length < 4) {
            return texto;
        }

        let resto = texto.slice(indice + presente.length);
        resto = resto.replace(/^[ \t]*(?:\n[ \t]*)+/, '\n\n');

        return encabezado.slice(0, 4).join('\n') +
            '\n\n' +
            presente +
            resto;
    };

    const normalizarModal = function (modal) {
        if (!modal || modal.id !== 'modalBorradorCorreoOficio') {
            return;
        }

        /*
         * Solo corregimos el formato inicial generado por la plantilla.
         * Si el Analista ya guardó un borrador, respetamos sus cambios.
         */
        if (modal.dataset.guardado === '1') {
            return;
        }

        const campo = modal.querySelector('[data-mail-body]');

        if (!campo) {
            return;
        }

        const normalizado = normalizarEncabezado(campo.value);

        if (normalizado !== campo.value) {
            campo.value = normalizado;
        }
    };

    document.addEventListener('shown.bs.modal', function (evento) {
        const modal = evento.target;

        if (!(modal instanceof HTMLElement)) {
            return;
        }

        window.setTimeout(function () {
            normalizarModal(modal);
        }, 0);
    });

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-mail-save]');

        if (!boton) {
            return;
        }

        normalizarModal(boton.closest('#modalBorradorCorreoOficio'));
    }, true);
})();
