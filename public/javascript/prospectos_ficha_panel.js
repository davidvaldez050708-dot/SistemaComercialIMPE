(function () {
    'use strict';
    var capa=document.querySelector('[data-prospecto-ficha-capa]');
    if (!capa || !window.fetch || !window.AbortController || !window.DOMParser) return;
    var panel=capa.querySelector('[data-prospecto-ficha-panel]');
    var cuerpo=capa.querySelector('[data-prospecto-ficha-cuerpo]');
    var cerrar=capa.querySelector('.prospecto-ficha-cerrar');
    var anterior=null,seleccionado=null,peticion=null,revision=0,overflowAnterior='';
    var estado='closed',cierreTimer=null,aperturaFrame=null,paddingAnterior='';
    var selectorFocus='a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    function permitido(url) {
        try {
            var link=new URL(url,location.href);
            var params=link.searchParams;
            return link.origin===location.origin &&
                link.pathname===location.pathname &&
                params.get('controller')==='prospecto' &&
                (params.get('action')==='detalle'||params.get('action')==='detalleRegistro');
        } catch (e) {return false;}
    }
    function bloquearFondo() {
        overflowAnterior=document.body.style.overflow;
        paddingAnterior=document.body.style.paddingRight;
        var barra=Math.max(0,window.innerWidth-document.documentElement.clientWidth);
        if (barra) document.body.style.paddingRight=(parseFloat(getComputedStyle(document.body).paddingRight)||0)+barra+'px';
        document.body.style.overflow='hidden';
    }
    function desbloquearFondo() {
        document.body.style.overflow=overflowAnterior;
        document.body.style.paddingRight=paddingAnterior;
    }
    function mostrar() {
        if (estado==='open') return;
        clearTimeout(cierreTimer);
        if (capa.hidden) {
            anterior=document.activeElement;
            bloquearFondo();
            capa.hidden=false;
            // Fijar el estado inicial antes del frame de entrada. También
            // permite abrir/cerrar rápido sin otro backdrop ni otro panel.
            panel.getBoundingClientRect();
        }
        estado='open';
        aperturaFrame=requestAnimationFrame(function () {
            aperturaFrame=null;
            if (estado==='open') capa.classList.add('is-open');
        });
        panel.focus();
    }
    function terminarCierre() {
        if (estado!=='closing') return;
        clearTimeout(cierreTimer);
        capa.hidden=true;
        estado='closed';
        desbloquearFondo();
        if (anterior && anterior.isConnected && typeof anterior.focus==='function') anterior.focus();
        anterior=null;
    }
    function cerrarPanel() {
        if (estado!=='open') return;
        revision++;
        if (peticion) peticion.abort();
        peticion=null;
        if (aperturaFrame!==null) {cancelAnimationFrame(aperturaFrame);aperturaFrame=null;}
        estado='closing';
        capa.classList.remove('is-open');
        if (seleccionado) seleccionado.classList.remove('is-ficha-seleccionada');
        seleccionado=null;
        // El cierre espera únicamente su transición; una ficha tardía nunca
        // vuelve a abrirlo. Con movimiento reducido no hay espera artificial.
        if (!parseFloat(getComputedStyle(panel).transitionDuration)) terminarCierre();
        else cierreTimer=setTimeout(terminarCierre,350);
    }
    panel.addEventListener('transitionend',function (event) {
        if (event.target===panel && event.propertyName==='transform' &&
            panel.getBoundingClientRect().left>=capa.getBoundingClientRect().right-1) terminarCierre();
    });
    function aviso(mensaje) {
        cuerpo.replaceChildren();
        var p=document.createElement('p');
        p.className='prospecto-ficha-mensaje';
        p.setAttribute('role','status');
        p.textContent=mensaje;
        cuerpo.appendChild(p);
    }
    function abrir(url,origen) {
        if (!permitido(url)) return;
        mostrar();
        if (seleccionado) seleccionado.classList.remove('is-ficha-seleccionada');
        seleccionado=origen||null;
        if (seleccionado) seleccionado.classList.add('is-ficha-seleccionada');
        var actual=++revision;
        if (peticion) peticion.abort();
        peticion=new AbortController();
        aviso('Cargando ficha del prospecto…');
        var destino=new URL(url,location.href),ancla=destino.hash;
        destino.hash='';
        destino.searchParams.set('panel','1');
        fetch(destino.toString(),{
            credentials:'same-origin',
            signal:peticion.signal,
            headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html'}
        }).then(function (respuesta) {
            if (!respuesta.ok || !respuesta.url.startsWith(location.origin+'/')) throw new Error('HTTP');
            return respuesta.text();
        }).then(function (html) {
            if (actual!==revision || estado!=='open') return;
            var documento=new DOMParser().parseFromString(html,'text/html');
            var ficha=documento.querySelector('[data-prospecto-ficha-contenido]');
            if (!ficha || !ficha.querySelector('.prospecto-ficha-identidad')) throw new Error('Contenido');
            cuerpo.replaceChildren(document.importNode(ficha,true));
            cuerpo.scrollTop=0;
            if (ancla) {
                var destinoInterno=cuerpo.querySelector(ancla);
                if (destinoInterno) destinoInterno.scrollIntoView({block:'start'});
            }
            panel.focus({preventScroll:true});
        }).catch(function (e) {
            if (e.name==='AbortError' || actual!==revision || capa.hidden) return;
            aviso('No fue posible consultar la ficha. Puedes cerrar el panel e intentarlo nuevamente.');
        }).finally(function () {
            if (actual===revision) peticion=null;
        });
    }
    document.addEventListener('click',function (e) {
        var boton=e.target.closest('[data-prospecto-ficha-cerrar]');
        if (boton && !capa.hidden) {
            e.preventDefault();
            cerrarPanel();
            return;
        }
        if (e.defaultPrevented || e.button!==0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        var enlace=e.target.closest('a[href]');
        if (!enlace || enlace.hasAttribute('download') || enlace.target && enlace.target!=='_self') return;
        if (capa.hidden && !enlace.matches('[data-prospecto-ficha]')) return;
        if (!capa.hidden && !cuerpo.contains(enlace) && !enlace.matches('[data-prospecto-ficha]')) return;
        if (!permitido(enlace.href)) return;
        e.preventDefault();
        abrir(enlace.href, cuerpo.contains(enlace)?seleccionado:enlace);
    });
    document.addEventListener('keydown',function (e) {
        if (capa.hidden) return;
        if (e.key==='Escape') {e.preventDefault();cerrarPanel();return;}
        if (e.key!=='Tab') return;
        var botones=Array.prototype.filter.call(panel.querySelectorAll(selectorFocus),function (el) {
            return el.getClientRects().length>0;
        });
        if (!botones.length) {e.preventDefault();panel.focus();return;}
        var primero=botones[0],ultimo=botones[botones.length-1];
        if (e.shiftKey && (document.activeElement===primero || document.activeElement===panel)) {
            e.preventDefault();ultimo.focus();
        } else if (!e.shiftKey && (document.activeElement===ultimo || document.activeElement===panel && botones.length===0)) {
            e.preventDefault();primero.focus();
        }
    });
    // Al salir de la pestaña, no dejamos conexiones de fichas pendientes.
    addEventListener('pagehide',function () {
        if (peticion) peticion.abort();
    });
    // Sólo habilitar desplazamiento cuando el controlador está preparado.
    // Un documento con el script anterior puede seguir mostrando su ficha.
    capa.classList.add('has-motion');
})();
