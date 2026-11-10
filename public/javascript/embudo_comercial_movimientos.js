/* Gestión Comercial IMPE · drag & drop progresivo, con alternativa de botón.
 * Identidad, permisos, CSRF y concurrencia se validan en el servidor.
 */
(() => {
  'use strict';
  const root = document.querySelector('.impe-embudo');
  if (!root) return;
  // Reutiliza el mismo teléfono persistente de Inicio y Mis llamadas.
  root.querySelectorAll('[data-embudo-marcar]').forEach(b=>b.addEventListener('click',()=>{
    const numero=String(b.dataset.embudoMarcar||'').trim();
    if (!numero) return;
    const dial=window.IMPE_TELEPHONY_PERSISTENT;
    if (dial && typeof dial.openDialer==='function') {
      const tarjeta=b.closest('.impe-embudo-card');
      const asignacionId=String(tarjeta?.dataset.asignacionId||'');
      const digitos=numero.replace(/\D/g,'');
      const usuarioId=Number(window.IMPE_CURRENT_USER_ID||0);
      // Contexto explícito del botón del prospecto. No acredita una llamada:
      // PBX, propiedad, número completo y permisos se comprueban en servidor.
      // Se guarda antes de abrir el marcador, para que persista al cambiar de vista.
      if (/^[1-9][0-9]*$/.test(asignacionId)&&usuarioId>0&&digitos.length>=10&&digitos.length<=15) {
        try{sessionStorage.setItem('impe:embudo:llamada-pendiente:'+usuarioId,
          JSON.stringify({asignacionId,numero,creada:Date.now()}));}catch(_){}
      }
      dial.openDialer({number:numero});
    } else {
      window.alert('El teléfono no está disponible en esta sesión.');
    }
  }));

  const dialog = root.querySelector('#impe-embudo-dialog');
  const form = root.querySelector('#impe-embudo-mover-form');
  if (!dialog || !form) return;
  const destino = form.querySelector('#impe-embudo-destino');
  const motivo = form.querySelector('#impe-embudo-motivo');
  const fecha = form.querySelector('#impe-embudo-fecha');
  const labelFecha = form.querySelector('#impe-embudo-fecha-wrap');
  const error = form.querySelector('#impe-embudo-mover-error');
  const enviar = form.querySelector('#impe-embudo-mover-enviar');
  let tarjeta = null;
  let drag = null;

  const activo = (etapa) => ['NUEVO','CONTACTADO','INTERESADO','SEGUIMIENTO'].includes(etapa);
  function actualizarCampos() {
    const stage = destino.value;
    motivo.required = ['CONTACTADO','INTERESADO','RECUPERACION','DESCARTADO'].includes(stage);
    fecha.required = stage === 'SEGUIMIENTO';
    labelFecha.hidden = stage !== 'SEGUIMIENTO';
    error.hidden = true;
  }
  function abrir(card, proposed) {
    if (!(card instanceof HTMLElement) || !card.dataset.asignacionId) return;
    tarjeta = card;
    const current = card.dataset.etapa || '';
    const options = Array.from(destino.options);
    const option = options.find(x=>x.value===proposed && x.value!==current)
      || options.find(x=>x.value!==current);
    if (!option) return;
    destino.value = option.value;
    form.querySelector('#impe-embudo-dialog-subtitulo').textContent =
      (card.dataset.nombre || 'Prospecto') + ' · ' + current.replace(/_/g,' ').toLowerCase();
    motivo.value = '';
    fecha.value = '';
    actualizarCampos();
    if (typeof dialog.showModal === 'function') dialog.showModal();
  }
  root.querySelectorAll('[data-mover-tarjeta]').forEach(b=>b.addEventListener('click',()=>{
    abrir(b.closest('.impe-embudo-card'),null);
  }));
  form.querySelectorAll('[data-cerrar-dialogo]').forEach(b=>b.addEventListener('click',()=>{
    dialog.close();
  }));
  destino.addEventListener('change',actualizarCampos);
  dialog.addEventListener('close',()=>{tarjeta=null;drag=null;});
  root.querySelectorAll('.impe-embudo-card[draggable="true"]').forEach(card=>{
    card.addEventListener('dragstart',(e)=>{
      if (e.target.closest('a,button,select,input,textarea')) {
        e.preventDefault(); return;
      }
      drag=card;
      e.dataTransfer.effectAllowed='move';
      e.dataTransfer.setData('text/plain',card.dataset.asignacionId || '');
      card.classList.add('is-dragging');
    });
    card.addEventListener('dragend',()=>{
      card.classList.remove('is-dragging');
      root.querySelectorAll('.impe-embudo-lane').forEach(l=>l.classList.remove('is-drop-target'));
      drag=null;
    });
  });
  root.querySelectorAll('.impe-embudo-lane[data-etapa]').forEach(lane=>{
    const target=lane.dataset.etapa;
    if (!activo(target)) return;
    lane.addEventListener('dragover',(e)=>{
      if (!drag || drag.dataset.etapa===target) return;
      e.preventDefault();
      e.dataTransfer.dropEffect='move';
      lane.classList.add('is-drop-target');
    });
    lane.addEventListener('dragleave',e=>{
      if (!lane.contains(e.relatedTarget)) lane.classList.remove('is-drop-target');
    });
    lane.addEventListener('drop',(e)=>{
      e.preventDefault();
      lane.classList.remove('is-drop-target');
      if (!drag || drag.dataset.etapa===target) return;
      const selected=drag;
      drag=null;
      abrir(selected,target);
    });
  });

  form.addEventListener('submit',async e=>{
    e.preventDefault();
    if (!tarjeta) return;
    const source=tarjeta.dataset.etapa;
    const target=destino.value;
    if (source===target) {
      error.textContent='Selecciona otra etapa.';
      error.hidden=false;
      return;
    }
    const body=new URLSearchParams({
      asignacion_id:tarjeta.dataset.asignacionId,
      etapa_anterior:source,
      etapa_nueva:target,
      motivo:motivo.value.trim(),
      proxima_accion:target==='SEGUIMIENTO'?fecha.value:''
    });
    enviar.disabled=true;
    error.hidden=true;
    try {
      const response=await fetch('index.php?controller=embudoComercial&action=moverEtapa',{
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept':'application/json',
          'X-CSRF-Token':String(window.IMPE_CSRF_TOKEN || '')
        },
        body:body.toString()
      });
      const result=await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.mensaje || 'No se pudo cambiar la etapa.');
      }
      dialog.close();
      window.location.reload();
    } catch(ex) {
      error.textContent=(ex && ex.message) ? ex.message : 'No se pudo completar el movimiento.';
      error.hidden=false;
    } finally {
      enviar.disabled=false;
    }
  });
})();