(() => {
 'use strict';
 const iniciar=()=>{
 const root=document.querySelector('[data-personal-supervision]');if(!root||root.dataset.initialized)return;
 const modalEl=document.getElementById('modalSupervisionPersonal'),historyEl=document.getElementById('modalBitacoraSupervision');
 if(!window.bootstrap?.Modal)return;
 root.dataset.initialized='1';
 const modal=bootstrap.Modal.getOrCreateInstance(modalEl),historyModal=bootstrap.Modal.getOrCreateInstance(historyEl);
 const el=key=>document.querySelector('[data-personal-'+key+']');
 let selected=new Set(),staff=[],kam=0,revision=0,active=false,busy=false,plan=null,sequence=0,historyPage=1,historySequence=0;
 const show=(node,on)=>node.classList.toggle('d-none',!on);
 const name=p=>[p.nombre,p.apellidos].filter(Boolean).join(' ');
 const clean=s=>String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 function notify(message,error=false){
  let host=document.querySelector('[data-personal-toasts]');if(!host){host=document.createElement('div');host.dataset.personalToasts='';host.className='toast-container position-fixed top-0 end-0 p-3';host.style.zIndex='1095';document.body.append(host);}
  const t=document.createElement('div');t.className='toast system-toast'+(error?' system-toast-error':'');t.setAttribute('role',error?'alert':'status');t.setAttribute('aria-live',error?'assertive':'polite');
  const body=document.createElement('div');body.className='toast-body';const icon=document.createElement('i');icon.className='bi '+(error?'bi-exclamation-circle':'bi-check2-circle');icon.setAttribute('aria-hidden','true');const text=document.createElement('span');text.textContent=message;body.append(icon,text);t.append(body);host.append(t);
  const toast=bootstrap.Toast.getOrCreateInstance(t,{delay:error?4500:3200,autohide:true});t.addEventListener('hidden.bs.toast',()=>{toast.dispose();t.remove()});toast.show();
 }
 async function request(action,body=null,query={}){
  const url=new URL(root.dataset.endpoint,location.href);url.searchParams.set('action',action);for(const [k,v]of Object.entries(query))url.searchParams.set(k,String(v));
  const abort=new AbortController(),timer=setTimeout(()=>abort.abort(),15000);
  try{const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:abort.signal,
   headers:{Accept:'application/json','X-Requested-With':'fetch','X-CSRF-Token':root.dataset.csrf},body});
   let data;try{data=await response.json()}catch(_){throw Error('La sesión o la respuesta cambió. Recarga la página y vuelve a intentarlo.')}
   if(!response.ok||!data.ok)throw Error(data.mensaje||'No fue posible completar la solicitud.');return data.datos;
  }catch(e){if(e.name==='AbortError')throw Error('No se recibió la confirmación. Conservamos la selección; puedes reintentar sin duplicar los cambios.');throw e}finally{clearTimeout(timer)}
 }
 function controls(){el('preview').disabled=busy||!active;el('select-visible').disabled=busy||!active;el('save').disabled=busy;el('back').disabled=busy;el('cancel').disabled=busy;modalEl.querySelector('[data-bs-dismiss]').disabled=busy;for(const input of modalEl.querySelectorAll('input,select'))input.disabled=busy||!active;}
 function rows(){
  const search=clean(el('search').value),role=el('role').value,filter=el('filter').value;
  return staff.filter(p=>(!search||clean(name(p)).includes(search))&&(!role||String(p.rol_id)===role)&&(!filter||(filter==='selected'?selected.has(Number(p.id)):!selected.has(Number(p.id)))));
 }
 function render(){
  const list=el('list');list.replaceChildren();const visible=rows();
  for(const p of visible){
   const id=Number(p.id),row=document.createElement('label');row.className='personal-staff-row';
   const check=document.createElement('input');check.type='checkbox';check.value=String(id);check.checked=selected.has(id);check.disabled=busy||!active;check.setAttribute('aria-label','Supervisar a '+name(p));
   check.addEventListener('change',()=>{check.checked?selected.add(id):selected.delete(id);plan=null;render()});
   const avatar=document.createElement('span');avatar.className='system-avatar system-avatar-sm system-avatar-initials';const initials=name(p).split(/\s+/).filter(Boolean).slice(0,2).map(s=>s[0]).join('').toUpperCase();avatar.textContent=initials;
   if(p.foto_url){try{const url=new URL(p.foto_url,location.href);if(url.origin===location.origin&&url.pathname.includes('/public/uploads/usuarios/')&&!url.pathname.includes('..')){const img=document.createElement('img');img.src=url.href;img.alt='Foto de '+name(p);img.className='system-avatar-image';img.addEventListener('error',()=>{avatar.textContent=initials});avatar.replaceChildren(img)}}catch(_){}}
   const copy=document.createElement('span');copy.className='personal-staff-copy';const title=document.createElement('strong');title.textContent=name(p);const label=document.createElement('small');label.textContent=(Number(p.rol_id)===4?'Analista de datos':Number(p.rol_id)===3?'Asesor de ventas':'Rol por revisar')+(!Number(p.activo)?' · Inactivo':'')+(p.requiere_revision?' · Revisar asignación heredada':'');copy.append(title,label);row.append(check,avatar,copy);list.append(row);
  }
  show(el('empty'),visible.length===0);el('selection-count').textContent=selected.size+' seleccionados';controls();
 }
 async function open(card){
  if(busy)return;const cycle=++sequence;kam=Number(card.dataset.kamCard);selected.clear();staff=[];plan=null;active=false;
  el('kam-label').textContent=card.dataset.kamName;show(el('loading'),true);show(el('editor'),false);show(el('review'),false);show(el('load-error'),false);show(el('preview'),true);show(el('save'),false);show(el('back'),false);
  busy=true;controls();modal.show();el('search').value='';el('role').value='';el('filter').value='';
  try{const data=await request('detalle',null,{kam_id:kam});if(cycle!==sequence)return;staff=data.colaboradores;revision=Number(data.estado.revision);active=!!data.estado.activo;selected=new Set(staff.filter(p=>Number(p.seleccionado)).map(p=>Number(p.id)));show(el('editor'),true);render();}
  catch(e){if(cycle===sequence){show(el('load-error'),true);notify(e.message,true)}}finally{if(cycle===sequence){busy=false;show(el('loading'),false);controls()}}
 }
 function payload(){const body=new FormData();body.set('kam_id',String(kam));body.set('revision',String(revision));body.set('csrf_token',root.dataset.csrf);for(const id of [...selected].sort((a,b)=>a-b))body.append('colaboradores[]',String(id));return body;}
 function reviewList(key,ids,names){const list=el(key);list.replaceChildren();for(const id of ids){const li=document.createElement('li');li.textContent=names[id]||'Colaborador #'+id;list.append(li)}if(!ids.length){const li=document.createElement('li');li.textContent='Sin cambios';list.append(li)}}
 el('preview').addEventListener('click',async()=>{
  if(busy||!active)return;busy=true;controls();const original=el('preview').innerHTML;el('preview').textContent='Preparando resumen…';
  try{plan=await request('previsualizar',payload());if(!plan.agregar.length&&!plan.retirar.length){notify('No hay cambios en la selección.');return;}
   reviewList('added',plan.agregar,plan.nombres);reviewList('removed',plan.retirar,plan.nombres);el('added-count').textContent='('+plan.agregar.length+')';el('removed-count').textContent='('+plan.retirar.length+')';show(el('editor'),false);show(el('review'),true);show(el('preview'),false);show(el('save'),true);show(el('back'),true);
  }catch(e){notify(e.message,true)}finally{busy=false;el('preview').innerHTML=original;controls()}
 });
 el('back').addEventListener('click',()=>{if(busy)return;plan=null;show(el('editor'),true);show(el('review'),false);show(el('preview'),true);show(el('save'),false);show(el('back'),false);render()});
 el('save').addEventListener('click',async()=>{
  if(busy||!plan)return;busy=true;controls();const original=el('save').innerHTML;el('save').textContent='Guardando…';
  try{const body=payload();body.set('confirmacion',plan.confirmacion);const result=await request('guardar',body);revision=Number(result.revision);busy=false;modal.hide();notify('Supervisión actualizada correctamente.');
   const data=await request('catalogo');for(const k of data.cuentas_clave){const card=root.querySelector('[data-kam-card="'+Number(k.id)+'"]');if(card)card.querySelector('[data-personal-count]').textContent=String(k.colaboradores)}
  }catch(e){notify(e.message,true)}finally{busy=false;el('save').innerHTML=original;controls()}
 });
 for(const key of ['search','role','filter'])el(key).addEventListener(key==='search'?'input':'change',render);
 el('select-visible').addEventListener('click',()=>{if(busy||!active)return;for(const p of rows())selected.add(Number(p.id));render()});
 root.querySelectorAll('[data-kam-card]').forEach(card=>card.addEventListener('click',()=>open(card)));
 el('kam-search').addEventListener('input',()=>{let count=0;for(const card of root.querySelectorAll('[data-kam-card]')){const visible=clean(card.dataset.kamName).includes(clean(el('kam-search').value));show(card,visible);if(visible)count++}show(el('no-kams'),count===0)});
 modalEl.addEventListener('hide.bs.modal',e=>{if(busy)e.preventDefault()});modalEl.addEventListener('hidden.bs.modal',()=>{sequence++;plan=null});
 async function history(){
  const cycle=++historySequence;el('history-list').textContent='Cargando movimientos…';el('history-prev').disabled=true;el('history-next').disabled=true;
  try{const data=await request('bitacora',null,{pagina:historyPage});if(cycle!==historySequence)return;const list=el('history-list');list.replaceChildren();
   for(const row of data.movimientos){const article=document.createElement('article');article.className='personal-history-row';const title=document.createElement('strong');title.textContent=(row.kam_nombre||'Sistema')+' · '+row.responsable;
    const date=document.createElement('time');date.dateTime=row.creado_at+'Z';date.textContent=new Intl.DateTimeFormat('es-MX',{timeZone:'America/Mexico_City',dateStyle:'medium',timeStyle:'short'}).format(new Date(row.creado_at.replace(' ','T')+'Z'));
    const text=document.createElement('p');let names={};try{names=JSON.parse(row.nombres_json)||{}}catch(_){}const label=ids=>ids.map(id=>names[id]||'#'+id).join(', ')||'Ninguno';
    text.textContent=['MANUAL','MIGRACION','AMPLIACION_REVISADA'].includes(row.tipo)?'Agregados: '+label(JSON.parse(row.agregados_json))+' · Retirados: '+label(JSON.parse(row.retirados_json)):row.tipo;article.append(title,date,text);list.append(article)}
   if(!data.movimientos.length)list.textContent='No hay movimientos en esta página.';el('history-page').textContent='Página '+historyPage;el('history-prev').disabled=historyPage<=1;el('history-next').disabled=data.movimientos.length<30;
  }catch(e){if(cycle===historySequence){el('history-list').textContent='No se pudo cargar la bitácora.';notify(e.message,true)}}
 }
 el('history').addEventListener('click',()=>{historyPage=1;historyModal.show();void history()});el('history-prev').addEventListener('click',()=>{if(historyPage>1){historyPage--;void history()}});el('history-next').addEventListener('click',()=>{historyPage++;void history()});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar,{once:true});else iniciar();
})();
