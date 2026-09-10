(function () {
    'use strict';

    const rolId = Number(window.IMPE_CURRENT_ROLE_ID || 0);

    if (rolId !== 4) {
        return;
    }

    const normalizarTexto = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    };

    const nombrePareceZadarma = function (elemento) {
        if (!(elemento instanceof HTMLElement)) {
            return false;
        }

        const firma = [
            elemento.id || '',
            typeof elemento.className === 'string' ? elemento.className : ''
        ].join(' ');

        return /(zadarma|zdrm|webphone)/i.test(firma);
    };

    const inputPareceZadarma = function (elemento) {
        if (!(elemento instanceof HTMLInputElement)) {
            return false;
        }

        const placeholder = normalizarTexto(elemento.getAttribute('placeholder'));
        return placeholder.includes('introduce el numero') ||
            placeholder.includes('introducir el numero') ||
            placeholder.includes('numero de telefono');
    };

    const esContenedorProtegido = function (elemento) {
        return !elemento ||
            elemento === document.body ||
            elemento === document.documentElement ||
            elemento.matches('.admin-shell, main, #modalLlamadaVinculacion, .modal, .offcanvas');
    };

    const localizarRaizVisual = function (origen) {
        let actual = origen;
        let candidatoPorNombre = null;

        for (let nivel = 0; actual && nivel < 9; nivel += 1) {
            if (!(actual instanceof HTMLElement) || esContenedorProtegido(actual)) {
                break;
            }

            if (nombrePareceZadarma(actual)) {
                candidatoPorNombre = actual;
            }

            const estilo = window.getComputedStyle(actual);
            const rect = actual.getBoundingClientRect();
            const posicionFlotante = ['fixed', 'absolute', 'sticky'].includes(estilo.position);
            const dimensionesRazonables = rect.width >= 30 && rect.width <= 760 &&
                rect.height >= 25 && rect.height <= 520;

            if (posicionFlotante && dimensionesRazonables) {
                return actual;
            }

            actual = actual.parentElement;
        }

        if (candidatoPorNombre && !esContenedorProtegido(candidatoPorNombre)) {
            return candidatoPorNombre;
        }

        actual = origen instanceof HTMLElement ? origen.parentElement : null;
        for (let nivel = 0; actual && nivel < 5; nivel += 1) {
            if (esContenedorProtegido(actual)) {
                break;
            }

            const rect = actual.getBoundingClientRect();
            if (rect.width >= 30 && rect.width <= 760 && rect.height >= 25 && rect.height <= 520) {
                return actual;
            }

            actual = actual.parentElement;
        }

        return null;
    };

    const ocultarRaizVisual = function (raiz) {
        if (!(raiz instanceof HTMLElement) || esContenedorProtegido(raiz)) {
            return;
        }

        if (raiz.dataset.impeZadarmaNativeUiHidden === '1') {
            return;
        }

        raiz.dataset.impeZadarmaNativeUiHidden = '1';
        raiz.setAttribute('aria-hidden', 'true');

        // Se conserva el nodo activo para no afectar WebRTC, audio ni la API del
        // widget. Solo se saca su interfaz nativa de la zona visible porque el
        // sistema utiliza su propio modal institucional para operar la llamada.
        raiz.style.setProperty('position', 'fixed', 'important');
        raiz.style.setProperty('left', '-10000px', 'important');
        raiz.style.setProperty('top', '-10000px', 'important');
        raiz.style.setProperty('right', 'auto', 'important');
        raiz.style.setProperty('bottom', 'auto', 'important');
        raiz.style.setProperty('opacity', '0', 'important');
        raiz.style.setProperty('pointer-events', 'none', 'important');
        raiz.style.setProperty('z-index', '-1', 'important');
    };

    const ocultarWidgetNativo = function () {
        const candidatos = new Set();

        document.querySelectorAll(
            '[id*="zadarma" i], [class*="zadarma" i], ' +
            '[id*="zdrm" i], [class*="zdrm" i], ' +
            '[id*="webphone" i], [class*="webphone" i]'
        ).forEach(function (elemento) {
            if (elemento instanceof HTMLElement) {
                candidatos.add(elemento);
            }
        });

        document.querySelectorAll('input[placeholder]').forEach(function (input) {
            if (inputPareceZadarma(input)) {
                candidatos.add(input);
            }
        });

        candidatos.forEach(function (candidato) {
            const raiz = localizarRaizVisual(candidato);
            if (raiz) {
                ocultarRaizVisual(raiz);
            }
        });
    };

    const iniciar = function () {
        ocultarWidgetNativo();

        if (!window.MutationObserver) {
            return;
        }

        let pendiente = false;
        const observer = new MutationObserver(function () {
            if (pendiente) {
                return;
            }

            pendiente = true;
            window.requestAnimationFrame(function () {
                pendiente = false;
                ocultarWidgetNativo();
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
