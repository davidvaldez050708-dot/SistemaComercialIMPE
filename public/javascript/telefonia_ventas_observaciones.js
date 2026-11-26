/* Anexos de sólo adición; no participa en la captura ni en el motor telefónico. */
(()=>{'use strict';
 const modal=document.getElementById('modalResultadoVenta'),section=modal?.querySelector('[data-sales-observaciones]');
 if(!section||section.dataset.initialized)return;section.dataset.initialized='1';
 const list=section.querySelector('[data-sales-notes-list]'),feedback=section.querySelector('[data-sales-notes-feedback]');
 const open=section.querySelector('[data-sales-note-open]'),editor=section.querySelector('[data-sales-note-editor]');
 const text=section.querySelector('[data-sales-note-text]'),save=section.querySelector('[data-sales-note-save]'),cancel=section.querySelector('[data-sales-note-cancel]');
 const drafts=new Map(),saveLabel=save.innerHTML;let current=null,cycle=0;
 const key=c=>c.ref+'|'+c.extension;
 const draft=c=>{const k=key(c);if(!drafts.has(k))drafts.set(k,{text:'',token:'',sent:'',busy:false});return drafts.get(k);};
 const token=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
 const endpoint=base=>{const url=new URL(base,location.href);if(url.origin!==location.origin)throw Error('La ruta no es válida.');return url;};
 async function json(url,options={}){
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
  try{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json','X-Requested-With':'fetch'},signal:controller.signal});
   const data=await response.json();if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo completar la consulta.');return data;
  }finally{clearTimeout(timeout);}
 }
 function row(note){
  const box=document.createElement('article');box.className='linkage-incoming-summary d-block mt-2';box.dataset.noteId=String(note.id);
  const title=document.createElement('strong');title.className='d-block';title.textContent=note.autor+' · '+note.fecha+' · hora de México';
  const body=document.createElement('p');body.className='mb-0 mt-2';body.style.whiteSpace='pre-wrap';body.style.overflowWrap='anywhere';body.textContent=note.nota;
  box.append(title,body);return box;
 }
 const buttons=d=>{save.disabled=d.busy;cancel.disabled=d.busy;text.disabled=d.busy;open.disabled=d.busy;save.innerHTML=d.busy?'Guardando…':saveLabel;};
 modal.addEventListener('impe:sales-result-loaded',async event=>{
  const version=++cycle;current=event.detail.saved?{ref:event.detail.ref,extension:event.detail.extension}:null;
  section.hidden=!current;open.hidden=true;editor.hidden=true;list.replaceChildren();feedback.textContent='';
  if(!current)return;
  const identity={...current},d=draft(identity);text.value=d.text;text.disabled=false;buttons(d);feedback.textContent='Consultando observaciones adicionales…';
  try{
   const url=endpoint(section.dataset.listUrl);url.searchParams.set('pbx_call_id',identity.ref);url.searchParams.set('extension',identity.extension);
   const data=await json(url);if(version!==cycle)return;
   list.replaceChildren(...data.observaciones.map(row));
   feedback.textContent=data.disponible?(data.observaciones.length?'':'Aún no hay observaciones adicionales.'):'Las observaciones adicionales aún no están instaladas.';
   open.hidden=!data.disponible;
   if(data.disponible&&d.text){editor.hidden=false;open.hidden=true;}
   buttons(d);
  }catch(error){if(version===cycle)feedback.textContent=error.name==='AbortError'?'La consulta tardó demasiado. Cierra y vuelve a abrir el resultado.':error.message;}
 });
 open.addEventListener('click',()=>{editor.hidden=false;open.hidden=true;text.focus();});
 text.addEventListener('input',()=>{if(current)draft(current).text=text.value;});
 cancel.addEventListener('click',()=>{if(!current)return;const d=draft(current);if(d.busy)return;d.text='';text.value='';editor.hidden=true;open.hidden=false;});
 save.addEventListener('click',async()=>{
  if(!current)return;const identity={...current},d=draft(identity),version=cycle,nota=text.value.trim();
  if(d.busy)return;
  if(!nota||Array.from(nota).length>1000){feedback.textContent='Escribe una observación de entre 1 y 1000 caracteres.';text.focus();return;}
  if(!d.token||d.sent!==nota){d.token=token();d.sent=nota;}
  d.text=text.value;d.busy=true;buttons(d);feedback.textContent='Guardando observación…';
  try{
   const csrf=modal.querySelector('[name=csrf_token]').value;
   const data=await json(endpoint(section.dataset.addUrl),{method:'POST',body:new URLSearchParams({csrf_token:csrf,pbx_call_id:identity.ref,extension:identity.extension,nota,token:d.token})});
   d.text='';d.token='';d.sent='';
   if(version!==cycle){
    // Si se volvió a abrir la misma llamada durante el envío, consultar otra
    // vez invalida una lectura anterior al guardado y evita repetir la nota.
    if(current&&key(current)===key(identity))modal.dispatchEvent(new CustomEvent('impe:sales-result-loaded',{detail:{...identity,saved:true}}));
    return;
   }
   if(!Array.from(list.children).some(el=>el.dataset.noteId===String(data.observacion.id)))list.append(row(data.observacion));
   text.value='';editor.hidden=true;open.hidden=false;feedback.textContent='Observación guardada. El resultado permanece igual.';
  }catch(error){if(version===cycle)feedback.textContent=error.name==='AbortError'?'No se pudo confirmar el guardado. Reintenta sin cambiar la nota para evitar duplicados.':error.message;}
  finally{d.busy=false;if(current&&key(current)===key(identity))buttons(d);}
 });
 modal.addEventListener('hidden.bs.modal',()=>{++cycle;current=null;section.hidden=true;});
})();
