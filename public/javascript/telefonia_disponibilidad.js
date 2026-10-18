/* Disponibilidad por CRM: nunca llama al SDK ni envía DTMF durante la confirmación. */
(()=>{
 'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;
 if(!cfg.availabilityUrl||!phone)return;
 const $=s=>document.querySelector(s),source=$('[data-availability-source]'),incoming=$('[data-availability-incoming]'),callback=$('[data-availability-callback]');
 const key='impe:availability:'+cfg.userId;
 let watched=null,received=null,polling=false,acting=false,transferring=false,callbacks=-1,deferred='';
 try{watched=JSON.parse(sessionStorage.getItem(key)||'null');}catch(_){}
 const persist=()=>{try{if(watched)sessionStorage.setItem(key,JSON.stringify(watched));else sessionStorage.removeItem(key);}catch(_){}};
 const refreshBell=()=>document.getElementById('impeCrmFrame')?.contentWindow?.postMessage({type:'IMPE_PHONE_CALL_CHANGED'},location.origin);
 const request=async(data=null,id=0)=>{
  const url=new URL(cfg.availabilityUrl,location.href),options={credentials:'same-origin',cache:'no-store'};
  if(data){options.method='POST';options.body=new URLSearchParams({...data,csrf_token:cfg.routingCsrf});}
  else if(id)url.searchParams.set('id',id);
  const r=await fetch(url,options),json=await r.json();
  if(!r.ok||!json.ok)throw Error(json.mensaje||'No se pudo actualizar la solicitud. Intenta de nuevo.');return json;
 };
 const action=async(op,accion,extra={})=>(await request({id:op.id,version:op.version,accion,...extra})).solicitud;
 const busy=()=>{const s=phone.getPhoneStatus();return !s.ready||s.active||s.pending;};
 const cancel=async op=>{try{return await action(op,'CANCELAR');}catch(_){return null;}};
 const render=()=>{
  if(!source)return;
  const same=phone.getActiveCall()?.id===watched?.referencia;
  source.hidden=!watched||!same||['ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR'].includes(watched.estado);
  if(!watched)return;
  const status=watched.estado,name=watched.responsable||'El responsable',seconds=Math.max(0,Number(watched.segundos)||0);
  const titles={PENDIENTE:'Esperando confirmación',ACEPTADA:'Responsable disponible',RECHAZADA:'No disponible',VENCIDA:'Sin confirmación a tiempo',CANCELADA:'Solicitud cancelada',FALLIDA:'No se envió la transferencia',DEVOLUCION:'Devolución registrada'};
  const copies={PENDIENTE:name+' · Ext. '+watched.destino_extension+' · '+seconds+' s. La persona sigue hablando contigo.',ACEPTADA:name+' confirmó que puede atender. Transfiere en los próximos '+seconds+' s.',RECHAZADA:'Continúa atendiendo a la persona. Puedes elegir otro responsable o dejar una devolución.',VENCIDA:'La persona sigue contigo. Solicita de nuevo o registra sus datos para devolver la llamada.',CANCELADA:'Puedes seleccionar otro responsable o solicitar una devolución.',FALLIDA:'La persona sigue contigo. Revisa la llamada antes de volver a solicitar disponibilidad.',DEVOLUCION:'El responsable tiene el contacto y el motivo en sus llamadas por devolver.'};
  $('[data-availability-source-title]').textContent=titles[status]||'Transferencia en proceso';
  $('[data-availability-source-copy]').textContent=copies[status]||'Espera la confirmación del teléfono.';
  $('[data-availability-transfer]').hidden=status!=='ACEPTADA';$('[data-availability-transfer]').disabled=transferring;
  $('[data-availability-cancel]').hidden=!['PENDIENTE','ACEPTADA'].includes(status);
  $('[data-availability-callback-open]').hidden=!['RECHAZADA','VENCIDA','CANCELADA','FALLIDA'].includes(status);
 };
 const store=op=>{watched=op;persist();render();window.dispatchEvent(new Event('impe:availability-state'));};
 const closeIncoming=()=>{if(incoming?.open)incoming.close();received=null;};
 const tick=async()=>{
  if(polling||!incoming)return;polling=true;
  try{
   const data=await request({accion:'POLL',id:watched?.id||0,disponible:busy()?'0':'1'});
   if(watched){
    const next=data.solicitud;
    if(!next||Number(next.version)!==Number(watched.version)){store(null);}
    else{
     store(next);
     if(phone.getActiveCall()?.id!==next.referencia && ['PENDIENTE','ACEPTADA'].includes(next.estado)){
      const ended=await cancel(next);if(ended)store(ended);
     }
    }
   }
   const op=data.entrante;
   if(Number.isFinite(data.devoluciones)&&data.devoluciones!==callbacks){if(callbacks>=0||data.devoluciones>0)refreshBell();callbacks=data.devoluciones;}
   const status=phone.getPhoneStatus(),waiting=$('[data-availability-waiting]');
   if(waiting)waiting.hidden=!op||!status.pending||deferred!==String(op.id)+':'+op.version;
   if(!op||!status.ready||status.active){closeIncoming();return;}
   received=op;
   if(status.pending && deferred===String(op.id)+':'+op.version){if(incoming.open)incoming.close();return;}
   $('[data-availability-accept]').disabled=Boolean(status.pending);
   $('[data-availability-pending]').hidden=!status.pending;
   $('[data-availability-feedback]').textContent=status.pending?'Tienes un resultado por registrar. Guárdalo antes de aceptar esta llamada.':'';
   $('[data-availability-caller]').textContent='Recepción · Ext. '+op.origen_extension+' · '+op.numero+(op.institucion_nombre?' · '+op.institucion_nombre:'');
   $('[data-availability-countdown]').textContent='Responde en '+op.segundos+' s. Aceptar reserva tu disponibilidad durante 30 s.';
   if(!incoming.open){incoming.showModal();$('[data-availability-accept]').focus();}
  }catch(e){if(watched){$('[data-availability-source-copy]').textContent=e.message;}if(incoming.open)$('[data-availability-feedback]').textContent=e.message;}
  finally{polling=false;}
 };
 const respond=async which=>{
  if(acting||!received)return;acting=true;
  const buttons=incoming.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
  try{if(which==='ACEPTAR'&&busy())throw Error('Ya tienes una llamada o tu teléfono no está conectado.');await action(received,which);closeIncoming();}
  catch(e){$('[data-availability-feedback]').textContent=e.message;}
  finally{acting=false;buttons.forEach(b=>b.disabled=false);}
 };
 $('[data-availability-pending]')?.addEventListener('click',()=>{
  if(!received)return;deferred=String(received.id)+':'+received.version;if(incoming.open)incoming.close();
  phone.openPendingResult?.();
  const waiting=$('[data-availability-waiting]');if(waiting)waiting.hidden=false;
 });
 $('[data-availability-accept]')?.addEventListener('click',()=>void respond('ACEPTAR'));
 $('[data-availability-decline]')?.addEventListener('click',()=>void respond('RECHAZAR'));
 incoming?.addEventListener('cancel',e=>{e.preventDefault();void respond('RECHAZAR');});
 $('[data-availability-cancel]')?.addEventListener('click',async()=>{if(!watched)return;const result=await cancel(watched);if(result)store(result);});
 $('[data-availability-transfer]')?.addEventListener('click',async()=>{
  if(transferring||!watched||watched.estado!=='ACEPTADA')return;transferring=true;render();const op={...watched};
  try{
   if(phone.getActiveCall()?.id!==op.referencia)throw Error('La llamada ya cambió.');
   const r=await fetch(cfg.routingAuthorizeUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({disponibilidad_id:op.id,version:op.version,csrf_token:cfg.routingCsrf})});
   const data=await r.json();if(!r.ok||!data.ok)throw Error(data.mensaje||'No se pudo preparar la transferencia.');
   if(phone.getActiveCall()?.id!==op.referencia||!phone.requestAuthorizedTransfer(data.token))throw Error('La llamada cambió; no se envió la transferencia.');
   store({...watched,estado:'ENVIANDO'});
  }catch(e){$('[data-availability-source-copy]').textContent=e.message;}
  finally{transferring=false;$('[data-availability-transfer]').disabled=false;}
 });
 $('[data-availability-callback-open]')?.addEventListener('click',()=>{
  if(!watched||!callback)return;callback.querySelector('form').reset();callback.querySelector('[name=telefono]').value=watched.numero;
  $('[data-callback-feedback]').textContent='';callback.showModal();callback.querySelector('[name=contacto]').focus();
 });
 $('[data-callback-close]')?.addEventListener('click',()=>callback.close());
 callback?.querySelector('form').addEventListener('submit',async e=>{
  e.preventDefault();if(acting||!watched)return;acting=true;const form=e.currentTarget,op={...watched},values=Object.fromEntries(new FormData(form));
  form.querySelectorAll('button,input,textarea').forEach(el=>el.disabled=true);
  try{store(await action(op,'DEVOLUCION',values));callback.close();refreshBell();window.IMPE_TELEPHONY_NOTIFY?.('Devolución registrada.');}
  catch(error){$('[data-callback-feedback]').textContent=error.message;}
  finally{acting=false;form.querySelectorAll('button,input,textarea').forEach(el=>el.disabled=false);}
 });
 window.IMPE_AVAILABILITY={watch:store,cancel,pending:()=>Boolean(watched&&phone.getActiveCall()?.id===watched.referencia&&['PENDIENTE','ACEPTADA','ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR','DEVOLUCION'].includes(watched.estado)),tick};
 window.addEventListener('pagehide',()=>{navigator.sendBeacon?.(cfg.availabilityUrl,new URLSearchParams({accion:'PRESENCIA',disponible:'0',csrf_token:cfg.routingCsrf}));});
 window.setInterval(()=>void tick(),3000);window.setInterval(render,1000);void tick();
})();
