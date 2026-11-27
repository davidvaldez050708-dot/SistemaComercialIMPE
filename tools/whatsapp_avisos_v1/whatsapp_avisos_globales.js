/* Global read-only inbox preview. Never starts a call, sends or marks read. */
(()=>{'use strict';
 const root=document.querySelector('[data-wa-global]');if(!root || root.dataset.ready)return;root.dataset.ready='1';
 const button=root.querySelector('.wa-global-button'),count=root.querySelector('[data-wa-count]'),summary=root.querySelector('[data-wa-summary]'),list=root.querySelector('[data-wa-list]'),status=root.querySelector('[data-wa-status]');
 const url=new URL('index.php',new URL(root.dataset.baseUrl,location.href));if(url.origin!==location.origin)return;
 url.searchParams.set('controller','whatsapp');url.searchParams.set('action','avisosGlobales');
 let timer=null,inflight=null,stopped=false,delay=15000,last='',epoch=0;
 const el=(tag,cls,value)=>{const node=document.createElement(tag);node.className=cls;if(value!==undefined)node.textContent=String(value);return node;};
 const clear=msg=>{count.hidden=true;count.textContent='';button.setAttribute('aria-label','WhatsApp');summary.textContent='Mensajes pendientes';list.replaceChildren();status.textContent=msg;last='';};
 const render=data=>{
   if(!Number.isInteger(data.total)||data.total<0||!Array.isArray(data.conversaciones))throw Error('invalid');
   const rows=data.conversaciones.slice(0,8);
   if(rows.some(r=>!Number.isSafeInteger(r.id)||r.id<=0||!Number.isInteger(r.no_leidos)||r.no_leidos<=0))throw Error('invalid row');
   count.hidden=data.total===0;count.textContent=data.total>99?'99+':String(data.total);
   button.setAttribute('aria-label',data.total?'WhatsApp: '+data.total+' conversaciones pendientes':'WhatsApp');
   summary.textContent=data.total===1?'1 conversación con mensajes no leídos':data.total+' conversaciones con mensajes no leídos';
   status.textContent=rows.length?'':'No tienes mensajes pendientes.';
   const signature=JSON.stringify(rows);if(signature===last)return;last=signature;
   const fragment=document.createDocumentFragment();
   for(const r of rows){
     const b=el('button','wa-global-row');b.type='button';b.dataset.whatsappConversation=String(r.id);
     const avatar=el('span','wa-global-avatar'),icon=el('i','bi bi-person');icon.setAttribute('aria-hidden','true');avatar.append(icon);
     const text=el('span','wa-global-text');text.append(el('strong','',r.nombre||'Contacto'),el('span','',r.mensaje||'Nuevo mensaje'));
     b.append(avatar,text,el('span','wa-global-unread',r.no_leidos>99?'99+':r.no_leidos));fragment.append(b);
   }list.replaceChildren(fragment);
 };
 const schedule=()=>{clearTimeout(timer);if(!stopped&&!document.hidden)timer=setTimeout(poll,delay);};
 async function poll(){
   if(stopped||document.hidden||inflight)return;clearTimeout(timer);
   const ctrl=new AbortController(),version=++epoch;inflight=ctrl;
   const timeout=setTimeout(()=>ctrl.abort(),8000);
   try{
     const res=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:ctrl.signal,headers:{'X-Requested-With':'fetch'}});
     if(version!==epoch)return;
     if(res.status===401||res.status===403){stopped=true;clear('Tu acceso cambió. Recarga la página.');return;}
     if(!res.ok)throw Error('http');
     const data=await res.json();if(version!==epoch)return;if(!data.ok)throw Error('response');
     render(data);delay=15000;
   }catch{if(version===epoch&&!document.hidden){clear('No se pudo actualizar WhatsApp.');delay=60000;}}
   finally{clearTimeout(timeout);if(inflight===ctrl)inflight=null;schedule();}
 }
 button.addEventListener('click',()=>{if(!stopped)poll();});
 list.addEventListener('click',e=>{const b=e.target.closest('[data-whatsapp-conversation]');if(b){try{window.bootstrap?.Dropdown.getInstance(button)?.hide();}catch{}}});
 document.addEventListener('visibilitychange',()=>{if(document.hidden){clearTimeout(timer);epoch++;inflight?.abort();}else poll();});
 window.addEventListener('pagehide',()=>{clearTimeout(timer);epoch++;inflight?.abort();});
 window.addEventListener('pageshow',()=>{if(!document.hidden)poll();});
 document.addEventListener('impe:whatsapp-chat-closed',()=>poll());
 poll();
})();
