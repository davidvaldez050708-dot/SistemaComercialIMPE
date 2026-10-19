/* Gestión comercial sobre el mismo teléfono persistente del CRM. */
(()=>{'use strict';const root=document.querySelector('[data-sales-callbacks]');if(!root)return;
 const status=root.querySelector('[data-sales-callback-status]'),key='impe:sales-callback:'+Number(window.IMPE_CURRENT_USER_ID);
 let busy=false,timer=null,stopped=false;
 const say=(message,error=false)=>{status.textContent=message;if(error)window.IMPE_TELEPHONY_NOTIFY?.(message,true);};
 async function api(fields){const url=new URL(root.dataset.callbackUrl,location.href);let options={credentials:'same-origin',cache:'no-store'};
  if(fields.accion){options.method='POST';options.body=new URLSearchParams({...fields,csrf_token:root.dataset.csrf});}else for(const[k,v]of Object.entries(fields))url.searchParams.set(k,v);
  const response=await fetch(url,options),data=await response.json();if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo consultar la devolución.');return data;
 }
 function forget(){try{sessionStorage.removeItem(key);}catch(_){}clearTimeout(timer);}
 async function review(id,automatic=false){try{
  const {intento:a}=await api({intento_id:id});if(stopped)return;
  if(a.estado==='CANCELADO'){forget();return;}
  if(a.llamada?.registro){forget();if(!automatic)location.reload();return;}
  if(a.llamada?.finalizada){forget();const url=new URL('index.php?controller=telefonia&action=misLlamadas',document.baseURI);url.searchParams.set('pbx_call_id',a.pbx_call_id);url.searchParams.set('extension',a.extension);location.assign(url.href);return;}
  say(a.ambigua?'Hay varias marcaciones posibles. Revisa el historial; no se asociará una llamada por su número solamente.':a.pbx_call_id?'Llamada en curso. Su resultado se abrirá al confirmarse el cierre.':'Esperando la referencia de la centralita. Puedes revisar el intento desde esta bandeja.');
  if(automatic&&Date.now()-Date.parse(a.created_at.replace(' ','T')+'Z')<180000)timer=setTimeout(()=>void review(id,true),4000);
 }catch(error){say(error.message,true);}}
 root.addEventListener('click',async event=>{const start=event.target.closest('[data-sales-callback-start]'),cancel=event.target.closest('[data-sales-callback-cancel]'),check=event.target.closest('[data-sales-callback-review]');
  if(check){void review(check.dataset.salesCallbackReview);return;}if((!start&&!cancel)||busy)return;
  const button=start||cancel;busy=true;button.disabled=true;let prepared=null;
  try{if(cancel){await api({accion:'CANCELAR',intento_id:cancel.dataset.salesCallbackCancel});forget();location.reload();return;}
   const phone=window.IMPE_TELEPHONY_PERSISTENT;if(!phone||!(window.parent?.IMPE_TELEPHONY_SHELL?.isPhoneReady?.()??phone.getState?.().hostReady))throw Error('Conecta tu teléfono antes de llamar.');
   if(phone.getState?.().active||window.parent?.IMPE_TELEPHONY_SHELL?.getPhoneStatus?.().pending)throw Error('Termina o registra tu llamada anterior.');
   const data=await api({accion:'PREPARAR',solicitud_id:start.dataset.salesCallbackStart});prepared=data.intento;
   try{sessionStorage.setItem(key,String(prepared.id));}catch(_){}
   await phone.startCall({destination:prepared.numero,institution:start.dataset.name||'',context:{type:'DIALER',venta_devolucion:Number(prepared.id)}});
   say('Marcación enviada. El estado y el resultado dependen de la llamada real.');timer=setTimeout(()=>void review(prepared.id,true),3000);
  }catch(error){say(error.message,true);if(prepared){try{await api({accion:'CANCELAR',intento_id:prepared.id});forget();}catch(_){say('Revisa el intento preparado antes de volver a llamar.',true);}}}
  finally{busy=false;button.disabled=false;}
 });
 window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);});
 try{const id=Number(sessionStorage.getItem(key));if(id>0)void review(id,true);}catch(_){}
})();
