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
        // Mejora local sobre el select nativo: lista acotada, búsqueda y teclado.
        const searchableSelect=select=>{
            const wrapper=document.createElement('div');wrapper.className='uniform-select uniform-select-form prospectos-searchable';
            const trigger=document.createElement('button');trigger.type='button';trigger.className='uniform-select-trigger';trigger.dataset.uniformSelectTrigger='';
            trigger.setAttribute('role','combobox');trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');
            const label=document.createElement('span');label.dataset.prospectosSelectorLabel='';label.id=select.id+'Selected';
            trigger.setAttribute('aria-labelledby',select.id+'Label '+label.id);
            const icon=document.createElement('i');icon.className='bi bi-chevron-down';icon.setAttribute('aria-hidden','true');trigger.append(label,icon);
            const menu=document.createElement('div');menu.className='uniform-select-menu';
            const searchWrap=document.createElement('div');searchWrap.className='prospectos-selector-search';
            const field=document.createElement('input');field.type='search';field.className='form-control';field.placeholder=select.dataset.prospectosSearchable==='aliado'?'Buscar aliado…':'Buscar programa…';field.setAttribute('aria-label',field.placeholder.replace('…',''));searchWrap.append(field);
            const list=document.createElement('div');list.className='prospectos-selector-options';list.id=select.id+'Options';list.setAttribute('role','listbox');list.setAttribute('aria-labelledby',select.id+'Label');trigger.setAttribute('aria-controls',list.id);
            const note=document.createElement('p');note.className='prospectos-selector-note';note.setAttribute('role','status');menu.append(searchWrap,list,note);wrapper.append(trigger,menu);
            select.after(wrapper);select.classList.add('uniform-select-native');select.tabIndex=-1;select.setAttribute('aria-hidden','true');
            const close=(focus=false)=>{wrapper.classList.remove('is-open');trigger.setAttribute('aria-expanded','false');if(focus)trigger.focus();};
            const sync=()=>{label.textContent=select.selectedOptions[0]?.textContent||'';trigger.disabled=select.disabled;};
            const render=()=>{
                const matches=[...select.options].filter(option=>!option.hidden&&!option.disabled&&normalize(option.textContent).includes(normalize(field.value)));
                list.replaceChildren();
                for(const option of matches.slice(0,40)){
                    const item=document.createElement('button');item.type='button';item.className='uniform-select-option';item.setAttribute('role','option');item.setAttribute('aria-selected',String(option.selected));item.classList.toggle('is-selected',option.selected);item.textContent=option.textContent;
                    item.addEventListener('click',()=>{const changed=select.value!==option.value;select.value=option.value;sync();close(true);if(changed)select.dispatchEvent(new Event('change',{bubbles:true}));});list.append(item);
                }
                note.textContent=matches.length>40?'Se muestran 40 de '+matches.length+'. Escribe para localizar otra opción.':matches.length===0?'No se encontraron opciones.':'';note.hidden=!note.textContent;
            };
            trigger.addEventListener('click',()=>{
                if(wrapper.classList.contains('is-open')){close();return;}
                root.querySelectorAll('.prospectos-searchable.is-open').forEach(other=>{other.classList.remove('is-open');other.querySelector('button').setAttribute('aria-expanded','false');});
                wrapper.classList.add('is-open');trigger.setAttribute('aria-expanded','true');field.value='';render();field.focus();
            });
            field.addEventListener('input',render);
            document.getElementById(select.id+'Label')?.addEventListener('click',event=>{event.preventDefault();trigger.focus();});
            trigger.addEventListener('keydown',event=>{if(['ArrowDown','ArrowUp'].includes(event.key)&&!wrapper.classList.contains('is-open')){event.preventDefault();trigger.click();}});
            wrapper.addEventListener('keydown',event=>{
                if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close(true);return;}
                const items=[...list.querySelectorAll('button')];
                if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)&&wrapper.classList.contains('is-open')){
                    event.preventDefault();const index=items.indexOf(document.activeElement);
                    const next=event.key==='Home'?0:event.key==='End'?items.length-1:event.key==='ArrowDown'?Math.min(index+1,items.length-1):Math.max(index-1,0);items[next]?.focus();
                }
                if(event.key==='Enter'&&event.target===field){event.preventDefault();items[0]?.click();}
            });
            wrapper.addEventListener('focusout',event=>{if(event.relatedTarget&&wrapper.contains(event.relatedTarget))return;setTimeout(()=>{if(!wrapper.contains(document.activeElement))close();},0);});
            document.addEventListener('click',event=>{if(!wrapper.contains(event.target))close();});
            select.addEventListener('change',sync);sync();return {sync};
        };
        const search=form.elements.namedItem('buscar');
        const ally=form.elements.namedItem('aliado_id');
        const program=form.elements.namedItem('programa');
        const allyWidget=searchableSelect(ally),programWidget=searchableSelect(program);
        const clear=root.querySelector('[data-prospectos-limpiar]');
        const filterTotal=root.querySelector('[data-prospectos-filter-total]');
        const searching=root.querySelector('[data-prospectos-searching]');
        const programError=root.querySelector('[data-prospectos-program-error]');
        const active=()=>{clear.hidden=!search.value.trim()&&!ally.value&&!program.value;};
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
            status.textContent='Buscando prospectos';searching.hidden=false;active();
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
                const nextProgram=doc.querySelector('#prospectoPrograma');
                const nextProgramError=doc.querySelector('[data-prospectos-program-error]');
                if(!next||!count||!nextProgram)throw new Error('Listado no disponible');
                // Se conserva el formulario para no perder foco, selección ni lo que se escribe.
                results.replaceChildren(...next.childNodes);
                total.textContent=count.textContent;filterTotal.textContent=count.textContent;
                program.replaceChildren(...[...nextProgram.options].map(option=>option.cloneNode(true)));
                program.value=nextProgram.value;program.disabled=nextProgram.disabled;programWidget.sync();
                programError.textContent=nextProgramError?.textContent||'';programError.hidden=nextProgramError?.hidden!==false;
                if(program.value)url.searchParams.set('programa',program.value);else url.searchParams.delete('programa');
                active();
                history.replaceState(null,'',url.href);
                status.textContent=count.textContent+' prospectos encontrados';
            }catch(cause){
                if(current!==sequence||cause.name==='AbortError')return;
                error.textContent='No fue posible actualizar los resultados. Presiona Enter para reintentar.';
                error.hidden=false;
                status.textContent='La búsqueda no pudo actualizarse';
            }finally{
                if(current===sequence){results.removeAttribute('aria-busy');searching.hidden=true;}
            }
        };
        const schedule=()=>{
            invalidate();
            results.removeAttribute('aria-busy');
            searching.hidden=true;active();
            if(!composing)timer=setTimeout(update,350);
        };
        search.addEventListener('compositionstart',()=>{composing=true;invalidate();});
        search.addEventListener('compositionend',()=>{composing=false;schedule();});
        search.addEventListener('input',schedule);
        search.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.isComposing){event.preventDefault();update();}});
        ally.addEventListener('change',update);
        program.addEventListener('change',update);
        clear.addEventListener('click',event=>{event.preventDefault();search.value='';ally.value='';program.value='';allyWidget.sync();programWidget.sync();search.focus();update();});
        form.addEventListener('submit',event=>{event.preventDefault();update();});
        // Evita que una respuesta pendiente cambie la tabla al volver desde la caché.
        window.addEventListener('pagehide',()=>{invalidate();results.removeAttribute('aria-busy');searching.hidden=true;});
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
