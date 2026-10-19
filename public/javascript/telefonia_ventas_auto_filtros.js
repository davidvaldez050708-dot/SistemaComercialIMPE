/* Filtros comerciales progresivos: búsqueda con debounce, select inmediato y sin botón. */
(()=>{'use strict';
 const form=document.querySelector('[data-sales-auto-filter]');
 if(!form)return;
 if(form.dataset.autoInitialized)return;form.dataset.autoInitialized='1';
 const search=form.querySelector('input[type="search"]');
 const focusKey='impe:sales-filter-focus:'+Number(window.IMPE_CURRENT_USER_ID||0)+':'+(form.elements.namedItem('vista')?.value||'historial');
 try{const saved=JSON.parse(sessionStorage.getItem(focusKey)||'null');sessionStorage.removeItem(focusKey);if(search&&saved&&Date.now()-saved.at<5000&&saved.url===location.pathname+location.search&&saved.value===search.value){search.focus({preventScroll:true});search.setSelectionRange(saved.start,saved.end);}}catch(_){}
 let timer=0,last='',composing=false;
 function go(){
   window.clearTimeout(timer);
   if(composing||!form.reportValidity())return;
   const from=form.elements.namedItem('desde'),until=form.elements.namedItem('hasta');
   if(from&&until&&from.value>until.value){from.setCustomValidity('Selecciona una fecha inicial anterior o igual a la final.');from.reportValidity();return;}
   // El campo oculto name=action del router eclipsa form.action en el DOM.
   const url=new URL(form.getAttribute('action')||location.href,location.href);
   const params=new URLSearchParams(new FormData(form));
   params.delete('pagina');
   url.search=params.toString();url.hash='';
   const target=url.pathname+'?'+url.searchParams.toString();
   if(target===last||target===location.pathname+location.search)return;
   last=target;
   if(document.activeElement===search)try{sessionStorage.setItem(focusKey,JSON.stringify({at:Date.now(),url:target,value:search.value,start:search.selectionStart,end:search.selectionEnd}));}catch(_){}
   location.assign(target);
 }
 form.addEventListener('submit',event=>{event.preventDefault();go();});
 form.addEventListener('input',event=>{
   if(['desde','hasta'].includes(event.target.name))form.elements.namedItem('desde')?.setCustomValidity('');
 });
 form.addEventListener('change',event=>{
   if(event.target!==search)go();
 });
 if(search){
   search.addEventListener('input',()=>{
     window.clearTimeout(timer);
     if(!composing)timer=window.setTimeout(go,450);
   });
   search.addEventListener('search',go);
   search.addEventListener('compositionstart',()=>{composing=true;clearTimeout(timer);});
   search.addEventListener('compositionend',()=>{composing=false;clearTimeout(timer);timer=setTimeout(go,450);});
   search.addEventListener('keydown',event=>{
     if(event.key==='Enter'){event.preventDefault();go();}
   });
 }
})();
