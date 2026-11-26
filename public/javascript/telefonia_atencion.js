/* Utilidades compartidas de atención; no ejecutan acciones sobre la llamada. */
(() => {
    'use strict';
    const endpoint=new URL('prueba_telefonia/api/borrador_llamada.php',document.baseURI);
    const valid=id=>/^[a-zA-Z0-9_-]{6,100}$/.test(String(id||''));
    const refs=ids=>{
        const selected=[...new Set(ids.filter(valid))];
        if(selected.length>3)throw new Error('Hay demasiadas referencias para esta captura. Conserva el formulario abierto.');
        return selected;
    };
    const key=(user,id)=>'impe:crm:call-draft:'+Number(user)+':'+id;
    const actor=()=>Number(window.IMPE_CURRENT_USER_ID||window.IMPE_PHONE_SHELL_CONFIG?.userId||0);
    const own=user=>{if(Number(user)!==actor()||actor()<1)throw new Error('La captura pertenece a otra sesión de usuario.');};
    const localRead=(user,id)=>{
        let latest=null;
        for(const name of ['localStorage','sessionStorage'])try {
            const data=JSON.parse(globalThis[name].getItem(key(user,id))||'null');
            if(data&&(!latest||Number(data.saved_at)>Number(latest.saved_at)))latest=data;
        }catch(_){}
        return latest;
    };
    const fresh=d=>d && !d.closed && Number.isFinite(Number(d.saved_at)) && Number(d.saved_at)>0;
    const localSave=(user,ids,values,contexto)=>{
        own(user);ids=refs(ids);
        if(!ids.length)throw new Error('La captura necesita una referencia exacta de llamada.');
        const snapshots=ids.map(id=>localRead(user,id)).filter(fresh).sort((a,b)=>b.saved_at-a.saved_at);
        const latest=snapshots[0],previous=Number(latest?.saved_at||0);
        const clean={...(latest?.valores||{}),...values};delete clean.csrf_token;
        const context=contexto?{adaptador:contexto.adaptador}:latest?.contexto;
        if(latest&&JSON.stringify(latest.valores)===JSON.stringify(clean)&&
            JSON.stringify(latest.ids)===JSON.stringify(ids)&&JSON.stringify(latest.contexto)===JSON.stringify(context))return latest;
        const d={saved_at:Math.max(Date.now(),previous+1),valores:clean,ids,pending_sync:true};
        if(context)d.contexto=context;
        try{for(const id of ids)localStorage.setItem(key(user,id),JSON.stringify({...d,call_id:id}));}
        catch(error){
            window.IMPE_TELEPHONY_NOTIFY?.('No se pudo conservar la captura en este navegador. Mantén abierto el formulario y reintenta guardar.',true);
            throw error;
        }
        return d;
    };
    const markSynced=(user,ids,revision)=>{
        for(const id of ids){const current=localRead(user,id);if(current?.saved_at===revision){
            try{localStorage.setItem(key(user,id),JSON.stringify({...current,pending_sync:false}));}catch(_){}
        }}
    };
    const formValues=form=>{
        const values=Object.fromEntries(new FormData(form));
        for(const input of form.querySelectorAll('input[type="checkbox"][name]')){
            // La intención capturada incluye desmarcar. El POST comercial sigue
            // usando FormData nativo y sus reglas de habilitación originales.
            values[input.name]=input.checked?String(input.value||'1'):'0';
        }
        return values;
    };
    const request=async body=>{
        const res=await window.IMPE_PHONE_HTTP.request(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
            headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(body)});
        const data=await res.json();
        if(!res.ok||!data.ok){const error=new Error(data.mensaje||'No se pudo guardar la captura.');error.status=res.status;throw error;}
        return data;
    };
    const contactosUnificados=new WeakMap();
    window.IMPE_CALL_DRAFTS={
        refs,localSave,localRead,formValues,
        unificarContacto(form) {
            const persona=form?.elements.namedItem('persona_atendio'),contacto=form?.elements.namedItem('nuevo_contacto_nombre');
            if(!persona||!contacto)return;
            let state=contactosUnificados.get(form);
            if(!state){
                state={referido:'',modo:false,espejo:''};contactosUnificados.set(form,state);
                if(!form.dataset.contactoUnificadoEventos){
                    form.dataset.contactoUnificadoEventos='1';
                    persona.addEventListener('input',()=>window.IMPE_CALL_DRAFTS.unificarContacto(form));
                    form.addEventListener('reset',()=>{contactosUnificados.delete(form);queueMicrotask(()=>window.IMPE_CALL_DRAFTS.unificarContacto(form));});
                }
            }
            const referido=form.elements.namedItem('resultado')?.value==='CONTACTO_REFERIDO';
            if(referido){
                if(!state.modo){
                    if(contacto.value && contacto.value!==state.espejo)state.referido=contacto.value;
                    contacto.value=state.referido;
                }
            }
            else {
                if(state.modo || (contacto.value && contacto.value!==state.espejo && contacto.value!==persona.value))state.referido=contacto.value;
                contacto.value=persona.value;state.espejo=persona.value;
            }
            state.modo=referido;
            const grupo=contacto.closest('.col-12, .col-md-6');if(grupo)grupo.hidden=!referido;
            const label=persona.id && form.querySelector('label[for="'+persona.id+'"]');
            if(label)label.textContent=referido?'Persona que atendió':'Persona de contacto';
            const labelReferido=contacto.id && form.querySelector('label[for="'+contacto.id+'"]');
            if(labelReferido)labelReferido.textContent='Otra persona de contacto';
        },
        actualizarFicha(datos) {
            const form=document.querySelector('[data-work-interaction-form]');
            if(!form || Number(form.elements.namedItem('seguimiento_id')?.value)!==Number(datos.get('seguimiento_id')))return;
            if(datos.get('actualizar_datos_llamada')!=='1' || !['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'].includes(datos.get('resultado')))return;
            const campos=[['nombre_entidad_actualizado','data-work-title',null],['nuevo_telefono_contacto','data-work-phone','telefono_verificado'],['nuevo_correo_contacto','data-work-email','correo_verificado'],['nuevo_contacto_nombre','data-work-contact-name','contacto_nombre'],['nuevo_contacto_cargo','data-work-contact-role','contacto_cargo']];
            for(const [nombre,selector,inputName] of campos) {
                const valor=String(datos.get(nombre)||'').trim();if(!valor)continue;
                const label=document.querySelector('['+selector+']');if(label)label.textContent=valor;
                const input=inputName && document.querySelector('[data-work-contact-form] [name="'+inputName+'"]');if(input)input.value=valor;
            }
            const correo=String(datos.get('nuevo_correo_contacto')||'').trim(),boton=document.querySelector('[data-work-email-button]');
            if(correo && boton){boton.disabled=false;boton.title='Enviar correo a '+correo;}
        },
        resultValues(values={}) {
            const out={...values};
            const names={nombre:'nombre_entidad',persona:'persona_atendio',telefono:'telefono_capturado',
                correo:'correo_capturado',notas:'observacion',contacto_nombre:'nuevo_contacto_nombre',contacto_cargo:'nuevo_contacto_cargo'};
            for(const [alias,name] of Object.entries(names)){
                if(!(name in out)&&alias in values)out[name]=values[alias];
                delete out[alias];
            }
            for(const [source,target] of [['telefono_capturado','nuevo_telefono_contacto'],['correo_capturado','nuevo_correo_contacto'],['nombre_entidad','nombre_entidad_actualizado']]) {
                if(!(target in out) && out[source])out[target]=out[source];
            }
            return out;
        },
        captureValues(values={}) {
            const out={...values};
            const names={nombre_entidad:'nombre',persona_atendio:'persona',telefono_capturado:'telefono',
                correo_capturado:'correo',observacion:'notas',nuevo_contacto_nombre:'contacto_nombre',nuevo_contacto_cargo:'contacto_cargo'};
            for(const [name,alias] of Object.entries(names))if(name in values)out[alias]=values[name];
            for(const [source,target] of [['nuevo_telefono_contacto','telefono'],['nuevo_correo_contacto','correo'],['nombre_entidad_actualizado','nombre']]) {
                if(source in values)out[target]=values[source];
            }
            return out;
        },
        async read(user,ids) {
            own(user);
            const found=refs(ids).map(id=>localRead(user,id)).filter(fresh);
            for(const id of refs(ids)) {
                try {
                    const url=new URL(endpoint);url.searchParams.set('id',id);
                    const res=await window.IMPE_PHONE_HTTP.request(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
                    const data=await res.json();
                    if(res.ok&&data.ok&&fresh(data.borrador)){
                        found.push(data.borrador);
                        if(Number(data.borrador.saved_at)>Number(localRead(user,id)?.saved_at||0))try{
                            localStorage.setItem(key(user,id),JSON.stringify({...data.borrador,ids:[id],call_id:id,pending_sync:false}));
                        }catch(_){}
                    }
                } catch (_) {}
            }
            found.push(...refs(ids).map(id=>localRead(user,id)).filter(fresh));
            found.sort((a,b)=>Number(b.saved_at)-Number(a.saved_at));
            const d=found[0];return d ? (d.valores||d) : null;
        },
        async save(user,ids,values,csrf,contexto) {
            const local=localSave(user,ids,values,contexto);
            if(!local.pending_sync)return {ok:true,borrador:local};
            const data=await request({csrf_token:csrf,ids:local.ids,valores:local.valores,capturado_at:local.saved_at,...(contexto?{contexto}:{})});
            own(user);markSynced(user,local.ids,local.saved_at);return data;
        },
        pending(user) {
            own(user);const found=new Map(),prefix=key(user,'');
            for(let i=0;i<localStorage.length;i++){
                const storageKey=localStorage.key(i);if(!storageKey?.startsWith(prefix))continue;
                const id=storageKey.slice(prefix.length),data=localRead(user,id);
                if(!fresh(data)||!data.pending_sync)continue;
                const ids=refs(data.ids||[id]),identity=[...ids].sort().join('|');
                if(!found.has(identity)||found.get(identity).saved_at<data.saved_at)found.set(identity,{...data,ids});
            }
            return [...found.values()];
        },
        async sync(user,csrf) {
            const result={saved:0,pending:0};
            for(const draft of this.pending(user).slice(0,8)){
                try{
                    await request({csrf_token:csrf,ids:draft.ids,valores:draft.valores,capturado_at:draft.saved_at,...(draft.contexto?{contexto:draft.contexto}:{})});
                    own(user);markSynced(user,draft.ids,draft.saved_at);result.saved++;
                }catch(error){result.pending++;if(error.status===401||error.status===403)break;}
            }
            return result;
        },
        beginSave(user,ids,form,contexto) {
            const values=Object.fromEntries(new FormData(form)),draft=localSave(user,ids,formValues(form),contexto);
            const controls=[...form.querySelectorAll('button,input,select,textarea')].map(el=>[el,el.disabled]);
            for(const [el]of controls)el.disabled=true;
            return {values,revision:draft.saved_at,ids:draft.ids,release(){for(const[el,disabled]of controls)el.disabled=disabled;}};
        },
        async remove(user,ids,csrf,revision) {
            own(user);ids=refs(ids);
            const previous=new Map(ids.map(id=>[id,localRead(user,id)?.saved_at]));
            const expected=revision??(Math.max(0,...[...previous.values()].map(value=>Number(value||0)))||null);
            await request({csrf_token:csrf,ids,accion:'eliminar',...(expected?{revision:expected}:{})});
            own(user);
            for(const id of ids){
                // Nunca borrar una edición nueva por el ACK de otra revisión.
                if(localRead(user,id)?.saved_at!==previous.get(id))continue;
                if(expected!==null && Number(localRead(user,id)?.saved_at||0)>expected)continue;
                for(const name of ['localStorage','sessionStorage'])try{globalThis[name].removeItem(key(user,id));}catch(_){}
            }
        }

    };
    // El formulario institucional se abre después de recibir la metadata exacta.
    // Recuperar sólo campos vacíos; una captura ya iniciada tiene prioridad.
    document.addEventListener('impe:call-result-form-opened',async event=>{
        const form=event.detail?.form,ids=refs(event.detail?.ids||[]);
        if(!(form instanceof HTMLFormElement)||!ids.length)return;
        contactosUnificados.delete(form);
        const cycle=(Number(form.dataset.draftCycle)||0)+1;form.dataset.draftCycle=String(cycle);
        let changed=false;const dirty=()=>{changed=true;};form.addEventListener('input',dirty,{once:true});
        const values=await window.IMPE_CALL_DRAFTS.read(Number(window.IMPE_CURRENT_USER_ID||0),ids);
        form.removeEventListener('input',dirty);
        if(!values||changed||form.dataset.draftCycle!==String(cycle))return;
        const institutional=!event.detail?.adaptador||['ANALISTA','SEGUIMIENTO'].includes(event.detail.adaptador);
        const normalized=institutional?window.IMPE_CALL_DRAFTS.resultValues(values):values;
          // El formulario original de salientes sólo dispone del contacto referido.
          // Conserva su captura de teléfono/correo sin mezclar los nombres de personas.
          for(const [source,target] of [['telefono_capturado','nuevo_telefono_contacto'],['correo_capturado','nuevo_correo_contacto']]){
              if(!form.elements.namedItem(source)&&!normalized[target]&&normalized[source])normalized[target]=normalized[source];
          }
        const camposCapturados=new Set(['persona_atendio','observacion','telefono_capturado','correo_capturado',
            'nombre_entidad_actualizado','nuevo_telefono_contacto','nuevo_correo_contacto','nuevo_contacto_nombre','nuevo_contacto_cargo']);
        for(const [name,value] of Object.entries(normalized)) {
            if(['csrf_token','pbx_call_id','sdk_session_id','sdk_reference','llamada_pbx_id','extension','catalogo_version'].includes(name))continue;
            const input=form.elements.namedItem(name);
            if(input && value && (!input.value || camposCapturados.has(name)))input.value=String(value);
        }
        form.querySelector('[name="resultado"]')?.dispatchEvent(new Event('change',{bubbles:true}));
        window.IMPE_TELEPHONY_NOTIFY?.(institutional?'Borrador recuperado. Revisa los datos de contacto: se guardarán en la ficha al registrar el resultado.':'Captura recuperada. Revisa los datos antes de registrar el resultado.');
    });
    window.IMPE_TELEPHONY_NOTIFY=(message,error=false)=>{
        if(!message||!window.bootstrap?.Toast)return;
        const host=Array.from(document.querySelectorAll('.modal.show')).pop()||document.body;
        let container=Array.from(host.children).find(el=>el.matches?.('[data-telephony-toasts]'));
        if(!container){container=document.createElement('div');container.dataset.telephonyToasts='';container.className='toast-container position-fixed top-0 end-0 p-3';host.append(container);}
        container.style.zIndex='1095';
        const el=document.createElement('div');el.className='toast system-toast'+(error?' system-toast-error':'');
        el.setAttribute('role',error?'alert':'status');el.setAttribute('aria-live',error?'assertive':'polite');
        el.innerHTML='<div class="toast-body"><i class="bi '+(error?'bi-exclamation-circle':'bi-check2-circle')+'" aria-hidden="true"></i><span></span></div>';
        el.querySelector('span').textContent=message;container.append(el);
        const instance=bootstrap.Toast.getOrCreateInstance(el,{delay:error?4500:4000,autohide:true});
        el.addEventListener('hidden.bs.toast',()=>{instance.dispose();el.remove();});instance.show();
    };
    const flash=()=>{try {const msg=sessionStorage.getItem('impe:call-result-flash');if(msg){sessionStorage.removeItem('impe:call-result-flash');window.IMPE_TELEPHONY_NOTIFY(msg);}}catch (_) {}};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',flash,{once:true});else flash();
})();
