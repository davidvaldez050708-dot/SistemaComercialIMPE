/* Filtros comerciales progresivos: búsqueda con debounce, select inmediato y sin botón. */
(()=>{'use strict';
 const form=document.querySelector('[data-embudo-v3-auto-filter]');
 if(!form)return;
 const search=form.querySelector('input[type="search"]');
 let timer=0,last='';
 function go(){
   window.clearTimeout(timer);
   const url=new URL(form.getAttribute('action')||location.href,location.href);
   const params=new URLSearchParams(new FormData(form));
   params.delete('pagina');
   for(const [key,value] of params)url.searchParams.set(key,value);
   const target=url.pathname+'?'+url.searchParams.toString();
   if(target===last || target===location.pathname+location.search)return;
   last=target;
   const tablero=window.IMPE_EMBUDO_V3;
   if(tablero?.actualizar){
     tablero.actualizar(target,true).then(ok=>{if(!ok && last===target)last='';}).catch(()=>{
       if(last===target)last='';
       tablero.avisar('No se pudo aplicar el filtro. La vista anterior se conserva.',undefined,true);
     });
   }else location.assign(target);
 }
 form.closest('.impe-embudo--v3-operativo')?.addEventListener('impe:embudo:v3:actualizado',()=>{last='';});
 form.addEventListener('change',event=>{
   if(event.target!==search)go();
 });
 if(search){
   search.addEventListener('input',()=>{
     window.clearTimeout(timer);
     timer=window.setTimeout(go,450);
   });
   search.addEventListener('search',go);
   search.addEventListener('keydown',event=>{
     if(event.key==='Enter'){event.preventDefault();go();}
   });
 }
})();
