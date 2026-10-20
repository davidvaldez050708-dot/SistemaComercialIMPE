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
    if (!uid || !crm || !mask || !config.homeUrl || window.IMPE_SHELL_NAVIGATION_STARTED) return;
    window.IMPE_SHELL_NAVIGATION_STARTED=true;

    const home = new URL(String(config.homeUrl), window.location.href);
    const key = 'impe:crm:shell:last-route:' + uid;
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
    let loadedOnce = false;
    let failed = false;
    let navigating = false;
    let watchdog = null;

    const safeUrl = function (input) {
        try {
            const url = new URL(String(input), home.href);
            if (url.origin !== home.origin || url.pathname !== home.pathname ||
                url.username || url.password) return null;
            const controller = (url.searchParams.get('controller') || '').toLowerCase();
            const action = (url.searchParams.get('action') || '').toLowerCase();
            const salesHistory=config.salesResults===true&&controller==='telefonia'&&action==='misllamadas'&&
                ['', 'devoluciones','agenda'].includes(url.searchParams.get('vista')||'');
            if (controller === 'login' || (controller === 'telefonia'&&!salesHistory) ||
                action === 'logout' || action === 'cerrarsesion' ||
                action === 'iniciarsesion' || action === 'cambiarpassword') return null;
            if (!/^https?:$/.test(url.protocol)) return null;
            return url;
        } catch (_) {
            return null;
        }
    };

    const setRecovery = function (visible, text) {
        // Jamás mostrar un cargador en los cambios normales de módulo.
        // Esta capa se reserva EXCLUSIVAMENTE para una respuesta PHP
        // incompleta o una navegación realmente detenida.
        mask.hidden = !visible;
        mask.classList.toggle('is-error', visible);
        if (message) message.textContent = text || '';
        if (retry) retry.hidden = !visible;
    };
    const startNavigation = function (target) {
        const targetUrl = safeUrl(target);
        if (!targetUrl) return false;
        pendingUrl = targetUrl.href;
        navigationToken++;
        failed = false;
        navigating = true;
        return true;
    };
    const resetNavigation = function () {
        navigating = false;
        if (watchdog !== null) {
            window.clearTimeout(watchdog);
            watchdog = null;
        }
        setRecovery(false, '');
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
        if(config.salesResults===true&&valid.searchParams.get('controller')==='telefonia'){
            // F5 conserva bandeja y filtros, sin reabrir un formulario transitorio.
            for(const key of ['pbx_call_id','extension','detalle','intento_id'])valid.searchParams.delete(key);
        }
        currentUrl = valid.href;
        try { sessionStorage.setItem(key, valid.href); }
        catch (_) { /* permitido navegar sin almacenamiento */ }
    };
    const markFailure = function () {
        if (failed) return;
        failed = true;
        // Nunca redirigir a Inicio después de un fallo. Queda disponible
        // reintentar la misma URL, o volver manualmente por el CRM.
        navigating = false;
        setRecovery(true,
            'No se pudo abrir esta página. Puedes reintentar el mismo módulo.');
    };
    const ensureWatchdog = function () {
        if (watchdog !== null) window.clearTimeout(watchdog);
        const token = navigationToken;
        watchdog = window.setTimeout(function () {
            if (token !== navigationToken || !navigating) return;
            markFailure();
        }, 20000);
    };
    const loaded = function () {
        const loc = getFrameLocation();
        // about:blank es el documento transitorio inicial del iframe y
        // no indica un fallo de PHP; esperar la ruta autenticada real.
        if (loc?.protocol === 'about:') return;
        if (!loc || loc.origin !== home.origin) {
            markFailure();
            return;
        }

        const controller = (loc.searchParams.get('controller') || '').toLowerCase();
        const action = (loc.searchParams.get('action') || '').toLowerCase();
        // El shell.js original gestiona la salida a Login. Esta capa nunca
        // almacena la página de login como ubicación del Analista.
        if (controller === 'login' && action === 'mostrarlogin') {
            resetNavigation();
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
        resetNavigation();
        try {
            const doc = crm.contentDocument;
            // Captura antes del desmontaje de un documento PHP.
            doc.defaultView?.addEventListener('beforeunload', function () {
                // El navegador navega de forma nativa. Sólo observar
                // y preparar recuperación ante una carga fallida.
                // Conservar la URL objetivo detectada en el clic: el
                // documento saliente todavía informa su URL anterior.
                if (!navigating) startNavigation(currentUrl);
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
                // Algunos módulos cancelan el enlace para mostrar
                // un modal/AJAX. No dejar un temporizador activo entonces.
                const token = navigationToken;
                window.setTimeout(function () {
                    if (event.defaultPrevented && token === navigationToken) {
                        pendingUrl = currentUrl;
                        resetNavigation();
                    }
                }, 0);
            }, true);
        } catch (_) { /* el navegador puede limitar el acceso al frame */ }
    };

    crm.addEventListener('load', loaded);

    retry?.addEventListener('click', function () {
        const url = safeUrl(pendingUrl) || safeUrl(currentUrl) || home;
        startNavigation(url.href);
        setRecovery(false, '');
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
    setRecovery(false, '');
    startNavigation(initial.href);
    ensureWatchdog();
    crm.src = initial.href;

    // Si la pestaña vuelve desde BFCache, verificar qué documento quedó
    // realmente visible en vez de forzar Home o recargar el motor telefónico.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && loadedOnce && getFrameLocation()) loaded();
    });
})();
