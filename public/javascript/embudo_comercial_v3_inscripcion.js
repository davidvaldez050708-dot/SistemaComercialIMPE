/* Gestión Comercial V3 · inscripción sustentada en formulario real.
 * No envía comunicaciones ni registra pagos; consulta/vincula por servidor.
 */
(() => {
  'use strict';
  const root=document.querySelector('.impe-embudo--v3-operativo');
  if(!root)return;
  const dialog=root.querySelector('#impe-v3i-dialog');
  const form=root.querySelector('#impe-v3i-form');
  if(!dialog||!form)return;
  const datos=form.querySelector('#impe-v3i-datos');
  const estado=form.querySelector('[data-v3i-estado]');
  const error=form.querySelector('[data-v3i-error]');
  const buscar=form.querySelector('[data-v3i-buscar]');
  const confirmar=form.querySelector('[data-v3i-confirmar]');
  const guardar=form.querySelector('[data-v3i-guardar]');
  const motivo=form.querySelector('[name=motivo]');
  const visto=form.querySelector('[name=confirmacion]');
  const token=String(window.IMPE_CSRF_TOKEN||'');
  const currentUser=Number(window.IMPE_CURRENT_USER_ID||0);
  const url='index.php?controller=embudoComercial&action=inscripcionOperativaV3';
  let seleccion=null;
  let busy=false;

  const mostrarError=msg=>{
    error.textContent=msg;
    error.hidden=false;
  };
  const limpiar=()=>{
    error.hidden=true;
    error.textContent='';
  };
  const mostrarAviso=mensaje=>{
    const container=document.createElement('div');
    container.className='toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex='2000';
    const toast=document.createElement('div');
    toast.className='toast system-toast border-0 show';
    toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');
    const body=document.createElement('div');body.className='toast-body';
    const icon=document.createElement('i');icon.className='bi bi-check-circle-fill';
    icon.setAttribute('aria-hidden','true');
    const text=document.createElement('span');text.textContent=mensaje;
    const close=document.createElement('button');
    close.type='button';close.className='btn-close ms-auto';
    close.setAttribute('aria-label','Cerrar aviso');
    close.addEventListener('click',()=>container.remove());
    body.append(icon,text,close);toast.appendChild(body);
    container.appendChild(toast);document.body.appendChild(container);
  };
  const telefonoActivo=()=>{
    try{
      if(window.IMPE_TELEPHONY_PERSISTENT?.getState?.()?.active)return true;
      if(window.parent!==window &&
          window.parent.IMPE_TELEPHONY_SHELL?.getPhoneStatus?.()?.active)return true;
      return false;
    }catch(_){return true;}
  };
  const post=async params=>{
    const response=await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/x-www-form-urlencoded',
        'X-CSRF-Token':token,'X-Requested-With':'fetch'},
      body:new URLSearchParams(params)
    });
    const data=await response.json().catch(()=>null);
    if(!response.ok||data?.ok!==true){
      throw new Error(typeof data?.mensaje==='string'?data.mensaje:
        'No fue posible verificar la solicitud institucional.');
    }
    return data;
  };
  const preparar=form.querySelector('[data-v3i-preparar]');
  const ligas=form.querySelector('[data-v3i-ligas]');
  const arrancar=form.querySelector('[data-v3i-arrancar]');
  if(!preparar||!ligas||!arrancar)return;
  const programa=form.querySelector('[data-v3i-programa]');
  const enlace=form.querySelector('[data-v3i-enlace]');
  const actualizarEnlace=()=>{
    if(!enlace||!programa)return;
    enlace.hidden=!programa.value;
    if(!programa.value)return;
    const url=new URL(enlace.dataset.base,location.href);
    url.searchParams.set('programa',programa.value);
    enlace.href=url.href;
  };
  programa?.addEventListener('change',actualizarEnlace);
  let tramiteIniciado=false;
  arrancar.addEventListener('click',async()=>{
    if(!seleccion||busy)return;
    limpiar();
    if(!form.elements.documentos.checked||!form.elements.comprobante.checked){
      mostrarError('Confirma los documentos y el comprobante recibido.');
      return;
    }
    busy=true;
    arrancar.disabled=true;
    try{
      await post({operacion:'INICIAR',asignacion_id:seleccion.asignacion,
        documentos:'1',comprobante:'1'});
      tramiteIniciado=true;
      arrancar.hidden=true;
      preparar.hidden=true;
      ligas.hidden=false;
      buscar.hidden=false;
      estado.textContent='Proceso iniciado. Comparte la liga del programa y busca la solicitud cuando se reciba.';
    }catch(ex){
      mostrarError(ex.message);
    }finally{busy=false;arrancar.disabled=false;}
  });
  root.addEventListener('click',e=>{
    const btn=e.target.closest('[data-v3i-iniciar]');
    if(!btn || !root.contains(btn) || busy)return;
    const card=btn.closest('[data-asignacion-id][data-etapa]');
    const asignacion=String(card?.dataset.asignacionId||'');
    if(!card || !['POTENCIAL','PROCESO_INSCRIPCION'].includes(card.dataset.etapa) ||
       card.dataset.v3Subetapa==='NO_CONTACTAR' ||
       !/^[1-9][0-9]*$/.test(asignacion) ||currentUser<1)return;
    form.reset();
    actualizarEnlace();
    tramiteIniciado=false;
    guardar.hidden=true;
    limpiar();
    confirmar.hidden=true;
    guardar.disabled=true;
    buscar.disabled=false;
    seleccion={asignacion,formulario:0};
    datos.textContent=String(card.dataset.nombre||'Prospecto');
    const iniciar=card.dataset.etapa==='POTENCIAL';
    preparar.hidden=!iniciar;
    arrancar.hidden=!iniciar;
    ligas.hidden=iniciar;
    buscar.hidden=iniciar;
    estado.textContent=iniciar?'Confirma los requisitos antes de compartir la liga.':
      'Comparte la liga del programa. Busca la solicitud cuando el prospecto confirme que la envió.';
    dialog.showModal();
    (iniciar?arrancar:buscar).focus();
  });
  form.querySelectorAll('[data-v3i-cerrar]').forEach(btn=>{
    btn.addEventListener('click',()=>{if(!busy)dialog.close();});
  });
  dialog.addEventListener('close',()=>{
    seleccion=null;
    limpiar();
    if(tramiteIniciado&&!telefonoActivo())window.location.reload();
  });
  buscar.addEventListener('click',async()=>{
    if(!seleccion||busy)return;
    busy=true;buscar.disabled=true;guardar.disabled=true;
    confirmar.hidden=true;guardar.hidden=true;limpiar();
    estado.textContent='Verificando identidad y solicitudes recibidas…';
    try{
      const data=await post({
        operacion:'BUSCAR',
        asignacion_id:seleccion.asignacion
      });
      const result=data.consulta||{};
      if(result.estado==='CANDIDATA'&&Number.isInteger(result.formulario_id) &&
         result.formulario_id>0){
        seleccion.formulario=result.formulario_id;
        confirmar.hidden=false;
        estado.textContent='Solicitud #'+result.formulario_id+
          ' recibida el '+String(result.fecha||'')+
          '. Confirma únicamente después de revisar la ficha.';
        guardar.hidden=false;
        guardar.disabled=false;
      }else {
        seleccion.formulario=0;
        estado.textContent=String(result.mensaje||
          'No se encontró una solicitud vinculable.');
      }
    }catch(ex){
      mostrarError(ex instanceof Error?ex.message:
        'No se pudo consultar la solicitud.');
    }finally{buscar.disabled=false;busy=false;}
  });
  form.addEventListener('submit',async e=>{
    e.preventDefault();
    if(!seleccion||seleccion.formulario<1||!visto.checked||busy)return;
    const razon=motivo.value.trim();
    if(razon.length<8||razon.length>125){mostrarError('Indica un motivo de 8 a 125 caracteres.');return;}
    busy=true;guardar.disabled=true;buscar.disabled=true;limpiar();
    try{
      await post({
        operacion:'VINCULAR',
        asignacion_id:seleccion.asignacion,
        formulario_id:String(seleccion.formulario),
        motivo:razon,confirmacion:'1'
      });
      dialog.close();
      if(telefonoActivo()){
        // Nunca recargar la pantalla mientras persiste una llamada.
        mostrarAviso('Solicitud institucional vinculada. Actualiza Gestión Comercial al finalizar la llamada.');
      }else {
        try {
          sessionStorage.setItem('impe:embudo:v3:aviso:'+currentUser,
            JSON.stringify({tipo:'movimiento',creado:Date.now()}));
        }catch(_){}
        window.location.reload();
      }
    }catch(ex){
      mostrarError(ex instanceof Error?ex.message:
        'No pudo confirmarse la solicitud.');
    }finally{busy=false;guardar.disabled=false;buscar.disabled=false;}
  });
})();
