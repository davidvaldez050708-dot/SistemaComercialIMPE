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
    const detailStatus=root.querySelector('[data-supervision-detail-status]');
    let detailSequence=0,detailTrigger=null;
    const node=(tag,className,text)=>{const element=document.createElement(tag);if(className)element.className=className;if(text!==undefined)element.textContent=text;return element;};
    const displayDate=value=>String(value||'').replace(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)$/, '$3/$2/$1 · $4');
    function releaseDetail(){
        detailSequence++;
        const trigger=detailTrigger;detailTrigger=null;
        if(trigger){trigger.disabled=false;if(trigger.isConnected)trigger.focus({preventScroll:true});}
    }
    function closeDetail(){dialog.close();releaseDetail();}
    root.querySelectorAll('[data-supervision-close]').forEach(button=>button.addEventListener('click',closeDetail));
    dialog.addEventListener('cancel',event=>{event.preventDefault();closeDetail();});
    dialog.addEventListener('close',()=>{if(!dialog.open)releaseDetail();});
    function section(title){
        const element=node('section','supervision-detail-section');
        element.append(node('h4','supervision-detail-section-title',title));fields.append(element);return element;
    }
    function renderDetail(call){
        const summary=node('div','supervision-detail-summary'),icon=node('i','bi bi-telephone');icon.setAttribute('aria-hidden','true');
        const contact=node('div','supervision-detail-contact');contact.append(node('span','supervision-detail-label','Número de la llamada'),node('strong','',call.numero||'Sin número disponible'));
        summary.append(icon,contact,node('span','supervision-detail-direction',call.direccion||'Sin tipo indicado'));fields.append(summary);
        const metadata=node('dl','supervision-detail-metadata');
        for(const [label,value] of [['Persona',call.persona],['Fecha y hora · México',displayDate(call.fecha)],['Nombre registrado',call.nombre],['Extensión',call.extension]]){
            const pair=node('div','supervision-detail-field');pair.append(node('dt','',label),node('dd','',value||'—'));metadata.append(pair);
        }
        fields.append(metadata);
        const result=section('Resultado registrado');result.append(node('p','supervision-detail-result',call.resultado_texto||'Sin resultado registrado.'));
        if(call.detalle_resultado)result.append(node('p','supervision-detail-clarification',call.detalle_resultado));
        section('Observaciones').append(node('p','supervision-detail-text'+(call.observacion?'':' is-empty'),call.observacion||'Sin observaciones registradas.'));
    }
    async function openDetail(button,key){
        const sequence=++detailSequence;
        if(detailTrigger)detailTrigger.disabled=false;
        detailTrigger=button;button.disabled=true;
        fields.replaceChildren();detailStatus.classList.remove('is-error');detailStatus.hidden=false;detailStatus.textContent='Consultando resultado de la llamada…';
        if(!dialog.open)dialog.showModal();
        const current=()=>sequence===detailSequence&&dialog.open;
        try{
            const data=await json(endpoint(root.dataset.detailUrl,key));if(!current())return;
            if(!data.llamada||typeof data.llamada!=='object')throw Error('No se pudo consultar el resultado. Vuelve a intentarlo.');
            renderDetail(data.llamada);detailStatus.hidden=true;
            if(key.startsWith('venta:')){
                const notesSection=section('Observaciones adicionales'),notesBody=node('div','supervision-detail-notes');notesSection.append(notesBody);
                notesBody.append(node('p','supervision-detail-text is-empty','Consultando observaciones…'));
                try{
                    const notesUrl=new URL(root.dataset.detailUrl,location.href);notesUrl.searchParams.set('controller','telefonia');notesUrl.searchParams.set('action','observacionesVenta');notesUrl.searchParams.set('llamada',key);
                    const notes=await json(notesUrl);if(!current())return;
                    notesBody.replaceChildren();
                    if(!notes.disponible)notesBody.append(node('p','supervision-detail-text is-empty','Las observaciones adicionales aún no están instaladas.'));
                    else{
                        if(!Array.isArray(notes.observaciones))throw Error('No se pudieron consultar las observaciones adicionales.');
                        if(!notes.observaciones.length)notesBody.append(node('p','supervision-detail-text is-empty','Sin observaciones adicionales.'));
                        for(const note of notes.observaciones){
                            const article=node('article','supervision-detail-note'),heading=node('div','supervision-detail-note-heading');
                            heading.append(node('strong','',note.autor||'Sin autor indicado'),node('span','',displayDate(note.fecha)+' · hora de México'));
                            article.append(heading,node('p','supervision-detail-text',note.nota||''));notesBody.append(article);
                        }
                    }
                }catch(error){if(current())notesBody.replaceChildren(node('p','supervision-detail-text is-error',error.message||'No se pudieron consultar las observaciones adicionales.'));}
            }
        }catch(error){if(current()){fields.replaceChildren();detailStatus.classList.add('is-error');detailStatus.hidden=false;detailStatus.textContent=error.message||'No se pudo consultar el resultado. Vuelve a intentarlo.';}}
        finally{button.disabled=false;}
    }
    root.addEventListener('click',async event=>{
        const button=event.target.closest('[data-supervision-detail],[data-supervision-audio]');
        if(!button||button.disabled)return;
        const card=button.closest('[data-call-key]'),key=card.dataset.callKey;
        if(button.hasAttribute('data-supervision-detail')){
            await openDetail(button,key);return;
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
