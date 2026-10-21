/* Captura comercial sobre el mismo teléfono persistente; nunca llama al SDK. */
(()=>{'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL,dialog=document.querySelector('[data-sales-prospect-dialog]');
 if(!cfg.salesResults||!phone||!dialog||dialog.dataset.initialized)return;dialog.dataset.initialized='1';
 const form=dialog.querySelector('form'),feedback=dialog.querySelector('[data-sales-prospect-feedback]');
 const notify=(message,error=false)=>{try{const crm=document.getElementById('impeCrmFrame')?.contentWindow;if(typeof crm?.IMPE_TELEPHONY_NOTIFY==='function'){crm.IMPE_TELEPHONY_NOTIFY(message,error);return;}}catch(_){}window.IMPE_TELEPHONY_NOTIFY?.(message,error);};
 const button=document.createElement('button');button.type='button';button.className='persistent-phone-action';button.textContent='Datos del prospecto';button.dataset.salesProspect='1';button.hidden=true;
 const actions=document.querySelector('[data-incoming-panel] .persistent-phone-actions'),close=actions?.querySelector('[data-incoming-close-finished]');
 if(close)actions.insertBefore(button,close);else actions?.append(button);
 let ref='',localId='',version=0,busy=false;const key=id=>'impe:sales-prospect:'+cfg.userId+':'+id;
 const valid=id=>/^[A-Za-z0-9_-]{6,100}$/.test(id||'');
 const request=async(post=false)=>{const url=new URL(cfg.salesProspectUrl,location.href);const values={pbx_call_id:ref,extension:cfg.extension};let options={credentials:'same-origin',cache:'no-store'};
  if(post)options={...options,method:'POST',body:new URLSearchParams({...values,...Object.fromEntries(new FormData(form)),version,csrf_token:cfg.routingCsrf})};else for(const [k,v]of Object.entries(values))url.searchParams.set(k,v);
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
  try{const r=await fetch(url,{...options,signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo recuperar la captura.');return j.captura;}
  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Conserva el borrador y vuelve a intentarlo.');throw error;}finally{clearTimeout(timeout);}
 };
 button.addEventListener('click',async()=>{if(busy)return;busy=true;form.reset();feedback.textContent='';version=0;
  const s=phone.getCallState();ref=String(s?.context?.transferReference||s?.finalMetadata?.pbx_call_id||s?.pbxCallId||'');localId=String(s?.context?.sdkSessionId||s?.callToken||ref);form.elements.telefono.value=s?.destination||'';
  try{if(valid(ref)){const data=await request();version=data.version;for(const [k,v]of Object.entries(data.valores||{}))if(form.elements[k])form.elements[k].value=v;}
   else feedback.textContent='Referencia de la llamada pendiente. Los datos se conservan como borrador en esta pestaña.';
  }catch(e){feedback.textContent=e.message;}
  try{const draft=JSON.parse(sessionStorage.getItem(key(localId))||'null');if(draft&&Date.now()-draft.at<86400000)for(const [k,v]of Object.entries(draft.values))if(form.elements[k])form.elements[k].value=v;}catch(_){}
  dialog.hidden=false;form.elements.nombre.focus();busy=false;
 });
 form.addEventListener('input',()=>{try{sessionStorage.setItem(key(localId),JSON.stringify({at:Date.now(),values:Object.fromEntries(new FormData(form))}));}catch(_){}});
 form.addEventListener('submit',async e=>{e.preventDefault();if(busy)return;busy=true;const submit=form.querySelector('[type=submit]');submit.disabled=true;
  try{if(!valid(ref)){const s=phone.getCallState();if(s?.callToken===localId||s?.context?.sdkSessionId===localId)ref=String(s.context?.transferReference||s.pbxCallId||'');}
   if(!valid(ref))throw Error('La referencia de la llamada aún no está confirmada. El borrador se conserva; vuelve a guardar cuando esté disponible.');
   const data=await request(true);version=data.version;try{sessionStorage.removeItem(key(localId));}catch(_){}feedback.textContent='';notify('Datos guardados. La conversación y su resultado permanecen separados.');
  }catch(error){feedback.textContent=error.message;notify(error.message,true);}finally{busy=false;submit.disabled=false;}
 });
 dialog.querySelector('[data-sales-prospect-close]').addEventListener('click',()=>{dialog.hidden=true;});
 const render=()=>{const s=phone.getCallState();button.hidden=!s||s.phase!=='in-progress';};setInterval(render,750);render();
})();
