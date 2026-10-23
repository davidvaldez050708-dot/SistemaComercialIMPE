/* Convenios: adaptador del editor V3 y del componente Registro/QR existente. */
(() => {
  'use strict';
  if (window.IMPEConvocatoriaDraftV4) return;
  const endpoint = (action,controller='crearConvocatoria') => {
    const url=new URL(document.querySelector('[data-crear-convocatoria-module]')?.dataset.convocatoriaBaseUrl||window.location.href,window.location.href);url.search='';url.hash='';
    if(url.origin!==location.origin)throw new Error('Ruta del servidor no autorizada.');
    url.searchParams.set('controller',controller);url.searchParams.set('action',action);return url;
  };
  const csrf = () => window.IMPE_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('[name="csrf_token"]')?.value || '';
  const operation = () => [...crypto.getRandomValues(new Uint8Array(32))].map(x=>x.toString(16).padStart(2,'0')).join('');
  const request = async (url,options={}) => {
    let res;try{res=await fetch(url,{credentials:'same-origin',...options,headers:{'X-Requested-With':'fetch',...options.headers}});}
    catch(_){throw new Error('No se pudo conectar. Tus cambios siguen pendientes; reintenta o continúa editando.');}
    let data;try { data=await res.json(); } catch (_) { throw new Error('Respuesta inesperada del servidor. El borrador sigue pendiente.'); }
    if(!res.ok||!data.ok){const error=new Error(data.mensaje||'No se pudo guardar el borrador.');error.status=res.status;throw error;}
    return data;
  };
  const post = async values => {
    const body=new FormData();body.set('csrf_token',csrf());
    Object.entries(values).forEach(([key,value])=>body.set(key,value));
    return (await request(endpoint('borrador'),{method:'POST',body})).datos;
  };
  const toast = (message,error=false) => {
    const tray=document.createElement('div');tray.className='toast-container position-fixed top-0 end-0 p-3';tray.style.zIndex='2000';
    const node=document.createElement('div');node.className='toast system-toast'+(error?' system-toast-error':'');node.setAttribute('role',error?'alert':'status');
    const body=document.createElement('div');body.className='toast-body';body.textContent=message;node.append(body);tray.append(node);document.body.append(tray);
    node.addEventListener('hidden.bs.toast',()=>tray.remove(),{once:true});bootstrap.Toast.getOrCreateInstance(node,{delay:error?6500:4500}).show();
  };
  let opening=false,active=null;
  const loadScript = src => new Promise((resolve,reject)=>{
    const url=new URL(src,location.href);if(url.origin!==location.origin)return reject(new Error('Recurso del editor no autorizado.'));
    // V2 debe montar cada nuevo contenedor. V3 mantiene sus listeners globales únicos.
    if(/convocatorias_aliado_multiprograma_v3\.js$/.test(url.pathname)&&window.__impeAliadoMultiV3Loaded)return resolve();
    const script=document.createElement('script');script.src=url.href;script.onload=()=>{script.remove();resolve();};script.onerror=()=>{script.remove();reject(new Error('No se pudo cargar el editor existente.'));};document.head.append(script);
  });
  const open = async link => {
    if(opening||active)return;opening=true;link.setAttribute('aria-busy','true');
    let container=null;
    const unmount = () => {
      const modal=container?.querySelector('#modalCrearConvocatoria');
      modal?.dispatchEvent(new Event('impe:editor-destroyed'));if(modal)bootstrap.Modal.getInstance(modal)?.dispose();
      container?.remove();
    };
    try{
      const source=new URL(link.href,location.href),tipo=source.searchParams.get('aliado_tipo'),id=Number(source.searchParams.get('aliado_id'));
      if(!['manual','institucional'].includes(tipo)||!Number.isSafeInteger(id)||id<1)throw new Error('No se pudo identificar el aliado.');
      const opened=await post({operacion:'abrir',aliado_tipo:tipo,aliado_id:String(id)});
      const url=endpoint('editorConvenio');url.searchParams.set('aliado_tipo',tipo);url.searchParams.set('aliado_id',String(id));
      const result=(await request(url)).datos;
      const detail=document.getElementById('modalConvenioCrearConvocatoria');
      if(!detail?.classList.contains('show')||link.href!==source.href)return;
      container=document.createElement('div');container.dataset.v4Container='1';container.innerHTML=result.html;
      const scripts=[...container.querySelectorAll('script[src]')].map(x=>x.src);
      container.querySelectorAll('script[src]').forEach(x=>x.remove());
      // El endpoint sólo devuelve el componente local autorizado. No ejecutar scripts inline.
      container.querySelectorAll('script:not([type="application/json"])').forEach(x=>x.remove());
      container.querySelectorAll('link[rel="stylesheet"]').forEach(node=>{
        if(new URL(node.href).origin!==location.origin)throw new Error('Estilo del editor no autorizado.');
        if(![...document.querySelectorAll('link[rel="stylesheet"]')].some(x=>x.href===node.href))document.head.append(node);else node.remove();
      });
      const modal=container.querySelector('#modalCrearConvocatoria');if(!modal)throw new Error('Falta el contenedor del editor.');
      modal.__draftV4=opened.borrador;document.body.append(container);
      for(const script of scripts)await loadScript(script);
      // Bootstrap ignora hide() durante la transición de apertura del detalle.
      if(detail?.classList.contains('show')){
        await Promise.allSettled((detail.getAnimations?.({subtree:true})||[]).map(animation=>animation.finished));
        await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
      }
      if(!detail?.classList.contains('show')||link.href!==source.href){unmount();return;}
      if(detail?.classList.contains('show'))await new Promise(resolve=>{detail.addEventListener('hidden.bs.modal',resolve,{once:true});bootstrap.Modal.getOrCreateInstance(detail).hide();});
      active={container,modal,detail,link,tipo,id};
      modal.addEventListener('hidden.bs.modal',()=>{
        modal.dispatchEvent(new Event('impe:editor-destroyed'));bootstrap.Modal.getInstance(modal)?.dispose();container.remove();active=null;
        if(detail)bootstrap.Modal.getOrCreateInstance(detail).show();
      },{once:true});
      bootstrap.Modal.getOrCreateInstance(modal).show();
    }catch(error){unmount();if(active?.container===container)active=null;toast(error.message,true);}
    finally{opening=false;link.removeAttribute('aria-busy');}
  };
  const attach = ({modal,form,entries,context,notify,footer}) => {
    let draft=modal.__draftV4,rev=0,savedRev=0,timer=null,inFlight=null,pending=null,conflict=false,allowClose=false,publishing=false;
    const images=[],controller=new AbortController(),signal=controller.signal;
    const status=modal.querySelector('[data-v4-status]'),retry=modal.querySelector('[data-v4-retry]'),reload=modal.querySelector('[data-v4-reload]');
    const backupKey='impe-convenio-borrador:'+draft.propietario_id+':'+context.origen+':'+context.id+':'+draft.id;
    const fields = () => Object.fromEntries(entries.map(e=>[e.p.codigo,{titulo:e.name.value,fecha_inicio:e.start.value,fecha_termino:e.end.value,estado:e.state.value,clasificacion:e.classification?.value||e.p.clases[0]}]));
    const text = (message,error=false) => {status.textContent=message;status.dataset.error=error?'1':'0';};
    const backup = () => {try{localStorage.setItem(backupKey,JSON.stringify({version:draft.version,programas:fields()}));}catch(_){/* El servidor sigue siendo la persistencia principal. */}};
    const eraseBackup = () => {try{localStorage.removeItem(backupKey);}catch(_){}};
    const dirty = () => rev>savedRev||images.length>0||pending!==null;
    const previews=new Map();
    entries.forEach(e=>{
      const wrap=document.createElement('div');wrap.className='impe-v4-preview';wrap.hidden=true;
      const image=document.createElement('img');image.alt='Vista previa de '+e.p.nombre;
      const label=document.createElement('small');wrap.append(image,label);e.photo.parentElement.append(wrap);previews.set(e.p.codigo,{wrap,image,label});
    });
    const renderImages = () => entries.forEach(e=>{
      const image=draft.imagenes?.[e.p.codigo],preview=previews.get(e.p.codigo);
      preview.wrap.hidden=!image;e.photo.required=!image;
      if(image){preview.image.src=image.url;preview.label.textContent='Imagen guardada · '+image.nombre;}
    });
    const hydrate = data => entries.forEach(e=>{
      const f=data?.[e.p.codigo]||{};e.name.value=f.titulo||'';e.start.value=f.fecha_inicio||'';e.end.value=f.fecha_termino||'';e.state.value=f.estado||'1';if(e.classification&&f.clasificacion)e.classification.value=f.clasificacion;
    });
    const saved = () => {text('Borrador guardado · '+draft.actualizado_at);retry.hidden=true;reload.hidden=true;notify('');if(!dirty())eraseBackup();};
    const fail = error => {
      text('Error al guardar · Cambios pendientes',true);notify(error.message||'No se pudo guardar el borrador.');
      if(error.status===409){conflict=true;reload.hidden=false;retry.hidden=true;}else retry.hidden=false;
      backup();
    };
    const flush = () => {
      clearTimeout(timer);
      if(inFlight)return inFlight;
      inFlight=(async()=>{
        if(conflict)throw new Error('Carga la versión del servidor para resolver el conflicto. Tus campos locales siguen conservados.');
        while(dirty()){
          text('Guardando…');
          if(!pending){
            if(images.length){const image=images[0];pending={kind:'imagen',version:draft.version,key:image.key,image};}
            else pending={kind:'guardar',version:draft.version,key:operation(),revision:rev,programas:JSON.stringify(fields())};
          }
          const values={operacion:pending.kind,id:String(draft.id),version:String(pending.version),operacion_id:pending.key};
          if(pending.kind==='imagen'){values.programa=pending.image.code;values.imagen=pending.image.file;}
          else values.programas=pending.programas;
          const result=await post(values);
          if(pending.kind==='imagen'){images.shift();const entry=entries.find(e=>e.p.codigo===pending.image.code);if(entry?.photo.files?.[0]===pending.image.file)entry.photo.value='';}
          else savedRev=pending.revision;
          draft=result;modal.__draftV4=draft;pending=null;renderImages();
        }
        saved();
      })().catch(error=>{fail(error);throw error;}).finally(()=>{inFlight=null;});
      return inFlight;
    };
    hydrate(draft.programas);renderImages();text('Borrador guardado · '+draft.actualizado_at);
    try{
      const local=JSON.parse(localStorage.getItem(backupKey)||'null');
      if(local&&local.version===draft.version){hydrate(local.programas);rev++;text('Cambios pendientes recuperados de este navegador');timer=setTimeout(()=>flush().catch(()=>{}),450);}
      else if(local){conflict=true;reload.hidden=false;text('Hay cambios locales y una versión más reciente del servidor.',true);notify('Los campos locales están conservados en este navegador. No se sobrescribió el borrador del servidor.');}
    }catch(_){}
    entries.forEach(e=>{
      [e.name,e.start,e.end,e.state,e.classification].filter(Boolean).forEach(input=>{
        input.addEventListener('input',()=>{rev++;backup();text('Cambios pendientes');clearTimeout(timer);timer=setTimeout(()=>flush().catch(()=>{}),450);},{signal});
        input.addEventListener('change',()=>{if(input.tagName==='SELECT'){rev++;backup();clearTimeout(timer);timer=setTimeout(()=>flush().catch(()=>{}),450);}},{signal});
      });
      e.photo.addEventListener('change',()=>{
        const file=e.photo.files?.[0];if(!file)return;
        if(file.size>5*1024*1024){notify('La imagen supera 5 MB.');e.photo.value='';return;}
        images.push({code:e.p.codigo,file,key:operation()});text('Guardando imagen…');flush().catch(()=>{});
      },{signal});
    });
    retry.addEventListener('click',()=>flush().catch(()=>{}),{signal});
    reload.addEventListener('click',async()=>{
      if(!window.confirm('¿Cargar la versión del servidor? Los campos visibles se reemplazarán. Puedes cancelar y conservarlos para revisarlos.'))return;
      try{draft=await post({operacion:'cargar',id:String(draft.id)});if(draft.estado!=='borrador')throw new Error('El borrador ya no está en edición. Cierra y vuelve a abrir el aliado.');pending=null;images.length=0;conflict=false;rev=0;savedRev=0;hydrate(draft.programas);renderImages();eraseBackup();saved();}catch(error){fail(error);}
    },{signal});
    window.addEventListener('online',()=>{if(dirty()&&!conflict)flush().catch(()=>{});},{signal});
    window.addEventListener('beforeunload',event=>{if(dirty()||publishing){backup();event.preventDefault();event.returnValue='';}},{signal});
    modal.addEventListener('hide.bs.modal',event=>{
      if(allowClose)return;
      event.preventDefault();if(publishing)return;
      flush().then(()=>{allowClose=true;if(active?.link)active.link.lastChild.textContent='Continuar borrador';bootstrap.Modal.getOrCreateInstance(modal).hide();}).catch(()=>{});
    },{signal});
    modal.querySelector('[data-v4-discard]').addEventListener('click',async()=>{
      if(publishing||!window.confirm('¿Descartar este borrador y sus imágenes? Las convocatorias ya publicadas se conservarán.'))return;
      clearTimeout(timer);
      try{if(inFlight)await inFlight;await post({operacion:'descartar',id:String(draft.id),version:String(draft.version),operacion_id:operation(),confirmar:'DESCARTAR'});pending=null;images.length=0;savedRev=rev;eraseBackup();allowClose=true;bootstrap.Modal.getOrCreateInstance(modal).hide();}catch(error){fail(error);}
    },{signal});
    modal.addEventListener('impe:editor-destroyed',()=>{clearTimeout(timer);controller.abort();},{once:true});
    const publish = async () => {
      if(publishing)return;publishing=true;if(footer)footer.disabled=true;
      try{
        await flush();text('Publicando convocatorias…');
        const body=new FormData();Object.entries({csrf_token:csrf(),aliado_tipo:context.origen,aliado_id:String(context.id),borrador_id:String(draft.id),version:String(draft.version)}).forEach(([key,value])=>body.set(key,value));
        const result=await request(endpoint('guardarProgramasAliado','convocatoria'),{method:'POST',body});
        if(!Array.isArray(result.ids)||result.cantidad!==result.ids.length)throw new Error('No se recibió la confirmación del lote. Reintenta para consultar el resultado.');
        eraseBackup();allowClose=true;savedRev=rev;toast(result.mensaje);
        document.querySelectorAll('[data-convenio-notification]').forEach(row=>{
          let data;try{data=JSON.parse(row.dataset.convenio||'{}');}catch(_){return;}
          if((data.origen_tipo||'institucional')!==context.origen||Number(data.origen_id||data.seguimiento_id)!==Number(context.id))return;
          data.estado_atencion='con_convocatoria';data.estado_atencion_etiqueta='Con convocatoria';row.dataset.convenio=JSON.stringify(data);
          const badge=row.querySelector('[data-convenio-card-attention]');if(badge){badge.textContent='Con convocatoria';badge.classList.add('is-done');badge.classList.remove('is-pending');}
        });
        if(active?.detail){const badge=active.detail.querySelector('[data-convenio-modal-attention]');if(badge){badge.textContent='Con convocatoria';badge.classList.add('is-done');badge.classList.remove('is-pending');}}
        bootstrap.Modal.getOrCreateInstance(modal).hide();
      }catch(error){fail(error);}finally{publishing=false;if(footer)footer.disabled=false;}
    };
    return {publish};
  };
  window.IMPEConvocatoriaDraftV4={attach};
  document.addEventListener('click',event=>{
    const link=event.target.closest('[data-convenio-create-link]');
    if(!link||!document.querySelector('[data-crear-convocatoria-module]'))return;
    const target=new URL(link.href,location.href);
    if(!['manual','institucional'].includes(target.searchParams.get('aliado_tipo'))||target.searchParams.has('aliado_internacional_id')||target.searchParams.get('controller')==='aliado')return;
    event.preventDefault();event.stopImmediatePropagation();open(link);
  },true);
})();
