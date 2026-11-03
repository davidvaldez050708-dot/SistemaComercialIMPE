(function () {
    'use strict';
    var form=document.querySelector('[data-central-filtros]');
    if (!form || !window.fetch || !window.AbortController) return;
    var timer, controller, revision=0;
    var status=document.querySelector('[data-central-status]'), error=document.querySelector('[data-central-error]');
    function load(url,push) {
        var current=++revision;
        if (controller) controller.abort();
        controller=new AbortController();status.textContent='Consultando prospectos…';error.hidden=true;
        document.querySelector('[data-central-results]').setAttribute('aria-busy','true');
        fetch(url,{credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function (r) {if (!r.ok) throw new Error('consulta');return r.text();})
            .then(function (html) {
                if (current!==revision) return;
                var doc=new DOMParser().parseFromString(html,'text/html'), next=doc.querySelector('[data-central-results]');
                if (!next || doc.querySelector('.central-alert:not([hidden])')) throw new Error('respuesta');
                document.querySelector('[data-central-results]').replaceWith(next);
                if (push) history.pushState(null,'',url);
                status.textContent='Reporte de prospectos actualizado.';
            }).catch(function (e) {
                if (e.name==='AbortError'||current!==revision) return;
                error.textContent='No se pudo actualizar el listado. Intenta nuevamente.';error.hidden=false;status.textContent='';
            }).finally(function () {if (current===revision) document.querySelector('[data-central-results]').removeAttribute('aria-busy');});
    }
    function submit() {var url=new URL(form.getAttribute('action')||location.href,location.href);url.search=new URLSearchParams(new FormData(form)).toString();load(url.toString(),true);}
    form.addEventListener('submit',function (e) {e.preventDefault();clearTimeout(timer);submit();});
    form.addEventListener('input',function (e) {if (e.target.type!=='search') return;clearTimeout(timer);timer=setTimeout(submit,350);});
    form.addEventListener('change',function (e) {if (e.target.tagName==='SELECT') {clearTimeout(timer);submit();}});
    document.addEventListener('click',function (e) {var a=e.target.closest('[data-central-results] .central-pagination a');if (!a||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey) return;e.preventDefault();clearTimeout(timer);load(a.href,true);});
    addEventListener('popstate',function () {location.reload();});
})();
