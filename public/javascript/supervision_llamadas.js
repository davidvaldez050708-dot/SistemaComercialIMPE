(() => {
    'use strict';
    const root=document.querySelector('[data-supervision-calls]');
    if(!root)return;
    const dialog=root.querySelector('dialog'),fields=root.querySelector('[data-supervision-fields]');
    let active=null;
    const endpoint=(base,key)=>{const url=new URL(base,location.href);if(url.origin!==location.origin)throw Error();url.searchParams.set('llamada',key);return url;};
    async function json(url){const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error(response.status===403?'Ya no tienes permiso para consultar esta llamada.':'No se pudo consultar la información. Vuelve a intentarlo.');const value=await response.json();if(!value.ok)throw Error();return value;}
    function notice(text){const node=root.querySelector('[data-supervision-notice]');node.textContent=text;node.hidden=false;}
    root.querySelector('[name=area]').addEventListener('change',function(){root.querySelector('[name=persona_id]').value='0';root.querySelector('[name=resultado]').value='';this.form.requestSubmit();});
    root.querySelector('[data-supervision-close]').addEventListener('click',()=>dialog.close());
    root.addEventListener('click',async event=>{
        const button=event.target.closest('[data-supervision-detail],[data-supervision-audio]');
        if(!button||button.disabled)return;
        const card=button.closest('[data-call-key]'),key=card.dataset.callKey;
        if(button.hasAttribute('data-supervision-detail')){
            button.disabled=true;
            try{const data=await json(endpoint(root.dataset.detailUrl,key));fields.replaceChildren();
                const labels={persona:'Persona',numero:'Número',nombre:'Nombre',fecha:'Fecha y hora',direccion:'Tipo',extension:'Extensión',resultado_texto:'Resultado',observacion:'Observaciones'};
                for(const [name,label] of Object.entries(labels)){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=data.llamada[name]||'—';fields.append(dt,dd);}
                if(!dialog.open)dialog.showModal();
            }catch(error){notice(error.message||'No se pudo consultar el resultado. Vuelve a intentarlo.');}finally{button.disabled=false;}return;
        }
        const panel=card.querySelector('[data-supervision-player]');
        if(button.getAttribute('aria-expanded')==='true'){panel.querySelectorAll('audio').forEach(a=>a.pause());panel.hidden=true;button.setAttribute('aria-expanded','false');return;}
        button.disabled=true;panel.hidden=false;button.setAttribute('aria-expanded','true');panel.textContent='Consultando grabaciones…';
        try{
            const url=endpoint(root.dataset.audioUrl,key),verify=new URL(url);verify.searchParams.set('verificar','1');
            const data=await json(verify);
            if(!data.disponible){panel.textContent=data.estado==='ERROR_TEMPORAL'?'No se pudo consultar el audio. Cierra y vuelve a intentarlo.':'La grabación aún no está disponible o falta confirmar su relación con la llamada.';return;}
            button.querySelector('span').textContent=data.segmentos===1?'Consultar grabación':`Consultar ${data.segmentos} grabaciones`;
            if(active&&active!==panel)active.querySelectorAll('audio').forEach(a=>a.pause());active=panel;
            if(!window.IMPE_TELEPHONY_SEGMENTS)throw Error('Recarga la página para cargar el reproductor.');
            panel.replaceChildren();window.IMPE_TELEPHONY_SEGMENTS.create(panel,url.toString(),data.segmentos,data.segmentos_info);
            panel.addEventListener('play',()=>{root.querySelectorAll('audio').forEach(a=>{if(!panel.contains(a))a.pause();});},true);
        }catch(error){panel.textContent=error.message||'No se pudo consultar la grabación. Vuelve a intentarlo.';}finally{button.disabled=false;}
    });
})();
