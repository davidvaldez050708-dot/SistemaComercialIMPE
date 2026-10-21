/* Operación comercial sobre el teléfono persistente; no instancia otro SDK. */
(()=>{'use strict';
 const root=document.querySelector('[data-sales-history]'),modal=document.getElementById('modalResultadoVenta');
 if(!root||!modal||root.dataset.salesResultsInitialized)return;
 root.dataset.salesResultsInitialized='1';
 const form=modal.querySelector('form'),feedback=form.querySelector('[data-sales-feedback]'),submit=form.querySelector('[data-sales-submit]');
 const token='sales-result-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
 const shell=()=>{try{return window.parent!==window?window.parent.IMPE_TELEPHONY_SHELL:null;}catch(_){return null;}};
 const recheck=form.querySelector('[data-sales-recheck]'),fields=form.querySelector('[data-sales-fields]');
 let call=null,cycle=0,opening=false,timer=null,disposed=false,waiting=false,recovered=false,requestController=null;
 const cancelRetry=()=>{clearTimeout(timer);timer=null;};
 let refreshing=false;
 async function refreshHistory(){
  if(disposed||refreshing||opening||document.hidden||modal.classList.contains('show')||root.querySelector('[data-sales-audio-slot]:not([hidden])'))return;
  refreshing=true;
  try{const r=await fetch(location.href,{credentials:'same-origin',cache:'no-store'});if(!r.ok)return;
   const doc=new DOMParser().parseFromString(await r.text(),'text/html'),fresh=doc.querySelector('[data-sales-history]');
   if(!fresh||disposed||modal.classList.contains('show'))return;
   for(const selector of ['.linkage-incoming-stats','.linkage-incoming-list-head','.linkage-incoming-history']){
    const old=root.querySelector(selector),next=fresh.querySelector(selector);if(old&&next)old.replaceWith(next);
   }
  }catch(_){}finally{refreshing=false;}
 }
 const historyTimer=setInterval(()=>{
  if(root.querySelector('[data-sales-audio-state]')||Array.from(root.querySelectorAll('.linkage-call-recording-duration')).some(el=>el.textContent.includes('Sin duración')))void refreshHistory();
 },60000);
 const notify=(message,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(message,error);
 const request=async url=>{
  const controller=new AbortController();requestController=controller;const timeout=setTimeout(()=>controller.abort(),10000);
  try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok){const e=Error(j.mensaje||'No se pudo consultar la llamada.');e.status=r.status;throw e;}return j;}
  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Vuelve a comprobar el cierre.');throw error;}
  finally{clearTimeout(timeout);if(requestController===controller)requestController=null;}
 };
 const draftKey=ref=>'impe:sales-result-draft:'+Number(window.IMPE_CURRENT_USER_ID)+':'+ref;
 async function open(ref,ext,attempt=0){
  if(opening||disposed)return;cancelRetry();opening=true;recheck.disabled=true;const version=++cycle;
  try{
   const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('pbx_call_id',ref);if(ext)url.searchParams.set('extension',ext);
   let data;
   try{data=await request(url);}
   catch(error){
    // La invitación SIP sí terminó, pero el tramo saliente 100→150
    // puede aún no estar materializado en la tabla comercial. Sólo el
    // caso explícito de apertura provisional y HTTP 409 espera al PBX.
    const provisional=new URL(location.href).searchParams.get('esperando_pbx')==='1';
    if(provisional&&error.status===409&&version===cycle){
     if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de registrar.');
     waiting=true;fields.hidden=true;submit.hidden=true;
     recheck.hidden=attempt<4;
     form.querySelector('[data-sales-form-state]').textContent='Finalizada en el teléfono · conciliando PBX';
     feedback.textContent=attempt<4?
      'Puedes continuar con el registro en cuanto Zadarma confirme esta transferencia. El sistema lo comprobará automáticamente.':
      'Zadarma aún no confirma el tramo. No se ha guardado ningún resultado.';
     bootstrap.Modal.getOrCreateInstance(modal).show();
     if(attempt<4)timer=setTimeout(()=>{if(version===cycle&&!disposed)void open(ref,ext,attempt+1);},1500*2**attempt);
     return;
    }
    throw error;
   }
   if(version!==cycle)return;
   call=data.llamada;
   if(shell()&&!shell().beginCallResult(token,[ref]))throw Error('Termina la llamada activa antes de abrir este registro.');
   if(!call.finalizada){
    waiting=true;fields.hidden=true;submit.hidden=true;recheck.hidden=false;
    form.querySelector('[data-sales-form-number]').textContent=call.numero;
    form.querySelector('[data-sales-form-state]').textContent='Cierre pendiente de confirmación · Ext. '+call.extension;
    feedback.textContent=attempt<4?'Esperando la confirmación del cierre. Se comprobará de nuevo automáticamente.':'Todavía no llega la confirmación del cierre. Puedes volver a comprobarla; no se ha guardado ningún resultado.';
    bootstrap.Modal.getOrCreateInstance(modal).show();
    if(attempt<4)timer=setTimeout(()=>{if(version===cycle&&!disposed)void open(ref,ext,attempt+1);},1500*2**attempt);
    return;
   }
   recovered=recovered||waiting;waiting=false;fields.hidden=false;recheck.hidden=true;
   form.reset();for(const el of form.elements)el.disabled=false;feedback.textContent='';
   form.elements.pbx_call_id.value=ref;form.elements.extension.value=call.extension;
   const values=call.registro||{prospecto_nombre:call.captura_prospecto?.nombre||call.nombre,correo:call.captura_prospecto?.correo||''};
   const captureSummary=form.querySelector('[data-sales-capture-summary]');
   if(captureSummary){captureSummary.hidden=!call.captura_prospecto;captureSummary.textContent=call.captura_prospecto?['Datos informados durante la atención',call.captura_prospecto.programa,call.captura_prospecto.notas].filter(Boolean).join(' · '):'';}
   for(const [key,value]of Object.entries(values)){const el=form.elements.namedItem(key);if(el&&value!==null)el.value=key==='proxima_accion_at'?String(value).replace(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}/,'$1T$2'):String(value);}
   if(!call.registro){try{const d=JSON.parse(sessionStorage.getItem(draftKey(ref))||'null');if(d&&Date.now()-d.at<86400000)for(const [k,v]of Object.entries(d.values||{})){const el=form.elements.namedItem(k);if(el&&!['csrf_token','pbx_call_id','extension'].includes(k)){if(el.type==='checkbox')el.checked=v==='1';else el.value=v;}}}catch(_){}}
   form.querySelector('[data-sales-form-number]').textContent=call.numero;
   form.querySelector('[data-sales-form-state]').textContent=call.estado_pbx+' · Ext. '+call.extension;
   submit.hidden=Boolean(call.registro);
   if(call.registro)for(const el of form.querySelectorAll('input,select,textarea'))el.disabled=true;
   if(!window.bootstrap?.Modal)throw Error('No se cargó el formulario. Recarga la página.');
   bootstrap.Modal.getOrCreateInstance(modal).show();
  }catch(e){if(version!==cycle||disposed)return;
   if(waiting){feedback.textContent=e.message+' Usa «Comprobar cierre» para reintentar.';}
   else{shell()?.endCallResult(token);notify(e.message,true);}
  }finally{opening=false;recheck.disabled=false;}
 }
 recheck.addEventListener('click',()=>{if(call)void open(call.pbx_call_id,call.extension);else{const u=new URL(location.href);if(u.searchParams.get('esperando_pbx')==='1'&&u.searchParams.get('pbx_call_id'))void open(u.searchParams.get('pbx_call_id'),u.searchParams.get('extension')||'');}});
 root.addEventListener('click',async e=>{
  const button=e.target.closest('[data-sales-program-complete]');if(!button||button.disabled)return;
  if(!window.confirm('¿Cerrar este recordatorio? No se registrará una llamada ni cambiará su resultado.'))return;
  button.disabled=true;
  try{const response=await fetch(root.dataset.programUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf_token:root.dataset.csrf,id:button.dataset.salesProgramComplete,origen:button.dataset.source,fecha:button.dataset.date})}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo cerrar el recordatorio.');notify('Recordatorio cerrado.');location.reload();}
  catch(error){notify(error.message,true);button.disabled=false;}
 });
 root.addEventListener('click',async e=>{
  const detail=e.target.closest('[data-sales-detail]');if(detail){void open(detail.dataset.salesDetail,detail.dataset.extension);return;}
  const dial=e.target.closest('[data-sales-dial]');if(dial){window.IMPE_TELEPHONY_PERSISTENT?.openDialer({number:dial.dataset.salesDial});return;}
  const audio=e.target.closest('[data-sales-audio]');if(!audio)return;
  const slot=audio.closest('.linkage-call-recording-body').querySelector('[data-sales-audio-slot]');
  if(!slot.hidden){slot.querySelectorAll('audio').forEach(a=>a.pause());slot.hidden=true;audio.setAttribute('aria-expanded','false');return;}
  if(!slot.dataset.loaded){
   if(audio.disabled)return;audio.disabled=true;
   const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
   try{
    if(root.dataset.evidenceUrl){
     const r=await fetch(root.dataset.evidenceUrl,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,body:new URLSearchParams({csrf_token:root.dataset.csrf,pbx_call_id:audio.dataset.salesAudio,extension:audio.dataset.extension})}),j=await r.json();
     if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo verificar la grabacion.');
     const parts=j.llamada?.segmentos;
     const duration=audio.closest('.linkage-call-history-card')?.querySelector('.linkage-call-recording-duration');
     if(duration&&Number.isInteger(j.llamada?.segundos))duration.textContent=String(Math.floor(j.llamada.segundos/60)).padStart(2,'0')+':'+String(j.llamada.segundos%60).padStart(2,'0');
     if(!Array.isArray(parts)||parts.length<1){notify(j.mensaje||'Grabacion pendiente de confirmar.');return;}
     audio.dataset.segments=String(parts.length);
     if(j.llamada.segmentos_info)audio.dataset.segmentInfo=JSON.stringify(j.llamada.segmentos_info);
     audio.parentElement.querySelector('[data-sales-audio-state]')?.remove();
    }
    const url=new URL('prueba_telefonia/api/grabacion_ventas.php',document.baseURI);url.searchParams.set('pbx_call_id',audio.dataset.salesAudio);url.searchParams.set('extension',audio.dataset.extension);
    url.searchParams.set('verificar','1');
    const verified=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),data=await verified.json().catch(()=>({}));
    if(!verified.ok||!data.ok||!data.disponible)throw Error(window.IMPE_TELEPHONY_SEGMENTS.message(data,verified.status));
    if(!window.IMPE_TELEPHONY_SEGMENTS?.create?.(slot,url.href,Number(data.segmentos),data.segmentos_info||[]))throw Error('No se cargo el reproductor.');
    slot.dataset.loaded='1';
   }catch(error){notify(error.name==='AbortError'?'La consulta tardo demasiado. Vuelve a intentar.':error.message,true);return;}
   finally{clearTimeout(timeout);audio.disabled=false;}
  }
  slot.hidden=false;audio.setAttribute('aria-expanded','true');
 });
 form.addEventListener('input',()=>{if(!call||call.registro)return;try{sessionStorage.setItem(draftKey(call.pbx_call_id),JSON.stringify({at:Date.now(),values:Object.fromEntries(new FormData(form))}));}catch(_){}});
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(!call||!call.finalizada||call.registro||submit.disabled)return;submit.disabled=true;feedback.textContent='Guardando resultado…';
  try{const r=await fetch(root.dataset.saveUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams(new FormData(form))}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo guardar el resultado.');
   try{sessionStorage.removeItem(draftKey(call.pbx_call_id));}catch(_){}
   window.parent?.postMessage({type:'IMPE_SALES_RESULT_SAVED',ref:call.pbx_call_id,extension:call.extension},location.origin);
   bootstrap.Modal.getInstance(modal)?.hide();notify('Resultado comercial guardado.');
   const url=new URL(location.href);for(const key of ['pbx_call_id','extension','detalle'])url.searchParams.delete(key);location.replace(url.href);
  }catch(error){feedback.textContent=error.message;notify(error.message,true);}finally{submit.disabled=false;}
 });
 modal.addEventListener('hidden.bs.modal',()=>{++cycle;cancelRetry();requestController?.abort();waiting=false;shell()?.endCallResult(token);if(recovered){const url=new URL(location.href);for(const key of ['pbx_call_id','extension','detalle'])url.searchParams.delete(key);location.replace(url.href);}});
 window.addEventListener('pagehide',()=>{disposed=true;++cycle;cancelRetry();clearInterval(historyTimer);requestController?.abort();});
 window.addEventListener('message',e=>{if(e.origin!==location.origin||e.source!==window.parent)return;
  if(e.data?.type==='IMPE_CALL_RESULT_INTERRUPT'){++cycle;cancelRetry();requestController?.abort();recovered=false;bootstrap.Modal.getInstance(modal)?.hide();}
  if(e.data?.type==='IMPE_SALES_RESULT_OPEN')void open(e.data.ref,e.data.extension);
  if(e.data?.type==='IMPE_PHONE_CALL_CHANGED')void refreshHistory();
 });
 if(root.dataset.autoCall)void open(root.dataset.autoCall,root.dataset.autoExtension);
})();
