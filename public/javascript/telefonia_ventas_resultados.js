/* Operación comercial sobre el teléfono persistente; no instancia otro SDK. */
(()=>{'use strict';
 const root=document.querySelector('[data-sales-history]'),modal=document.getElementById('modalResultadoVenta');
 if(!root||!modal||root.dataset.salesResultsInitialized)return;
 root.dataset.salesResultsInitialized='1';
 const form=modal.querySelector('form'),feedback=form.querySelector('[data-sales-feedback]'),submit=form.querySelector('[data-sales-submit]');
 const token='sales-result-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
 const shell=()=>{try{return window.parent!==window?window.parent.IMPE_TELEPHONY_SHELL:null;}catch(_){return null;}};
 const fields=form.querySelector('[data-sales-fields]');
 let call=null,callRef='',callExt='',queued=false,cycle=0,opening=false,timer=null,disposed=false,waiting=false,recovered=false,requestController=null,importedCaptureNote='',captureConflictNote='';
 const saveLabel=submit.innerHTML;
 let openContext={sessionKey:'',prepared:false,automatic:false},closeReason='user',closingForm=null;
 const draftAliases=new Set();
 const tellParent=data=>{if(window.parent!==window)try{window.parent.postMessage(data,location.origin);}catch(_){}};
 const cancelRetry=()=>{clearTimeout(timer);timer=null;};
 let refreshing=false;
 async function refreshHistory(){
  if(root.dataset.salesGlobalResult==='1'||disposed||refreshing||opening||document.hidden||modal.classList.contains('show')||root.querySelector('[data-sales-audio-slot]:not([hidden])'))return;
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
  if(root.querySelector('[data-sales-audio-state],[data-sales-duration-pending="1"]'))void refreshHistory();
 },60000);
 const notify=(message,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(message,error);
 const updateDuration=(audio,seconds)=>{
  if(!Number.isInteger(seconds)||seconds<0)return;
  const duration=audio.closest('.linkage-call-history-card')?.querySelector('.linkage-call-recording-duration');
  if(!duration)return;
  duration.textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
  duration.dataset.salesDurationPending='0';
 };
 async function reconcileTransferDuration(audio){
  // La conciliación verifica la transferencia y guarda la duración propia,
  // incluso si falta END y ya existen referencias firmadas de grabación.
  // Nunca condicionar los audios ya autorizados a que responda esta consulta.
  if(!root.dataset.evidenceUrl)return;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
  try{
   const response=await fetch(root.dataset.evidenceUrl,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,body:new URLSearchParams({csrf_token:root.dataset.csrf,pbx_call_id:audio.dataset.salesAudio,extension:audio.dataset.extension})});
   const data=await response.json();
   if(response.ok&&data.ok)updateDuration(audio,data.llamada?.segundos);
  }catch(_){}finally{clearTimeout(timeout);}
 }

 const request=async url=>{
  const controller=new AbortController();requestController=controller;const timeout=setTimeout(()=>controller.abort(),10000);
  try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok){const e=Error(j.mensaje||'No se pudo consultar la llamada.');e.status=r.status;throw e;}return j;}
  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Vuelve a comprobar el cierre.');throw error;}
  finally{clearTimeout(timeout);if(requestController===controller)requestController=null;}
 };
 const draftKey=ref=>'impe:sales-result-draft:'+Number(window.IMPE_CURRENT_USER_ID)+':'+(ref||'session:'+openContext.sessionKey);
 const readDraft=ref=>{try{const d=JSON.parse(sessionStorage.getItem(draftKey(ref))||'null');return d&&Date.now()-d.at<86400000?d:null;}catch(_){return null;}};
 const storeDraft=()=>{if((!callRef&&!openContext.sessionKey)||call?.registro)return;try{sessionStorage.setItem(draftKey(callRef),JSON.stringify({at:Date.now(),queued,captureNote:importedCaptureNote,values:Object.fromEntries(new FormData(form))}));}catch(_){}};
 const show=()=>{if(!window.bootstrap?.Modal)throw Error('No se cargó el formulario. Recarga la página.');bootstrap.Modal.getOrCreateInstance(modal).show();};
 const schedule=(ref,ext,attempt,version)=>{const delay=Math.min(1500*2**Math.min(attempt,3),12000);timer=setTimeout(()=>{if(version===cycle&&!disposed&&modal.classList.contains('show'))void open(ref,ext,attempt+1);},delay);};
 const resultRules=()=>{
  const result=form.elements.namedItem('resultado'),notes=form.elements.namedItem('observacion');
  if(!result||!notes)return;
  const cancelled=result.value==='CANCELADA_POR_ASESOR';notes.required=cancelled;notes.minLength=cancelled?3:0;
  const label=form.querySelector('label[for="salesNote"]');if(label)label.textContent=cancelled?'Motivo de cancelación *':'Observaciones';
  for(const option of result.options||[])option.disabled=Boolean(call?.finalizada&&!call.conectada&&option.dataset.requiresConnection==='1');
  const selected=Array.from(result.options||[]).find(option=>option.value===result.value);
  result.setCustomValidity?.(call?.finalizada&&!call.conectada&&selected?.dataset.requiresConnection==='1'?'Esta llamada no tiene conexión confirmada. Selecciona lo que ocurrió sin conversación.':'');
 };
 async function open(ref,ext,attempt=0,context=null){
  if(opening||disposed)return;
  const next=context||(ref===callRef?openContext:{sessionKey:ref,prepared:false,automatic:false});
  const sameSession=Boolean(next.sessionKey&&next.sessionKey===openContext.sessionKey);
  const changed=!sameSession&&(callRef!==ref||(callExt&&ext&&callExt!==ext)||next.sessionKey!==openContext.sessionKey);
  if(changed){
   storeDraft();call=null;waiting=false;queued=false;importedCaptureNote='';captureConflictNote='';draftAliases.clear();
   form.reset();for(const el of form.elements)el.disabled=false;
   form.elements.namedItem('observacion')?.setCustomValidity?.('');
  }
  const oldKey=draftKey(callRef);openContext={...next};callRef=ref;callExt=ext||callExt;closeReason='user';
  if(sameSession&&oldKey!==draftKey(ref))draftAliases.add(oldKey);
  if(changed){
   const draft=readDraft(ref);
   if(draft){importedCaptureNote=typeof draft.captureNote==='string'?draft.captureNote:'';
    for(const[key,value]of Object.entries(draft.values||{})){
     if(['csrf_token','pbx_call_id','extension'].includes(key))continue;
     const el=form.elements.namedItem(key);if(!el)continue;
     if(el.type==='checkbox')el.checked=value==='1';else el.value=String(value??'');
    }
   }
  }
  cancelRetry();opening=true;const version=++cycle;
  try{
   const ownExt=ext||callExt,ownerRef=ref||'pending-'+String(openContext.sessionKey).replace(/[^A-Za-z0-9_-]/g,'_').slice(0,80);
   if(shell()&&!shell().beginCallResult(token,[ownerRef]))throw Error('Termina la llamada activa antes de registrar.');
   form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;
   if(fields)fields.hidden=false;submit.hidden=false;submit.disabled=true;submit.innerHTML='Identificando llamada…';waiting=true;
   form.querySelector('[data-sales-form-number]').textContent=openContext.number||call?.numero||'Llamada finalizada';
   form.querySelector('[data-sales-form-state]').textContent='Identificando la llamada de tu extensión · Ext. '+ownExt;
   feedback.textContent='Puedes completar la captura. Guardar se habilitará al confirmar el registro de esta llamada.';
   resultRules();show();storeDraft();
   if(!ref)return; // El padre sólo podrá vincular la PBX de esta misma sesión.
   const url=new URL(root.dataset.detailUrl,location.href);url.searchParams.set('pbx_call_id',ref);if(ownExt)url.searchParams.set('extension',ownExt);
   const data=await request(url);if(version!==cycle||disposed)return;
   if(!data.llamada){schedule(ref,ownExt,attempt,version);return;}
   if(data.llamada.pbx_call_id!==ref||String(data.llamada.extension)!==String(ownExt))throw Error('La confirmación no pertenece a esta llamada.');
   call=data.llamada;
   let localCapture=null,captureNotice='';
   if(!call.registro){try{localCapture=window.parent!==window?window.parent.IMPE_SALES_PROSPECT_CAPTURE?.forResult?.(ref,ownExt):null;}catch(_){}}
   const capture=localCapture||call.captura_prospecto;
   form.querySelector('[data-sales-form-number]').textContent=call.numero||'Llamada finalizada';
   const summary=form.querySelector('[data-sales-capture-summary]');
   if(summary){summary.hidden=!capture;summary.textContent=capture?['Datos informados durante la atención',capture.programa,capture.notas].filter(Boolean).join(' · '):'';}
   const nombre=form.elements.namedItem('prospecto_nombre'),correo=form.elements.namedItem('correo');
   if(nombre&&!nombre.value)nombre.value=call.registro?.prospecto_nombre||capture?.nombre||call.nombre||'';
   if(correo&&!correo.value)correo.value=call.registro?.correo||capture?.correo||'';
   const notes=form.elements.namedItem('observacion'),captured=String(capture?.notas||'').trim();
   if(!call.registro&&notes&&captured&&captured!==importedCaptureNote){
    const existing=notes.value.trim(),combined=!existing?captured:(existing.includes(captured)?notes.value:captured+'\n\n'+notes.value);
    if(combined.length<=1000){notes.value=combined;importedCaptureNote=captured;}
    else{captureNotice='Las notas de atención aparecen arriba. Para guardar, resume o integra ambos textos en Observaciones sin superar 1000 caracteres; tu texto se conserva.';captureConflictNote=captured;notes.setCustomValidity?.(captureNotice);}
   }
   const parts=String(call.fecha||'').match(/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):[0-9]{2}$/);
   const date=parts?`${parts[3]}/${parts[2]}/${parts[1]} · ${parts[4]}:${parts[5]}`:'';
   form.querySelector('[data-sales-form-state]').textContent=[date,call.finalizada?call.estado_pbx:'Confirmación de cierre pendiente','Ext. '+ownExt].filter(Boolean).join(' · ');
   if(call.registro){
    queued=false;waiting=false;
    try{sessionStorage.removeItem(draftKey(ref));for(const key of draftAliases)sessionStorage.removeItem(key);}catch(_){}
    for(const[key,value]of Object.entries(call.registro)){const el=form.elements.namedItem(key);if(el&&value!==null)el.value=key==='proxima_accion_at'?String(value).replace(/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}/,'$1T$2'):String(value);}
    for(const el of form.querySelectorAll('input,select,textarea'))el.disabled=true;
    submit.hidden=true;feedback.textContent='Resultado guardado anteriormente.';
    if(openContext.automatic){closeReason='saved';tellParent({type:'IMPE_SALES_RESULT_SAVED',ref,extension:ownExt});bootstrap.Modal.getInstance(modal)?.hide();shell()?.endCallResult(token);return;}
   }else{
    const capturable=Boolean(call.finalizada||(call.conectada&&call.solicitud_id&&call.direccion==='Entrante'&&call.origen_extension&&String(call.origen_extension)!==String(call.extension)));
    waiting=!capturable;submit.disabled=!capturable;submit.innerHTML=capturable?saveLabel:'Confirmando cierre…';
    feedback.textContent=[capturable?'':'Puedes completar los datos. Estamos esperando la confirmación de cierre de esta misma llamada.',captureNotice].filter(Boolean).join(' ');
    storeDraft();if(!capturable)schedule(ref,ownExt,attempt,version);
   }
   resultRules();modal.dispatchEvent(new CustomEvent('impe:sales-result-loaded',{detail:{ref,extension:ownExt,saved:Boolean(call.registro)}}));
  }catch(error){
   if(version!==cycle||disposed)return;
   feedback.textContent=error.message+' La captura se conserva; no se ha guardado el resultado.';
   if(!error.status||error.status===409)schedule(ref,ext,Math.max(attempt,1),version);
  }finally{if(version===cycle)opening=false;}
 }
 root.addEventListener('click',async e=>{
  const button=e.target.closest('[data-sales-program-complete]');if(!button||button.disabled)return;
  if(!window.confirm('¿Cerrar este recordatorio? No se registrará una llamada ni cambiará su resultado.'))return;
  button.disabled=true;
  try{const response=await fetch(root.dataset.programUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf_token:root.dataset.csrf,id:button.dataset.salesProgramComplete,origen:button.dataset.source,fecha:button.dataset.date})}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo cerrar el recordatorio.');notify('Recordatorio cerrado.');location.reload();}
  catch(error){notify(error.message,true);button.disabled=false;}
 });
 root.addEventListener('click',async e=>{
  const detail=e.target.closest('[data-sales-detail]');if(detail){void open(detail.dataset.salesDetail,detail.dataset.extension,0,{sessionKey:detail.dataset.salesDetail,automatic:false,prepared:false});return;}
  const dial=e.target.closest('[data-sales-dial]');if(dial){window.IMPE_TELEPHONY_PERSISTENT?.openDialer({number:dial.dataset.salesDial});return;}
  const audio=e.target.closest('[data-sales-audio]');if(!audio)return;
  const slot=audio.closest('.linkage-call-recording-body').querySelector('[data-sales-audio-slot]');
  if(!slot.hidden){slot.querySelectorAll('audio').forEach(a=>a.pause());slot.hidden=true;audio.setAttribute('aria-expanded','false');return;}
  if(!slot.dataset.loaded){
   if(audio.disabled)return;audio.disabled=true;
   const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
   try{
    const transferred=audio.dataset.salesTransferida==='1';
    // El aviso de grabación no depende de un END PBX que no llegó. Para
    // transferencias el endpoint valida la operación y enumera NOTIFY_RECORD.
    if(root.dataset.evidenceUrl&&!transferred){
     const r=await fetch(root.dataset.evidenceUrl,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,body:new URLSearchParams({csrf_token:root.dataset.csrf,pbx_call_id:audio.dataset.salesAudio,extension:audio.dataset.extension})}),j=await r.json();
     if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo verificar la grabacion.');
     const parts=j.llamada?.segmentos;
     updateDuration(audio,j.llamada?.segundos);
     if(!Array.isArray(parts)||parts.length<1){notify(j.mensaje||'Grabacion pendiente de confirmar.');return;}
     audio.dataset.segments=String(parts.length);
     if(j.llamada.segmentos_info)audio.dataset.segmentInfo=JSON.stringify(j.llamada.segmentos_info);
     audio.parentElement.querySelector('[data-sales-audio-state]')?.remove();
    }
    const url=new URL('prueba_telefonia/api/grabacion_ventas.php',document.baseURI);url.searchParams.set('pbx_call_id',audio.dataset.salesAudio);url.searchParams.set('extension',audio.dataset.extension);
    url.searchParams.set('verificar','1');
    const verified=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),data=await verified.json().catch(()=>({}));
    if(transferred&&verified.ok&&data.ok){updateDuration(audio,data.segundos);void reconcileTransferDuration(audio);}
    if(!verified.ok||!data.ok||!data.disponible)throw Error(window.IMPE_TELEPHONY_SEGMENTS.message(data,verified.status));
    if(!window.IMPE_TELEPHONY_SEGMENTS?.create?.(slot,url.href,Number(data.segmentos),data.segmentos_info||[]))throw Error('No se cargo el reproductor.');
    slot.dataset.loaded='1';
    if(transferred){
     const count=Number(data.segmentos)||0;
     audio.dataset.segments=String(count);
     // Una porción en preparación nunca oculta el audio ya verificado.
     audio.childNodes.forEach(node=>{if(node.nodeType===3)node.textContent=' '+(count===1?'Consultar grabación':'Consultar '+count+' grabaciones')+' ';});
     audio.parentElement.querySelector('[data-sales-audio-state]')?.remove();
    }
   }catch(error){notify(error.name==='AbortError'?'La consulta tardo demasiado. Vuelve a intentar.':error.message,true);return;}
   finally{clearTimeout(timeout);audio.disabled=false;}
  }
  slot.hidden=false;audio.setAttribute('aria-expanded','true');
 });
 form.addEventListener('input',event=>{
  const notes=form.elements.namedItem('observacion');
  if(captureConflictNote&&event.target===notes){
   notes.setCustomValidity?.('');importedCaptureNote=captureConflictNote;captureConflictNote='';
   feedback.textContent='Revisa las observaciones antes de guardar.';
  }
  storeDraft();
 });
 form.addEventListener('change',()=>{resultRules();storeDraft();});
 form.addEventListener('submit',async e=>{
  e.preventDefault();
  if(!callRef||!call||call?.registro||submit.disabled||!form.reportValidity())return;
  if(call.pbx_call_id!==callRef||String(call.extension)!==String(form.elements.extension.value))return;
  const savedContext={...openContext},savedRef=callRef,savedExt=call.extension,savedDrafts=[draftKey(callRef),...draftAliases];
  const sameForm=()=>callRef===savedRef&&callExt===String(savedExt)&&openContext.sessionKey===savedContext.sessionKey;
  submit.disabled=true;submit.innerHTML='Guardando…';feedback.textContent='Guardando resultado…';
  try{
   const r=await fetch(root.dataset.saveUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams(new FormData(form))}),j=await r.json();
   if(!r.ok||!j.ok){
    // Una negativa de autorización se muestra inmediatamente; nunca
    // prometer un guardado automático condicionado a un evento ausente.
    throw Error(j.mensaje||'No se pudo guardar el resultado.');
   }
   try{for(const key of savedDrafts)sessionStorage.removeItem(key);}catch(_){}
   window.parent?.postMessage({type:'IMPE_SALES_RESULT_SAVED',ref:savedRef,extension:savedExt},location.origin);
   if(!sameForm()||disposed)return;
   queued=false;closeReason='saved';
   bootstrap.Modal.getInstance(modal)?.hide();notify(j.registro?.pbx_pendiente?'Resultado guardado. La confirmación de la llamada y la grabación continúan pendientes.':'Resultado comercial guardado.');
   if(!openContext.prepared&&root.dataset.salesGlobalResult!=='1'){const url=new URL(location.href);for(const key of ['pbx_call_id','extension','detalle','esperando_pbx'])url.searchParams.delete(key);location.replace(url.href);}
  }catch(error){if(sameForm()&&!disposed){queued=false;storeDraft();feedback.textContent=error.message;notify(error.message,true);}}
  finally{if(sameForm()&&!disposed){submit.disabled=false;submit.innerHTML=saveLabel;}}
 });
 const closingSnapshot=()=>({context:{...openContext},ref:callRef,extension:callExt,reason:closeReason});
 modal.addEventListener('hide.bs.modal',event=>{
  if(event.defaultPrevented)return;
  const snapshot=closingSnapshot();closingForm=snapshot;
  Promise.resolve().then(()=>{if(event.defaultPrevented&&closingForm===snapshot)closingForm=null;});
 });
 modal.addEventListener('hidden.bs.modal',()=>{
  const closed=closingForm||closingSnapshot();closingForm=null;
  if(closed.context.sessionKey===openContext.sessionKey){++cycle;opening=false;cancelRetry();requestController?.abort();waiting=false;shell()?.endCallResult(token);}
  tellParent({type:'IMPE_SALES_RESULT_FORM_CLOSED',sessionKey:closed.context.sessionKey,ref:closed.ref,extension:closed.extension,reason:closed.reason});
  if(recovered&&!closed.context.prepared){const url=new URL(location.href);for(const key of ['pbx_call_id','extension','detalle','esperando_pbx'])url.searchParams.delete(key);location.replace(url.href);}
  if(closed.reason==='saved')void refreshHistory();
  tellParent({type:'IMPE_SALES_RESULT_FORM_READY',userId:Number(window.IMPE_CURRENT_USER_ID)});
 });
 window.addEventListener('pagehide',()=>{
  storeDraft();disposed=true;++cycle;cancelRetry();clearInterval(historyTimer);requestController?.abort();shell()?.endCallResult(token);
  if(openContext.prepared)tellParent({type:'IMPE_SALES_RESULT_FORM_CLOSED',sessionKey:openContext.sessionKey,ref:callRef,extension:callExt,reason:'navigation'});
 });
 const preparedOpen=context=>{
  if(disposed||!context||!/^\d{3,6}$/.test(String(context.extension||''))||!String(context.sessionKey||''))return false;
  if(context.ref&&!/^[A-Za-z0-9_-]{6,100}$/.test(String(context.ref)))return false;
  if(shell()?.getPhoneStatus?.().active)return false;
  if(opening||closingForm)return false;
  modal.classList.remove?.('fade');
  void open(String(context.ref||''),String(context.extension),0,{...context,prepared:true});return true;
 };
 window.IMPE_SALES_RESULT_FORM=Object.freeze({
  userId:Number(window.IMPE_CURRENT_USER_ID),open:preparedOpen,
  bind(context){
   if(!context||openContext.sessionKey!==context.sessionKey||!context.ref||callRef===context.ref)return false;
   if(call&&call.pbx_call_id!==context.ref)return false;
   if(opening){++cycle;requestController?.abort();opening=false;}
   return preparedOpen(context);
  }
 });
 window.addEventListener('message',e=>{if(e.origin!==location.origin||e.source!==window.parent)return;
  if(e.data?.type==='IMPE_CALL_RESULT_INTERRUPT'){closeReason='interrupt';++cycle;opening=false;cancelRetry();requestController?.abort();recovered=false;storeDraft();bootstrap.Modal.getInstance(modal)?.hide();}
  if(e.data?.type==='IMPE_SALES_RESULT_OPEN')void open(e.data.ref,e.data.extension,0,{sessionKey:e.data.sessionKey||e.data.ref,prepared:false,automatic:false});
  if(e.data?.type==='IMPE_PHONE_CALL_CHANGED')void refreshHistory();
 });
 tellParent({type:'IMPE_SALES_RESULT_FORM_READY',userId:Number(window.IMPE_CURRENT_USER_ID)});
 if(root.dataset.autoCall)void open(root.dataset.autoCall,root.dataset.autoExtension);
})();
