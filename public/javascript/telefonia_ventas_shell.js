/* Observador comercial de la capa compartida. No ejecuta SIP, DTMF ni transferencias. */
(()=>{'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;
 if(!cfg.salesResults||!phone||window.IMPE_SALES_ATTENTION)return;
 const crm=document.getElementById('impeCrmFrame'),panel=document.querySelector('[data-incoming-panel]');
 const key='impe:sales-pending:'+cfg.userId,valid=ref=>/^[A-Za-z0-9_-]{6,100}$/.test(String(ref||''));
 let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;
 let acceptedBound=null,candidate=null,bound=null;
 const pbx=ref=>valid(ref)&&!/^sdk-/i.test(ref);
 const reference=state=>bound&&[bound.token,bound.token+'-external'].includes(String(state?.callToken))?bound.ref:String(state?.context?.transferReference||state?.finalMetadata?.pbx_call_id||state?.pbxCallId||'');
 const digits10=value=>String(value||'').replace(/\D/g,'').slice(-10);
 // El nombre aceptado pertenece a una solicitud única, no al último
 // número marcado. Se ata una sola vez a la nueva invitación SDK o a su
 // referencia PBX exacta y no se hereda en llamadas consecutivas.
 const acceptedFor= (state,ref)=>{
  const op=window.IMPE_AVAILABILITY?.acceptedTransfer?.();
  if(!op?.nombre||!valid(op.referencia)||!state?.active||state.direction!=='incoming')return null;
  const token=String(state.callToken||''),started=Number(state.requestedAt||0)*1000;
  if(!token||!started||started<op.at-20000||started>op.at+90000)return null;
  if(pbx(ref)&&ref!==op.referencia)return null;
  if(!pbx(ref)&&state.context?.type!=='SDK_INCOMING')return null;
  const caller=digits10(state.destination),expected=digits10(op.numero);
  if(caller.length===10&&expected.length===10&&caller!==expected)return null;
  if(acceptedBound&&acceptedBound.id!==op.id)acceptedBound=null;
  if(acceptedBound&&!pbx(ref)&&acceptedBound.token!==token)return null;
  if(!acceptedBound)acceptedBound={id:op.id,token};
  return op;
 };
 try{const data=JSON.parse(localStorage.getItem(key)||sessionStorage.getItem(key)||'[]');if(Array.isArray(data))pending=data.filter(x=>pbx(x.ref)&&Date.now()-x.at<7*86400000).slice(-40);}catch(_){}
 const persist=()=>{try{localStorage.setItem(key,JSON.stringify(pending.slice(-40)));sessionStorage.removeItem(key);}catch(_){}};
 const card=document.createElement('div');card.className='impe-availability-source';card.hidden=true;
 const name=document.createElement('strong'),hint=document.createElement('p');card.append(name,hint);
 panel.querySelector('.persistent-phone-origin').after(card);
 const button=panel.querySelector('[data-incoming-register-finished]')||document.createElement('button');button.type='button';button.className='persistent-phone-action is-primary';button.textContent='Registrar resultado';button.dataset.incomingRegisterFinished='';button.hidden=true;
 const actions=panel.querySelector('.persistent-phone-actions'),close=actions?.querySelector('[data-incoming-close-finished]');
 if(close)actions.insertBefore(button,close);else actions?.append(button);
 // Los pendientes se notifican desde la campana y los toasts compartidos del CRM.
 const announced=new Set();
 const refreshNotices=()=>{try{const win=crm?.contentWindow;win?.dispatchEvent(new win.Event('impe:phone-notices-refresh'));}catch(_){/* La consulta periódica sigue disponible tras navegar. */}};
 const remove=ref=>{const existed=pending.some(p=>p.ref===ref);phone.dismissFinishedCall?.(ref);pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};
 const open=()=>{if(phone.getPhoneStatus().active||!crm)return;
  const state=phone.getCallState(),ref=reference(state);
  const task=state?.phase==='incoming-finished'||state?.phase==='finished'?pending.find(x=>x.ref===ref):pending[pending.length-1];
  const url=new URL(cfg.salesHistoryUrl,location.href);
  // Sin referencia PBX confirmada sólo puede consultarse el historial.
  if(!task)return;
  if(!metadata?.finalizada||metadata.pbx_call_id!==task.ref){void lookup(task.ref,revision);return;}
  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);
  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}
  crm.src=url.href;
 };
 button.addEventListener('click',open);
 const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();
  const ref=reference(state);
  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);
  const task=finished?pending.find(x=>x.ref===ref):pending[pending.length-1];
  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);
  button.hidden=active||form||(!finished&&!pending.length);
  button.disabled=!confirmed;
  button.textContent=confirmed?'Registrar resultado':'Conciliando llamada';
  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);
  button.dataset.salesPending=task?'1':'0';
 };
 window.IMPE_SALES_ATTENTION=Object.freeze({hasPending:()=>pending.length>0,openPending:open});
 async function lookup(ref,version){
  if(fetching||(!pbx(ref)&&!candidate))return;fetching=true;const requestToken=phone.getCallState()?.callToken;
  const controller=typeof AbortController==='function'?new AbortController():null;
  const timeout=controller?setTimeout(()=>controller.abort(),10000):null;
  try{const url=new URL(cfg.salesDetailUrl,location.href);
   if(pbx(ref))url.searchParams.set('pbx_call_id',ref);
   else {url.searchParams.set('solicitud_id',candidate.id);url.searchParams.set('sdk',candidate.token);}
   if(cfg.extension)url.searchParams.set('extension',cfg.extension);
   const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...(controller?{signal:controller.signal}:{})}),j=await r.json();
   const current=phone.getCallState();
   const currentRef=reference(current);
   if(version!==revision||!r.ok||!j.ok||current?.callToken!==requestToken||(pbx(ref)&&current?.active&&currentRef!==ref))return;
   metadata=j.llamada;if(!metadata)return;
   ref=metadata.pbx_call_id;
   if(!pbx(ref)||String(metadata.extension)!==String(cfg.extension))return;
   if(candidate&&candidate.token===requestToken)bound={token:requestToken,ref};
   if(metadata.finalizada&&!current?.active){
    if(!metadata.registro&&!pending.some(p=>p.ref===ref)){pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:requestToken});persist();}
    phone.confirmFinishedCall?.(ref,metadata,requestToken);
   }
   if(metadata.registro)remove(ref);
   else if(metadata.finalizada&&!phone.getPhoneStatus().active&&!announced.has(ref)){announced.add(ref);refreshNotices();crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);}
   card.hidden=!current?.active||!metadata.nombre;name.textContent=metadata.nombre||'';
   hint.textContent=metadata.origen_extension?'Nombre informado por Recepción · Ext. '+metadata.origen_extension:'Atención comercial de tu extensión';
  }catch(_){/* El historial conserva el pendiente mientras llega evidencia PBX. */}finally{if(timeout!==null)clearTimeout(timeout);fetching=false;render();}
 }
 let lastLookup=0,tries=0,lastPending=0,pendingBusy=false;
 async function recoverPending(){
  if(pendingBusy||Date.now()-lastPending<10000)return;pendingBusy=true;lastPending=Date.now();
  try{const url=new URL(cfg.salesDetailUrl,location.href);url.searchParams.set('pendientes','1');
   const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),data=await response.json();
   if(response.ok&&data.ok&&Array.isArray(data.pendientes)){
    for(const c of data.pendientes)if(valid(c.pbx_call_id)&&String(c.extension)===String(cfg.extension)&&!pending.some(p=>p.ref===c.pbx_call_id))pending.push({ref:c.pbx_call_id,extension:c.extension,at:Date.now()});
    persist();
   }
  }catch(_){}finally{pendingBusy=false;}
 }
 void recoverPending();
 function observe(){
  const state=phone.getCallState();if(!state){if(pending[0]&&Date.now()-lastLookup>Math.min(60000,3000*2**tries)){lastLookup=Date.now();tries++;void lookup(pending[0].ref,revision);}render();return;}
  const ref=reference(state);
  const identity=String(state.callToken||'')+':'+ref;
  if(state.active&&identity!==scope){if(previous?.callToken!==state.callToken&&!String(state.callToken).endsWith('-external')){candidate=null;bound=null;}scope=identity;++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';lastLookup=0;tries=0;}
  if(state.active&&!metadata){
   const accepted=acceptedFor(state,ref);
   if(accepted){
    candidate={id:Number(accepted.id),token:state.callToken};
    name.textContent=accepted.nombre;
    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;
    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));
   }
  }
  if(previous?.active&&!state.active&&pbx(ref)&&['finished','incoming-finished'].includes(state.phase)){
   metadata=null;lastLookup=0;tries=0;
   // También una llamada no contestada necesita poder consultar su resultado.
   // La referencia PBX y la finalización se validan en el servidor.
   const needs=previous.direction==='outgoing'||previous.direction==='incoming';
   if(needs&&!pending.some(p=>p.ref===ref)){
    pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken||previous.callToken});persist();
    crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);
    // El resultado se abre sólo cuando el usuario lo solicita; evita dos modales superpuestos.
   }
  }
  const currentRef=pbx(ref)?ref:candidate?ref:state.active?null:pending[0]?.ref;
  if(!state.active){card.hidden=true;void recoverPending();}
  if((currentRef||candidate)&&Date.now()-lastLookup>(metadata?.finalizada?60000:Math.min(10000,1500*2**Math.min(tries,3)))&&(!metadata||!state.active)){
   lastLookup=Date.now();tries++;void lookup(currentRef,revision);
  }
  previous=state;render();
 }
 window.addEventListener('message',e=>{
  if(e.origin!==location.origin||e.source!==crm?.contentWindow||e.data?.type!=='IMPE_SALES_RESULT_SAVED'||!valid(e.data.ref))return;
  // El mensaje sólo actualiza UX; toda consulta/guardado se autoriza en servidor.
  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);remove(e.data.ref);
  if(task&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}
 });
 setInterval(observe,750);observe();
})();
