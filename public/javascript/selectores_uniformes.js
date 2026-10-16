document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const SELECTOR =
        'select:not([multiple]):not([data-native-select]):not([data-uniform-select-ready])';

    const normalizarTexto = function (valor) {
        return String(valor == null ? '' : valor).trim();
    };

    const cerrar = function (wrapper) {
        if (!wrapper) {
            return;
        }

        wrapper.classList.remove('is-open');

        const trigger = wrapper.querySelector(
            '[data-uniform-select-trigger]'
        );

        trigger?.setAttribute('aria-expanded', 'false');
    };

    const cerrarTodosExcepto = function (actual) {
        document
            .querySelectorAll('.uniform-select.is-open')
            .forEach(function (wrapper) {
                if (wrapper !== actual) {
                    cerrar(wrapper);
                }
            });
    };

    const sincronizar = function (select, wrapper) {
        if (!select || !wrapper) {
            return;
        }

        const trigger = wrapper.querySelector(
            '[data-uniform-select-trigger]'
        );
        const label = wrapper.querySelector(
            '[data-uniform-select-label]'
        );
        const opciones = Array.from(
            wrapper.querySelectorAll(
                '[data-uniform-select-value]'
            )
        );

        const seleccionada = select.options[select.selectedIndex] || null;
        const texto = seleccionada
            ? normalizarTexto(seleccionada.textContent)
            : '';

        if (label) {
            label.textContent = texto;
        }

        if (trigger) {
            trigger.disabled = Boolean(select.disabled);
            trigger.classList.toggle(
                'is-placeholder',
                String(select.value || '') === ''
            );
        }

        opciones.forEach(function (boton) {
            const indice = Number(
                boton.dataset.uniformSelectIndex ?? -1
            );
            const activa = indice === select.selectedIndex;

            boton.classList.toggle('is-selected', activa);
            boton.setAttribute(
                'aria-selected',
                activa ? 'true' : 'false'
            );

            const opcionOriginal = select.options[indice];

            if (opcionOriginal) {
                boton.disabled = Boolean(opcionOriginal.disabled);
                boton.hidden = Boolean(opcionOriginal.hidden);
            }
        });
    };

    const construir = function (select) {
        if (
            !(select instanceof HTMLSelectElement) ||
            select.dataset.uniformSelectReady === '1' ||
            select.multiple ||
            Number(select.size || 0) > 1
        ) {
            return;
        }

        /*
         * El <select> original se conserva intacto.
         * Solo se oculta visualmente y la nueva capa refleja su mismo valor.
         */
        select.dataset.uniformSelectReady = '1';

        const wrapper = document.createElement('div');
        wrapper.className = 'uniform-select';

        if (select.classList.contains('form-select')) {
            wrapper.classList.add('uniform-select-form');
        }

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'uniform-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('data-uniform-select-trigger', '');

        const label = document.createElement('span');
        label.setAttribute('data-uniform-select-label', '');

        const icon = document.createElement('i');
        icon.className = 'bi bi-chevron-down';
        icon.setAttribute('aria-hidden', 'true');

        trigger.appendChild(label);
        trigger.appendChild(icon);

        const menu = document.createElement('div');
        menu.className = 'uniform-select-menu';
        menu.setAttribute('role', 'listbox');

        if (select.getAttribute('aria-label')) {
            menu.setAttribute(
                'aria-label',
                select.getAttribute('aria-label')
            );
        }

        Array.from(select.options).forEach(function (opcion, indice) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'uniform-select-option';
            boton.setAttribute('role', 'option');
            boton.setAttribute(
                'data-uniform-select-value',
                String(opcion.value ?? '')
            );
            boton.dataset.uniformSelectIndex = String(indice);
            boton.textContent = normalizarTexto(opcion.textContent);
            boton.disabled = Boolean(opcion.disabled);
            boton.hidden = Boolean(opcion.hidden);

            boton.addEventListener('click', function () {
                if (boton.disabled) {
                    return;
                }

                const nuevoIndice = Number(
                    boton.dataset.uniformSelectIndex ?? -1
                );

                if (
                    nuevoIndice < 0 ||
                    nuevoIndice >= select.options.length
                ) {
                    return;
                }

                const cambio = select.selectedIndex !== nuevoIndice;
                select.selectedIndex = nuevoIndice;

                sincronizar(select, wrapper);
                cerrar(wrapper);

                if (cambio) {
                    select.dispatchEvent(
                        new Event('change', { bubbles: true })
                    );
                }
            });

            menu.appendChild(boton);
        });

        /*
         * Mantener el select en su posición original garantiza que
         * name/value, validación y scripts existentes sigan operando.
         */
        select.classList.add('uniform-select-native');

        select.parentNode.insertBefore(wrapper, select.nextSibling);
        wrapper.appendChild(trigger);
        wrapper.appendChild(menu);

        trigger.addEventListener('click', function () {
            if (select.disabled) {
                return;
            }

            const abrir = !wrapper.classList.contains('is-open');

            cerrarTodosExcepto(wrapper);
            wrapper.classList.toggle('is-open', abrir);
            trigger.setAttribute(
                'aria-expanded',
                abrir ? 'true' : 'false'
            );

            if (abrir) {
                sincronizar(select, wrapper);
            }
        });

        select.addEventListener('change', function () {
            sincronizar(select, wrapper);
        });

        sincronizar(select, wrapper);
    };

    const iniciar = function (root) {
        const scope = root instanceof Element || root instanceof Document
            ? root
            : document;

        if (
            scope instanceof HTMLSelectElement &&
            scope.matches(SELECTOR)
        ) {
            construir(scope);
            return;
        }

        scope.querySelectorAll?.(SELECTOR).forEach(construir);
    };

    iniciar(document);

    /*
     * Solo aplica el mismo acabado visual a nuevos selects que otros
     * módulos agreguen dinámicamente. No altera sus opciones ni su lógica.
     */
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node instanceof Element) {
                    iniciar(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    document.addEventListener('click', function (event) {
        const wrapper = event.target.closest('.uniform-select');

        document
            .querySelectorAll('.uniform-select.is-open')
            .forEach(function (item) {
                if (item !== wrapper) {
                    cerrar(item);
                }
            });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document
                .querySelectorAll('.uniform-select.is-open')
                .forEach(cerrar);
        }
    });
});
