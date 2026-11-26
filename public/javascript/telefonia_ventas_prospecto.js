/* Captura comercial sobre el mismo teléfono persistente; nunca llama al SDK. */
(()=>{'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL,dialog=document.querySelector('[data-sales-prospect-dialog]');
 if(!cfg.salesResults||!phone||!dialog||dialog.dataset.initialized)return;dialog.dataset.initialized='1';
 const form=dialog.querySelector('form'),feedback=dialog.querySelector('[data-sales-prospect-feedback]');
 const crm=document.getElementById('impeCrmFrame');
 const notify=(message,error=false)=>{try{if(typeof crm?.contentWindow?.IMPE_TELEPHONY_NOTIFY==='function'){crm.contentWindow.IMPE_TELEPHONY_NOTIFY(message,error);return;}}catch(_){}window.IMPE_TELEPHONY_NOTIFY?.(message,error);};
 const button=document.createElement('button');button.type='button';button.className='persistent-phone-action';button.textContent='Datos del prospecto';button.dataset.salesProspect='1';button.hidden=true;
 button.setAttribute('aria-controls',dialog.id);button.setAttribute('aria-expanded','false');
 const icon=document.createElement('i');icon.className='bi bi-clipboard-check';icon.setAttribute('aria-hidden','true');button.prepend(icon);
 dialog.setAttribute('role','region');dialog.setAttribute('aria-label','Datos del prospecto');
 const actions=document.querySelector('[data-incoming-panel] .persistent-phone-actions'),close=actions?.querySelector('[data-incoming-close-finished]');
 if(close)actions.insertBefore(button,close);else actions?.append(button);
 const fields=['nombre','telefono','correo','programa','notas'];
 let ref='',localId='',version=0,busy=false,dirty=false,edited=false,cycle=0;
 const key=id=>'impe:sales-prospect:'+cfg.userId+':'+id;
 const valid=id=>/^[A-Za-z0-9_-]{6,100}$/.test(id||'')&&!/^sdk-/i.test(id);
 const tokens=s=>[s?.context?.sdkSessionId,s?.callToken].filter(x=>typeof x==='string'&&x);
 const reference=s=>{const refs=[...new Set([s?.context?.transferReference,s?.finalMetadata?.pbx_call_id,s?.pbxCallId].filter(valid))];return refs.length===1?refs[0]:'';};
 const same=s=>Boolean(localId&&(tokens(s).length?tokens(s).includes(localId):ref&&reference(s)===ref));
 const working=s=>Boolean(s?.active&&s.phase==='in-progress'&&!phone.isResultFormOpen?.());
 const values=()=>Object.fromEntries(fields.map(name=>[name,String(form.elements.namedItem(name)?.value||'')]));
 const bind=()=>{const s=phone.getCallState(),resolved=reference(s);if(same(s)&&!ref&&resolved)ref=resolved;};
 const persist=()=>{
  if(!localId||!dirty)return;
  bind();const draft={at:Date.now(),ref,extension:String(cfg.extension),values:values()};
  try{sessionStorage.setItem(key(localId),JSON.stringify(draft));if(valid(ref))sessionStorage.setItem(key(ref),JSON.stringify(draft));}catch(_){}
 };
 const read=id=>{try{const d=JSON.parse(sessionStorage.getItem(key(id))||'null');return d&&Number(d.at)>0&&Date.now()-d.at<86400000?d:null;}catch(_){return null;}};
 const clearDraft=()=>{try{if(localId)sessionStorage.removeItem(key(localId));if(valid(ref))sessionStorage.removeItem(key(ref));}catch(_){}};
 const freeze=locked=>{for(const el of form.elements)el.disabled=locked;};
 const closePanel=(focus=true)=>{persist();dialog.hidden=true;button.setAttribute('aria-expanded','false');if(focus&&!button.hidden)button.focus({preventScroll:true});};
 const request=async(post=false)=>{
  const sent={ref,version,values:values()},url=new URL(cfg.salesProspectUrl,location.href),identity={pbx_call_id:sent.ref,extension:cfg.extension};
  let options={credentials:'same-origin',cache:'no-store'};
  if(post)options={...options,method:'POST',body:new URLSearchParams({...identity,...sent.values,version:sent.version,csrf_token:cfg.routingCsrf})};else for(const[k,v]of Object.entries(identity))url.searchParams.set(k,v);
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
  try{const r=await fetch(url,{...options,signal:controller.signal}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo recuperar la captura.');return{data:j.captura,sent};}
  catch(error){if(error.name==='AbortError')throw Error('La consulta tardó demasiado. Conserva el borrador y vuelve a intentarlo.');throw error;}finally{clearTimeout(timeout);}
 };
 // El resultado sólo puede recuperar la misma referencia PBX y extensión.
 // Nunca buscar notas por teléfono, fecha ni por el último borrador disponible.
 const forResult=(requested,extension)=>{
  if(String(extension)!==String(cfg.extension)||!valid(requested)||phone.getPhoneStatus?.().active)return null;
  bind();
  if(ref===requested&&localId){closePanel(false);freeze(true);return edited?values():null;}
  const draft=read(requested);
  return draft?.ref===requested&&draft.extension===String(extension)?{...draft.values}:null;
 };
 window.IMPE_SALES_PROSPECT_CAPTURE=Object.freeze({
  forResult,
  closeForResult(requested,extension){if(phone.getPhoneStatus?.().active)return;forResult(requested,extension);closePanel(false);freeze(true);}
 });
 button.addEventListener('click',async()=>{
  if(busy||!working(phone.getCallState()))return;
  if(!dialog.hidden){closePanel();return;}
  persist();busy=true;const ownCycle=++cycle;const s=phone.getCallState();
  ref=reference(s);localId=tokens(s)[0]||ref;version=0;dirty=false;edited=false;freeze(false);form.reset();feedback.textContent='';form.elements.telefono.value=s?.destination||'';
  try{
   if(valid(ref)){const{data}=await request();if(ownCycle!==cycle)return;version=data.version;for(const[k,v]of Object.entries(data.valores||{}))if(form.elements[k])form.elements[k].value=v;}
   else feedback.textContent='Referencia de la llamada pendiente. Los datos se conservan como borrador en esta pestaña.';
  }catch(error){if(ownCycle===cycle)feedback.textContent=error.message;}
  finally{if(ownCycle===cycle)busy=false;}
  if(ownCycle!==cycle)return;
  const drafts=[read(localId),valid(ref)?read(ref):null].filter(d=>d&&(!d.ref||!ref||d.ref===ref)&&(!d.extension||d.extension===String(cfg.extension))).sort((a,b)=>Number(b.at)-Number(a.at));
  if(drafts[0]){for(const[k,v]of Object.entries(drafts[0].values||{}))if(form.elements[k])form.elements[k].value=v;dirty=true;edited=true;}
  // Una respuesta HTTP que llega después de colgar no vuelve a abrir el panel.
  if(!same(phone.getCallState())||!working(phone.getCallState())){closePanel(false);freeze(true);return;}
  dialog.hidden=false;button.setAttribute('aria-expanded','true');dialog.dispatchEvent(new Event('impe:call-capture-layout',{bubbles:true}));form.elements.nombre.focus({preventScroll:true});
 });
 form.addEventListener('input',()=>{if(!working(phone.getCallState())||!same(phone.getCallState()))return;dirty=true;edited=true;persist();});
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||!working(phone.getCallState())||!same(phone.getCallState()))return;
  dirty=true;edited=true;persist();busy=true;const ownCycle=cycle,submit=form.querySelector('[type=submit]');submit.disabled=true;
  try{
   bind();if(!valid(ref))throw Error('La referencia de la llamada aún no está confirmada. El borrador se conserva; vuelve a guardar cuando esté disponible.');
   const{data,sent}=await request(true);if(ownCycle!==cycle||sent.ref!==ref)return;version=data.version;
   if(JSON.stringify(values())===JSON.stringify(sent.values)){dirty=false;clearDraft();feedback.textContent='';notify('Datos guardados. Las notas se recuperarán al registrar el resultado.');}
   else{persist();feedback.textContent='Datos guardados. Los cambios posteriores siguen como borrador.';}
  }catch(error){if(ownCycle===cycle){feedback.textContent=error.message;notify(error.message,true);}}
  finally{if(ownCycle===cycle){busy=false;submit.disabled=!working(phone.getCallState());}}
 });
 dialog.querySelector('[data-sales-prospect-close]').addEventListener('click',()=>closePanel());
 window.addEventListener('keydown',event=>{if(event.key==='Escape'&&!dialog.hidden)closePanel();});
 document.querySelector('[data-incoming-minimize]')?.addEventListener('click',()=>{if(!dialog.hidden)closePanel();});
 window.addEventListener('message',event=>{
  if(event.origin!==location.origin||event.source!==crm?.contentWindow||event.data?.type!=='IMPE_SALES_RESULT_SAVED'||event.data.ref!==ref||String(event.data.extension)!==String(cfg.extension))return;
  clearDraft();dirty=false;edited=false;closePanel(false);freeze(true);++cycle;ref='';localId='';busy=false;
 });
 const render=()=>{
  const s=phone.getCallState();bind();button.hidden=!working(s);button.disabled=busy||button.hidden;
  if((!working(s)||!same(s))&&!dialog.hidden){closePanel(false);freeze(true);}
 };
 setInterval(render,250);render();
})();
