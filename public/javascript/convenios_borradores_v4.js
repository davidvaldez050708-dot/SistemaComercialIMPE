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


/* IMPE_CONVENIOS_QR_COMPARTIDO_V5_20261023
 * Personalizador local del QR compartido en el editor de borradores V4.
 * No altera el enlace del aliado, los campos de programas ni el guardado.
 */
(() => {
  'use strict';
  if (window.__impeQrConveniosV5) return;
  window.__impeQrConveniosV5 = true;
  const installed = new WeakSet();
  const html = String.raw`
    <div class="impe-v5-qr-heading">
      <span class="impe-v5-qr-symbol"><i class="bi bi-sliders2" aria-hidden="true"></i></span>
      <div><strong>Personalizar QR compartido</strong><small>El diseño es para el mismo enlace de Registro de todos los programas.</small></div>
    </div>
    <div class="impe-v5-qr-layout">
      <div class="impe-v5-qr-preview"><span class="impe-v5-qr-preview-label">VISTA PREVIA · QR COMPARTIDO</span></div>
      <div class="impe-v5-qr-settings">
        <div class="impe-v5-qr-fields">
          <label>Color del código <input type="color" data-v5qr="dark" value="#223a84"></label>
          <label>Color de fondo <input type="color" data-v5qr="light" value="#ffffff"></label>
          <label>Color de esquinas <input type="color" data-v5qr="finder" value="#223a84"></label>
          <label>Estilo de puntos <select data-v5qr="dots">
            <option value="square">Cuadrado</option><option value="dots">Circular</option>
            <option value="rounded">Redondeado</option><option value="extra-rounded">Muy redondeado</option>
            <option value="classy">Clásico</option><option value="classy-rounded">Clásico redondeado</option>
          </select></label>
          <label>Estilo de esquinas <select data-v5qr="corners">
            <option value="square">Cuadrado</option><option value="dot">Circular</option>
            <option value="extra-rounded">Redondeado</option>
          </select></label>
          <label>Centro de esquinas <select data-v5qr="centers">
            <option value="square">Cuadrado</option><option value="dot">Circular</option>
          </select></label>
          <label>Resolución PNG <select data-v5qr="size">
            <option value="400">400 px</option><option value="600" selected>600 px</option>
            <option value="800">800 px</option><option value="1000">1000 px</option>
          </select></label>
          <label>Margen del QR <select data-v5qr="margin">
            <option value="4" selected>Normal · 4 módulos</option>
            <option value="6">Amplio · 6 módulos</option><option value="8">Extra · 8 módulos</option>
          </select></label>
          <label>Corrección de errores <select data-v5qr="ec">
            <option value="L">Baja · L</option><option value="M" selected>Media · M</option>
            <option value="Q">Alta · Q</option><option value="H">Máxima · H</option>
          </select></label>
        </div>
        <label class="impe-v5-qr-check"><input type="checkbox" data-v5qr="transparent"> Fondo transparente</label>
        <div class="impe-v5-qr-logo">
          <div class="impe-v5-qr-logo-title"><div><strong>Imagen central</strong><small>Opcional: escudo o logotipo.</small></div>
            <label class="impe-v5-qr-check"><input type="checkbox" data-v5qr="logo"> Usar imagen</label>
          </div>
          <div data-v5qr-logo-config hidden>
            <div class="impe-v5-qr-source">
              <label><input type="radio" name="impe_qr_v5_source" value="file" checked> Desde equipo</label>
              <label><input type="radio" name="impe_qr_v5_source" value="url"> URL pública</label>
            </div>
            <label data-v5qr-file-wrap>Seleccionar imagen <input data-v5qr="file" type="file" accept="image/png,image/jpeg,image/webp"></label>
            <label data-v5qr-url-wrap hidden>URL de la imagen <input data-v5qr="url" type="url" placeholder="https://..."></label>
            <label>Tamaño de imagen central <output data-v5qr-logo-value>20%</output>
              <input data-v5qr="logoSize" type="range" min="10" max="30" value="20" step="1">
            </label>
          </div>
        </div>
        <div class="impe-v5-qr-bottom">
          <p data-v5qr-status role="status" aria-live="polite">Código listo para personalizar.</p>
          <div class="impe-v5-qr-save-status" data-v5qr-save-status role="status" aria-live="polite">Recuperando diseño del aliado…</div>
          <button type="button" class="btn btn-system-save" data-v5qr-save disabled><i class="bi bi-floppy" aria-hidden="true"></i> Guardar diseño</button>
          <button type="button" class="btn btn-system-light" data-v5qr-reset><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restablecer diseño</button>
        </div>
      </div>
    </div>
  `;
  function rounded(ctx,x,y,w,h,r) {
    r=Math.max(0,Math.min(r,w/2,h/2));
    ctx.beginPath();ctx.moveTo(x+r,y);ctx.lineTo(x+w-r,y);
    ctx.quadraticCurveTo(x+w,y,x+w,y+r);ctx.lineTo(x+w,y+h-r);
    ctx.quadraticCurveTo(x+w,y+h,x+w-r,y+h);ctx.lineTo(x+r,y+h);
    ctx.quadraticCurveTo(x,y+h,x,y+h-r);ctx.lineTo(x,y+r);
    ctx.quadraticCurveTo(x,y,x+r,y);ctx.closePath();ctx.fill();
  }
  function contrast(one,two) {
    function lum(hex) {
      const raw=hex.replace('#','');
      const rgb=[0,2,4].map(i=>parseInt(raw.slice(i,i+2),16)/255);
      const l=rgb.map(c=>c<=.04045?c/12.92:Math.pow((c+.055)/1.055,2.4));
      return l[0]*.2126+l[1]*.7152+l[2]*.0722;
    }
    const a=lum(one),b=lum(two);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
  }
  function drawDots(ctx,qr,n,p,off,style) {
    for(let y=0;y<n;y++)for(let x=0;x<n;x++) {
      // Patrones detectores: se pintan por separado, completos.
      if ((y<7&&(x<7||x>=n-7))||(y>=n-7&&x<7)) continue;
      if(!qr.isDark(y,x))continue;
      const px=off+x*p,py=off+y*p;
      if(style==='square') ctx.fillRect(px,py,p,p);
      else if(style==='dots') {ctx.beginPath();ctx.arc(px+p/2,py+p/2,p*.43,0,Math.PI*2);ctx.fill();}
      else rounded(ctx,px+p*.03,py+p*.03,p*.94,p*.94,
        style==='rounded'?p*.24:style==='extra-rounded'?p*.46:style==='classy'?p*.30:p*.44);
    }
  }
  function drawFinder(ctx,x,y,p,color,corner,center,background,transparent) {
    const px=x*p,py=y*p,side=7*p,outer=corner==='square'?0:corner==='dot'?side/2:p*2;
    ctx.fillStyle=color;rounded(ctx,px,py,side,side,outer);
    ctx.save();
    if(transparent)ctx.globalCompositeOperation='destination-out';
    else ctx.fillStyle=background;
    rounded(ctx,px+p,py+p,side-2*p,side-2*p,
      corner==='square'?0:corner==='dot'?(side-2*p)/2:p*1.15);
    ctx.restore();
    ctx.fillStyle=color;rounded(ctx,px+2*p,py+2*p,3*p,3*p,center==='dot'?1.5*p:0);
  }
  function getUrl(modal) {
    const form=modal.querySelector('form');
    const items=[
      ...modal.querySelectorAll('.convocatoria-aliado-modulo-registro input[readonly]'),
      ...modal.querySelectorAll('[data-impe-url-input],[data-impe-enlace-url],[data-impe-url]'),
      form?.querySelector('[name="enlace_registro"]')
    ];
    for(const item of items) {
      const value=String(item?.value||'').trim();
      try {const url=new URL(value);if(['https:','http:'].includes(url.protocol))return url.href;}catch(_){}
    }
    return '';
  }
  function init(modal) {
    if (!modal || modal.dataset.borradorV4!=='1' || installed.has(modal))return;
    const wrap=modal.querySelector('[data-impe-qr-wrap]');
    const canvas=wrap?.querySelector('[data-impe-qr-canvas]');
    const button=modal.querySelector('[data-impe-crear-qr]');
    if(!wrap||!canvas||!button)return;
    installed.add(modal);
    const original=[...wrap.childNodes];
    const surface=document.createElement('div');surface.className='impe-v5-qr';surface.innerHTML=html;
    const preview=surface.querySelector('.impe-v5-qr-preview');
    original.forEach(node=>preview.append(node));wrap.append(surface);
    const field=name=>surface.querySelector('[data-v5qr="'+name+'"]');
    const status=surface.querySelector('[data-v5qr-status]');
    const buttons=[wrap.querySelector('[data-impe-copiar-qr]'),wrap.querySelector('[data-impe-descargar-qr]')].filter(Boolean);
    const config=surface.querySelector('[data-v5qr-logo-config]');
    const fileWrap=surface.querySelector('[data-v5qr-file-wrap]');
    const urlWrap=surface.querySelector('[data-v5qr-url-wrap]');
    let localImage='',renderId=0,correccionPrevia='M';
    let savedVersion=0,serverReady=false,saving=false,previewReady=false,dirty=false;
    const saveButton=surface.querySelector('[data-v5qr-save]');
    const saveStatus=surface.querySelector('[data-v5qr-save-status]');
    const contextNode=modal.querySelector('#impe-convocatoria-aliado-contexto');
    let allyContext={};
    try { allyContext=JSON.parse(contextNode?.textContent||'{}'); } catch(_) {}
    const getEndpoint=()=>{
      const src=document.querySelector('[data-crear-convocatoria-module]')?.dataset.convocatoriaBaseUrl||location.href;
      const url=new URL(src,location.href);
      if(url.origin!==location.origin)throw Error('El servicio del QR no pertenece al CRM.');
      url.search='';url.hash='';
      url.searchParams.set('controller','crearConvocatoria');
      url.searchParams.set('action','qrDiseno');
      url.searchParams.set('aliado_tipo',String(allyContext.origen||''));
      url.searchParams.set('aliado_id',String(allyContext.id||0));
      return url;
    };
    const request=async (url,options={})=>{
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
      let json;
      try {json=await response.json();}catch(_){throw Error('El servidor no devolvió un diseño válido.');}
      if(!response.ok||!json.ok)throw Error(json.mensaje||'No fue posible consultar el diseño del QR.');
      return json.datos;
    };
    function source(){return surface.querySelector('[name="impe_qr_v5_source"]:checked')?.value||'file';}
    function exporting(ready) {
      previewReady=ready;
      buttons.forEach(b=>b.disabled=!ready);
      saveButton.disabled=!ready||!serverReady||saving;
    }
    function saveMessage(text,error=false) {
      saveStatus.textContent=text;
      saveStatus.dataset.error=error?'1':'0';
      saveStatus.dataset.dirty=dirty?'1':'0';
    }
    function markDirty() {
      if(!serverReady)return;
      dirty=true;
      saveMessage('Cambios sin guardar');
    }
    function message(value,error=false){status.textContent=value;status.dataset.error=error?'1':'0';}
    const loadImage=src=>new Promise((resolve,reject)=>{
      const image=new Image();image.crossOrigin='anonymous';
      image.onload=()=>resolve(image);
      image.onerror=()=>reject(new Error('No se pudo cargar la imagen central. Prueba subiéndola desde tu equipo.'));
      image.src=src;
    });
    async function render(){
      const token=++renderId;exporting(false);
      try {
        const url=getUrl(modal);
        if(!url)throw Error('Primero genera el enlace de Registro compartido.');
        if(typeof window.qrcode!=='function')throw Error('No está disponible el generador de QR del CRM.');
        const dark=field('dark').value,light=field('light').value,finder=field('finder').value;
        if(!field('transparent').checked&&(contrast(dark,light)<4.5||contrast(finder,light)<4.5)){
          throw Error('El código y las esquinas deben contrastar claramente con el fondo.');
        }
        if(field('transparent').checked&&dark.toLowerCase()==='#ffffff')throw Error('Utiliza un color oscuro para un QR transparente.');
        const enabled=field('logo').checked, imageSource=source();
        const logoSrc=enabled?(imageSource==='file'?localImage:field('url').value.trim()):'';
        if(enabled&&!logoSrc)throw Error('Selecciona una imagen central o desactiva esta opción.');
        if(enabled&&imageSource==='url'&&!/^https?:\/\//i.test(logoSrc))throw Error('Indica una URL HTTP o HTTPS válida.');
        const ec=enabled?'H':field('ec').value;
        const qr=window.qrcode(0,ec);qr.addData(url);qr.make();
        const n=qr.getModuleCount(),size=Number(field('size').value),margin=Number(field('margin').value);
        const p=Math.floor(size/(n+2*margin));
        if(p<2)throw Error('El enlace requiere un QR más grande para conservar su legibilidad.');
        const off=Math.floor((size-n*p)/2);
        canvas.width=canvas.height=size;
        const ctx=canvas.getContext('2d');
        if(!ctx)throw Error('Este navegador no admite la vista previa del QR.');
        ctx.clearRect(0,0,size,size);
        if(!field('transparent').checked){ctx.fillStyle=light;ctx.fillRect(0,0,size,size);}
        ctx.fillStyle=dark;
        drawDots(ctx,qr,n,p,off,field('dots').value);
        // Los detectores se dibujan completos, no se recortan por estilización.
        const corners=[[0,0],[n-7,0],[0,n-7]];
        corners.forEach(([x,y])=>{
          // Trasladar el origen a la zona del QR, que incluye margen de seguridad.
          ctx.save();ctx.translate(off,off);
          drawFinder(ctx,x,y,p,finder,field('corners').value,field('centers').value,light,field('transparent').checked);ctx.restore();
        });
        if(enabled) {
          const image=await loadImage(logoSrc);
          if(token!==renderId)return;
          const fraction=Number(field('logoSize').value)/100;
          const side=Math.floor(n*p*fraction),left=Math.round(size/2-side/2);
          // Área blanca limitada al centro. EC H conserva redundancia para logotipos.
          ctx.fillStyle='#ffffff';rounded(ctx,left-5,left-5,side+10,side+10,Math.max(5,side*.10));
          ctx.drawImage(image,left,left,side,side);
        }
        if(token!==renderId)return;
        // Verificar exportabilidad (las imágenes URL deben permitir CORS).
        canvas.toDataURL('image/png');
        exporting(true);
        message('QR personalizado listo. Verifica su lectura antes de compartirlo.');
      } catch(error) {
        if(token!==renderId)return;
        exporting(false);
        message(error.message||'No se pudo actualizar el código QR.',true);
      }
    }

    function currentConfig(){
      const logo=field('logo').checked;
      return {
        dark:field('dark').value,light:field('light').value,
        finder:field('finder').value,dots:field('dots').value,
        corners:field('corners').value,centers:field('centers').value,
        size:field('size').value,margin:field('margin').value,
        ec:logo?'H':field('ec').value,
        transparent:field('transparent').checked,logo:logo,
        logoSize:Number(field('logoSize').value),
        source:logo?source():'none',
        url:logo&&source()==='url'?field('url').value.trim():''
      };
    }
    async function restoreConfig(data){
      if(!data||!data.config||!Number.isSafeInteger(Number(data.version))) {
        throw Error('El servidor devolvió una configuración de QR inválida.');
      }
      const c=data.config;
      for(const name of ['dark','light','finder','dots','corners','centers','size','margin','ec','logoSize']){
        if(c[name]!==undefined&&field(name))field(name).value=String(c[name]);
      }
      field('transparent').checked=Boolean(c.transparent);
      field('logo').checked=Boolean(c.logo);
      const method=c.source==='url'?'url':'file';
      const radio=surface.querySelector('[name="impe_qr_v5_source"][value="'+method+'"]');
      if(radio)radio.checked=true;
      field('url').value=String(c.url||'');
      field('file').value='';
      localImage=c.logo&&c.source==='file'?String(data.logo_data_uri||''):'';
      savedVersion=Number(data.version);
      correccionPrevia=String(c.ec||'M');
      toggleLogo();
      dirty=false;
      saveMessage(savedVersion>0?'Diseño guardado y asociado a este aliado':'Todavía no hay diseño guardado');
      if(!wrap.classList.contains('d-none'))await render();
    }
    async function loadSaved(){
      serverReady=false;saveButton.disabled=true;
      saveMessage('Recuperando diseño del aliado…');
      try {
        if(!['manual','institucional'].includes(allyContext.origen)||Number(allyContext.id)<1) {
          throw Error('No fue posible identificar el aliado del QR.');
        }
        const data=await request(getEndpoint());
        await restoreConfig(data);
        serverReady=true;
        saveButton.disabled=!previewReady||saving;
      } catch(error) {
        serverReady=false;saveButton.disabled=true;
        saveMessage(error.message||'No fue posible recuperar el diseño. No se ha guardado nada.',true);
      }
    }
    saveButton.addEventListener('click',async()=>{
      if(!serverReady||saving||!previewReady)return;
      saving=true;saveButton.disabled=true;
      saveMessage('Guardando diseño del QR…');
      try {
        const body=new FormData();
        const token=window.IMPE_CSRF_TOKEN||modal.querySelector('[name="csrf_token"]')?.value||
                    document.querySelector('meta[name="csrf-token"]')?.content||'';
        if(!token)throw Error('Sesión caducada: recarga el CRM.');
        body.set('csrf_token',token);
        body.set('aliado_tipo',String(allyContext.origen||''));
        body.set('aliado_id',String(allyContext.id||''));
        body.set('version',String(savedVersion));
        body.set('config',JSON.stringify(currentConfig()));
        if(field('logo').checked&&source()==='file'&&field('file').files?.[0]) {
          body.set('logo',field('file').files[0]);
        }
        const result=await request(getEndpoint(),{method:'POST',body});
        await restoreConfig(result);
        if(field('logo').checked&&source()==='file'&&!localImage) {
          throw Error('El servidor no pudo devolver la imagen central guardada.');
        }
        dirty=false;
        saveMessage('Diseño guardado correctamente · se utilizará para este aliado');
      }catch(error){
        dirty=true;
        saveMessage((error.message||'No se pudo guardar el diseño.')+' Los cambios siguen en pantalla.',true);
      }finally{
        saving=false;saveButton.disabled=!previewReady||!serverReady;
      }
    });
    function toggleLogo() {
      config.hidden=!field('logo').checked;
      if(field('logo').checked) {
        if(!field('ec').disabled) correccionPrevia=field('ec').value;
        field('ec').value='H';
        field('ec').disabled=true;
      } else {
        if(field('ec').disabled) field('ec').value=correccionPrevia;
        field('ec').disabled=false;
      }
      surface.querySelector('[data-v5qr-logo-value]').textContent=field('logoSize').value+'%';
      fileWrap.hidden=source()!=='file';urlWrap.hidden=source()!=='url';
    }
    surface.addEventListener('input',event=>{
      if(event.target===field('file')||event.target===field('url'))return;
      markDirty();
      if(event.target===field('logoSize'))surface.querySelector('[data-v5qr-logo-value]').textContent=event.target.value+'%';
      if(!wrap.classList.contains('d-none'))render();
    });
    surface.addEventListener('change',event=>{
      markDirty();
      if(event.target===field('file')) {
        const file=event.target.files?.[0];
        if(!file){localImage='';render();return;}
        if(!/^image\/(png|jpeg|webp)$/i.test(file.type)||file.size>2*1024*1024){
          localImage='';field('file').value='';message('Utiliza una imagen PNG, JPG o WEBP de máximo 2 MB.',true);exporting(false);return;
        }
        exporting(false);
        const reader=new FileReader();
        reader.onload=()=>{localImage=String(reader.result||'');render();};
        reader.onerror=()=>{message('No se pudo leer la imagen.',true);exporting(false);};
        reader.readAsDataURL(file);return;
      }
      if(event.target===field('logo')||event.target.matches('[name="impe_qr_v5_source"]'))toggleLogo();
      if(!wrap.classList.contains('d-none'))render();
    });
    field('url').addEventListener('input',()=>markDirty());
    field('url').addEventListener('change',()=>{if(field('logo').checked&&source()==='url')render();});
    surface.querySelector('[data-v5qr-reset]').addEventListener('click',()=>{
      markDirty();
      for(const [name,value] of Object.entries({
        dark:'#223a84',light:'#ffffff',finder:'#223a84',dots:'square',
        corners:'square',centers:'square',size:'600',margin:'4',ec:'M',logoSize:'20'
      }))field(name).value=value;
      field('transparent').checked=false;field('logo').checked=false;
      field('ec').disabled=false;correccionPrevia='M';
      field('file').value='';field('url').value='';localImage='';
      surface.querySelector('[name="impe_qr_v5_source"][value="file"]').checked=true;
      toggleLogo();render();
    });
    toggleLogo();
    loadSaved();
    button.addEventListener('click',()=>{
      // El listener existente generó primero el QR y reveló su contenedor.
      if(!wrap.classList.contains('d-none'))render();
    });
    modal.addEventListener('impe:editor-destroyed',()=>{renderId++;localImage='';},{once:true});
  }
  document.addEventListener('shown.bs.modal',event=>{
    if(event.target?.id==='modalCrearConvocatoria')init(event.target);
  });
  // Cubre editores que llegan al DOM antes de conectar el módulo.
  document.addEventListener('click',event=>{
    if(!event.target.closest('[data-impe-crear-qr]'))return;
    const modal=event.target.closest('#modalCrearConvocatoria[data-borrador-v4]');
    if(modal)init(modal);
  },true);
})();
