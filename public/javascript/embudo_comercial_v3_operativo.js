/* IMPE · Embudo comercial V3 operativo del asesor.
 * Reutiliza el teléfono persistente y el mismo contexto del bridge V4.
 * Solo envía cambios manuales seguros al endpoint V3 con CSRF de sesión.
 * No modifica registros PBX, grabaciones, llamadas ni decisiones automáticas.
 */
(() => {
  'use strict';
  const root=document.querySelector('.impe-embudo--v3-operativo');
  if (!root) return;
  const usuarioId=Number(window.IMPE_CURRENT_USER_ID||0);
  const cambioKey='impe:embudo:resultado-guardado:'+usuarioId;
  const vistaCargada=Date.now();
  let refresh=false;

  const telefonoActivo=()=>{
    try{
      if(window.IMPE_TELEPHONY_PERSISTENT?.getState?.()?.active) return true;
      if(window.parent!==window &&
          window.parent.IMPE_TELEPHONY_SHELL?.getPhoneStatus?.()?.active) return true;
      return false;
    }catch(_){return true;}
  };
  const refrescarTrasLlamada=()=>{
    if(refresh || usuarioId<1 || document.hidden || telefonoActivo())return;
    let info=null;
    try{info=JSON.parse(localStorage.getItem(cambioKey)||'null');}
    catch(_){return;}
    if(!info || Number(info.usuarioId)!==usuarioId ||
       !/^[1-9][0-9]*$/.test(String(info.asignacionId||'')) ||
       !Number.isFinite(Number(info.creada)) ||
       Number(info.creada)<=vistaCargada ||
       Date.now()-Number(info.creada)>60000) return;
    refresh=true;
    window.location.reload();
  };
  window.addEventListener('storage',event=>{
    if(event.key===cambioKey)refrescarTrasLlamada();
  });
  window.addEventListener('focus',refrescarTrasLlamada);
  document.addEventListener('visibilitychange',refrescarTrasLlamada);
  window.addEventListener('pageshow',event=>{
    if(event.persisted)refrescarTrasLlamada();
  });

  // Dialer usado también por Gestión comercial V1. No iniciar una llamada
  // directamente ni falsificar un resultado: lo valida y guarda Telefonía.
  root.querySelectorAll('[data-embudo-marcar]').forEach(b=>{
    b.addEventListener('click',()=>{
      const numero=String(b.dataset.embudoMarcar||'').trim();
      if(!numero)return;
      const dial=window.IMPE_TELEPHONY_PERSISTENT;
      if(!dial || typeof dial.openDialer!=='function'){
        window.alert('El teléfono persistente no está disponible en esta sesión.');
        return;
      }
      const card=b.closest('.impe-v3o-card');
      const asignacionId=String(card?.dataset.asignacionId||'');
      const digitos=numero.replace(/\D/g,'');
      if(/^[1-9][0-9]*$/.test(asignacionId) &&
         digitos.length>=10 && digitos.length<=15 && usuarioId>0){
        try{
          sessionStorage.setItem('impe:embudo:llamada-pendiente',
            JSON.stringify({
              asignacionId,numero,usuarioId,creada:Date.now()
            })
          );
        }catch(_){}
      }
      dial.openDialer({number:numero});
    });
  });

  const dialog=root.querySelector('#impe-v3o-dialog');
  const form=root.querySelector('#impe-v3o-mover-form');
  if(!dialog || !form)return;
  const destino=form.querySelector('#impe-v3o-destino');
  const motivo=form.querySelector('#impe-v3o-motivo');
  const fecha=form.querySelector('#impe-v3o-fecha');
  const fechaLabel=form.querySelector('#impe-v3o-fecha-label');
  const error=form.querySelector('#impe-v3o-dialog-error');
  const enviar=form.querySelector('#impe-v3o-enviar');
  const titulo=form.querySelector('#impe-v3o-dialog-datos');
  let tarjeta=null;
  let arrastre=null;
  const destinosDe=card=>String(card?.dataset.v3Destinos||'')
    .split(',').filter(Boolean);
  const destinoValido=(card,etapa)=>destinosDe(card).includes(etapa);

  const actualizar=()=>{
    const requiere=destino.value==='SEGUIMIENTO';
    fechaLabel.hidden=!requiere;
    fecha.required=requiere;
    if(!requiere)fecha.value='';
    error.hidden=true;
  };
  const abrir=(card,destinoSolicitado='')=>{
    if(!(card instanceof HTMLElement) || telefonoActivo() || dialog.open)return;
    const actual=String(card.dataset.etapa||'');
    const autorizados=destinosDe(card);
    if(destinoSolicitado && !autorizados.includes(destinoSolicitado))return;
    // El navegador oculta lo innecesario pero el servidor valida de nuevo
    // cada par origen/destino, propietario y evidencia PBX real.
    let primero='';
    Array.from(destino.options).forEach(o=>{
      const permitido=autorizados.includes(o.value);
      o.disabled=!permitido;
      o.hidden=!permitido;
      if(permitido && !primero)primero=o.value;
    });
    if(!primero)return;
    if(destinoSolicitado){
      const sugerida=Array.from(destino.options).find(o=>
        o.value===destinoSolicitado && !o.disabled);
      if(!sugerida)return;
      primero=destinoSolicitado;
    }
    tarjeta=card;
    destino.value=primero;
    titulo.textContent=String(card.dataset.nombre||'Prospecto')+
      ' · '+actual.replace(/_/g,' ').toLowerCase();
    motivo.value='';fecha.value='';error.hidden=true;actualizar();
    if(typeof dialog.showModal==='function')dialog.showModal();
  };
  root.querySelectorAll('[data-v3-mover]').forEach(b=>{
    b.addEventListener('click',()=>abrir(b.closest('.impe-v3o-card')));
  });
  form.querySelectorAll('[data-v3-cerrar]').forEach(b=>{
    b.addEventListener('click',()=>dialog.close());
  });
  destino.addEventListener('change',actualizar);
  dialog.addEventListener('close',()=>{tarjeta=null;});

  // Soltar NO escribe nada: reutiliza el formulario y la autorización
  // del endpoint moverEtapaV3. Sólo se destacan destinos permitidos.
  const limpiarArrastre=()=>{
    if(arrastre)arrastre.classList.remove('is-dragging');
    root.querySelectorAll('.impe-v3o-columna').forEach(lane=>
      lane.classList.remove('is-drop-target','is-drop-allowed'));
    arrastre=null;
  };
  root.querySelectorAll('.impe-v3o-card[draggable="true"]').forEach(card=>{
    card.addEventListener('dragstart',event=>{
      const origen=event.target;
      if(telefonoActivo() || dialog.open || !card.dataset.asignacionId ||
         !destinosDe(card).length ||
         (origen instanceof Element &&
          origen.closest('a,button,input,select,textarea,[contenteditable="true"]')) ||
         !event.dataTransfer){
        event.preventDefault();
        return;
      }
      arrastre=card;
      event.dataTransfer.effectAllowed='move';
      event.dataTransfer.setData('text/plain',card.dataset.asignacionId);
      card.classList.add('is-dragging');
      root.querySelectorAll('.impe-v3o-columna[data-etapa]').forEach(lane=>{
        if(destinoValido(card,String(lane.dataset.etapa||'')))
          lane.classList.add('is-drop-allowed');
      });
    });
    card.addEventListener('dragend',limpiarArrastre);
  });
  root.querySelectorAll('.impe-v3o-columna[data-etapa]').forEach(lane=>{
    const etapa=String(lane.dataset.etapa||'');
    lane.addEventListener('dragover',event=>{
      if(!arrastre || telefonoActivo() || !destinoValido(arrastre,etapa))return;
      event.preventDefault();
      if(event.dataTransfer)event.dataTransfer.dropEffect='move';
      lane.classList.add('is-drop-target');
    });
    lane.addEventListener('dragleave',event=>{
      if(!lane.contains(event.relatedTarget))
        lane.classList.remove('is-drop-target');
    });
    lane.addEventListener('drop',event=>{
      if(!arrastre || telefonoActivo() || !destinoValido(arrastre,etapa))return;
      event.preventDefault();
      const seleccionada=arrastre;
      limpiarArrastre();
      abrir(seleccionada,etapa);
    });
  });

  form.addEventListener('submit',async(event)=>{
    event.preventDefault();
    if(!tarjeta || telefonoActivo()) {
      error.textContent='Termina la llamada antes de cambiar etapas.';
      error.hidden=false;
      return;
    }
    const token=String(window.IMPE_CSRF_TOKEN||'');
    if(!token){
      error.textContent='La sesión no tiene token de seguridad. Recarga la página.';
      error.hidden=false;
      return;
    }
    const actual=String(tarjeta.dataset.etapa||'');
    const next=destino.value;
    if(!String(tarjeta.dataset.v3Destinos||'').split(',').includes(next)){
      error.textContent='Esa transición no está disponible.';
      error.hidden=false;return;
    }
    const reason=motivo.value.trim();
    if(reason.length<8 || reason.length>190 || (next==='SEGUIMIENTO'&&!fecha.value)){
      error.textContent='Indica un motivo válido y la próxima fecha cuando corresponda.';
      error.hidden=false;return;
    }
    const body=new URLSearchParams({
      asignacion_id:String(tarjeta.dataset.asignacionId||''),
      etapa_anterior:actual,
      etapa_nueva:next,
      motivo:reason,
      proxima_accion:next==='SEGUIMIENTO'?fecha.value:''
    });
    enviar.disabled=true;error.hidden=true;
    try{
      const response=await fetch(
        'index.php?controller=embudoComercial&action=moverEtapaV3',{
          method:'POST',credentials:'same-origin',
          headers:{
            'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
            'Accept':'application/json','X-CSRF-Token':token
          },body:body.toString()
        });
      const result=await response.json();
      if(!response.ok || !result.ok){
        throw new Error(result.mensaje||'No se pudo registrar el movimiento.');
      }
      dialog.close();
      if(!telefonoActivo()){
        window.location.reload();
      }else{
        window.alert('Movimiento guardado. Actualiza el tablero cuando termine la llamada.');
      }
    }catch(err){
      error.textContent=err?.message||'No se pudo completar el movimiento.';
      error.hidden=false;
    }finally{enviar.disabled=false;}
  });
})();
