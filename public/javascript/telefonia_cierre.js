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
    let calls=[], current=null, busy=false, saving=false, initial=true, openId='', transition=null;
    let scanSince=Date.now(), statusBefore=null;
    transition=saved.waiting||null;
    const completed=new Set(), observed=new Set();
    const matches=(call,id)=>call.id===id||call.aliases.includes(id);
    const refreshView=()=>{
        const missed=calls.filter(c=>c.perdida && c.fecha_ts>Date.now()-30*86400000 &&
            !saved.seen.includes(c.id) && !c.aliases.some(id=>saved.seen.includes(id)));
        if(notices){
            notices.hidden=!missed.length && !transition;
            notices.querySelector('span').textContent=!missed.length && transition?
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
            const draft=saved.forms[openId];
            if(draft){form.elements.resultado.value=String(draft.resultado||'');form.elements.nota.value=String(draft.nota||'').slice(0,500);}
        }
        if(!dialog.open){dialog.showModal();form.elements.resultado.focus({preventScroll:true});}
    };
    const load=async()=>{
        if(busy)return;busy=true;
        try{
            const response=await fetch(cfg.callNoticesUrl,{credentials:'same-origin',cache:'no-store'});
            const data=await response.json();
            if(!response.ok||!data.ok)return;
            if(String(data.extension)!==String(cfg.extension))return;
            calls=data.llamadas||[];
            if(transition){
                const exact=calls.filter(c=>matches(c,transition.id));
                const candidates=exact.length?exact:calls.filter(c=>c.fin_ts>=transition.at-3000 &&
                    transition.number.length===10 && String(c.numero).replace(/\D/g,'').slice(-10)===transition.number);
                // Una transferencia puede aparecer inicialmente como saliente atendida:
                // esperar la conciliación PBX antes de abrir resultado obligatorio.
                const transferGrace=transition.transfer && Date.now()-transition.at<12000;
                if(candidates.length===1 && (candidates[0].finalizada||candidates[0].transferida) &&
                    (!transferGrace || candidates[0].transferida)) {
                    const call=candidates[0];
                    if(call.requiere_resultado&&!saved.pending.includes(call.id))saved.pending.push(call.id);
                    if(call.transferida)notify('Llamada transferida'+(call.destino?' a extensión '+call.destino:'')+'.');
                    transition=null;saved.waiting=null;
                }
            }
            for(const call of calls){
                if(!call.finalizada){observed.add(call.id);continue;}
                const isTransition=transition && (matches(call,transition.id) ||
                    (call.fin_ts>=transition.at-3000 && String(call.numero).replace(/\D/g,'').slice(-10)===transition.number));
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
            }).slice(-50);
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
        if(openId){saved.forms[openId]={resultado:form.elements.resultado.value,nota:form.elements.nota.value};persist();}
    });
    form?.addEventListener('submit',async event=>{
        event.preventDefault();if(saving||!openId||!form.reportValidity())return;
        const id=openId, values=new FormData(form);saving=true;
        values.set('csrf_token',cfg.routingCsrf);values.set('pbx_call_id',id);
        form.querySelectorAll('button,input,select,textarea').forEach(e=>e.disabled=true);
        feedback.textContent='Guardando resultado…';
        try{
            const r=await fetch(cfg.receptionResultUrl,{method:'POST',credentials:'same-origin',cache:'no-store',body:values});
            const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.mensaje||'No se pudo guardar. Inténtalo nuevamente.');
            saved.pending=saved.pending.filter(ref=>ref!==id);delete saved.forms[id];persist();dialog.close();openId='';
            const call=calls.find(c=>c.id===id);if(call)call.requiere_resultado=false;
            notify('Resultado guardado.');
            document.getElementById('impeCrmFrame')?.contentWindow?.postMessage({type:'IMPE_RECEPTION_RESULT_SAVED',id},window.location.origin);
        }catch(e){feedback.textContent=e.message;notify(e.message,true);}
        finally{saving=false;form.querySelectorAll('button,input,select,textarea').forEach(e=>e.disabled=false);refreshView();void load();}
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
                transition={id:String(value.pbxCallId||statusBefore.pbxCallId||value.callToken||''),at:Date.now(),number:String(value.destination||statusBefore.destination||'').replace(/\D/g,'').slice(-10),transfer};
                saved.waiting=transition;
                persist();void load();
            }
            refreshView();
        },
        hasPending(){return cfg.receptionResults && (saved.pending.length>0||Boolean(transition));},
        showPending(){refreshView();void load();}
    };
    void load();window.setInterval(()=>void load(),6000);
})();
