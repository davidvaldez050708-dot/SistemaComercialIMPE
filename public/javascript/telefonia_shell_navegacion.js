/* Navegación estable dentro del shell telefónico.
 * No intercepta acciones ni reemplaza el router PHP: observa sólo navegaciones
 * reales del iframe del CRM y mantiene el motor WebRTC en su propio iframe.
 */
(function () {
    'use strict';

    const config = window.IMPE_PHONE_SHELL_CONFIG || {};
    const uid = Number(config.userId || 0);
    const crm = document.getElementById('impeCrmFrame');
    const mask = document.querySelector('[data-shell-navigation-mask]');
    const message = mask?.querySelector('[data-shell-navigation-message]');
    const retry = mask?.querySelector('[data-shell-navigation-retry]');
    if (!uid || !crm || !mask || !config.homeUrl) return;

    const home = new URL(String(config.homeUrl), window.location.href);
    const shell = new URL(window.location.href);
    const key = 'impe:crm:shell:last-route:' + uid;
    const loginUrl = new URL(String(config.loginUrl || config.homeUrl), window.location.href);
    const isReload = (function () {
        try {
            return window.performance?.getEntriesByType('navigation')[0]?.type === 'reload';
        } catch (_) {
            return false;
        }
    })();
    let currentUrl = home.href;
    let pendingUrl = home.href;
    let navigationToken = 0;
    let loadingSince = Date.now();
    let loadedOnce = false;
    let failed = false;
    let watchdog = null;

    const safeUrl = function (input) {
        try {
            const url = new URL(String(input), home.href);
            if (url.origin !== home.origin || url.pathname !== home.pathname ||
                url.username || url.password) return null;
            const controller = (url.searchParams.get('controller') || '').toLowerCase();
            const action = (url.searchParams.get('action') || '').toLowerCase();
            if (controller === 'login' || controller === 'telefonia' ||
                action === 'logout' || action === 'cerrarsesion' ||
                action === 'iniciarsesion' || action === 'cambiarpassword') return null;
            if (!/^https?:$/.test(url.protocol)) return null;
            return url;
        } catch (_) {
            return null;
        }
    };

    const setMask = function (visible, text, error) {
        mask.hidden = !visible;
        mask.classList.toggle('is-error', Boolean(error));
        if (message) message.textContent = text || 'Abriendo módulo…';
        if (retry) retry.hidden = !error;
    };
    const startNavigation = function (target) {
        const targetUrl = safeUrl(target);
        if (!targetUrl) return;
        pendingUrl = targetUrl.href;
        navigationToken++;
        loadingSince = Date.now();
        failed = false;
        setMask(true, 'Cargando módulo…', false);
    };
    const getFrameLocation = function () {
        try {
            return new URL(crm.contentWindow.location.href);
        } catch (_) {
            return null;
        }
    };
    const saveRoute = function (url) {
        const valid = safeUrl(url);
        if (!valid) return;
        currentUrl = valid.href;
        try { sessionStorage.setItem(key, valid.href); }
        catch (_) { /* permitido navegar sin almacenamiento */ }
    };
    const markFailure = function () {
        if (failed) return;
        failed = true;
        // Nunca redirigir a Inicio después de un fallo. Queda disponible
        // reintentar la misma URL, o volver manualmente por el CRM.
        setMask(true,
            'Esta página no terminó de cargar. Puedes intentar abrir de nuevo el mismo módulo.',
            true);
    };
    const ensureWatchdog = function () {
        if (watchdog !== null) window.clearTimeout(watchdog);
        const token = navigationToken;
        watchdog = window.setTimeout(function () {
            if (token !== navigationToken || mask.hidden) return;
            markFailure();
        }, 20000);
    };
    const loaded = function () {
        const loc = getFrameLocation();
        if (!loc || loc.origin !== home.origin) {
            markFailure();
            return;
        }

        const controller = (loc.searchParams.get('controller') || '').toLowerCase();
        const action = (loc.searchParams.get('action') || '').toLowerCase();
        // El shell.js original gestiona la salida a Login. Esta capa nunca
        // almacena la página de login como ubicación del Analista.
        if (controller === 'login' && action === 'mostrarlogin') {
            try { sessionStorage.removeItem(key); } catch (_) {}
            return;
        }

        let validDocument = false;
        try {
            const doc = crm.contentDocument;
            validDocument = Boolean(doc?.body &&
                doc.body.querySelector('.admin-shell'));
        } catch (_) { validDocument = false; }

        if (!validDocument) {
            // Puede tratarse de una respuesta PHP vacía/500. Conservar la
            // URL que falló y mostrar recuperación, sin ir a Home.
            pendingUrl = safeUrl(loc.href)?.href || pendingUrl;
            markFailure();
            return;
        }

        saveRoute(loc.href);
        pendingUrl = currentUrl;
        failed = false;
        loadedOnce = true;
        if (watchdog !== null) {
            window.clearTimeout(watchdog);
            watchdog = null;
        }
        setMask(false, '', false);
        try {
            const doc = crm.contentDocument;
            // Captura antes del desmontaje de un documento PHP.
            doc.defaultView?.addEventListener('beforeunload', function () {
                startNavigation(getFrameLocation()?.href || currentUrl);
                ensureWatchdog();
            });
            doc.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 ||
                    event.metaKey || event.ctrlKey || event.altKey ||
                    event.shiftKey) return;
                const link = event.target.closest?.('a[href]');
                if (!link || link.download ||
                    (link.target && !['_self', ''].includes(link.target))) return;
                const url = safeUrl(link.href);
                if (!url || url.href.split('#')[0] === loc.href.split('#')[0]) return;
                startNavigation(url.href);
                ensureWatchdog();
            }, true);
        } catch (_) { /* el navegador puede limitar el acceso al frame */ }
    };

    crm.addEventListener('load', loaded);

    retry?.addEventListener('click', function () {
        const url = safeUrl(pendingUrl) || safeUrl(currentUrl) || home;
        startNavigation(url.href);
        ensureWatchdog();
        crm.src = url.href;
    });

    let initial = home;
    if (isReload) {
        try {
            const stored = safeUrl(sessionStorage.getItem(key) || '');
            if (stored) initial = stored;
        } catch (_) {}
    } else {
        // Una sesión recién iniciada debe comenzar en Inicio, nunca en
        // un expediente antiguo guardado por otro acceso a la misma pestaña.
        try { sessionStorage.removeItem(key); } catch (_) {}
    }
    // Solo una carga inicial. El iframe se entrega sin src en PHP para
    // evitar un Home provisional cuando el navegador recarga Seguimiento.
    startNavigation(initial.href);
    ensureWatchdog();
    crm.src = initial.href;

    // Si la pestaña vuelve desde BFCache, verificar qué documento quedó
    // realmente visible en vez de forzar Home o recargar el motor telefónico.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && loadedOnce && getFrameLocation()) loaded();
    });
})();
