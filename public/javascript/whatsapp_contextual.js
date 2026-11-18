/* Un único panel compartido. Abrir nunca envía ni completa gestiones. */
(()=>{'use strict';
 if(window.IMPE_WHATSAPP_CONTEXTUAL)return;
 const loader=document.querySelector('script[data-whatsapp-contextual]');
 if(!loader)return;
 window.IMPE_WHATSAPP_CONTEXTUAL=true;
 const endpoint=new URL('index.php',new URL(loader.dataset.baseUrl,location.href));
 endpoint.searchParams.set('controller','whatsapp');endpoint.searchParams.set('action','contexto');
 const dialog=document.createElement('dialog');dialog.className='wa-contextual-dialog';
 dialog.setAttribute('aria-labelledby','wa-contextual-title');
 dialog.innerHTML='<header class="wa-contextual-header"><h2 id="wa-contextual-title"><i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp</h2><button type="button" aria-label="Cerrar conversación">×</button></header><p class="wa-contextual-status" role="status"></p>';
 document.body.append(dialog);
 const status=dialog.querySelector('[role=status]');
 const drafts=new Map();let frame=null,version=0,trigger=null,current='';
 const guardar=()=>{try{const area=frame?.contentDocument?.querySelector('[data-whatsapp-message-input]');if(area)drafts.set(current,area.value);}catch{}};
 const limpiar=()=>{guardar();frame?.remove();frame=null;};
 dialog.querySelector('button').addEventListener('click',()=>dialog.close());
 dialog.addEventListener('close',()=>{if(dialog.open)return;version++;limpiar();trigger?.focus();});
 const escapar=event=>{if(event.key==='Escape' && dialog.open){event.preventDefault();dialog.close();}};
 document.addEventListener('keydown',escapar);
 document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-whatsapp-contexto]');if(!button || button.disabled)return;
  event.preventDefault();
  if(dialog.open && current===button.dataset.whatsappContexto+':'+button.dataset.whatsappId)return;
  limpiar();trigger=button;current=button.dataset.whatsappContexto+':'+button.dataset.whatsappId;
  const epoch=++version;status.textContent='Abriendo conversación…';
  if(!dialog.open)dialog.show();
  const body=new FormData();body.set('tipo',button.dataset.whatsappContexto);body.set('id',button.dataset.whatsappId);
  body.set('csrf_token',String(window.IMPE_CSRF_TOKEN||''));
  try{
   const response=await fetch(endpoint,{method:'POST',body,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});
   const data=await response.json();
   if(epoch!==version || !dialog.open)return;
   if(!response.ok || !data.ok)throw new Error(data.mensaje||'No fue posible abrir la conversación.');
   const url=new URL(data.url,location.href);
   if(url.origin!==location.origin || url.searchParams.get('controller')!=='whatsapp' || url.searchParams.get('action')!=='panel')throw new Error('El destino de la conversación no es válido.');
   status.textContent='';frame=document.createElement('iframe');frame.title='Chat de WhatsApp';
   frame.addEventListener('load',()=>{try{frame?.contentDocument?.addEventListener('keydown',escapar);const input=frame?.contentDocument?.querySelector('[data-whatsapp-message-input]');if(input && drafts.has(current))input.value=drafts.get(current);}catch{}});
   frame.src=url.href;dialog.append(frame);
  }catch(error){if(epoch===version && dialog.open)status.textContent=error.message||'No fue posible conectar. Intenta de nuevo.';}
 });
})();
