document.addEventListener('DOMContentLoaded',()=>{
 'use strict';const root=document.querySelector('[data-callbacks]');if(!root)return;
 const $=s=>root.querySelector(s),modal=document.getElementById('modalResultadoDevolucion'),form=modal.querySelector('form')||modal.querySelector('[data-callback-result-form]');
 const phone=window.IMPE_TELEPHONY_PERSISTENT,labels={CONTACTO:'Persona atendió',BUZON:'Buzón de voz',SIN_RESPUESTA:'Sin respuesta',NO_DISPONIBLE:'No disponible',NUMERO_INCORRECTO:'Número incorrecto',LLAMAR_DESPUES:'Llamar después'};
 let closingAfterSave=false;
 let tasks=JSON.parse($('[data-callback-data]').textContent||'[]'),page=1,busy=false,saving=false,opened=null,following=0,checking=false,autoOpened=0,searchSequence=0,searchTimer;
 const notify=(m,error=false)=>window.IMPE_TELEPHONY_NOTIFY?.(m,error);
 const node=(tag,cls,text)=>{const e=document.createElement(tag);e.className=cls||'';if(text!==undefined)e.textContent=String(text);return e;};
 const api=async(data=null,id=0)=>{const url=new URL(root.dataset.endpoint,location.href),opts={credentials:'same-origin',cache:'no-store'};
  if(data){opts.method='POST';opts.body=new URLSearchParams({...data,csrf_token:root.dataset.csrf});}else if(id)url.searchParams.set('intento_id',id);
  const r=await fetch(url,opts),j=await r.json();if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudo actualizar la devolución.');return j;};
 const taskFor=id=>tasks.find(t=>Number(t.id)===Number(id));
 const action=(text,icon,fn,primary=false)=>{const b=node('button',primary?'is-primary':'');b.type='button';b.innerHTML='<i class="bi '+icon+'" aria-hidden="true"></i>';b.append(document.createTextNode(text));b.addEventListener('click',fn);return b;};
 const render=()=>{
  const pending=tasks.filter(t=>t.devolucion==='PENDIENTE');
  for(const [key,value] of Object.entries({pending:pending.length,retry:pending.filter(t=>t.intento?.resultado).length,done:tasks.length-pending.length,total:tasks.length}))$('[data-callback-stat="'+key+'"]').textContent=value;
  const q=$('[data-callback-search]').value.trim().toLocaleLowerCase(),filter=$('[data-callback-filter]').value;
  const selected=tasks.filter(t=>(filter==='TODAS'||t.devolucion===filter)&&[t.contacto,t.telefono,t.nota,t.institucion_nombre].join(' ').toLocaleLowerCase().includes(q));
  const pages=Math.max(1,Math.ceil(selected.length/8));page=Math.min(page,pages);const list=$('[data-callback-list]');list.replaceChildren();$('[data-callback-count]').textContent=selected.length;
  for(const task of selected.slice((page-1)*8,page*8)){
   const card=node('article','linkage-call-history-card'),top=node('div','linkage-call-history-top'),identity=node('div','linkage-call-history-identity');card.dataset.callbackId=task.id;
   identity.append(node('strong','',task.institucion_nombre||task.contacto),node('span','',task.contacto+' · '+task.telefono));
   const date=new Date(task.created_at.replace(' ','T')+'Z');top.append(identity,node('span','linkage-call-history-date','Recepción · Ext. '+task.origen_extension+' · '+date.toLocaleString('es-MX',{dateStyle:'short',timeStyle:'short'})));card.append(top);
   card.append(node('p','impe-callback-note',task.nota||'Sin nota de recepción'));
   const last=task.intento,done=task.devolucion==='ATENDIDA';card.append(node('span','impe-callback-state '+(done?'is-done':last?.resultado?'is-retry':''),last?.resultado?labels[last.resultado]:(done?'Atendida':'Por devolver')));
   if(last?.observacion)card.append(node('p','impe-callback-note','Último intento: '+last.observacion));
   const actions=node('div','impe-callback-actions');actions.style.marginTop='12px';
   if(!done){
    if(last&&!last.resultado&&last.estado!=='CANCELADO'){
     const review=action('Registrar resultado','bi-journal-check',()=>void openResult(last.id));review.disabled=busy;actions.append(review);
     if(!last.pbx_call_id){const discard=action('Revisar preparación','bi-arrow-clockwise',()=>void discardAttempt(last.id));discard.disabled=busy;actions.append(discard);}
    }else{const call=action('Llamar','bi-telephone-outbound',()=>void callTask(task),true);call.disabled=busy||!phone;actions.append(call);}
   }
   if(Number(task.seguimiento_id)>0){const a=node('a','','Ver expediente');a.href='index.php?controller=seguimientoVinculacion&action=detalle&id='+Number(task.seguimiento_id);actions.append(a);}
   if(last?.resultado){const detail=action('Consultar resultado','bi-journal-text',()=>notify(labels[last.resultado]+(last.observacion?' · '+last.observacion:'')));actions.append(detail);}
   card.append(actions);list.append(card);
  }
  if(!selected.length)list.append(node('div','impe-callback-empty','No hay devoluciones para esta selección.'));
  $('[data-callback-page]').textContent=page+' / '+pages;$('[data-callback-prev]').disabled=page<=1;$('[data-callback-next]').disabled=page>=pages;
 };
 const refresh=async()=>{const data=await api();tasks=data.devoluciones;render();};
 const identity=()=>{const kind=form.elements.identificacion.value;modal.querySelector('[data-callback-existing]').hidden=kind!=='EXISTENTE';modal.querySelector('[data-callback-new]').hidden=kind!=='NUEVA';form.elements.seguimiento_id.required=kind==='EXISTENTE';form.elements.institucion_nombre.required=kind==='NUEVA';};
 const openResult=async(id,quiet=false)=>{
  if(saving||opened)return;try{const data=await api(null,id),attempt=data.intento;if(data.devoluciones)tasks=data.devoluciones;render();
   if(!attempt?.puede_registrar){if(!quiet)notify(attempt?.resultado?'El resultado ya está guardado.':'Espera a que se confirme el fin de la llamada.',true);return;}
   const task=taskFor(attempt.solicitud_id);if(!task)throw Error('La devolución ya no está disponible.');opened=attempt;form.reset();
   modal.querySelector('[data-callback-result-number]').textContent=task.telefono+' · '+task.contacto;
   form.elements.seguimiento_id.replaceChildren(new Option('Selecciona un expediente',''));
   if(Number(task.seguimiento_id)>0){form.elements.seguimiento_id.add(new Option(task.institucion_nombre||'Expediente propio',task.seguimiento_id));form.elements.seguimiento_id.value=String(task.seguimiento_id);form.elements.identificacion.value='EXISTENTE';}
   else if(task.institucion_nombre){form.elements.identificacion.value='NUEVA';form.elements.institucion_nombre.value=task.institucion_nombre;}
   const suggestions={'Sin respuesta':'SIN_RESPUESTA','Ocupada':'NO_DISPONIBLE','Número inexistente':'NUMERO_INCORRECTO','No disponible':'NO_DISPONIBLE'};form.elements.resultado.value=suggestions[attempt.estado_llamada]||'';identity();
   window.parent?.IMPE_TELEPHONY_SHELL?.minimizeCallbackResult?.(Number(id));
   bootstrap.Modal.getOrCreateInstance(modal,{backdrop:'static'}).show();
  }catch(e){if(!quiet)notify(e.message,true);}
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
  if(checking||!following||opened||saving||phone?.getState?.()?.active)return;checking=true;
  try{const d=await api(null,following);if(d.intento?.puede_registrar&&autoOpened!==following){autoOpened=following;await openResult(following,true);}if(d.intento?.resultado){following=0;}}
  catch(_){}finally{checking=false;}
 };
 phone?.subscribe?.(state=>{if(state?.context?.type==='DEVOLUCION'&&Number(state.context.callbackAttempt)>0){following=Number(state.context.callbackAttempt);if(!state.active)void check();}});
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(!opened||saving||!form.reportValidity())return;saving=true;const attempt={...opened},values=Object.fromEntries(new FormData(form));form.querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=true);
  try{await api({accion:'RESULTADO',intento_id:attempt.id,...values});closingAfterSave=true;bootstrap.Modal.getOrCreateInstance(modal).hide();following=0;notify('Resultado guardado.');
   window.parent.postMessage({type:'IMPE_CALLBACK_RESULT_SAVED',attemptId:Number(attempt.id)},location.origin);window.dispatchEvent(new Event('impe:phone-notices-refresh'));await refresh();
  }catch(error){notify(error.message,true);}finally{saving=false;form.querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=false);}
 });
 modal.addEventListener('hide.bs.modal',e=>{if(saving&&!closingAfterSave)e.preventDefault();});modal.addEventListener('hidden.bs.modal',()=>{opened=null;closingAfterSave=false;searchSequence++;});
 form.elements.identificacion.addEventListener('change',identity);
 modal.querySelector('[data-callback-followup-search]').addEventListener('input',e=>{clearTimeout(searchTimer);const q=e.target.value.trim(),seq=++searchSequence;searchTimer=setTimeout(async()=>{
  if(q.length<2)return;try{const u=new URL(root.dataset.searchUrl,location.href);u.searchParams.set('q',q);const r=await fetch(u,{credentials:'same-origin',cache:'no-store'}),j=await r.json();if(seq!==searchSequence||!opened)return;if(!r.ok||!j.ok)throw Error(j.mensaje||'No se pudieron buscar expedientes.');form.elements.seguimiento_id.replaceChildren(new Option('Selecciona un expediente',''));for(const x of j.resultados||[])form.elements.seguimiento_id.add(new Option(x.nombre_entidad+' · '+x.estado_nombre,x.id));}catch(err){notify(err.message,true);}
 },250);});
 $('[data-callback-search]').addEventListener('input',()=>{page=1;render();});$('[data-callback-filter]').addEventListener('change',()=>{page=1;render();});$('[data-callback-prev]').addEventListener('click',()=>{page--;render();});$('[data-callback-next]').addEventListener('click',()=>{page++;render();});$('[data-callback-refresh]').addEventListener('click',()=>void refresh().catch(e=>notify(e.message,true)));
 window.setInterval(()=>void check(),2500);render();
 const initial=Number(root.dataset.autoAttempt);if(initial){following=initial;void openResult(initial);}
});
