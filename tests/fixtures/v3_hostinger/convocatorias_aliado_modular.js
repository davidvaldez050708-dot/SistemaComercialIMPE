/* Módulo de Convocatorias para aliados. No toca el generador QR de convocatorias comunes. */
(() => {
  'use strict';
  const init = () => {
    const data = document.getElementById('impe-convocatoria-aliado-contexto');
    const template = document.getElementById('impe-convocatoria-aliado-template');
    const modal = document.getElementById('modalCrearConvocatoria');
    if (!data || !template || !modal || modal.dataset.impeAliadoInicializado === '1') return;
    const form = modal.querySelector('form[action*="action=guardar"]') || modal.querySelector('form');
    const grid = form?.querySelector('.system-form-grid');
    if (!form || !grid) {
      console.error('[Convocatorias Aliados] El modal no expone el formulario/grilla esperados. No se modificó.');
      return;
    }
    let context;
    try { context = JSON.parse(data.textContent || '{}'); }
    catch (_) { console.error('[Convocatorias Aliados] Contexto no válido'); return; }
    if (!['manual','institucional'].includes(context.origen) || Number(context.id) < 1) return;
    const section = template.content.firstElementChild.cloneNode(true);
    grid.prepend(section);
    modal.dataset.impeAliadoInicializado = '1';

    const one = s => section.querySelector(s);
    const options = [...section.querySelectorAll('[data-impe-programa-option]')];
    const code = one('[data-impe-programa-codigo]');
    const state = one('[data-impe-estado]');
    const error = one('[data-impe-error]');
    const linkInput = form.querySelector('[name="enlace_registro"]');
    const classification = form.querySelector('[name="clasificacion"]');
    const genericShare = modal.querySelector('[data-convocatoria-form-share]');
    // Las opciones antiguas de QR e Inscripción no aplican al formulario exclusivo.
    if (genericShare) genericShare.classList.add('d-none');
    const genericSelector = modal.querySelector('[data-convocatoria-classification-dropdown]');
    const selectorGroup = genericSelector?.closest('.system-form-full');
    if (selectorGroup) selectorGroup.classList.add('d-none');
    if (linkInput) {
      linkInput.readOnly = true;
      linkInput.setAttribute('aria-label','Enlace único de Registro del aliado');
    }

    const setError = message => {
      error.textContent = message || '';
      error.classList.toggle('d-none', !message);
    };
    const toast = (message, fail = false) => {
      if (!window.bootstrap?.Toast) { if (fail) setError(message); return; }
      let tray = document.getElementById('impe-convocatoria-aliado-toasts');
      if (!tray) {
        tray = document.createElement('div');
        tray.id = 'impe-convocatoria-aliado-toasts';
        tray.className = 'toast-container position-fixed top-0 end-0 p-3';
        tray.style.zIndex = '2000';
        document.body.append(tray);
      }
      const node = document.createElement('div');
      node.className = 'toast system-toast' + (fail ? ' system-toast-error' : '');
      node.setAttribute('role',fail?'alert':'status');
      const body = document.createElement('div');body.className='toast-body';
      const icon = document.createElement('i');icon.className='bi '+(fail?'bi-exclamation-circle':'bi-check2-circle');
      const text = document.createElement('span');text.textContent=message;
      body.append(icon,text);node.append(body);tray.append(node);
      node.addEventListener('hidden.bs.toast',()=>node.remove(),{once:true});
      bootstrap.Toast.getOrCreateInstance(node,{delay:fail?4500:3200}).show();
    };
    const choose = option => {
      const codeValue = option.dataset.codigo || '';
      const classificationValue = option.dataset.clasificacion || '';
      if (!codeValue || !classificationValue) return;
      options.forEach(b => {
        const active = b === option;
        b.classList.toggle('is-selected',active);
        b.setAttribute('aria-pressed',active?'true':'false');
      });
      code.value = codeValue;
      if (classification) {
        classification.value = classificationValue;
        classification.dispatchEvent(new Event('change',{bubbles:true}));
        classification.dispatchEvent(new Event('input',{bubbles:true}));
      }
      setError('');
    };
    options.forEach(option=>option.addEventListener('click',()=>choose(option)));
    if (options.length === 1) choose(options[0]);

    const createLink = one('[data-impe-crear-enlace]');
    const createQr = one('[data-impe-crear-qr]');
    const enable = one('[data-impe-habilitar]');
    const urlWrap = one('[data-impe-url-wrap]');
    const urlBox = one('[data-impe-url]');
    const qrWrap = one('[data-impe-qr-wrap]');
    const canvas = one('[data-impe-qr-canvas]');
    let busy = false;
    const setUrl = url => {
      if (!url) return;
      context.url = url;
      if (linkInput) {
        linkInput.value = url;
        linkInput.dispatchEvent(new Event('change',{bubbles:true}));
      }
      urlBox.value = url;
      urlWrap.classList.remove('d-none');
      createQr.disabled = false;
      qrWrap.classList.add('d-none');
    };
    const renderState = () => {
      state.textContent = context.habilitado
        ? 'Registro habilitado para todos los programas autorizados.'
        : 'Registro deshabilitado: no se admitirán nuevos prospectos hasta habilitarlo.';
      enable.hidden = Boolean(context.habilitado);
    };
    renderState();
    setUrl(context.url || '');

    const manage = async operation => {
      const csrf = window.IMPE_CSRF_TOKEN || form.querySelector('[name="csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '';
      if (!csrf) throw new Error('No se encontró el token de sesión. Recarga el CRM.');
      const payload = new FormData();
      payload.set('csrf_token',csrf);
      payload.set('origen',context.origen);
      payload.set('id',String(context.id));
      payload.set('operacion',operation);
      const url = new URL(window.location.href);
      url.search='';
      url.searchParams.set('controller','aliado');
      url.searchParams.set('action','registroGestion');
      const res=await fetch(url.toString(),{method:'POST',body:payload,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});
      const response=await res.json();
      if (!res.ok || !response.ok) throw new Error(response.mensaje || 'No se pudo administrar el enlace.');
      return response.datos;
    };
    const act = async (operation,button) => {
      if (busy) return;
      busy = true;button.disabled = true;setError('');
      try {
        const result = await manage(operation);
        context.habilitado = Boolean(result.habilitado);
        setUrl(result.url || '');
        renderState();
        toast(context.habilitado ? 'Enlace de Registro del aliado listo.' : 'Enlace generado, pero el Registro está deshabilitado.',!context.habilitado);
      } catch(e) {setError(e.message);toast(e.message,true);}
      finally {busy=false;button.disabled=false;}
    };
    createLink.addEventListener('click',()=>act('generar',createLink));
    enable.addEventListener('click',()=>act('habilitar',enable));
    one('[data-impe-copiar-enlace]').addEventListener('click',async()=>{
      try {await navigator.clipboard.writeText(urlBox.value);toast('Enlace copiado.');}
      catch(_) {urlBox.select();toast('Selecciona y copia el enlace.',true);}
    });
    const drawQr = () => {
      if (!context.url) throw new Error('Genera primero el enlace de Registro.');
      if (!window.qrcode) throw new Error('No se cargó el generador QR local.');
      const qr = qrcode(0,'M');qr.addData(context.url);qr.make();
      const ctx = canvas.getContext('2d');
      canvas.width = canvas.height = 600;
      ctx.fillStyle='#fff';ctx.fillRect(0,0,600,600);
      const modules=qr.getModuleCount(),quiet=5;
      const size=Math.floor(600/(modules+quiet*2));
      const offset=Math.floor((600-modules*size)/2);
      ctx.fillStyle='#273a8a';
      for(let y=0;y<modules;y++)for(let x=0;x<modules;x++)
        if(qr.isDark(y,x))ctx.fillRect(offset+x*size,offset+y*size,size,size);
      qrWrap.classList.remove('d-none');
    };
    createQr.addEventListener('click',()=>{
      try {drawQr();toast('QR compartido listo para las convocatorias del aliado.');}
      catch(e){toast(e.message,true);}
    });
    one('[data-impe-descargar-qr]').addEventListener('click',()=>{
      if(qrWrap.classList.contains('d-none')) return;
      const link=document.createElement('a');
      link.download='registro-aliado-qr.png';link.href=canvas.toDataURL('image/png');link.click();
    });
    one('[data-impe-copiar-qr]').addEventListener('click',async()=>{
      try {
        if(qrWrap.classList.contains('d-none')) throw new Error('Genera el QR primero.');
        if(!navigator.clipboard?.write || typeof ClipboardItem==='undefined') throw new Error('Este navegador no admite copiar imágenes. Descarga el PNG.');
        const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
        if(!blob)throw new Error('No se pudo copiar el QR.');
        await navigator.clipboard.write([new ClipboardItem({'image/png':blob})]);toast('QR copiado.');
      } catch(e) {toast(e.message,true);}
    });
    form.addEventListener('submit',event=>{
      if(!code.value || !classification?.value){
        event.preventDefault();event.stopImmediatePropagation();
        setError('Selecciona un programa del aliado antes de guardar la convocatoria.');
        section.scrollIntoView({behavior:'smooth',block:'nearest'});
      }
    },true);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init,{once:true});
  else init();
})();
