document.addEventListener('DOMContentLoaded',function(){
    'use strict';
    const request=async(url,body)=>{
        const response=await fetch(url,{method:'POST',credentials:'same-origin',body,headers:{'X-Requested-With':'fetch'}});
        let data;try{data=await response.json();}catch{throw Error('No se pudo confirmar la operación. Revisa el historial antes de reintentar.');}
        if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo confirmar la operación. Revisa el historial antes de reintentar.');
        return data;
    };
    document.querySelectorAll('[data-wa-ventas-config]').forEach(form=>form.addEventListener('submit',async event=>{
        event.preventDefault();if(form.dataset.pending==='1'||!form.reportValidity())return;
        form.dataset.pending='1';const button=form.querySelector('button');button.disabled=true;
        const feedback=form.querySelector('[data-wa-ventas-feedback]');feedback.textContent='Validando con Meta…';
        try{const data=await request(form.action,new FormData(form));feedback.textContent=data.mensaje;form.querySelector('[data-wa-ventas-preview]').textContent=data.plantilla.cuerpo;const state=form.closest('article')?.querySelector('[data-wa-template-state]');if(state){state.textContent='Plantilla configurada';state.classList.remove('is-pending');}}
        catch(error){feedback.textContent=error.message||'No se pudo validar con Meta.';}
        finally{form.dataset.pending='0';button.disabled=false;}
    }));
    document.querySelectorAll('[data-wa-ventas-inicio]').forEach(button=>button.addEventListener('click',async()=>{
        if(button.dataset.pending==='1'||button.dataset.sent==='1')return;
        button.dataset.pending='1';button.disabled=true;
        const feedback=button.parentElement.querySelector('[data-wa-ventas-status]');
        if(!button.dataset.requestId)button.dataset.requestId=globalThis.crypto?.randomUUID?.()||('wa-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2));
        const body=new FormData();body.set('csrf_token',button.dataset.csrf||'');body.set('conversacion_id',button.dataset.id||'');body.set('request_id',button.dataset.requestId);
        feedback.textContent='Enviando presentación…';
        try{const data=await request(button.dataset.url,body);feedback.textContent=data.mensaje;button.dataset.sent='1';button.textContent='Presentación enviada';}
        catch(error){feedback.textContent=error.message||'No se pudo confirmar el envío. Revisa el historial antes de reintentar.';}
        finally{button.dataset.pending='0';button.disabled=button.dataset.sent==='1';}
    }));
});
