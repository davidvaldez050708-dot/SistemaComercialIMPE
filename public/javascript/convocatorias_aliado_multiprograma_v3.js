/*
 * Extensión del modal modular instalado en Hostinger.
 * No sustituye crear_modal.php ni convocatoria_crear_compartido.js.
 * El módulo V2 carga primero contexto, programa y enlace compartido.
 */
(() => {
  'use strict';
  const init = () => {
    const modal = document.getElementById('modalCrearConvocatoria');
    const section = modal?.querySelector('[data-convocatoria-aliado-modulo]');
    const ctxNode = document.getElementById('impe-convocatoria-aliado-contexto');
    if (!modal || !section || !ctxNode || section.dataset.multiV3 === '1') return;
    const form = section.closest('form');
    const grid = section.closest('.system-form-grid');
    if (!form || !grid) return;
    let context;
    try { context = JSON.parse(ctxNode.textContent || '{}'); } catch (_) { return; }
    if (!['manual','institucional'].includes(context.origen) || Number(context.id) < 1) return;
    const options = [...section.querySelectorAll('[data-impe-programa-option]')];
    const byCode = new Map();
    options.forEach(option => {
      const codigo = String(option.dataset.codigo || '');
      const clase = String(option.dataset.clasificacion || '');
      if (!codigo || !clase || !/^[a-z0-9-]{2,64}$/.test(codigo)) return;
      if (!byCode.has(codigo)) byCode.set(codigo, {codigo, nombre:option.querySelector('span')?.textContent?.trim() || codigo, clases:[]});
      if (!byCode.get(codigo).clases.includes(clase)) byCode.get(codigo).clases.push(clase);
    });
    if (!byCode.size || byCode.size > 30) return;
    section.dataset.multiV3 = '1';
    form.noValidate = true;
    const dialog = modal.querySelector('.modal-dialog');
    dialog?.classList.remove('modal-lg');
    dialog?.classList.add('modal-xl','impe-aliado-multi-dialog');
    // Conserva el formulario original en el DOM (y todos sus eventos), pero
    // evita mostrar dos formularios de convocatoria incongruentes a la vez.
    [...grid.children].forEach(child => {
      if (child !== section) {
        child.dataset.impeMultiOculto = '1';
        child.hidden = true;
        child.style.display = 'none';
      }
    });
    const oldOptions = section.querySelector('.convocatoria-aliado-modulo-grid');
    if (oldOptions) oldOptions.hidden = true;
    section.classList.add('impe-aliado-multi-section');
    const eyebrow = section.querySelector('.convocatoria-aliado-modulo-eyebrow');
    if (eyebrow) eyebrow.textContent = 'PUBLICACIONES POR PROGRAMA';
    const intro = section.querySelector('h3 + p');
    if (intro) intro.textContent = 'Cada programa tendrá título, fechas, disponibilidad e imagen independientes. El enlace y QR de Registro pertenecen al aliado y se comparten en todas las publicaciones.';
    const firstOption=options[0];
    if (firstOption) firstOption.click(); // conserva validadores originales sin guardar un registro simple.
    const errorBox = section.querySelector('[data-impe-error]');
    const notify = message => {
      errorBox.textContent = message || '';
      errorBox.classList.toggle('d-none',!message);
      if (message) errorBox.scrollIntoView({block:'nearest',behavior:'smooth'});
    };
    const cards = document.createElement('div');
    cards.className = 'impe-aliado-multi-cards';
    const make = (tag, cls, text) => {
      const n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text !== undefined) n.textContent = text;
      return n;
    };
    const field=(label,type,attrs={})=>{
      const wrap=make('label','impe-aliado-multi-field');
      const title=make('span','',label);
      const el=document.createElement(type==='select'?'select':'input');
      if(type!=='select')el.type=type;
      el.className='form-control system-form-control'+(type==='select'?' form-select':'');
      Object.entries(attrs).forEach(([k,v])=>{if(k==='required')el.required=Boolean(v);else el.setAttribute(k,String(v));});
      wrap.append(title,el);
      return {wrap,el};
    };
    const entries=[];
    [...byCode.values()].forEach((p,index)=>{
      const card=make('section','impe-aliado-multi-card');
      const header=make('div','impe-aliado-multi-card-head');
      const left=make('div');
      left.append(make('small','', 'PROGRAMA '+(index+1)),make('h4','',p.nombre),
        make('p','', 'Se creará una convocatoria independiente en Gestión de convocatorias.'));
      header.append(left,make('span','impe-aliado-multi-shared-tag','Registro y QR compartidos'));
      card.append(header);
      const name=field('Título de la convocatoria','text',{maxlength:180,required:true,
        placeholder:'Convocatoria '+new Date().getFullYear()+' - '+p.nombre});
      const row=make('div','impe-aliado-multi-row');
      const start=field('Fecha de inicio','date',{required:true});
      const end=field('Fecha de término','date',{required:true});
      const state=field('Disponibilidad','select');
      [['1','Activa'],['0','Inactiva']].forEach(([v,t])=>{const x=make('option','',t);x.value=v;state.el.append(x);});
      const photo=field('Imagen de la convocatoria','file',{accept:'image/jpeg,image/png,image/webp',required:true});
      row.append(start.wrap,end.wrap,state.wrap,photo.wrap);
      card.append(name.wrap,row);
      let classification;
      if (p.clases.length>1) {
        classification=field('Clasificación de la publicación','select');
        for(const opt of p.clases){const x=make('option','',opt.replace('|',' · '));x.value=opt;classification.el.append(x);}
        card.append(classification.wrap);
      }
      cards.append(card);
      entries.push({p,name:name.el,start:start.el,end:end.el,state:state.el,photo:photo.el,classification:classification?.el});
    });
    section.append(cards);
    const footer = modal.querySelector('.modal-footer [type="submit"]')
      || form.querySelector('[type="submit"]');
    const originalText=footer?.textContent;
    if(footer) footer.textContent='Guardar convocatorias';
    const footerLabel=modal.querySelector('.modal-title');
    if(footerLabel) footerLabel.textContent='Nueva convocatoria · '+entries.length+' programas';
    let processing=false;
    // Capturar en window antes de los manejadores heredados del modal y el
    // formulario único: no disparar jamás guardar() en un lote multiprograma.
    window.addEventListener('submit',async event=>{
      if (event.target !== form || !section.isConnected) return;
      event.preventDefault();event.stopImmediatePropagation();
      if(processing) return;
      const csrf=window.IMPE_CSRF_TOKEN
        || form.querySelector('[name="csrf_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content || '';
      if(!csrf){notify('La sesión no está disponible. Recarga el CRM.');return;}
      const url = section.querySelector('[data-impe-url]')?.value
        || form.querySelector('[name="enlace_registro"]')?.value || context.url || '';
      const statusText=section.querySelector('[data-impe-estado]')?.textContent || '';
      if(!url || /deshabilitado/i.test(statusText)){
        notify('Genera y habilita el enlace compartido del aliado antes de publicar.');return;
      }
      const payload=new FormData();
      payload.set('csrf_token',csrf);
      payload.set('aliado_tipo',context.origen);
      payload.set('aliado_id',String(context.id));
      const failures=[];
      entries.forEach(({p,name,start,end,state,photo,classification})=>{
        if(!name.value.trim())failures.push('Falta el título de '+p.nombre+'.');
        if(!start.value || !end.value || end.value<start.value) failures.push('Revisa las fechas de '+p.nombre+'.');
        if(!photo.files?.[0])failures.push('Selecciona la imagen de '+p.nombre+'.');
        if(photo.files?.[0]?.size>5*1024*1024)failures.push('La imagen de '+p.nombre+' supera 5 MB.');
        const root='programas['+p.codigo+']';
        payload.append(root+'[titulo]',name.value.trim());
        payload.append(root+'[fecha_inicio]',start.value);
        payload.append(root+'[fecha_termino]',end.value);
        payload.append(root+'[estado]',state.value);
        payload.append(root+'[clasificacion]',classification?.value || p.clases[0]);
        if(photo.files?.[0])payload.append('imagenes['+p.codigo+']',photo.files[0],photo.files[0].name);
      });
      if(failures.length){notify(failures.join(' '));return;}
      const dest=new URL(window.location.href);
      dest.search='';
      dest.searchParams.set('controller','convocatoria');
      dest.searchParams.set('action','guardarProgramasAliado');
      processing=true;if(footer)footer.disabled=true;notify('');
      try {
        const res=await fetch(dest.toString(),{
          method:'POST',body:payload,credentials:'same-origin',
          headers:{'X-Requested-With':'fetch'}
        });
        let data;
        try{data=await res.json();}catch(_){throw new Error('Respuesta inesperada al guardar el lote.');}
        if(!res.ok || !data?.ok)throw new Error(data?.mensaje || 'No se pudo guardar el lote.');
        if (!data.url) throw new Error('No se recibió la ruta de Gestión de convocatorias.');
        const next=new URL(data.url,window.location.href);
        if(next.origin!==window.location.origin)throw new Error('Ruta de redirección no segura.');
        window.location.assign(next.href);
      }catch(e){notify(e.message||'No se pudieron guardar las convocatorias.');}
      finally {processing=false;if(footer)footer.disabled=false;}
    },true);
    modal.addEventListener('hidden.bs.modal',()=>{
      if(processing)return;
      // No limpiar datos mientras el usuario edita; reinicialización bajo
      // contexto de navegación normal conserva los campos del lote.
    });
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
  else init();
})();