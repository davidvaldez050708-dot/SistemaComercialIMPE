(function () {
    'use strict';

    const STORAGE_PREFIX = 'sistema-comercial:sidebar-scroll:';
    const SAVE_DELAY = 80;

    const storageDisponible = function () {
        try {
            const testKey = STORAGE_PREFIX + 'test';
            window.sessionStorage.setItem(testKey, '1');
            window.sessionStorage.removeItem(testKey);
            return true;
        } catch (error) {
            return false;
        }
    };

    const puedeGuardar = storageDisponible();

    const clave = function (nav) {
        const tipo =
            nav.getAttribute('data-sidebar-scroll') || 'sidebar';
        const usuario =
            nav.getAttribute('data-sidebar-user') || '0';

        return STORAGE_PREFIX + usuario + ':' + tipo;
    };

    const guardar = function (nav) {
        if (!puedeGuardar || !nav) {
            return;
        }

        try {
            window.sessionStorage.setItem(
                clave(nav),
                String(Math.max(0, Math.round(nav.scrollTop || 0)))
            );
        } catch (error) {
            // La persistencia del scroll nunca debe bloquear la navegación.
        }
    };

    const restaurar = function (nav) {
        if (!puedeGuardar || !nav) {
            return;
        }

        let guardado = null;

        try {
            guardado = window.sessionStorage.getItem(clave(nav));
        } catch (error) {
            return;
        }

        if (guardado === null || guardado === '') {
            return;
        }

        const posicion = Number.parseInt(guardado, 10);
        if (!Number.isFinite(posicion) || posicion < 0) {
            return;
        }

        const aplicar = function () {
            const maximo = Math.max(
                0,
                nav.scrollHeight - nav.clientHeight
            );

            nav.scrollTop = Math.min(posicion, maximo);
        };

        // Espera a que el navegador calcule por completo el alto del menú.
        window.requestAnimationFrame(function () {
            aplicar();
            window.requestAnimationFrame(aplicar);
        });
    };

    const iniciar = function () {
        const navegaciones = Array.from(
            document.querySelectorAll(
                '.sidebar-navigation[data-sidebar-scroll]'
            )
        );

        if (navegaciones.length === 0) {
            return;
        }

        navegaciones.forEach(function (nav) {
            let temporizador = 0;

            restaurar(nav);

            nav.addEventListener(
                'scroll',
                function () {
                    window.clearTimeout(temporizador);
                    temporizador = window.setTimeout(
                        function () {
                            guardar(nav);
                        },
                        SAVE_DELAY
                    );
                },
                { passive: true }
            );

            nav.addEventListener('click', function (event) {
                if (!event.target.closest('a[href]')) {
                    return;
                }

                // Guarda inmediatamente antes de abandonar el módulo.
                guardar(nav);
            });
        });

        window.addEventListener('pagehide', function () {
            navegaciones.forEach(guardar);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
