/* IMPE · Decisiones excepcionales de Recuperación/Descartados V3.
 * No realiza llamadas, no modifica resultados PBX ni calcula intentos en cliente.
 */
(() => {
  'use strict';
  const root=document.querySelector('.impe-embudo--v3-operativo, .impe-embudo--v3-bandejas');
  if(!root)return;
  // Recuperación usa exactamente el mismo teléfono persistente y contexto
  // de asignación del embudo principal. No realiza marcación directa.
  // Los descartados, los legados y los registros NO_CONTACTAR no ofrecen este control.
  root.querySelectorAll('[data-v3b-llamar]').forEach(b=>{
    b.addEventListener('click',()=>{
      const tarjeta=b.closest('[data-asignacion-id][data-etapa]');
      if(!tarjeta || tarjeta.dataset.etapa!=='RECUPERACION' ||
         tarjeta.dataset.noContactar==='1')return;
      const numero=String(b.dataset.v3bLlamar||'').trim();
      const digits=numero.replace(/\\D/g,'');
      const asignacionId=String(tarjeta.dataset.asignacionId||'');
      const usuarioId=Number(window.IMPE_CURRENT_USER_ID||0);
      if(!/^[1-9][0-9]*$/.test(asignacionId) ||
         digits.length<10 || digits.length>15 || usuarioId<1)return;
      const dial=window.IMPE_TELEPHONY_PERSISTENT;
      if(!dial || typeof dial.openDialer!=='function'){
        window.alert('El teléfono persistente no está disponible en esta sesión.');
        return;
      }
      try{
        sessionStorage.setItem('impe:embudo:llamada-pendiente',
          JSON.stringify({asignacionId,numero,usuarioId,creada:Date.now()}));
      }catch(_){}
      dial.openDialer({number:numero});
    });
  });
  const dialog=root.querySelector('#impe-v3b-dialog');
  const form=root.querySelector('#impe-v3b-form');
  if(!dialog || !form)return;
  const csrf=String(window.IMPE_CSRF_TOKEN||'');
  const close=()=>{if(dialog.open)dialog.close();};
  const fecha=form.querySelector('[name=dia]');
  const motivo=form.querySelector('[name=motivo]');
  const subetapa=form.querySelector('[name=subetapa]');
  const confirmar=form.querySelector('[name=confirmacion]');
  const subContainer=form.querySelector('[data-v3b-subetapa]');
  const noContactar=form.querySelector('[data-v3b-no-contactar]');
  const noContactarCheck=form.querySelector('[name=confirmacion_no_contactar]');
  const actualizarNoContactar=()=>{
    const activar=subetapa.value==='NO_CONTACTAR' && !subContainer.hidden;
    if(noContactar)noContactar.hidden=!activar;
    if(noContactarCheck){
      noContactarCheck.required=activar;
      if(!activar)noContactarCheck.checked=false;
    }
  };
  subetapa.addEventListener('change',actualizarNoContactar);
  const diaContainer=form.querySelector('[data-v3b-dia]');
  const info=form.querySelector('#impe-v3b-ayuda');
  const data=form.querySelector('#impe-v3b-datos');
  const error=form.querySelector('[data-v3b-error]');
  const titulo=form.querySelector('#impe-v3b-titulo');
  const guardar=form.querySelector('#impe-v3b-confirmar');
  let seleccionado=null;

  const textos={
    RECUPERACION:{
      titulo:'Revisar ingreso a Recuperación',
      ayuda:'El servidor comprobará tres llamadas salientes PBX distintas, sin contacto, durante la jornada seleccionada. Es una decisión manual; no genera mensajes.',
      boton:'Confirmar revisión'
    },
    DESCARTAR:{
      titulo:'Registrar descarte acreditado',
      ayuda:'Solo se admiten decisiones con evidencia PBX. «No contactar» exige conversación humana acreditada y confirmación expresa; no equivale a «No interesado».',
      boton:'Confirmar descarte'
    },
    REACTIVAR:{
      titulo:'Revisar reactivación',
      ayuda:'Solo puede pasar de Recuperación a Contactados si existe conversación PBX posterior a su ingreso. No permite reactivar Descartados.',
      boton:'Confirmar reactivación'
    }
  };
  const abrir=boton=>{
    const tarjeta=boton.closest('[data-asignacion-id][data-etapa]');
    if(!tarjeta)return;
    const accion=boton.dataset.v3bAccion;
    const cfg=textos[accion];
    if(!cfg)return;
    const id=String(tarjeta.dataset.asignacionId||'');
    if(!/^[1-9][0-9]*$/.test(id))return;
    if((accion==='RECUPERACION' && tarjeta.dataset.etapa!=='NUEVO') ||
       (accion==='REACTIVAR' && tarjeta.dataset.etapa!=='RECUPERACION') ||
       (accion==='DESCARTAR' && !['NUEVO','CONTACTADO','SEGUIMIENTO',
         'POTENCIAL','RECUPERACION'].includes(tarjeta.dataset.etapa)))return;
    form.reset();
    error.hidden=true;
    error.textContent='';
    seleccionado={accion,id,etapa:String(tarjeta.dataset.etapa)};
    titulo.textContent=cfg.titulo;
    info.textContent=cfg.ayuda;
    guardar.textContent=cfg.boton;
    data.textContent=String(tarjeta.dataset.nombre||'Prospecto seleccionado');
    diaContainer.hidden=accion!=='RECUPERACION';
    subContainer.hidden=accion!=='DESCARTAR';
    fecha.required=accion==='RECUPERACION';
    subetapa.required=accion==='DESCARTAR';
    actualizarNoContactar();
    const dia=String(root.dataset.dia||fecha.max||'');
    if(accion==='RECUPERACION')fecha.value=dia;
    dialog.showModal();
    motivo.focus();
  };
  root.addEventListener('click',e=>{
    const btn=e.target.closest('[data-v3b-accion]');
    if(btn && root.contains(btn))abrir(btn);
  });
  form.querySelectorAll('[data-v3b-cerrar]').forEach(btn=>btn.addEventListener('click',close));
  dialog.addEventListener('close',()=>{seleccionado=null;error.hidden=true;});
  const mostrarAviso=(mensaje)=>{
    const wrapper=document.createElement('div');
    wrapper.className='toast-container position-fixed top-0 end-0 p-3';
    wrapper.style.zIndex='2000';
    const toast=document.createElement('div');
    toast.className='toast system-toast border-0 show';
    toast.setAttribute('role','status');
    toast.setAttribute('aria-live','polite');
    const body=document.createElement('div');body.className='toast-body';
    const icon=document.createElement('i');icon.className='bi bi-check-circle-fill';
    icon.setAttribute('aria-hidden','true');
    const label=document.createElement('span');label.textContent=mensaje;
    const quit=document.createElement('button');
    quit.type='button';quit.className='btn-close ms-auto';quit.setAttribute('aria-label','Cerrar aviso');
    quit.addEventListener('click',()=>wrapper.remove());
    body.append(icon,label,quit);toast.appendChild(body);wrapper.appendChild(toast);
    document.body.appendChild(wrapper);
  };
  const telefonoActivo=()=>{
    try {
      if(window.IMPE_TELEPHONY_PERSISTENT?.getState?.()?.active)return true;
      if(window.parent!==window && window.parent.IMPE_TELEPHONY_SHELL?.getPhoneStatus?.()?.active)return true;
      return false;
    } catch(_){return true;}
  };
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!seleccionado || guardar.disabled || !confirmar.checked ||
       (seleccionado.accion==='DESCARTAR' &&
        subetapa.value==='NO_CONTACTAR' && !noContactarCheck?.checked))return;
    error.hidden=true;
    guardar.disabled=true;
    const {accion,id,etapa}=seleccionado;
    const payload=new URLSearchParams({
      asignacion_id:id,etapa_actual:etapa,operacion:accion,
      subetapa:accion==='DESCARTAR'?subetapa.value:'',
      dia:accion==='RECUPERACION'?fecha.value:'',
      motivo:motivo.value.trim(),confirmacion:'1',
      confirmacion_no_contactar:subetapa.value==='NO_CONTACTAR' &&
        noContactarCheck?.checked?'1':'0'
    });
    try{
      const url='index.php?controller=embudoComercial&action=bandejasOperativasV3';
      const res=await fetch(url,{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/x-www-form-urlencoded',
          'X-CSRF-Token':csrf,'X-Requested-With':'fetch'},
        body:payload
      });
      const body=await res.json().catch(()=>null);
      if(!res.ok || body?.ok!==true){
        throw new Error(typeof body?.mensaje==='string'&&body.mensaje.trim()
          ?body.mensaje:'No pudo confirmarse la decisión.');
      }
      close();
      if(telefonoActivo()){
        mostrarAviso('Decisión registrada. Actualiza el tablero al finalizar la llamada.');
      }else {
        const key='impe:v3b:confirmacion:'+String(window.IMPE_CURRENT_USER_ID||0);
        try{sessionStorage.setItem(key,JSON.stringify({creado:Date.now()}));}catch(_){}
        window.location.reload();
      }
    } catch(err){
      error.textContent=err instanceof Error?err.message:'No pudo confirmarse la decisión.';
      error.hidden=false;
    } finally{guardar.disabled=false;}
  });
  try{
    const key='impe:v3b:confirmacion:'+String(window.IMPE_CURRENT_USER_ID||0);
    const saved=JSON.parse(sessionStorage.getItem(key)||'null');
    sessionStorage.removeItem(key);
    if(saved && Number.isFinite(saved.creado) && Date.now()-saved.creado<60000){
      mostrarAviso('Decisión comercial registrada y auditada correctamente.');
    }
  }catch(_){}
})();
