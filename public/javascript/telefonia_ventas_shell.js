/* Observador comercial de la capa compartida. No ejecuta SIP, DTMF ni transferencias. */
(()=>{'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;
 if(!cfg.salesResults||!phone||window.IMPE_SALES_ATTENTION)return;
 const crm=document.getElementById('impeCrmFrame'),panel=document.querySelector('[data-incoming-panel]');
 const key='impe:sales-pending:'+cfg.userId,valid=ref=>/^[A-Za-z0-9_-]{6,100}$/.test(String(ref||''));
 let pending=[],previous=null,scope='',revision=0,fetching=false,metadata=null;
 let acceptedBound=null;
 const digits10=value=>String(value||'').replace(/\D/g,'').slice(-10);
 // El nombre aceptado pertenece a una solicitud única, no al último
 // número marcado. Se ata una sola vez a la nueva invitación SDK o a su
 // referencia PBX exacta y no se hereda en llamadas consecutivas.
 const acceptedFor= (state,ref)=>{
  const op=window.IMPE_AVAILABILITY?.acceptedTransfer?.();
  if(!op?.nombre||!valid(op.referencia)||!state?.active||state.direction!=='incoming')return null;
  const token=String(state.callToken||''),started=Number(state.requestedAt||0)*1000;
  if(!token||!started||started<op.at-20000||started>op.at+90000)return null;
  if(ref&&ref!==op.referencia)return null;
  if(!ref&&state.context?.type!=='SDK_INCOMING')return null;
  const caller=digits10(state.destination),expected=digits10(op.numero);
  if(caller.length===10&&expected.length===10&&caller!==expected)return null;
  if(acceptedBound&&acceptedBound.id!==op.id)acceptedBound=null;
  if(acceptedBound&&!ref&&acceptedBound.token!==token)return null;
  if(!acceptedBound)acceptedBound={id:op.id,token};
  return op;
 };
 try{const data=JSON.parse(sessionStorage.getItem(key)||'[]');if(Array.isArray(data))pending=data.filter(x=>valid(x.ref)&&Date.now()-x.at<86400000).slice(-5);}catch(_){}
 const persist=()=>{try{sessionStorage.setItem(key,JSON.stringify(pending));}catch(_){}};
 const card=document.createElement('div');card.className='impe-availability-source';card.hidden=true;
 const name=document.createElement('strong'),hint=document.createElement('p');card.append(name,hint);
 panel.querySelector('.persistent-phone-origin').after(card);
 const button=document.createElement('button');button.type='button';button.className='persistent-phone-action is-primary';button.textContent='Registrar resultado comercial';button.dataset.salesOpen='1';button.hidden=true;
 panel.querySelector('.persistent-phone-actions').append(button);
 // Los pendientes se notifican desde la campana y los toasts compartidos del CRM.
 const announced=new Set();
 const refreshNotices=()=>{try{const win=crm?.contentWindow;win?.dispatchEvent(new win.Event('impe:phone-notices-refresh'));}catch(_){/* La consulta periódica sigue disponible tras navegar. */}};
 const remove=ref=>{const existed=pending.some(p=>p.ref===ref);phone.dismissFinishedCall?.(ref);pending=pending.filter(p=>p.ref!==ref);persist();render();if(existed)refreshNotices();};
 const open=()=>{const task=pending[0];if(!task||phone.getPhoneStatus().active||!crm)return;
  const url=new URL(cfg.salesHistoryUrl,location.href);url.searchParams.set('pbx_call_id',task.ref);if(task.extension)url.searchParams.set('extension',task.extension);
  if(crm.src===url.href){crm.contentWindow?.postMessage({type:'IMPE_SALES_RESULT_OPEN',ref:task.ref,extension:task.extension},location.origin);return;}
  crm.src=url.href;
 };
 button.addEventListener('click',open);
 const render=()=>{const state=phone.getCallState(),active=Boolean(state?.active),form=phone.isResultFormOpen();button.hidden=!pending.length||active||form;};
 window.IMPE_SALES_ATTENTION=Object.freeze({hasPending:()=>pending.length>0,openPending:open});
 async function lookup(ref,version){
  if(fetching||!valid(ref))return;fetching=true;const requestToken=phone.getCallState()?.callToken;
  try{const url=new URL(cfg.salesDetailUrl,location.href);url.searchParams.set('pbx_call_id',ref);
   if(cfg.extension)url.searchParams.set('extension',cfg.extension);
   const r=await fetch(url,{credentials:'same-origin',cache:'no-store'}),j=await r.json();
   const current=phone.getCallState();
   const currentRef=String(current?.context?.transferReference||current?.finalMetadata?.pbx_call_id||current?.pbxCallId||'');
   if(version!==revision||!r.ok||!j.ok||(current?.active&&(currentRef!==ref||current.callToken!==requestToken)))return;
   metadata=j.llamada;if(!metadata)return;
   if(metadata.registro)remove(ref);
   else if(metadata.finalizada&&!phone.getPhoneStatus().active&&!announced.has(ref)){announced.add(ref);refreshNotices();}
   card.hidden=!current?.active||!metadata.nombre;name.textContent=metadata.nombre||'';
   hint.textContent=metadata.origen_extension?'Nombre informado por Recepción · Ext. '+metadata.origen_extension:'Atención comercial de tu extensión';
  }catch(_){/* El historial conserva el pendiente mientras llega evidencia PBX. */}finally{fetching=false;}
 }
 let lastLookup=0,tries=0;
 function observe(){
  const state=phone.getCallState();if(!state){if(pending[0]&&Date.now()-lastLookup>Math.min(60000,3000*2**tries)){lastLookup=Date.now();tries++;void lookup(pending[0].ref,revision);}render();return;}
  const ref=String(state.context?.transferReference||state.finalMetadata?.pbx_call_id||state.pbxCallId||'');
  const identity=String(state.callToken||'')+':'+ref;
  if(state.active&&identity!==scope){scope=identity;++revision;metadata=null;card.hidden=true;name.textContent='';hint.textContent='';lastLookup=0;tries=0;}
  if(state.active&&!metadata){
   const accepted=acceptedFor(state,ref);
   if(accepted){
    name.textContent=accepted.nombre;
    hint.textContent='Nombre informado en disponibilidad · Ext. '+accepted.origen_extension;
    card.hidden=false;
   }
  }
  if(previous?.active&&!state.active&&valid(ref)&&['finished','incoming-finished'].includes(state.phase)){
   metadata=null;lastLookup=0;tries=0;
   const needs=previous.direction==='outgoing'||previous.phase==='in-progress'||previous.context?.sdkConnected||previous.context?.pbxAnswered;
   if(needs&&!pending.some(p=>p.ref===ref)){
    pending.push({ref,extension:cfg.extension,at:Date.now(),callToken:state.callToken||previous.callToken});persist();
    crm?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);
    // El resultado se abre sólo cuando el usuario lo solicita; evita dos modales superpuestos.
   }
  }
  const currentRef=valid(ref)?ref:state.active?null:pending[0]?.ref;
  if(!state.active)card.hidden=true;
  if(currentRef&&Date.now()-lastLookup>(metadata?60000:Math.min(60000,3000*2**tries))&&(!metadata||(!state.active&&pending.some(p=>p.ref===currentRef)))){
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
