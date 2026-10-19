/* Operación comercial sobre el teléfono persistente; no instancia otro SDK. */
(()=>{'use strict';
 const root=document.querySelector('[data-sales-history]'),modal=document.getElementById('modalResultadoVenta');
 if(!root||!modal)return;
 const form=modal.querySelector('form'),feedback=form.querySelector('[data-sales-feedback]'),submit=form.querySelector('[data-sales-submit]');
 const token='sales-result-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
 const shell=()=>{try{return window.parent!==window?window.parent.IMPE_TELEPHONY_SHELL:null;}catch(_){return null;}};
 let call=null,cycle=0,opening=false;
 const notify=(message,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(message,error);
 const request=async url=>{const r=await fetch(url,{credentials:'same-origin',cache:'no-store'}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo consultar la llamada.');return j;};
 const draftKey=ref=>'impe:sales-result-draft:'+Number(window.IMPE_CURRENT_USER_ID)+':'+ref;
 async function open(ref,ext,attempt=0){
  if(opening)return;opening=true;const version=++cycle;
  try{
   const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('pbx_call_id',ref);if(ext)url.searchParams.set('extension',ext);
   const data=await request(url);if(version!==cycle)return;
   call=data.llamada;if(!call.finalizada)throw Error('La centralita aún no confirma el cierre de tu llamada.');
   if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de abrir este registro.');
   form.reset();for(const el of form.elements)el.disabled=false;feedback.textContent='';
   form.elements.pbx_call_id.value=ref;form.elements.extension.value=call.extension;
   const values=call.registro||{prospecto_nombre:call.nombre};
   for(const [key,value]of Object.entries(values)){const el=form.elements.namedItem(key);if(el&&value!==null)el.value=key==='proxima_accion_at'?String(value).replace(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}/,'$1T$2'):String(value);}
   if(!call.registro){try{const d=JSON.parse(sessionStorage.getItem(draftKey(ref))||'null');if(d&&Date.now()-d.at<86400000)for(const [k,v]of Object.entries(d.values||{})){const el=form.elements.namedItem(k);if(el&&!['csrf_token','pbx_call_id','extension'].includes(k)){if(el.type==='checkbox')el.checked=v==='1';else el.value=v;}}}catch(_){}}
   form.querySelector('[data-sales-form-number]').textContent=call.numero;
   form.querySelector('[data-sales-form-state]').textContent=call.estado_pbx+' · Ext. '+call.extension;
   submit.hidden=Boolean(call.registro);
   if(call.registro)for(const el of form.querySelectorAll('input,select,textarea'))el.disabled=true;
   if(!window.bootstrap?.Modal)throw Error('No se cargó el formulario. Recarga la página.');
   bootstrap.Modal.getOrCreateInstance(modal).show();
  }catch(e){shell()?.endCallResult(token);notify(e.message,true);
   if(attempt<4&&root.dataset.autoCall===ref)setTimeout(()=>void open(ref,ext,attempt+1),1500);
  }finally{opening=false;}
 }
 root.addEventListener('click',async e=>{
  const button=e.target.closest('[data-sales-program-complete]');if(!button||button.disabled)return;
  if(!window.confirm('¿Cerrar este recordatorio? No se registrará una llamada ni cambiará su resultado.'))return;
  button.disabled=true;
  try{const response=await fetch(root.dataset.programUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf_token:root.dataset.csrf,id:button.dataset.salesProgramComplete,origen:button.dataset.source,fecha:button.dataset.date})}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo cerrar el recordatorio.');notify('Recordatorio cerrado.');location.reload();}
  catch(error){notify(error.message,true);button.disabled=false;}
 });
 root.addEventListener('click',e=>{
  const detail=e.target.closest('[data-sales-detail]');if(detail){void open(detail.dataset.salesDetail,detail.dataset.extension);return;}
  const dial=e.target.closest('[data-sales-dial]');if(dial){window.IMPE_TELEPHONY_PERSISTENT?.openDialer({number:dial.dataset.salesDial});return;}
  const audio=e.target.closest('[data-sales-audio]');if(!audio)return;
  const slot=audio.closest('.linkage-call-recording-body').querySelector('[data-sales-audio-slot]');
  if(!slot.hidden){slot.querySelectorAll('audio').forEach(a=>a.pause());slot.hidden=true;audio.setAttribute('aria-expanded','false');return;}
  if(!slot.dataset.loaded){const url=new URL('prueba_telefonia/api/grabacion_ventas.php',document.baseURI);url.searchParams.set('pbx_call_id',audio.dataset.salesAudio);url.searchParams.set('extension',audio.dataset.extension);if(!window.IMPE_TELEPHONY_SEGMENTS?.create?.(slot,url.href,Number(audio.dataset.segments))){notify('No se cargó el reproductor.',true);return;}slot.dataset.loaded='1';}
  slot.hidden=false;audio.setAttribute('aria-expanded','true');
 });
 form.addEventListener('input',()=>{if(!call||call.registro)return;try{sessionStorage.setItem(draftKey(call.pbx_call_id),JSON.stringify({at:Date.now(),values:Object.fromEntries(new FormData(form))}));}catch(_){}});
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(!call||call.registro||submit.disabled)return;submit.disabled=true;feedback.textContent='Guardando resultado…';
  try{const r=await fetch(root.dataset.saveUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams(new FormData(form))}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo guardar el resultado.');
   try{sessionStorage.removeItem(draftKey(call.pbx_call_id));}catch(_){}
   window.parent?.postMessage({type:'IMPE_SALES_RESULT_SAVED',ref:call.pbx_call_id,extension:call.extension},location.origin);
   bootstrap.Modal.getInstance(modal)?.hide();notify('Resultado comercial guardado.');
   const url=new URL(location.href);for(const key of ['pbx_call_id','extension','detalle'])url.searchParams.delete(key);location.replace(url.href);
  }catch(error){feedback.textContent=error.message;notify(error.message,true);}finally{submit.disabled=false;}
 });
 modal.addEventListener('hidden.bs.modal',()=>{shell()?.endCallResult(token);});
 window.addEventListener('message',e=>{if(e.origin===location.origin&&e.source===window.parent&&e.data?.type==='IMPE_CALL_RESULT_INTERRUPT'){++cycle;bootstrap.Modal.getInstance(modal)?.hide();}});
 if(root.dataset.autoCall)void open(root.dataset.autoCall,root.dataset.autoExtension);
})();
