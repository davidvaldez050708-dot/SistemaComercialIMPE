/* Cierre de recepción y avisos persistentes de llamadas perdidas.
 * Las llamadas siempre se vuelven a obtener del servidor autenticado.
 */
(() => {
    'use strict';
    const cfg=window.IMPE_PHONE_SHELL_CONFIG||{};
    if(!cfg.callNoticesUrl)return;
    const dialog=document.querySelector('[data-call-result-required]');
    const form=dialog?.querySelector('form'), number=dialog?.querySelector('[data-result-number]');
    const feedback=dialog?.querySelector('[data-result-feedback]');
    const notices=document.querySelector('[data-missed-calls]');
    const key='impe:call-closure:'+cfg.userId+':'+cfg.extension;
    let saved={pending:[],seen:[],forms:{}};
    try{const data=JSON.parse(localStorage.getItem(key)||'null');if(data && Array.isArray(data.pending)&&Array.isArray(data.seen))saved=data;}catch(_){}
    const persist=()=>{try{localStorage.setItem(key,JSON.stringify(saved));}catch(_){}};
    saved.forms ||= {};
    let calls=[], current=null, busy=false, saving=false, initial=true, openId='';
    let scanSince=Date.now(), statusBefore=null,draftTimer=null;
    const user=Number(cfg.userId),drafts=window.IMPE_CALL_DRAFTS;
    const valid=id=>/^[a-zA-Z0-9_-]{6,100}$/.test(String(id||''));
    if(saved.waiting&&valid(saved.waiting.id))try{
        const legacy=saved.waiting;
        const existing=drafts.localRead(user,legacy.id);
        drafts.localSave(user,[legacy.id],existing?{}:(saved.forms[legacy.id]||{}),{adaptador:'RECEPCION',
            cierre_local:existing?.contexto?.cierre_local||{at:Number(legacy.at)||Date.now(),transferencia:Boolean(legacy.transfer),conciliado:false}});
        delete saved.waiting;persist();
    }catch(_){/* Conservar el aviso anterior si no pudo migrarse al almacén común. */}
    const waitingEntries=()=>{
        let entries=[];
        try{entries=(drafts?.list(user)||[]).filter(d=>d.contexto?.adaptador==='RECEPCION'&&
            d.contexto?.cierre_local&&!d.contexto.cierre_local.conciliado&&!d.pending_close)
            .map(d=>({id:d.ids[0],ids:d.ids,at:d.contexto.cierre_local.at,transfer:d.contexto.cierre_local.transferencia}));}catch(_){}
        if(saved.waiting&&valid(saved.waiting.id)&&!entries.some(e=>e.ids.includes(saved.waiting.id)))
            entries.push({...saved.waiting,ids:[saved.waiting.id],legacy:true});
        return entries;
    };
    const completed=new Set(), observed=new Set();
    const matches=(call,id)=>call.id===id||(call.aliases||[]).includes(id);
    const matchesEntry=(call,entry)=>entry.ids.some(id=>matches(call,id));
    const resolveWaiting=(entry,requiresResult)=>{
        const capture=drafts?.localRead(user,entry.id);
        if(capture)try{
            const resolved=drafts.localSave(user,entry.ids,{}, {adaptador:'RECEPCION',
                cierre_local:{at:entry.at,transferencia:Boolean(entry.transfer),conciliado:true}});
            // Sólo cerrar automáticamente un aviso vacío cuando la respuesta
            // autenticada acredita que esa llamada no necesita resultado.
            // Cualquier dato humano permanece en el almacén común.
            if(!requiresResult&&Object.keys(resolved.valores||{}).length===0){
                const csrf=window.IMPE_PHONE_HTTP.csrfToken?.()||cfg.routingCsrf;
                void drafts.remove(user,entry.ids,csrf,resolved.saved_at).catch(()=>{});
            }
            if(entry.legacy)delete saved.waiting;
        }catch(_){}
    };
    const refreshView=()=>{
        const missed=calls.filter(c=>c.perdida && c.fecha_ts>Date.now()-30*86400000 &&
            !saved.seen.includes(c.id) && !c.aliases.some(id=>saved.seen.includes(id)));
        if(notices){
            const waiting=waitingEntries();
            notices.hidden=!missed.length && !waiting.length;
            notices.querySelector('span').textContent=!missed.length && waiting.length?
                'Actualizando llamada · revisar':missed.length===1?
                '1 llamada perdida · revisar':missed.length+' llamadas perdidas · revisar';
        }
        // Una nueva entrante tiene prioridad. El resultado pendiente vuelve
        // al finalizarla; no se descarta ni bloquea el botón de Contestar.
        if(current?.active){if(dialog?.open)dialog.close();return;}
        if(!dialog || saving)return;
        const call=saved.pending.map(id=>calls.find(c=>matches(c,id))).find(c=>c?.requiere_resultado);
        if(!call){if(dialog.open)dialog.close();openId='';return;}
        try{if(document.getElementById('impeCrmFrame')?.contentDocument?.querySelector('[data-marketing-result-dialog].show'))return;}catch(_){}
        if(openId!==call.id){
            form.reset();feedback.textContent='';openId=call.id;number.textContent=call.tipo+' · '+call.numero;
            const draft=window.IMPE_CALL_DRAFTS?.localRead(Number(cfg.userId),openId)?.valores||saved.forms[openId];
            if(draft){form.elements.resultado.value=String(draft.resultado||'');form.elements.nota.value=String(draft.nota||'').slice(0,500);}
        }
        if(!dialog.open){
            dialog.showModal();form.elements.resultado.focus({preventScroll:true});
            document.dispatchEvent(new CustomEvent('impe:call-result-form-opened',{detail:{form,ids:[openId],adaptador:'RECEPCION'}}));
        }
    };
    const load=async()=>{
        if(busy)return;busy=true;
        try{
            const response=await window.IMPE_PHONE_HTTP.request(cfg.callNoticesUrl,{credentials:'same-origin',cache:'no-store'});
            const data=await response.json();
            if(!response.ok||!data.ok)return;
            if(String(data.extension)!==String(cfg.extension))return;
            calls=data.llamadas||[];
            const waiting=waitingEntries();
            for(const entry of waiting){
                const exact=calls.filter(c=>matchesEntry(c,entry));
                // El número y la cercanía temporal no identifican una llamada.
                // Sin una referencia o alias acreditado, conservar el pendiente.
                const candidates=exact;
                // Una transferencia puede aparecer inicialmente como saliente atendida:
                // esperar la conciliación PBX antes de abrir resultado obligatorio.
                const transferGrace=entry.transfer && Date.now()-entry.at<12000;
                if(candidates.length===1 && (candidates[0].finalizada||candidates[0].transferida) &&
                    (!transferGrace || candidates[0].transferida)) {
                    const call=candidates[0];
                    if(call.requiere_resultado&&!saved.pending.includes(call.id))saved.pending.push(call.id);
                    if(call.transferida)notify('Llamada transferida'+(call.destino?' a extensión '+call.destino:'')+'.');
                    resolveWaiting(entry,Boolean(call.requiere_resultado));
                }
            }
            for(const call of calls){
                if(!call.finalizada){observed.add(call.id);continue;}
                const transition=waiting.find(entry=>matchesEntry(call,entry));
                const isTransition=Boolean(transition);
                // La llamada puede haberse observado viva antes del cierre:
                // tampoco encolar por observed ni por !initial mientras
                // la transferencia espera su confirmación PBX.
                if(isTransition && transition.transfer && Date.now()-transition.at<12000){
                    completed.add(call.id);continue;
                }
                // No forzar a clasificar todo el historial antiguo al instalar.
                if(call.requiere_resultado && (isTransition||observed.has(call.id)||
                    (!initial && !completed.has(call.id) && call.fin_ts>=scanSince-3000)) && !saved.pending.includes(call.id))saved.pending.push(call.id);
                completed.add(call.id);
            }
            saved.pending=saved.pending.filter(id=>{
                const c=calls.find(c=>matches(c,id));
                // Un registro que tarda en llegar sigue pendiente. Una
                // respuesta definitiva del servidor sí retira la obligación.
                return c?c.requiere_resultado:true;
            });
            saved.seen=saved.seen.slice(-200);persist();initial=false;refreshView();
        }catch(_){/* El pendiente se conserva para reintentar. */}
        finally{busy=false;}
    };
    const notify=(message,error=false)=>{
        let box=document.querySelector('[data-call-closure-toast]');
        if(!box){box=document.createElement('div');box.dataset.callClosureToast='';box.className='impe-call-closure-toast toast system-toast';box.setAttribute('role','status');document.body.appendChild(box);}
        (dialog?.open?dialog:document.body).appendChild(box);
        box.classList.toggle('system-toast-error',error);
        const icon=document.createElement('i');icon.className='bi '+(error?'bi-exclamation-circle':'bi-check2-circle');icon.setAttribute('aria-hidden','true');
        const text=document.createElement('span');text.textContent=message;box.replaceChildren(icon,text);
        box.hidden=false;window.setTimeout(()=>box.hidden=true,5000);
    };
    dialog?.addEventListener('cancel',e=>e.preventDefault());
    form?.addEventListener('input',()=>{
        if(openId){
            const id=openId,values={resultado:form.elements.resultado.value,nota:form.elements.nota.value};
            try{window.IMPE_CALL_DRAFTS.localSave(Number(cfg.userId),[id],values,{adaptador:'RECEPCION'});}
            catch(_){feedback.textContent='Mantén abierto el formulario: no se pudo conservar la captura.';return;}
            clearTimeout(draftTimer);draftTimer=setTimeout(()=>{
                void window.IMPE_CALL_DRAFTS.save(Number(cfg.userId),[id],values,cfg.routingCsrf,{adaptador:'RECEPCION'}).catch(()=>{});
            },700);
        }
    });
    form?.addEventListener('submit',async event=>{
        event.preventDefault();if(saving||!openId||!form.reportValidity())return;
        const id=openId;let capture;
        try{capture=window.IMPE_CALL_DRAFTS.beginSave(Number(cfg.userId),[id],form,{adaptador:'RECEPCION'});}
        catch(_){feedback.textContent='No se pudo conservar la captura. Mantén abierto el formulario.';return;}
        const values=new URLSearchParams(capture.values);saving=true;
        values.set('csrf_token',cfg.routingCsrf);values.set('pbx_call_id',id);
        form.querySelectorAll('button,input,select,textarea').forEach(e=>e.disabled=true);
        feedback.textContent='Guardando resultado…';
        try{
            const r=await window.IMPE_PHONE_HTTP.request(cfg.receptionResultUrl,{method:'POST',credentials:'same-origin',cache:'no-store',body:values});
            const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.mensaje||'No se pudo guardar. Inténtalo nuevamente.');
            clearTimeout(draftTimer);
            void window.IMPE_CALL_DRAFTS.remove(Number(cfg.userId),[id],cfg.routingCsrf,capture.revision).catch(()=>{});
            saved.pending=saved.pending.filter(ref=>ref!==id);delete saved.forms[id];persist();dialog.close();openId='';
            const call=calls.find(c=>c.id===id);if(call)call.requiere_resultado=false;
            notify('Resultado guardado.');
            document.getElementById('impeCrmFrame')?.contentWindow?.postMessage({type:'IMPE_RECEPTION_RESULT_SAVED',id},window.location.origin);
        }catch(e){feedback.textContent=e.message;notify(e.message,true);}
        finally{saving=false;capture.release();refreshView();void load();}
    });
    notices?.addEventListener('click',()=>{
        const frame=document.getElementById('impeCrmFrame');
        const url=new URL(cfg.callHistoryUrl,window.location.href);if(url.origin!==window.location.origin)return;
        if(frame)frame.src=url.toString();
        for(const call of calls.filter(c=>c.perdida))saved.seen.push(call.id,...call.aliases);
        persist();refreshView();
    });
    window.addEventListener('message',event=>{
        if(event.origin!==window.location.origin || event.source!==document.getElementById('impeCrmFrame')?.contentWindow)return;
        if(event.data?.type==='IMPE_RECEPTION_RESULT_SAVED')void load();
    });
    window.IMPE_CALL_CLOSURE={
        onState(value){
            statusBefore=current;current=value;
            if(cfg.receptionResults && statusBefore?.active && !value.active && ['finished','incoming-finished'].includes(value.phase)){
                const transferStage=String(value.context?.transferRequest?.stage||statusBefore.context?.transferRequest?.stage||'');
                const transfer=Boolean(value.status==='transferred'||
                    ['ENVIADA','ENTREGADA','ENTREGANDO','SIN_CONFIRMAR','SIN_RESPUESTA'].includes(transferStage));
                const id=String(value.pbxCallId||statusBefore.pbxCallId||value.callToken||'');
                const ids=[...new Set([id,value.callToken,value.context?.sdkSessionId].filter(valid))];
                if(ids.length)try{
                    const existing=ids.some(ref=>drafts.localRead(user,ref));
                    drafts.localSave(user,ids,existing?{}:(saved.forms[id]||{}), {adaptador:'RECEPCION',
                        cierre_local:{at:Date.now(),transferencia:transfer,conciliado:false}});
                }catch(_){notify('No se pudo conservar el aviso de esta llamada. Revisa el historial de recepción.',true);}
                persist();void load();
            }
            refreshView();
        },
        hasPending(){return cfg.receptionResults && saved.pending.length>0;},
        showPending(){refreshView();void load();}
    };
    void load();window.setInterval(()=>void load(),6000);
})();
