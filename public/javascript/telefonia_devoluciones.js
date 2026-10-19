document.addEventListener('DOMContentLoaded',()=>{
 'use strict';const root=document.querySelector('[data-callbacks]');if(!root)return;
 const $=s=>root.querySelector(s),modal=document.getElementById('modalLlamadaEntranteAnalista'),form=modal.querySelector('form')||modal.querySelector('[data-callback-result-form]');
 const phone=window.IMPE_TELEPHONY_PERSISTENT,labels={CONTACTO_CORRECTO:'Contacto correcto',CONTACTO_REFERIDO:'Contacto referido',SOLICITO_INFORMACION:'Solicitó información',NO_INTERESADO:'No interesado',OTRO:'Otro',OCUPADO:'Ocupado',BUZON_VOZ:'Buzón de voz',FUERA_SERVICIO:'Fuera de servicio',SOLICITO_LLAMAR_DESPUES:'Llamar después',CONTACTO:'Persona atendió',BUZON:'Buzón de voz',SIN_RESPUESTA:'Sin respuesta',NO_DISPONIBLE:'No disponible',NUMERO_INCORRECTO:'Número incorrecto',LLAMAR_DESPUES:'Llamar después'};
 let tasks=JSON.parse($('[data-callback-data]').textContent||'[]'),page=1,busy=false,opened=null,following=0,checking=false,autoOpened=0;
 const focusAttempt=Number(root.dataset.focusAttempt||0);
 let focusHandled=false;
 const notify=(m,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(m,error);
 const node=(tag,cls,text)=>{const e=document.createElement(tag);e.className=cls||'';if(text!==undefined)e.textContent=String(text);return e;};
 const api=async(data=null,id=0)=>{const url=new URL(root.dataset.endpoint,location.href),opts={credentials:'same-origin',cache:'no-store'};
  if(data){opts.method='POST';opts.body=new URLSearchParams({...data,csrf_token:root.dataset.csrf});}else if(id)url.searchParams.set('intento_id',id);
  const r=await fetch(url,opts),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo actualizar la devolución.');return j;};
 const taskFor=id=>tasks.find(t=>Number(t.id)===Number(id));
 const action=(text,icon,fn)=>{const b=node('button','linkage-incoming-open');b.type='button';const i=node('i','bi '+icon);i.setAttribute('aria-hidden','true');b.append(i,document.createTextNode(text));b.addEventListener('click',fn);return b;};
 const render=()=>{
  const pending=tasks.filter(t=>t.devolucion==='PENDIENTE');
  for(const [key,value] of Object.entries({pending:pending.length,retry:pending.filter(t=>t.intento?.resultado).length,done:tasks.length-pending.length,total:tasks.length}))$('[data-callback-stat="'+key+'"]').textContent=value;
  const q=$('[data-callback-search]').value.trim().toLocaleLowerCase(),filter=$('[data-callback-filter]').value;
  const selected=tasks.filter(t=>(filter==='TODAS'||t.devolucion===filter)&&[t.contacto,t.telefono,t.nota,t.institucion_nombre].join(' ').toLocaleLowerCase().includes(q));
  const pages=Math.max(1,Math.ceil(selected.length/10));page=Math.min(page,pages);const list=$('[data-callback-list]');list.replaceChildren();$('[data-callback-count]').textContent=selected.length;
  for(const task of selected.slice((page-1)*10,page*10)){
   const card=node('article','linkage-call-history-card linkage-incoming-card'),body=node('div','linkage-call-recording-body'),top=node('div','linkage-call-recording-top'),identity=node('div','linkage-call-history-identity');card.dataset.callbackId=task.id;card.setAttribute('role','listitem');
   if(focusAttempt>0 && Number(task.intento?.id)===focusAttempt){
    card.classList.add('impe-incoming-targeted');card.tabIndex=-1;
    if(!focusHandled){focusHandled=true;setTimeout(()=>{card.scrollIntoView({behavior:'smooth',block:'center'});card.focus({preventScroll:true});},160);}
   }
   const icon=node('span','linkage-call-recording-card-icon');icon.innerHTML='<i class="bi bi-telephone-forward" aria-hidden="true"></i>';card.append(icon,body);
   identity.append(node('strong','',task.institucion_nombre||task.contacto),node('span','',task.contacto+' · '+task.telefono));
   const date=new Date(task.created_at.replace(' ','T')+'Z');top.append(identity,node('span','linkage-call-history-date','Recepción · Ext. '+task.origen_extension+' · '+date.toLocaleString('es-MX',{dateStyle:'short',timeStyle:'short'})));body.append(top);
   const details=node('div','linkage-call-detail');const detail=(label,value)=>{const row=node('div','linkage-call-detail-row');row.append(node('span','linkage-call-detail-label',label),node('span','linkage-call-detail-value',value));details.append(row);};
   detail('Motivo',task.nota||'Sin nota de recepción');body.append(details);
   const last=task.intento,done=task.devolucion==='ATENDIDA';const badge=node('span','linkage-call-result-badge '+(done?'is-contact':'is-no-contact'),last?.resultado?labels[last.resultado]:(done?'Atendida':'Por devolver'));
   if(last?.observacion)detail('Último intento',last.observacion);
   const footer=node('div','linkage-call-history-footer'),actions=node('div','linkage-call-result-group');footer.append(actions);actions.append(badge);
   if(!done){
    if(last&&!last.resultado&&last.estado!=='CANCELADO'){
     const review=action('Registrar resultado','bi-journal-check',()=>void openResult(last.id));review.disabled=busy;actions.append(review);
     if(!last.pbx_call_id){const discard=action('Revisar preparación','bi-arrow-clockwise',()=>void discardAttempt(last.id));discard.disabled=busy;actions.append(discard);}
    }else{const call=action('Llamar','bi-telephone-outbound',()=>void callTask(task));call.disabled=busy||!phone;actions.append(call);}
   }
   if(Number(task.seguimiento_id)>0){const a=node('a','linkage-incoming-open','Ver expediente');a.href='index.php?controller=seguimientoVinculacion&action=detalle&id='+Number(task.seguimiento_id);actions.append(a);}
   if(last?.resultado){const detail=action('Consultar resultado','bi-journal-text',()=>notify(labels[last.resultado]+(last.observacion?' · '+last.observacion:'')));actions.append(detail);}
   body.append(footer);list.append(card);
  }
  if(!selected.length)list.append(node('div','linkage-incoming-empty-panel','No hay devoluciones para esta selección.'));
  $('[data-callback-page]').textContent=page+' / '+pages;
  $('[data-callback-range]').textContent='Mostrando '+(selected.length?(page-1)*10+1:0)+'–'+Math.min(page*10,selected.length)+' de '+selected.length+' devoluciones';$('[data-callback-prev]').disabled=page<=1;$('[data-callback-next]').disabled=page>=pages;
 };
 const refresh=async()=>{const data=await api();tasks=data.devoluciones;render();};
 const openResult=async(id,quiet=false)=>{
  if(opened)return;
  try{const data=await api(null,id),attempt=data.intento;
   if(!attempt?.puede_registrar){if(!quiet)notify(attempt?.resultado?'El resultado ya está guardado.':'Espera a que se confirme el fin de la llamada.',true);return false;}
   const task=taskFor(attempt.solicitud_id);if(!task)throw Error('La devolución ya no está disponible.');
   const shell=window.parent?.IMPE_TELEPHONY_SHELL;
   if(shell && !shell.beginCallbackResult(Number(id)))return false;
   opened=attempt;const trigger=document.createElement('button');
   const duration=Math.max(0,Number(attempt.duracion)||0),suggestions={'Sin respuesta':'SIN_RESPUESTA','Ocupada':'OCUPADO','Número inexistente':'NUMERO_INCORRECTO','No disponible':'FUERA_SERVICIO'};
   Object.assign(trigger.dataset,{callId:attempt.pbx_call_id,attemptId:String(id),phone:task.telefono,answer:attempt.contestada?'1':'0',duration:String(Math.floor(duration/60)).padStart(2,'0')+':'+String(duration%60).padStart(2,'0'),followupId:task.seguimiento_id?String(task.seguimiento_id):'',institution:task.institucion_nombre||'',suggestedResult:suggestions[attempt.estado_llamada]||''});
   window.dispatchEvent(new CustomEvent('impe:callback-result-open',{detail:trigger}));return true;
  }catch(e){if(!quiet)notify(e.message,true);return false;}
 };
 const callTask=async task=>{
  if(busy)return;let attempt;busy=true;render();
  try{
   const status=window.parent?.IMPE_TELEPHONY_SHELL?.getPhoneStatus?.();
   if(status?.pending){window.parent.IMPE_TELEPHONY_SHELL.openPendingResult?.();throw Error('Registra el resultado anterior antes de llamar.');}
   if(phone?.getState?.()?.active)throw Error('Finaliza primero la llamada activa.');
   attempt=(await api({accion:'PREPARAR',solicitud_id:task.id})).intento;following=Number(attempt.id);autoOpened=0;
   await phone.startCall({destination:attempt.numero,institution:task.institucion_nombre||task.contacto,context:{type:'DEVOLUCION',callbackSolicitud:Number(task.id),callbackAttempt:Number(attempt.id)}});
   await refresh();
  }catch(e){if(attempt){try{await api({accion:'CANCELAR',intento_id:attempt.id});}catch(_){}following=0;}notify(e.message,true);}
  finally{busy=false;render();}
 };
 const discardAttempt=async id=>{try{if(phone?.getState?.()?.active)throw Error('Todavía tienes una llamada activa.');await api({accion:'CANCELAR',intento_id:id});following=0;await refresh();notify('Preparación descartada. Puedes volver a llamar.');}catch(e){notify(e.message,true);}};
 const check=async()=>{
  if(checking||!following||opened||phone?.getState?.()?.active)return;checking=true;
  try{const d=await api(null,following);if(d.intento?.puede_registrar&&autoOpened!==following){if(await openResult(following,true))autoOpened=following;}if(d.intento?.resultado){following=0;}}
  catch(_){}finally{checking=false;}
 };
 phone?.subscribe?.(state=>{if(state?.context?.type==='DEVOLUCION'&&Number(state.context.callbackAttempt)>0){following=Number(state.context.callbackAttempt);if(!state.active)void check();}});
 modal.addEventListener('impe:callback-result-saved',e=>{
  const attempt=e.detail;following=0;
  notify(e.detail?.atencion?.verificacion_confirmada?'Atención guardada con verificación efectiva. Máximo una institución por día.':'Resultado guardado.');
  window.parent.postMessage({type:'IMPE_CALLBACK_RESULT_SAVED',attemptId:Number(attempt.id)},location.origin);
  window.dispatchEvent(new Event('impe:phone-notices-refresh'));void refresh().catch(e=>notify(e.message,true));
 });
 modal.addEventListener('hidden.bs.modal',()=>{window.parent?.IMPE_TELEPHONY_SHELL?.endCallbackResult?.(Number(opened?.id));opened=null;});
 phone?.subscribe?.(state=>{if(state.active && modal.classList.contains('show'))window.dispatchEvent(new Event('impe:callback-result-interrupt'));});
 window.addEventListener('pagehide',()=>window.parent?.IMPE_TELEPHONY_SHELL?.endCallbackResult?.(Number(opened?.id)));
 $('[data-callback-search]').addEventListener('input',()=>{page=1;render();});$('[data-callback-filter]').addEventListener('change',()=>{page=1;render();});$('[data-callback-prev]').addEventListener('click',()=>{page--;render();});$('[data-callback-next]').addEventListener('click',()=>{page++;render();});$('[data-callback-refresh]').addEventListener('click',()=>void refresh().catch(e=>notify(e.message,true)));
 if(focusAttempt>0){
  const targetTask=tasks.find(t=>Number(t.intento?.id)===focusAttempt);
  if(targetTask){
   $('[data-callback-filter]').value='TODAS';
   $('[data-callback-search]').value='';
   page=Math.floor(tasks.findIndex(t=>Number(t.id)===Number(targetTask.id))/10)+1;
  }
 }
 window.setInterval(()=>void check(),2500);render();
 const initial=Number(root.dataset.autoAttempt);if(initial){following=initial;void openResult(initial);}
});
