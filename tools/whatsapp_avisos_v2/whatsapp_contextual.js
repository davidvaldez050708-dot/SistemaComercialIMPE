/* Un único panel compartido. Abrir nunca envía ni completa gestiones. */
(()=>{'use strict';
 if(window.IMPE_WHATSAPP_CONTEXTUAL)return;
 const loader=document.querySelector('script[data-whatsapp-contextual]');
 if(!loader)return;
 window.IMPE_WHATSAPP_CONTEXTUAL=true;
 const endpoint=new URL('index.php',new URL(loader.dataset.baseUrl,location.href));if(endpoint.origin!==location.origin)return;
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
 dialog.addEventListener('close',()=>{if(dialog.open)return;version++;limpiar();(trigger?.isConnected?trigger:document.querySelector('[data-wa-button]'))?.focus();document.dispatchEvent(new Event('impe:whatsapp-chat-closed'));});
 const escapar=event=>{if(event.key==='Escape' && dialog.open){event.preventDefault();dialog.close();}};
 document.addEventListener('keydown',escapar);
 document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-whatsapp-contexto], [data-whatsapp-conversation]');if(!button || button.disabled)return;
  const direct=button.dataset.whatsappConversation||'';if(direct&&!/^[1-9][0-9]*$/.test(direct))return;
  event.preventDefault();
  const key=direct?'conversation:'+direct:button.dataset.whatsappContexto+':'+button.dataset.whatsappId;
  if(dialog.open && current===key)return;
  limpiar();trigger=button;current=key;
  const epoch=++version;status.textContent='Abriendo conversación…';
  if(!dialog.open)dialog.show();
  try{
   let data;
   if(direct){const dest=new URL(endpoint);dest.searchParams.set('action','panel');dest.searchParams.set('conversacion_id',direct);data={ok:true,url:dest.href,conversacion_id:Number(direct)};}
   else{const body=new FormData();body.set('tipo',button.dataset.whatsappContexto);body.set('id',button.dataset.whatsappId);body.set('csrf_token',String(window.IMPE_CSRF_TOKEN||''));
   const response=await fetch(endpoint,{method:'POST',body,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});data=await response.json();if(!response.ok)throw new Error(data.mensaje||'No fue posible abrir la conversación.');}
   if(epoch!==version || !dialog.open)return;
   if(!data.ok)throw new Error(data.mensaje||'No fue posible abrir la conversación.');
   const url=new URL(data.url,location.href);
   if(url.origin!==location.origin || url.searchParams.get('controller')!=='whatsapp' || url.searchParams.get('action')!=='panel')throw new Error('El destino de la conversación no es válido.');
   const canonical='conversation:'+String(data.conversacion_id||url.searchParams.get('conversacion_id'));current=canonical;
   status.textContent='';frame=document.createElement('iframe');frame.title='Chat de WhatsApp';
   const loaded=frame;frame.addEventListener('load',()=>{if(frame!==loaded||epoch!==version||!dialog.open)return;try{loaded.contentDocument?.addEventListener('keydown',escapar);const input=loaded.contentDocument?.querySelector('[data-whatsapp-message-input]');if(input && drafts.has(current))input.value=drafts.get(current);}catch{}});
   frame.src=url.href;dialog.append(frame);
  }catch(error){if(epoch===version && dialog.open)status.textContent=error.message||'No fue posible conectar. Intenta de nuevo.';}
 });
})();
