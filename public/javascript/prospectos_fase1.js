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

    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
