/* Observador comercial de la capa compartida. No ejecuta SIP, DTMF ni transferencias. */
(()=>{'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;
 if(!cfg.salesResults||!phone||window.IMPE_SALES_ATTENTION)return;
 const crm=document.getElementById('impeCrmFrame'),panel=document.querySelector('[data-incoming-panel]');
 const key='impe:sales-pending:'+cfg.userId,valid=ref=>/^[A-Za-z0-9_-]{6,100}$/.test(String(ref||''));
 let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;
 let acceptedBound=null,candidate=null,bound=null;
 const pbx=ref=>valid(ref)&&!/^sdk-/i.test(ref);
 const identities=state=>[state?.callToken,state?.context?.sdkSessionId].filter(x=>typeof x==='string'&&x);
 const overlaps=(a,b)=>a.some(x=>b.includes(x));
 const reference=state=>{
  if(bound&&overlaps(bound.tokens,identities(state)))return bound.ref;
  // Una referencia local SDK no debe ocultar una PBX final posterior.
  const refs=Array.from(new Set([state?.context?.transferReference,state?.finalMetadata?.pbx_call_id,state?.pbxCallId].filter(pbx)));
  return refs.length===1?refs[0]:'';
 };
 const digits10=value=>String(value||'').replace(/\D/g,'').slice(-10);
 // El teléfono compartido (Analista/Ventas) sólo publica una fase final
 // tras END SIP verificado o cierre propio de PBX. No exigir sdkEnded cuando
 // finalizarEntrante() ya publicó incoming-finished desde Zadarma.
 // Esto habilita la captura humana, NO acredita el cierre en BD.
 const phoneFinished=state=>Boolean(state&&!state.active&&
  ['incoming','outgoing'].includes(state.direction)&&
  ['incoming-finished','finished'].includes(state.phase));
 const provisional=state=>{
  if(!phoneFinished(state))return null;
  const ref=reference(state);
  if(pbx(ref))return {ref,extension:cfg.extension,callToken:state.callToken};
  const sameCandidate=Boolean(candidate&&candidate.ref&&pbx(candidate.ref)&&
   [state.callToken,state.context?.sdkSessionId,
    String(state.callToken||'').replace(/-external$/,'')].includes(candidate.token));
  return sameCandidate?{ref:candidate.ref,extension:cfg.extension,callToken:state.callToken}:null;
 };
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
 const announced=new Set(),retired=new Set();
 const refreshNotices=()=>{try{const win=crm?.contentWindow;win?.dispatchEvent(new win.Event('impe:phone-notices-refresh'));}catch(_){/* La consulta periódica sigue disponible tras navegar. */}};
 const remove=(ref,token='')=>{retired.add(ref);const existed=pending.some(p=>p.ref===ref);const own=pending.find(p=>p.ref===ref);phone.dismissFinishedCall?.(ref,token||own?.callToken||'');pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};
 const open=()=>{if(phone.getPhoneStatus().active||!crm)return;
  const state=phone.getCallState(),ref=reference(state);
  const task=(state?.phase==='incoming-finished'||state?.phase==='finished')?
   (pending.find(x=>x.ref===ref)||provisional(state)):pending[0];
  const url=new URL(cfg.salesHistoryUrl,location.href);
  // Sin referencia PBX confirmada sólo puede consultarse el historial.
  if(!task)return;
  if((!metadata?.finalizada||metadata.pbx_call_id!==task.ref)&&
   !(phoneFinished(state)&&provisional(state)?.ref===task.ref)){
   void lookup(task.ref,revision);return;
  }
  url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);
  if(phoneFinished(state)&&(!metadata?.finalizada||metadata.pbx_call_id!==task.ref))url.searchParams.set('esperando_pbx','1');
  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}
  crm.src=url.href;
 };
 button.addEventListener('click',open);
 const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();
  const ref=reference(state);
  const finished=!active&&['finished','incoming-finished'].includes(state?.phase);
  const instant=provisional(state);
  const task=finished?(pending.find(x=>x.ref===ref)||instant):pending[0];
  const confirmed=Boolean(task&&metadata?.pbx_call_id===task.ref&&metadata.finalizada);
  const canOpen=confirmed||Boolean(instant&&task?.ref===instant.ref);
  // Sin referencia de esta sesión, no usar el primer pendiente histórico.
  button.hidden=active||form||(!finished&&!pending.length&&!instant)||(finished&&!task);
  button.disabled=!canOpen;
  button.textContent='Registrar resultado';
  const icon=document.createElement('i');icon.className='bi bi-journal-check';icon.setAttribute?.('aria-hidden','true');button.prepend?.(icon);
  button.dataset.salesPending=task?'1':'0';
 };
 window.IMPE_SALES_ATTENTION=Object.freeze({hasPending:()=>pending.length>0,openPending:open});
 function adopt(facts,state,expectedRef=''){
  if(!facts||!state||!pbx(facts.pbx_call_id)||String(facts.extension)!==String(cfg.extension)||retired.has(facts.pbx_call_id))return false;
  const ref=facts.pbx_call_id,keys=identities(state);
  if(keys.some(x=>/^sdk-/.test(x))&&facts.sdk_tokens?.length&&!overlaps(keys,facts.sdk_tokens))return false;
  if(expectedRef?ref!==expectedRef:!overlaps(keys,Array.isArray(facts.sdk_tokens)?facts.sdk_tokens:[]))return false;
  if(metadata?.pbx_call_id===ref&&metadata.finalizada&&!facts.finalizada)return false;
  metadata=facts;bound={ref,tokens:keys};
  if(facts.finalizada){
   if(facts.registro){remove(ref);return true;}
   const task=pending.find(p=>p.ref===ref);
   if(task)task.callToken=state.callToken;
   else pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken});
   persist();
   phone.confirmFinishedCall?.(ref,facts,state.callToken);
   if(!announced.has(ref)){announced.add(ref);refreshNotices();crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);}
  }
  render();return true;
 }
 async function lookup(ref,version){
  const initial=phone.getCallState(),sdk=identities(initial).find(x=>/^sdk-/.test(x));
  if(fetching||(!pbx(ref)&&!candidate&&!sdk))return;fetching=true;const requestKeys=identities(initial);
  const controller=typeof AbortController==='function'?new AbortController():null;
  const timeout=controller?setTimeout(()=>controller.abort(),10000):null;
  try{const url=new URL(cfg.salesDetailUrl,location.href);
   if(pbx(ref))url.searchParams.set('pbx_call_id',ref);
   else if(candidate){url.searchParams.set('solicitud_id',candidate.id);url.searchParams.set('sdk',candidate.token);}
   else url.searchParams.set('sdk',sdk);
   if(cfg.extension)url.searchParams.set('extension',cfg.extension);
   const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...(controller?{signal:controller.signal}:{})}),j=await r.json();
   const current=phone.getCallState();
   const currentRef=reference(current);
   const idle=s=>!s||(!s.active&&['ready','idle'].includes(s.phase)&&identities(s).length===0);
   if(idle(initial)&&idle(current)&&version===revision&&r.ok&&j.ok&&pbx(ref)&&pending.some(p=>p.ref===ref)){
    const facts=j.llamada;
    if(facts?.pbx_call_id===ref&&String(facts.extension)===String(cfg.extension)&&!retired.has(ref)){
     metadata=facts;if(facts.registro)remove(ref);
    }
    return;
   }
   if(version!==revision||!r.ok||!j.ok||!overlaps(identities(current),requestKeys)||(pbx(ref)&&current?.active&&currentRef!==ref))return;
   if(!adopt(j.llamada,current,pbx(ref)?ref:''))return;
   card.hidden=!current?.active||!metadata.nombre;name.textContent=metadata.nombre||'';
   hint.textContent=metadata.origen_extension?'Nombre informado por Recepción · Ext. '+metadata.origen_extension:'Atención comercial de tu extensión';
  }catch(_){/* El historial conserva el pendiente mientras llega evidencia PBX. */}finally{if(timeout!==null)clearTimeout(timeout);fetching=false;render();}
 }
 let lastLookup=0,tries=0,lastPending=0,pendingBusy=false;
 async function recoverPending(){
  if(pendingBusy||Date.now()-lastPending<10000)return;pendingBusy=true;lastPending=Date.now();
  const version=revision,requestKeys=identities(phone.getCallState());
  try{const url=new URL(cfg.salesDetailUrl,location.href);url.searchParams.set('pendientes','1');
   const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),data=await response.json();
   if(response.ok&&data.ok&&Array.isArray(data.pendientes)){
    const rows=data.pendientes.filter(c=>pbx(c.pbx_call_id)&&c.finalizada===true&&String(c.extension)===String(cfg.extension)&&!retired.has(c.pbx_call_id));
    for(const c of rows)if(!pending.some(p=>p.ref===c.pbx_call_id))pending.push({ref:c.pbx_call_id,extension:c.extension,at:Date.now()});
    persist();
    const current=phone.getCallState(),ref=reference(current);
    if(version===revision&&current&&!current.active&&overlaps(identities(current),requestKeys)){
     const matches=rows.filter(c=>pbx(ref)?c.pbx_call_id===ref:overlaps(identities(current),c.sdk_tokens||[]));
     if(matches.length===1)adopt(matches[0],current,pbx(ref)?ref:'');
    }
   }
  }catch(_){}finally{pendingBusy=false;render();}
 }
 void recoverPending();
 function observe(){
  const state=phone.getCallState();if(!state){if(pending[0]&&Date.now()-lastLookup>Math.min(60000,3000*2**tries)){lastLookup=Date.now();tries++;void lookup(pending[0].ref,revision);}render();return;}
  let ref=reference(state);
  const identity=String(state.callToken||'')+':'+ref;
  if(state.active&&identity!==scope){
   const nextCall=previous&&(!previous.active||!overlaps(identities(previous),identities(state)));
   if(nextCall){candidate=null;bound=null;ref=reference(state);}
   if(nextCall||!scope||(pbx(ref)&&metadata&&metadata.pbx_call_id!==ref)){++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';}
   scope=String(state.callToken||'')+':'+ref;lastLookup=0;tries=0;
  }
  if(state.active&&!metadata){
   const accepted=acceptedFor(state,ref);
   if(accepted){
    candidate={id:Number(accepted.id),token:state.callToken,ref:accepted.referencia};
    name.textContent=accepted.nombre;
    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;
    card.hidden=false;phone.bindTransferCandidate?.(Number(accepted.id));
   }
  }
  if(previous?.active&&!state.active&&pbx(ref)&&['finished','incoming-finished'].includes(state.phase)){
   if(metadata?.pbx_call_id===ref&&metadata.finalizada)adopt(metadata,state,ref);
   else metadata=null;
   lastLookup=0;tries=0;
   // También una llamada no contestada necesita poder consultar su resultado.
   // La referencia PBX y la finalización se validan en el servidor.
   const needs=previous.direction==='outgoing'||previous.direction==='incoming';
   if(needs&&!pending.some(p=>p.ref===ref)){
    pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken||previous.callToken});persist();
    crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);
    // El resultado se abre sólo cuando el usuario lo solicita; evita dos modales superpuestos.
   }
  }
  const currentRef=pbx(ref)?ref:'';
  if(!state.active){card.hidden=true;void recoverPending();}
  if((currentRef||candidate||identities(state).some(x=>/^sdk-/.test(x)))&&Date.now()-lastLookup>(metadata?.finalizada?60000:Math.min(10000,1500*2**Math.min(tries,3)))&&(!metadata||!state.active)){
   lastLookup=Date.now();tries++;void lookup(currentRef,revision);
  }
  previous=state;render();
 }
 window.addEventListener('message',e=>{
  if(e.origin!==location.origin||e.source!==crm?.contentWindow||e.data?.type!=='IMPE_SALES_RESULT_SAVED'||!valid(e.data.ref))return;
  // El mensaje sólo actualiza UX; toda consulta/guardado se autoriza en servidor.
  const task=pending.find(p=>p.ref===e.data.ref&&p.extension===e.data.extension);
  // El mensaje del iframe es sólo UX. Sin pendiente del propietario exacto,
  // no borrar un resumen que pueda pertenecer a otra llamada.
  if(!task)return;
  remove(e.data.ref,task.callToken||'');
  if(task.callToken&&!phone.getPhoneStatus().active){try{localStorage.setItem('impe:telephony:command:'+cfg.userId,JSON.stringify({type:'COMMAND',action:'CLEAR_FINISHED',payload:{callToken:task.callToken},id:'sales-clear-'+Date.now()}));}catch(_){}}
 });
 setInterval(observe,750);observe();
})();
