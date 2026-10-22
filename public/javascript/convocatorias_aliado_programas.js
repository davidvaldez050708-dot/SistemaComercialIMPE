document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const root = document.querySelector('[data-convocatoria-programas-aliado]');
    const share = document.querySelector('[data-convocatoria-form-share][data-aliado-registro-contexto="1"]');
    if (!root || !share) return;

    const buttons = [...root.querySelectorAll('[data-aliado-programa-crear]')];
    const codeInput = document.querySelector('[data-programa-aliado-codigo]');
    const classInput = document.querySelector('[data-convocatoria-context-classification]');
    const classOptions = [...document.querySelectorAll('[data-convocatoria-classification-option]')];
    const urlInput = document.querySelector('[data-convocatoria-form-url]');
    const linkInput = document.querySelector('[data-convocatoria-link-input]');
    const qrPanel = share.querySelector('[data-aliado-qr-panel]');
    const canvas = share.querySelector('[data-aliado-qr-canvas]');
    const buttonLink = share.querySelector('[data-convocatoria-generate-link]');
    const buttonQr = share.querySelector('[data-convocatoria-generate-qr]');
    const result = share.querySelector('[data-convocatoria-form-result]');
    const type = share.dataset.aliadoRegistroTipo;
    const id = share.dataset.aliadoRegistroId;
    let busy = false;

    const toast = (message, error = false) => {
        if (!window.bootstrap?.Toast) return;
        let tray = document.querySelector('[data-convocatoria-copy-toast-container]');
        if (!tray) {
            tray = document.createElement('div');
            tray.className = 'toast-container position-fixed top-0 end-0 p-3';
            tray.dataset.convocatoriaCopyToastContainer = '';
            tray.style.zIndex = '1500';
            document.body.appendChild(tray);
        }
        const node = document.createElement('div');
        node.className = 'toast system-toast' + (error ? ' system-toast-error' : '');
        node.setAttribute('role',error?'alert':'status');
        const body = document.createElement('div'); body.className='toast-body';
        const icon = document.createElement('i');icon.className='bi '+(error?'bi-exclamation-circle':'bi-check2-circle');
        const span = document.createElement('span');span.textContent=message;
        body.append(icon,span);node.appendChild(body);tray.appendChild(node);
        node.addEventListener('hidden.bs.toast',()=>node.remove(),{once:true});
        bootstrap.Toast.getOrCreateInstance(node,{delay:error?4400:2800}).show();
    };

    const marcar = button => {
        buttons.forEach(item=>{
            const selected=item===button;
            item.classList.toggle('is-selected',selected);
            item.setAttribute('aria-pressed',selected?'true':'false');
        });
        if (!button) return;
        codeInput.value=button.dataset.programaCodigo;
        const classification=button.dataset.programaClasificacion;
        const option=classOptions.find(o=>o.dataset.convocatoriaClassificationOption===classification);
        if (option) option.click();
        else {
            if (classInput) { classInput.value=classification;classInput.dispatchEvent(new Event('change',{bubbles:true})); }
        }
    };
    buttons.forEach(button=>{
        button.setAttribute('aria-pressed','false');
        button.addEventListener('click',()=>marcar(button));
    });
    const restored = buttons.find(button=>button.dataset.programaCodigo===codeInput?.value &&
        button.dataset.programaClasificacion===classInput?.value);
    if (restored) marcar(restored);
    else if (buttons.length===1) marcar(buttons[0]);

    const actualizarLink = url => {
        if (!url) return;
        share.dataset.aliadoRegistroUrl=url;
        urlInput.value=url;
        linkInput.value=url;
        linkInput.readOnly=true;
        result?.classList.remove('d-none');
        buttonQr.disabled=false;
        if (qrPanel) qrPanel.classList.add('d-none');
    };
    if (share.dataset.aliadoRegistroUrl) actualizarLink(share.dataset.aliadoRegistroUrl);

    const gestionar = async operacion => {
        const form=new FormData();
        form.set('origen',type);form.set('id',id);form.set('operacion',operacion);
        form.set('csrf_token',window.IMPE_CSRF_TOKEN||'');
        const base = new URL(window.location.href);
        base.search='';
        base.searchParams.set('controller','aliado');
        base.searchParams.set('action','registroGestion');
        const res=await fetch(base.toString(),{method:'POST',body:form,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});
        const data=await res.json();
        if(!res.ok||!data.ok)throw new Error(data.mensaje||'No fue posible gestionar el enlace.');
        return data.datos;
    };
    buttonLink?.addEventListener('click',async()=>{
        if(busy)return;
        busy=true;buttonLink.disabled=true;
        try{
            const data=await gestionar('generar');
            share.dataset.aliadoRegistroHabilitado=data.habilitado?'1':'0';
            actualizarLink(data.url);
            if(!data.habilitado) toast('El enlace existe, pero está deshabilitado. Habilítalo antes de difundir.',true);
            else toast('Enlace estable del aliado listo para sus convocatorias.');
        }catch(e){toast(e.message,true);}
        finally{busy=false;buttonLink.disabled=false;}
    });

    const activar = share.querySelector('[data-aliado-habilitar-registro]');
    const estado = share.querySelector('[data-aliado-habilitacion-estado]');
    const mostrarHabilitacion = () => {
        if(activar) activar.hidden=share.dataset.aliadoRegistroHabilitado==='1';
        if(estado) estado.textContent=share.dataset.aliadoRegistroHabilitado==='1' ?
            'Captura habilitada para todos los programas autorizados' :
            'Captura deshabilitada: el enlace no aceptará registros';
    };
    mostrarHabilitacion();
    activar?.addEventListener('click',async()=>{
        if(busy)return;busy=true;activar.disabled=true;
        try {
            const data=await gestionar('habilitar');
            share.dataset.aliadoRegistroHabilitado=data.habilitado?'1':'0';
            actualizarLink(data.url);mostrarHabilitacion();
            toast('Registro del aliado habilitado.');
        } catch(e){toast(e.message,true);}
        finally{busy=false;activar.disabled=false;}
    });

    const generarQR=()=>{
        const url=String(share.dataset.aliadoRegistroUrl||'');
        if(!url||!window.qrcode)throw new Error('Primero genera el enlace del aliado.');
        const code=qrcode(0,'M');code.addData(url);code.make();
        const count=code.getModuleCount(),size=600,quiet=5;
        canvas.width=canvas.height=size;
        const ctx=canvas.getContext('2d');
        ctx.fillStyle='#ffffff';ctx.fillRect(0,0,size,size);
        const cell=Math.floor(size/(count+quiet*2)),offset=Math.floor((size-count*cell)/2);
        ctx.fillStyle='#273a8a';
        for(let y=0;y<count;y++)for(let x=0;x<count;x++)
            if(code.isDark(y,x))ctx.fillRect(offset+x*cell,offset+y*cell,cell,cell);
        qrPanel.classList.remove('d-none');
    };
    buttonQr?.addEventListener('click',()=>{
        try{generarQR();toast('QR local listo: mismo enlace para todos los programas.');}
        catch(e){toast(e.message,true);}
    });
    share.querySelector('[data-aliado-qr-download]')?.addEventListener('click',()=>{
        if(!canvas.width)return;
        const link=document.createElement('a');link.download='qr-registro-aliado.png';
        link.href=canvas.toDataURL('image/png');link.click();
    });
    share.querySelector('[data-aliado-qr-copy]')?.addEventListener('click',async()=>{
        try{
            if(!navigator.clipboard?.write||typeof ClipboardItem==='undefined')throw new Error('Tu navegador no admite copiar imágenes. Utiliza Descargar QR.');
            const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
            if(!blob)throw new Error('No se pudo copiar el QR.');
            await navigator.clipboard.write([new ClipboardItem({'image/png':blob})]);
            toast('Código QR copiado.');
        }catch(e){toast(e.message,true);}
    });
});
