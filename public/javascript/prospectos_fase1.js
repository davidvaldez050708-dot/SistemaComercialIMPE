/* Sólo búsqueda del catálogo de estados; los prospectos se filtran en PHP. */
(() => {
    'use strict';
    const init = () => {
        const root=document.querySelector('[data-prospectos-modulo]');
        if(!root)return;
        const input=root.querySelector('[data-prospectos-state-search]');
        const cards=[...root.querySelectorAll('[data-prospectos-state]')];
        const empty=root.querySelector('[data-prospectos-state-empty]');
        const counter=root.querySelector('[data-prospectos-state-counter]');
        const normalize=value=>String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('es').trim();
        const filter=()=>{
            let total=0;const term=normalize(input?.value||'');
            for(const card of cards){card.hidden=!normalize(card.dataset.prospectosState).includes(term);if(!card.hidden)total++;}
            if(empty)empty.hidden=total>0;
            if(counter)counter.textContent=total+' '+(total===1?'estado':'estados');
        };
        input?.addEventListener('input',filter);
        root.querySelector('[data-prospectos-state-search-form]')?.addEventListener('submit',event=>{event.preventDefault();filter();});
        if(input)filter();

        // La tabla consulta PHP sin recargar ni filtrar únicamente las filas visibles.
        const form=root.querySelector('[data-prospectos-filtros]');
        const results=root.querySelector('[data-prospectos-resultados]');
        if(!form||!results)return;
        const search=form.elements.namedItem('buscar');
        const ally=form.elements.namedItem('aliado_id');
        const error=root.querySelector('[data-prospectos-search-error]');
        const status=root.querySelector('[data-prospectos-search-status]');
        const total=root.querySelector('.prospectos-total strong');
        let timer,controller,sequence=0,composing=false;
        const invalidate=()=>{
            clearTimeout(timer);
            controller?.abort();
            sequence++;
        };
        const update=async()=>{
            invalidate();
            const current=sequence;
            controller=new AbortController();
            const url=new URL(form.getAttribute('action')||location.href,location.href);
            url.search=new URLSearchParams(new FormData(form)).toString();
            url.searchParams.delete('pagina');
            error.hidden=true;
            results.setAttribute('aria-busy','true');
            status.textContent='Actualizando resultados';
            try{
                const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
                if(current!==sequence)return;
                // Una sesión caducada o un permiso revocado deben mostrar su pantalla real.
                if(response.redirected||response.status===401||response.status===403){
                    const destination=new URL(response.url);
                    if(destination.origin===location.origin)location.assign(destination.href);
                    else throw new Error('Respuesta no válida');
                    return;
                }
                if(!response.ok)throw new Error('No se pudo consultar');
                const doc=new DOMParser().parseFromString(await response.text(),'text/html');
                if(current!==sequence)return;
                const next=doc.querySelector('[data-prospectos-resultados]');
                const count=doc.querySelector('.prospectos-total strong');
                if(!next||!count)throw new Error('Listado no disponible');
                // Se conserva el formulario para no perder foco, selección ni lo que se escribe.
                results.replaceChildren(...next.childNodes);
                total.textContent=count.textContent;
                history.replaceState(null,'',url.href);
                status.textContent=count.textContent+' prospectos encontrados';
            }catch(cause){
                if(current!==sequence||cause.name==='AbortError')return;
                error.textContent='No fue posible actualizar los resultados. Presiona Enter para reintentar.';
                error.hidden=false;
                status.textContent='La búsqueda no pudo actualizarse';
            }finally{
                if(current===sequence)results.removeAttribute('aria-busy');
            }
        };
        const schedule=()=>{
            invalidate();
            results.removeAttribute('aria-busy');
            if(!composing)timer=setTimeout(update,350);
        };
        search.addEventListener('compositionstart',()=>{composing=true;invalidate();});
        search.addEventListener('compositionend',()=>{composing=false;schedule();});
        search.addEventListener('input',schedule);
        ally.addEventListener('change',update);
        form.addEventListener('submit',event=>{event.preventDefault();update();});
        // Evita que una respuesta pendiente cambie la tabla al volver desde la caché.
        window.addEventListener('pagehide',()=>{invalidate();results.removeAttribute('aria-busy');});
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
