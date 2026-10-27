(() => {
    'use strict';
    const root=document.querySelector('[data-supervision-calls]');
    if(!root||root.dataset.supervisionInitialized)return;
    root.dataset.supervisionInitialized='1';
    const dialog=root.querySelector('dialog'),fields=root.querySelector('[data-supervision-fields]');
    let active=null;
    const endpoint=(base,key)=>{const url=new URL(base,location.href);if(url.origin!==location.origin)throw Error();url.searchParams.set('llamada',key);return url;};
    async function json(url){const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error(response.status===403?'Ya no tienes permiso para consultar esta llamada.':'No se pudo consultar la información. Vuelve a intentarlo.');const value=await response.json();if(!value.ok)throw Error();return value;}
    function notice(text){const node=root.querySelector('[data-supervision-notice]');node.textContent=text;node.hidden=false;}
    const form=root.querySelector('[data-supervision-filters]');
    if(form){
        const search=form.elements.namedItem('buscar'),from=form.elements.namedItem('desde'),until=form.elements.namedItem('hasta');
        const focusKey='impe:supervision-filter-focus:'+Number(window.IMPE_CURRENT_USER_ID||0);
        const parameters=()=>{const values=new URLSearchParams(new FormData(form));values.delete('pagina');return values.toString();};
        let timer=0,composing=false,last=parameters();
        try{
            const saved=JSON.parse(sessionStorage.getItem(focusKey)||'null');sessionStorage.removeItem(focusKey);
            if(search&&saved&&Date.now()-saved.at<5000&&saved.url===location.pathname+location.search&&saved.value===search.value){search.focus({preventScroll:true});search.setSelectionRange(saved.start,saved.end);}
        }catch(_){}
        const valid=()=>{
            from.setCustomValidity('');
            if(from.value&&until.value){
                if(from.value>until.value)from.setCustomValidity('La fecha inicial debe ser anterior o igual a la final.');
                else if((Date.parse(until.value)-Date.parse(from.value))/86400000>366)from.setCustomValidity('Selecciona un periodo de hasta 366 días.');
            }
            return form.checkValidity();
        };
        const apply=()=>{clearTimeout(timer);if(!composing&&valid()&&parameters()!==last)form.requestSubmit();};
        form.addEventListener('submit',event=>{
            clearTimeout(timer);
            if(composing||!valid()||parameters()===last){event.preventDefault();return;}
            last=parameters();form.querySelector('[name=pagina]')?.remove();
            if(document.activeElement===search)try{
                const url=new URL(form.getAttribute('action')||location.href,location.href);url.search=last;
                sessionStorage.setItem(focusKey,JSON.stringify({at:Date.now(),url:url.pathname+url.search,value:search.value,start:search.selectionStart,end:search.selectionEnd}));
            }catch(_){}
        });
        form.addEventListener('change',event=>{
            if(event.target.name==='area'){form.elements.namedItem('persona_id').value='0';form.elements.namedItem('resultado').value='';}
            if(event.target!==search)apply();
        });
        form.addEventListener('input',event=>{if(event.target===from||event.target===until)from.setCustomValidity('');});
        if(search){
            search.addEventListener('input',()=>{clearTimeout(timer);if(!composing)timer=setTimeout(apply,450);});
            search.addEventListener('search',apply);
            search.addEventListener('compositionstart',()=>{composing=true;clearTimeout(timer);});
            search.addEventListener('compositionend',()=>{composing=false;clearTimeout(timer);timer=setTimeout(apply,450);});
            search.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.isComposing){event.preventDefault();apply();}});
        }
        window.addEventListener('pagehide',()=>clearTimeout(timer),{once:true});
    }
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
