/* IMPE · Embudo comercial V3 operativo del asesor.
 * Reutiliza el teléfono persistente y el mismo contexto del bridge V4.
 * Solo envía cambios manuales seguros al endpoint V3 con CSRF de sesión.
 * No modifica registros PBX, grabaciones, llamadas ni decisiones automáticas.
 */
(() => {
  'use strict';
  const root=document.querySelector('.impe-embudo--v3-operativo');
  if (!root) return;
  root.querySelectorAll('.impe-v3o-gestion-boton').forEach(boton=>{
    const menu=document.getElementById(boton.getAttribute('popovertarget'));
    if(!menu)return;
    menu.addEventListener('toggle',evento=>{
      if(evento.newState!=='open'){
        delete menu.dataset.posicionado;
        return;
      }
      const ancla=boton.getBoundingClientRect();
      const ancho=menu.offsetWidth;
      const alto=menu.offsetHeight;
      const izquierda=Math.max(8,Math.min(ancla.right-ancho,window.innerWidth-ancho-8));
      const abajo=ancla.bottom+6;
      const arriba=ancla.top-alto-6;
      menu.style.left=izquierda+'px';
      menu.style.top=Math.max(8,abajo+alto<=window.innerHeight-8
        ?abajo:Math.min(arriba,window.innerHeight-alto-8))+'px';
      menu.dataset.posicionado='1';
    });
    menu.addEventListener('click',evento=>{
      if(evento.target.closest('button,a') && menu.matches(':popover-open'))menu.hidePopover();
    });
    window.addEventListener('resize',()=>{
      if(menu.matches(':popover-open'))menu.hidePopover();
    });
  });
  const usuarioId=Number(window.IMPE_CURRENT_USER_ID||0);
  const cambioKey='impe:embudo:resultado-guardado:'+usuarioId;
  const avisoKey='impe:embudo:v3:aviso:'+usuarioId;
  const mensajes=new Map([
    ['gestion','Gestión comercial registrada correctamente.'],
    ['movimiento','Movimiento registrado correctamente.'],
    ['actividad','Actividad comercial registrada correctamente.']
  ]);
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
  const mostrarAviso=(mensaje,alCerrar=()=>{})=>{
    let contenedor=document.getElementById('impe-v3o-avisos');
    if(!contenedor){
      contenedor=document.createElement('div');
      contenedor.id='impe-v3o-avisos';
      contenedor.className='toast-container position-fixed top-0 end-0 p-3';
      contenedor.style.zIndex='2000';
      document.body.appendChild(contenedor);
    }
    const toast=document.createElement('div');
    toast.className='toast system-toast border-0';
    toast.setAttribute('role','status');
    toast.setAttribute('aria-live','polite');
    toast.setAttribute('aria-atomic','true');
    const cuerpo=document.createElement('div');
    cuerpo.className='toast-body';
    const icono=document.createElement('i');
    icono.className='bi bi-check-circle-fill';
    icono.setAttribute('aria-hidden','true');
    const texto=document.createElement('span');
    texto.textContent=mensaje;
    const cerrar=document.createElement('button');
    cerrar.type='button';
    cerrar.className='btn-close ms-auto';
    cerrar.setAttribute('aria-label','Cerrar aviso');
    cuerpo.append(icono,texto,cerrar);
    toast.appendChild(cuerpo);
    contenedor.appendChild(toast);
    const finalizar=()=>{
      toast.remove();
      alCerrar();
    };
    const BootstrapToast=window.bootstrap?.Toast;
    if(BootstrapToast){
      const instancia=BootstrapToast.getOrCreateInstance(toast,{delay:4600});
      toast.addEventListener('hidden.bs.toast',()=>{
        instancia.dispose();
        finalizar();
      },{once:true});
      cerrar.addEventListener('click',()=>instancia.hide());
      instancia.show();
    }else{
      toast.classList.add('show');
      cerrar.addEventListener('click',finalizar,{once:true});
    }
  };
  try{
    const guardado=sessionStorage.getItem(avisoKey);
    if(guardado!==null){
      sessionStorage.removeItem(avisoKey);
      const aviso=JSON.parse(guardado);
      const edad=Date.now()-aviso.creado;
      if(mensajes.has(aviso.tipo) && edad>=0 && edad<=60000){
        mostrarAviso(mensajes.get(aviso.tipo));
      }
    }
  }catch(_){}
  const confirmarGuardado=tipo=>{
    const mensaje=mensajes.get(tipo);
    if(telefonoActivo()){
      mostrarAviso(mensaje+' Actualiza el tablero cuando termine la llamada.');
      return;
    }
    try{
      sessionStorage.setItem(avisoKey,JSON.stringify({tipo,creado:Date.now()}));
    }catch(_){
      mostrarAviso(mensaje,()=>{
        if(!telefonoActivo())window.location.reload();
      });
      return;
    }
    window.location.reload();
  };
  const comprobarRespuesta=async(response,mensaje)=>{
    const result=await response.json().catch(()=>null);
    if(!response.ok || result?.ok!==true){
      throw new Error(typeof result?.mensaje==='string' && result.mensaje.trim()
        ?result.mensaje:mensaje);
    }
    return result;
  };
  let reintentoRefresco=0;
  const refrescarTrasLlamada=()=>{
    if(refresh || usuarioId<1 || document.hidden)return;
    let info=null;
    try{info=JSON.parse(localStorage.getItem(cambioKey)||'null');}
    catch(_){return;}
    if(!info || Number(info.usuarioId)!==usuarioId ||
       !/^[1-9][0-9]*$/.test(String(info.asignacionId||'')) ||
       !Number.isFinite(Number(info.creada)) ||
       Number(info.creada)<=vistaCargada ||
       Date.now()-Number(info.creada)>60000) return;
    if(telefonoActivo() || root.querySelector('dialog[open], :popover-open') ||
       document.querySelector('#modalResultadoVenta.show')){
      if(!reintentoRefresco) reintentoRefresco=window.setTimeout(()=>{
        reintentoRefresco=0;
        refrescarTrasLlamada();
      },750);
      return;
    }
    refresh=true;
    window.location.reload();
  };
  window.addEventListener('storage',event=>{
    if(event.key===cambioKey)refrescarTrasLlamada();
  });
  window.addEventListener('message',event=>{
    if(event.origin===location.origin && event.source===window.parent &&
       event.data?.type==='IMPE_PHONE_CALL_CHANGED')refrescarTrasLlamada();
  });
  document.getElementById('modalResultadoVenta')
    ?.addEventListener('hidden.bs.modal',refrescarTrasLlamada);
  // El evento storage no se emite en la ventana que escribe el resultado.
  // Este respaldo sólo lee el marcador local; no consulta el servidor.
  window.setInterval(refrescarTrasLlamada,1000);
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
              asignacionId,numero,usuarioId,creada:Date.now(),
              origen:'embudo-v3',nombre:card.dataset.nombre||'',correo:card.dataset.correo||''
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
  const calificacion=form.querySelector('#impe-v3o-calificacion');
  const calificacionLabel=form.querySelector('#impe-v3o-calificacion-label');
  const error=form.querySelector('#impe-v3o-dialog-error');
  const enviar=form.querySelector('#impe-v3o-enviar');
  const titulo=form.querySelector('#impe-v3o-dialog-datos');
  let tarjeta=null;
  let arrastre=null;
  const destinosDe=card=>String(card?.dataset.v3Destinos||'')
    .split(',').filter(Boolean);
  const destinoValido=(card,etapa)=>destinosDe(card).includes(etapa);

  const actualizar=()=>{
    const requiere=destino.value==='SEGUIMIENTO' && tarjeta?.dataset.v3ActividadPendiente!=='1';
    fechaLabel.hidden=!requiere;
    fecha.required=requiere;
    if(!requiere)fecha.value='';
    if(calificacion && calificacionLabel){
      calificacionLabel.hidden=destino.value!=='POTENCIAL';
      calificacion.required=!calificacionLabel.hidden;
      calificacion.checked=false;
    }
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
          origen.closest('a,button,summary,input,select,textarea,[contenteditable="true"]')) ||
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
    if(enviar.disabled)return;
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
    if(reason.length<8 || reason.length>190 || (fecha.required&&!fecha.value)){
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
    if(next==='POTENCIAL' && calificacion?.checked)body.set('calificacion_confirmada','1');
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
      await comprobarRespuesta(response,'No se pudo registrar el movimiento.');
      dialog.close();
      confirmarGuardado('movimiento');
    }catch(err){
      error.textContent=err?.message||'No se pudo completar el movimiento.';
      error.hidden=false;
    }finally{enviar.disabled=false;}
  });

  const actividadDialog=root.querySelector('#impe-v3o-actividad-dialog');
  if(actividadDialog){
    const actividadForm=actividadDialog.querySelector('form');
    const {operacion,fecha,subetapa,motivo}=actividadForm.elements;
    const errorActividad=actividadForm.querySelector('[data-actividad-error]');
    const historial=actividadForm.querySelector('[data-actividad-historial]');
    const estadoHistorial=actividadForm.querySelector('[data-actividad-historial-estado]');
    const mas=actividadForm.querySelector('[data-actividad-mas]');
    const detalles=actividadForm.querySelector('details');
    const enviarActividad=actividadForm.querySelector('[type=submit]');
    const url='index.php?controller=embudoComercial&action=seguimientoOperativoV3';
    const formato=new Intl.DateTimeFormat('es-MX',{
      timeZone:'America/Mexico_City',dateStyle:'short',timeStyle:'short'
    });
    const formatear=valor=>formato.format(new Date(valor.replace(' ','T')+'Z'));
    const acciones={REGISTRAR:'Gestión',PROGRAMAR:'Programación',REPROGRAMAR:'Reprogramación',COMPLETAR:'Completada'};
    let seleccion=null;
    let antes=0;
    let consultado=false;
    const camposActividad=()=>{
      const requiereFecha=['PROGRAMAR','REPROGRAMAR'].includes(operacion.value);
      fecha.required=requiereFecha;
      actividadForm.querySelector('[data-actividad-fecha]').hidden=!requiereFecha;
      if(!requiereFecha)fecha.value='';
    };
    const cargarHistorial=async()=>{
      if(!seleccion || mas.disabled)return;
      const vigente=seleccion;
      mas.disabled=true;
      estadoHistorial.dataset.error='false';
      estadoHistorial.textContent='Cargando…';
      try{
        const params=new URLSearchParams({asignacion_id:vigente.id,antes:String(antes)});
        const resultado=await comprobarRespuesta(await fetch(url+'&'+params.toString(),{
          credentials:'same-origin',headers:{Accept:'application/json'}
        }),'No se pudo consultar el historial.');
        if(seleccion!==vigente)return;
        resultado.historial.forEach(item=>{
          const li=document.createElement('li');
          const cabecera=document.createElement('div');
          cabecera.className='impe-v3o-actividad-item-top';
          const tipo=document.createElement('span');
          tipo.className='impe-v3o-actividad-item-tipo';
          tipo.textContent=acciones[item.accion]||'Actividad';
          const momento=document.createElement('time');
          momento.className='impe-v3o-actividad-item-fecha';
          momento.textContent=formatear(item.creado_at);
          cabecera.append(tipo,momento);
          const descripcion=document.createElement('p');
          descripcion.className='impe-v3o-actividad-item-motivo';
          descripcion.textContent=item.motivo||'Sin descripción';
          const metadatos=document.createElement('div');
          metadatos.className='impe-v3o-actividad-item-meta';
          const dato=texto=>{
            const span=document.createElement('span');
            span.textContent=texto;
            metadatos.appendChild(span);
          };
          dato('Usuario #'+item.usuario_id);
          if(item.fecha_anterior)dato('Fecha anterior: '+formatear(item.fecha_anterior));
          if(item.fecha_pendiente)dato('Próxima fecha: '+formatear(item.fecha_pendiente));
          if(item.completada_at && item.accion==='COMPLETAR'){
            dato('Completada: '+formatear(item.completada_at));
          }
          if(item.subetapa_nueva && item.subetapa_nueva!==item.subetapa_anterior){
            const etiqueta=Array.from(subetapa.options)
              .find(o=>o.value===item.subetapa_nueva)?.textContent||item.subetapa_nueva;
            dato('Subetapa: '+etiqueta);
          }
          li.append(cabecera,descripcion,metadatos);
          historial.appendChild(li);
        });
        if(resultado.historial.length)antes=resultado.historial.at(-1).id;
        mas.hidden=resultado.historial.length<20;
        consultado=true;
        estadoHistorial.dataset.error='false';
        estadoHistorial.textContent=historial.childElementCount?'':'Aún no hay actividades.';
      }catch(err){
        if(seleccion===vigente){
          estadoHistorial.dataset.error='true';
          estadoHistorial.textContent=err.message;
        }
      }finally{
        if(seleccion===vigente)mas.disabled=false;
      }
    };
    root.querySelectorAll('[data-v3-actividad]').forEach(b=>b.addEventListener('click',()=>{
      const card=b.closest('.impe-v3o-card');
      if(!card || actividadDialog.open || telefonoActivo())return;
      seleccion={id:card.dataset.asignacionId,etapa:card.dataset.etapa,version:b.dataset.version};
      actividadForm.reset();
      historial.replaceChildren();
      detalles.open=false;
      antes=0;consultado=false;mas.hidden=true;mas.disabled=false;
      estadoHistorial.dataset.error='false';
      estadoHistorial.textContent='';
      errorActividad.hidden=true;
      actividadForm.querySelector('[data-actividad-prospecto]').textContent=card.dataset.nombre||'Prospecto';
      actividadForm.querySelector('[data-actividad-subetapa]').hidden=seleccion.etapa!=='SEGUIMIENTO';
      Array.from(operacion.options).forEach(o=>{
        o.disabled=o.value!=='REGISTRAR' && (o.value==='PROGRAMAR')===(b.dataset.pendiente==='1');
        o.hidden=o.disabled;
      });
      camposActividad();
      actividadDialog.showModal();
    }));
    actividadDialog.addEventListener('close',()=>{seleccion=null;});
    actividadForm.querySelectorAll('[data-actividad-cerrar]').forEach(b=>
      b.addEventListener('click',()=>{if(!enviarActividad.disabled)actividadDialog.close();}));
    actividadDialog.addEventListener('cancel',event=>{if(enviarActividad.disabled)event.preventDefault();});
    operacion.addEventListener('change',camposActividad);
    detalles.addEventListener('toggle',()=>{if(detalles.open && !consultado)cargarHistorial();});
    mas.addEventListener('click',cargarHistorial);
    actividadForm.addEventListener('submit',async event=>{
      event.preventDefault();
      if(!seleccion || enviarActividad.disabled)return;
      errorActividad.hidden=true;
      if(telefonoActivo()){
        errorActividad.textContent='Termina la llamada antes de guardar la actividad.';
        errorActividad.hidden=false;return;
      }
      enviarActividad.disabled=true;
      try{
        const body=new URLSearchParams({
          asignacion_id:seleccion.id,etapa_actual:seleccion.etapa,version:seleccion.version,
          operacion:operacion.value,motivo:motivo.value.trim(),fecha:fecha.value,subetapa:subetapa.value
        });
        await comprobarRespuesta(await fetch(url,{
          method:'POST',credentials:'same-origin',headers:{
            'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
            'Accept':'application/json','X-CSRF-Token':String(window.IMPE_CSRF_TOKEN||'')
          },body:body.toString()
        }),'No se pudo guardar la actividad.');
        actividadDialog.close();
        confirmarGuardado('actividad');
      }catch(err){
        errorActividad.textContent=err.message;
        errorActividad.hidden=false;
      }finally{enviarActividad.disabled=false;}
    });
  }

  // Gestión transversal de Potenciales: se conserva la etapa original,
  // incluso INTERESADO histórico; no se envían resultados PBX ni pagos.
  const potencialDialog=root.querySelector('#impe-v3o-potencial-dialog');
  const potencialForm=root.querySelector('#impe-v3o-potencial-form');
  if(potencialDialog && potencialForm){
    const subetapa=potencialForm.querySelector('#impe-v3o-potencial-subetapa');
    const fechaPot=potencialForm.querySelector('#impe-v3o-potencial-fecha');
    const motivoPot=potencialForm.querySelector('#impe-v3o-potencial-motivo');
    const datosPot=potencialForm.querySelector('#impe-v3o-potencial-datos');
    const errorPot=potencialForm.querySelector('#impe-v3o-potencial-error');
    const enviarPot=potencialForm.querySelector('#impe-v3o-potencial-guardar');
    let gestionCard=null;

    root.querySelectorAll('[data-v3-gestionar]').forEach(button=>{
      button.addEventListener('click',()=>{
        const card=button.closest('.impe-v3o-card');
        const etapa=String(card?.dataset.etapa||'');
        if(!card || telefonoActivo() || potencialDialog.open ||
           !['INTERESADO','POTENCIAL'].includes(etapa))return;
        gestionCard=card;
        const prev=String(card.dataset.v3Subetapa||'');
        subetapa.value=Array.from(subetapa.options).some(o=>o.value===prev)
          ?prev:'';
        fechaPot.value='';
        motivoPot.value='';
        datosPot.textContent=String(card.dataset.nombre||'Prospecto');
        errorPot.hidden=true;
        if(typeof potencialDialog.showModal==='function')potencialDialog.showModal();
      });
    });
    potencialForm.querySelectorAll('[data-v3-gestionar-cerrar]').forEach(button=>{
      button.addEventListener('click',()=>potencialDialog.close());
    });
    potencialDialog.addEventListener('close',()=>{gestionCard=null;});

    potencialForm.addEventListener('submit',async event=>{
      event.preventDefault();
      if(enviarPot.disabled)return;
      if(!gestionCard || telefonoActivo()){
        errorPot.textContent='Termina la llamada antes de actualizar la gestión.';
        errorPot.hidden=false;
        return;
      }
      const token=String(window.IMPE_CSRF_TOKEN||'');
      if(!token){
        errorPot.textContent='Tu sesión cambió. Actualiza la página.';
        errorPot.hidden=false;
        return;
      }
      const motivo=String(motivoPot.value||'').trim();
      const etapa=String(gestionCard.dataset.etapa||'');
      const elegido=String(subetapa.value||'');
      if(!['INTERESADO','POTENCIAL'].includes(etapa) || !elegido ||
         motivo.length<8 || motivo.length>190){
        errorPot.textContent='Selecciona una situación y describe el motivo (8 a 190 caracteres).';
        errorPot.hidden=false;
        return;
      }
      const body=new URLSearchParams({
        asignacion_id:String(gestionCard.dataset.asignacionId||''),
        etapa_actual:etapa,
        subetapa_anterior:String(gestionCard.dataset.v3Subetapa||''),
        subetapa_nueva:elegido,
        motivo,
        proxima_accion:String(fechaPot.value||'')
      });
      enviarPot.disabled=true;
      errorPot.hidden=true;
      try{
        const response=await fetch(
          'index.php?controller=embudoComercial&action=gestionarPotencialV3',{
            method:'POST',credentials:'same-origin',
            headers:{
              'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
              'Accept':'application/json',
              'X-CSRF-Token':token
            },
            body:body.toString()
          });
        await comprobarRespuesta(response,'No se pudo registrar la gestión.');
        potencialDialog.close();
        confirmarGuardado('gestion');
      }catch(err){
        errorPot.textContent=err?.message||'No se pudo registrar la gestión.';
        errorPot.hidden=false;
      }finally{
        enviarPot.disabled=false;
      }
    });
  }
})();
