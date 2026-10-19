/* Utilidades compartidas de atención; no ejecutan acciones sobre la llamada. */
(() => {
    'use strict';
    const endpoint=new URL('prueba_telefonia/api/borrador_llamada.php',document.baseURI);
    const valid=id=>/^[a-zA-Z0-9_-]{6,100}$/.test(String(id||''));
    const refs=ids=>[...new Set(ids.filter(valid))].slice(0,3);
    const key=(user,id)=>'impe:crm:call-draft:'+Number(user)+':'+id;
    const localRead=(user,id)=>{try {return JSON.parse(sessionStorage.getItem(key(user,id))||'null');} catch (_) {return null;}};
    const fresh=d=>d && Date.now()-Number(d.saved_at||0)<86400000;
    const localSave=(user,ids,values)=>{
        const previous=Math.max(0,...refs(ids).map(id=>Number(localRead(user,id)?.saved_at||0)));
        const d={saved_at:Math.max(Date.now(),previous+1),valores:values};
        for(const id of refs(ids))try {sessionStorage.setItem(key(user,id),JSON.stringify({...d,call_id:id}));} catch (_) {}
        return d;
    };
    const request=async body=>{
        const res=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
            keepalive:true,headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(body)});
        const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.mensaje||'No se pudo guardar el borrador.');return data;
    };
    window.IMPE_CALL_DRAFTS={
        refs,localSave,
        resultValues(values={}) {
            const out={...values};
            const names={nombre:'nombre_entidad',persona:'persona_atendio',telefono:'telefono_capturado',
                correo:'correo_capturado',notas:'observacion',contacto_nombre:'nuevo_contacto_nombre',contacto_cargo:'nuevo_contacto_cargo'};
            for(const [alias,name] of Object.entries(names)){
                if(!(name in out)&&alias in values)out[name]=values[alias];
                delete out[alias];
            }
            return out;
        },
        captureValues(values={}) {
            const out={...values};
            const names={nombre_entidad:'nombre',persona_atendio:'persona',telefono_capturado:'telefono',
                correo_capturado:'correo',observacion:'notas',nuevo_contacto_nombre:'contacto_nombre',nuevo_contacto_cargo:'contacto_cargo'};
            for(const [name,alias] of Object.entries(names))if(name in values)out[alias]=values[name];
            return out;
        },
        async read(user,ids) {
            const found=refs(ids).map(id=>localRead(user,id)).filter(fresh);
            for(const id of refs(ids)) {
                try {
                    const url=new URL(endpoint);url.searchParams.set('id',id);
                    const res=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
                    const data=await res.json();if(res.ok&&data.ok&&fresh(data.borrador))found.push(data.borrador);
                } catch (_) {}
            }
            found.sort((a,b)=>Number(b.saved_at)-Number(a.saved_at));
            const d=found[0];return d ? (d.valores||d) : null;
        },
        async save(user,ids,values,csrf) {
            const local=localSave(user,ids,values);
            return request({csrf_token:csrf,ids:refs(ids),valores:values,capturado_at:local.saved_at});
        },
        async remove(user,ids,csrf) {
            for(const id of refs(ids))try {sessionStorage.removeItem(key(user,id));}catch (_) {}
            await request({csrf_token:csrf,ids:refs(ids),accion:'eliminar'});
        }
    };
    // El formulario institucional se abre después de recibir la metadata exacta.
    // Recuperar sólo campos vacíos; una captura ya iniciada tiene prioridad.
    document.addEventListener('impe:call-result-form-opened',async event=>{
        const form=event.detail?.form,ids=refs(event.detail?.ids||[]);
        if(!(form instanceof HTMLFormElement)||!ids.length)return;
        const cycle=(Number(form.dataset.draftCycle)||0)+1;form.dataset.draftCycle=String(cycle);
        let changed=false;const dirty=()=>{changed=true;};form.addEventListener('input',dirty,{once:true});
        const values=await window.IMPE_CALL_DRAFTS.read(Number(window.IMPE_CURRENT_USER_ID||0),ids);
        form.removeEventListener('input',dirty);
        if(!values||changed||form.dataset.draftCycle!==String(cycle))return;
        const normalized=window.IMPE_CALL_DRAFTS.resultValues(values);
          // El formulario original de salientes sólo dispone del contacto referido.
          // Conserva su captura de teléfono/correo sin mezclar los nombres de personas.
          for(const [source,target] of [['telefono_capturado','nuevo_telefono_contacto'],['correo_capturado','nuevo_correo_contacto']]){
              if(!form.elements.namedItem(source)&&!normalized[target]&&normalized[source])normalized[target]=normalized[source];
          }
        for(const [name,value] of Object.entries(normalized)) {
            const input=form.elements.namedItem(name);if(input&&!input.value&&value)input.value=String(value);
        }
        window.IMPE_TELEPHONY_NOTIFY?.('Datos de la llamada recuperados. Revisa el resultado y confirma los datos verificados.');
    });
    window.IMPE_TELEPHONY_NOTIFY=(message,error=false)=>{
        if(!message||!window.bootstrap?.Toast)return;
        let container=document.querySelector('.toast-container');
        if(!container){container=document.createElement('div');container.className='toast-container position-fixed top-0 end-0 p-3';document.body.append(container);}
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
