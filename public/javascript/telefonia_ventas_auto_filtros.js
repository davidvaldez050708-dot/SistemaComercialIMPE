/* Filtros comerciales progresivos: búsqueda con debounce, select inmediato y sin botón. */
(()=>{'use strict';
 const form=document.querySelector('[data-sales-auto-filter]');
 if(!form)return;
 const search=form.querySelector('input[type="search"]');
 let timer=0,last='';
 function go(){
   window.clearTimeout(timer);
   const url=new URL(form.action||location.href,location.href);
   const params=new URLSearchParams(new FormData(form));
   params.delete('pagina');
   for(const [key,value] of params)url.searchParams.set(key,value);
   const target=url.pathname+'?'+url.searchParams.toString();
   if(target===last)return;
   last=target;
   location.assign(target);
 }
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
