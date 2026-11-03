(function () {
    'use strict';

    var root = document.documentElement;
    var script = document.currentScript;
    var user = script ? script.getAttribute('data-sidebar-user') : '';
    var key = 'sistema-comercial:sidebar-desktop:' + (user || '0');
    var media = window.matchMedia ? window.matchMedia('(min-width: 992px)') : null;
    var sidebar = document.querySelector('.admin-sidebar-fixed');
    var mobile = document.getElementById('mobileSidebar');
    var collapsed = false;

    // No activar el control de escritorio fuera de un layout con menú fijo.
    if (!sidebar) return;
    if (!sidebar.id) sidebar.id = 'desktopSidebar';
    try { collapsed = window.localStorage.getItem(key) === 'collapsed'; }
    catch (_) { /* La navegación también funciona sin almacenamiento disponible. */ }

    function desktop() {
        return media ? media.matches : window.innerWidth >= 992;
    }

    function button() {
        return document.querySelector('.admin-topbar [data-sidebar-toggle]');
    }

    function notifyLayout() {
        // Los gráficos que escuchan resize recalculan su espacio, sin recargar
        // el CRM ni el iframe independiente del teléfono.
        window.requestAnimationFrame(function () {
            window.dispatchEvent(new Event('resize'));
        });
    }

    function render() {
        var wide = desktop();
        var trigger = button();
        var open = wide ? !collapsed : Boolean(mobile && mobile.classList.contains('show'));
        root.classList.toggle('sidebar-desktop-collapsed', wide && collapsed);
        sidebar.setAttribute('aria-hidden', wide && !collapsed ? 'false' : 'true');
        if (!trigger) return;
        trigger.setAttribute('aria-controls', wide ? sidebar.id : 'mobileSidebar');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        trigger.setAttribute('aria-label', open ? 'Cerrar navegación' : 'Abrir navegación');
        trigger.setAttribute('title', open ? 'Cerrar navegación' : 'Abrir navegación');
        if ((!wide || collapsed) && sidebar.contains(document.activeElement)) {
            trigger.focus();
        }
    }

    // Captura el clic de escritorio antes del manejador delegado de Bootstrap.
    // En móvil se conserva intacto su data-bs-toggle="offcanvas".
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-sidebar-toggle]') : null;
        if (!trigger || !trigger.closest('.admin-topbar') || !desktop()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        collapsed = !collapsed;
        try { window.localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded'); }
        catch (_) { /* Mantener el estado de esta página cuando se bloquea storage. */ }
        render();
        notifyLayout();
    }, true);

    function modeChanged() {
        // Un offcanvas abierto en celular no debe dejar backdrop ni bloquear
        // el desplazamiento al ampliar la ventana de vuelta a escritorio.
        if (desktop() && mobile && window.bootstrap && window.bootstrap.Offcanvas) {
            var instance = window.bootstrap.Offcanvas.getInstance(mobile);
            if (instance) instance.hide();
        }
        render();
    }

    if (media && media.addEventListener) media.addEventListener('change', modeChanged);
    else if (media && media.addListener) media.addListener(modeChanged);
    else window.addEventListener('resize', modeChanged);

    if (mobile) {
        mobile.addEventListener('shown.bs.offcanvas', render);
        mobile.addEventListener('hidden.bs.offcanvas', render);
    }
    window.addEventListener('storage', function (event) {
        if (event.key !== key && event.key !== null) return;
        try { collapsed = window.localStorage.getItem(key) === 'collapsed'; }
        catch (_) { return; }
        render();
        notifyLayout();
    });
    window.addEventListener('pageshow', function () {
        try { collapsed = window.localStorage.getItem(key) === 'collapsed'; }
        catch (_) { /* Conservar el estado de esta página. */ }
        modeChanged();
    });

    // Se ejecuta antes del contenido principal para restaurar el ancho inicial.
    root.classList.add('sidebar-desktop-enabled');
    render();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
    else render();
})();
