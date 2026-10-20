/* Disponibilidad por CRM: nunca llama al SDK ni envía DTMF durante la confirmación. */
(()=>{
 'use strict';
 const cfg=window.IMPE_PHONE_SHELL_CONFIG||{},phone=window.IMPE_TELEPHONY_SHELL;
 if(!cfg.availabilityUrl||!phone||window.IMPE_AVAILABILITY)return;
 const $=s=>document.querySelector(s),source=$('[data-availability-source]'),incoming=$('[data-availability-incoming]'),callback=$('[data-availability-callback]');
 const key='impe:availability:'+cfg.userId;
 let watched=null,received=null,polling=false,acting=false,transferring=false,callbacks=-1,deferred='';
 // Aviso de disponibilidad independiente del timbre y del contexto de audio del SDK.
 const soundKey='impe:availability-chimes:'+cfg.userId;
 const REMINDER_AFTER_MS=8000,REMINDER_MIN_REMAINING_MS=1000;
 let audio=null,soundRequest=null,soundExpiry=0,soundRepeat=0,soundNodes=[],heard=[];
 let audioMeter=null,audioError='',audioPeak=0,lastEmission=0;
 const sdkRing=()=>{try{return $('[data-shell-engine]')?.contentDocument?.getElementById('zdrm-incomingRing')||null;}catch(_){return null;}};
 const audioDiagnostic=()=>({context:audio?.state||'sin desbloquear',clock:audio?.currentTime||0,sink:audio?.sinkId??'predeterminado (API no disponible)',
  output:audio?.getOutputTimestamp?.()||null,visible:!document.hidden,pending:Boolean(soundRequest),lastEmission,peak:audioPeak,error:audioError,
  sdk:sdkRing()?{sink:sdkRing().sinkId||'',volume:sdkRing().volume,muted:sdkRing().muted}:null});
 try{const value=JSON.parse(sessionStorage.getItem(soundKey)||'[]');if(Array.isArray(value))heard=value.slice(-40);}catch(_){}
 const stopSound=()=>{clearTimeout(soundExpiry);clearInterval(soundRepeat);soundRepeat=0;soundRequest=null;for(const node of soundNodes){try{node.stop?.();node.disconnect?.();}catch(_){}}soundNodes=[];};
 const playSound=(force=false)=>{
  // No mezclar avisos con conversación SIP, pestaña oculta ni audio bloqueado.
  const status=phone.getPhoneStatus();
  if(!soundRequest||!audio||audio.state!=='running'||document.hidden||
     !status.ready||status.active||Date.now()>=soundRequest.until)return;
  const request=soundRequest,identity=request.key,reminder=!force&&request.plays>0;
  if(!force&&request.plays===0&&heard.includes(identity))return;
  if(!force&&reminder&&(Date.now()<request.reminderAt||
      request.until-Date.now()<=REMINDER_MIN_REMAINING_MS||status.pending))return;
  if(force){for(const node of soundNodes){try{node.stop?.();node.disconnect?.();}catch(_){}}soundNodes=[];}
  // La misma melodía compartida, con pausas; sólo mientras siga pendiente.
  const notes=[[740,0],[990,.22]]; // Melodía original de 2c087d81.
  const volume=.13;
  try{
   for(const [frequency,offset] of notes){
    const oscillator=audio.createOscillator(),gain=audio.createGain(),start=audio.currentTime+offset;
    oscillator.type='sine';oscillator.frequency.value=frequency;
    gain.gain.setValueAtTime(0,start);
    gain.gain.linearRampToValueAtTime(volume,start+.02);
    gain.gain.linearRampToValueAtTime(0,start+.17);
    oscillator.connect(gain);gain.connect(audio.destination);
    if(audioMeter)gain.connect(audioMeter);
    oscillator.start(start);oscillator.stop(start+.18);soundNodes.push(oscillator,gain);
   }
   request.plays++;
   lastEmission=Date.now();
   if(audioMeter)for(const delay of [120,420,700])setTimeout(()=>{try{const data=new Float32Array(audioMeter.fftSize);audioMeter.getFloatTimeDomainData(data);audioPeak=Math.max(audioPeak,Math.sqrt(data.reduce((sum,x)=>sum+x*x,0)/data.length));}catch(_){}},delay);
   request.reminderAt=Date.now()+REMINDER_AFTER_MS;
   if(!reminder){
    heard=[...heard.filter(k=>k!==identity),identity].slice(-40);
    try{sessionStorage.setItem(soundKey,JSON.stringify(heard));}catch(_){}
   }
  }catch(_){stopSound();}
 };
 const signalAvailability=op=>{
  const identity=String(op.id)+':'+op.version;
  if(soundRequest?.key!==identity){
   stopSound();
   const duration=Math.max(0,Number(op.segundos)||0)*1000;
   soundRequest={key:identity,until:Date.now()+duration,plays:heard.includes(identity)?1:0,reminderAt:Date.now()+REMINDER_AFTER_MS};
   soundExpiry=setTimeout(stopSound,duration);
   soundRepeat=setInterval(playSound,1000);
   unlockAudio(); // Intento automático; resume() sigue sujeto a autoplay.
  }
  playSound();
 };
 const unlockAudio=event=>{
  if(event&&!event.isTrusted)return;
  if(event&&event.type!=='click'&&event.target?.closest?.('[data-availability-audio]'))return;
  const explicit=event?.currentTarget===audioButton;
  try{
   const Context=window.AudioContext||window.webkitAudioContext;if(!Context)return;
   if(!audio||audio.state==='closed'){audio=new Context({latencyHint:'interactive'});audioMeter=audio.createAnalyser?.()||null;audioPeak=0;if(audioMeter){const silent=audio.createGain();silent.gain.value=0;audioMeter.connect(silent);silent.connect(audio.destination);}}
   const started=()=>{if(audio.state!=='running')throw new DOMException('Autoplay requiere interacción','NotAllowedError');audioError='';if(!phone.getPhoneStatus().pending){const feedback=$('[data-availability-feedback]');if(feedback)feedback.textContent='';}playSound(explicit);if(audioButton)audioButton.hidden=true;};
   // Utilizar la salida efectiva del timbre SDK, cuando la API lo permite;
   // en otros navegadores conservar la predeterminada y mostrar diagnóstico.
   const output=()=>{audioError='';}; // Salida predeterminada, como el mecanismo original; no heredar una salida SDK silenciosa.
   const failed=error=>{audioError=String(error?.name||error?.message||'Audio bloqueado');if(audioButton)audioButton.hidden=false;const feedback=$('[data-availability-feedback]');if(feedback)feedback.textContent='El navegador no permitió reproducir el aviso. Pulsa Activar avisos sonoros y revisa la salida de audio del navegador.';};
   const resumed=audio.state!=='running'?audio.resume():Promise.resolve();
   void resumed.then(output).then(started).catch(failed);
  }catch(error){audioError=String(error?.name||'Audio no disponible');if(audioButton)audioButton.hidden=false;}
 };
 const audioButton=$('[data-availability-audio]');
 audioButton?.addEventListener('click',unlockAudio);
 document.addEventListener('pointerdown',unlockAudio,{capture:true});
 document.addEventListener('keydown',unlockAudio,{capture:true});
 const audioDocuments=new WeakSet();
 const bindCrmGesture=()=>{try{
  const doc=document.getElementById('impeCrmFrame')?.contentDocument;
  if(!doc||audioDocuments.has(doc))return;
  audioDocuments.add(doc);doc.addEventListener('pointerdown',unlockAudio,{capture:true});doc.addEventListener('keydown',unlockAudio,{capture:true});
 }catch(_){}};
 document.getElementById('impeCrmFrame')?.addEventListener('load',bindCrmGesture);bindCrmGesture();
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
 const matchesCall=(op,active=phone.getActiveCall())=>Boolean(op&&active&&[active.id,...(active.aliases||[])].map(String).includes(String(op.referencia)));
 const render=()=>{
  if(!source)return;
  const same=matchesCall(watched);
  source.hidden=!watched||!same||['ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR'].includes(watched.estado);
  const panel=source.closest('.impe-routing-panel'),show=!source.hidden;
  const help=panel?.querySelector('.impe-routing-help'),feedback=panel?.querySelector('[data-routing-feedback]');
  if(help)help.hidden=show;
  if(feedback)feedback.hidden=show&&!feedback.classList.contains('is-error');
  if(!watched){source.removeAttribute('data-availability-state');return;}
  const status=watched.estado,name=watched.responsable||'El responsable',seconds=Math.max(0,Number(watched.segundos)||0);
  // Estado visual únicamente: no altera la autorización ni la transferencia.
  source.dataset.availabilityState=status;
  const titles={PENDIENTE:'Esperando confirmación',ACEPTADA:'Responsable disponible',RECHAZADA:'No disponible',VENCIDA:'Sin confirmación a tiempo',CANCELADA:'Solicitud cancelada',FALLIDA:'No se envió la transferencia',DEVOLUCION:'Devolución registrada'};
  const copies={PENDIENTE:name+' · Ext. '+watched.destino_extension+' · '+seconds+' s.',ACEPTADA:name+' · Ext. '+watched.destino_extension+' · Transfiere en '+seconds+' s.',RECHAZADA:'Continúa atendiendo a la persona. Puedes elegir otro responsable o dejar una devolución.',VENCIDA:'La persona sigue contigo. Solicita de nuevo o registra sus datos para devolver la llamada.',CANCELADA:'Puedes seleccionar otro responsable o solicitar una devolución.',FALLIDA:'La persona sigue contigo. Revisa la llamada antes de volver a solicitar disponibilidad.',DEVOLUCION:'El responsable tiene el contacto y el motivo en sus llamadas por devolver.'};
  $('[data-availability-source-title]').textContent=titles[status]||'Transferencia en proceso';
  $('[data-availability-source-copy]').textContent=copies[status]||'Espera la confirmación del teléfono.';
  $('[data-availability-transfer]').hidden=status!=='ACEPTADA';$('[data-availability-transfer]').disabled=transferring;
  $('[data-availability-cancel]').hidden=!['PENDIENTE','ACEPTADA'].includes(status);
  $('[data-availability-callback-open]').hidden=!['RECHAZADA','VENCIDA','CANCELADA','FALLIDA'].includes(status);
 };
 const store=op=>{watched=op;persist();render();window.dispatchEvent(new Event('impe:availability-state'));};
 const closeIncoming=()=>{stopSound();if(incoming?.open)incoming.close();received=null;};
 const tick=async()=>{
  if(polling||!incoming)return;polling=true;
  try{
   const snapshot=watched,active=phone.getActiveCall();
   const data=await request({accion:'POLL',id:snapshot?.id||0,referencia:active?.id||'',disponible:busy()?'0':'1'});
   // No pisar una solicitud creada mientras el polling estaba en vuelo.
   if(watched===snapshot){
    const next=data.solicitud;
    if(next||snapshot)store(next||null);
    // Una instantanea ausente o caducada del shell no acredita cierre PBX.
    // La solicitud conserva su referencia hasta la respuesta o vencimiento.
   }
   const op=data.entrante;
   if(Number.isFinite(data.devoluciones)&&data.devoluciones!==callbacks){if(callbacks>=0||data.devoluciones>0)refreshBell();callbacks=data.devoluciones;}
   const status=phone.getPhoneStatus(),waiting=$('[data-availability-waiting]');
   if(waiting)waiting.hidden=!op||!status.pending||deferred!==String(op.id)+':'+op.version;
   if(!op||!status.ready||status.active){closeIncoming();return;}
   received=op;
   // Destacar bloqueo y cuenta regresiva sin modificar el estado real del teléfono.
   incoming.dataset.availabilityBlocked=status.pending?'1':'0';
   incoming.dataset.availabilityUrgent=Number(op.segundos)<=10?'1':'0';
   if(status.pending && deferred===String(op.id)+':'+op.version){stopSound();if(incoming.open)incoming.close();return;}
   $('[data-availability-accept]').disabled=Boolean(status.pending);
   $('[data-availability-pending]').hidden=!status.pending;
   $('[data-availability-feedback]').textContent=status.pending?'Tienes un resultado por registrar. Guárdalo antes de aceptar esta llamada.':audioError?'El navegador no permitió el aviso. Habilita los avisos sonoros con el botón disponible.':'';
   $('[data-availability-caller]').textContent='Recepción · Ext. '+op.origen_extension+' · '+op.numero+(op.institucion_nombre?' · '+op.institucion_nombre:'');
   $('[data-availability-countdown]').textContent='Responde en '+op.segundos+' s. Aceptar reserva tu disponibilidad durante 30 s.';
   if(!incoming.open){incoming.showModal();$('[data-availability-accept]').focus();}
   if(audioButton)audioButton.hidden=!audioError;
   signalAvailability(op);
  }catch(e){if(watched){$('[data-availability-source-copy]').textContent=e.message;}if(incoming.open)$('[data-availability-feedback]').textContent=e.message;}
  finally{polling=false;}
 };
 const respond=async which=>{
  if(acting||!received)return;acting=true;stopSound();
  const buttons=incoming.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
  try{if(which==='ACEPTAR'&&busy())throw Error('Ya tienes una llamada o tu teléfono no está conectado.');await action(received,which);closeIncoming();}
  catch(e){$('[data-availability-feedback]').textContent=e.message;}
  finally{acting=false;buttons.forEach(b=>b.disabled=false);}
 };
 $('[data-availability-pending]')?.addEventListener('click',()=>{
  if(!received)return;stopSound();deferred=String(received.id)+':'+received.version;if(incoming.open)incoming.close();
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
   if(!matchesCall(op))throw Error('La llamada ya cambió.');
   const r=await fetch(cfg.routingAuthorizeUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({disponibilidad_id:op.id,version:op.version,csrf_token:cfg.routingCsrf})});
   const data=await r.json();if(!r.ok||!data.ok)throw Error(data.mensaje||'No se pudo preparar la transferencia.');
   if(!matchesCall(op)||!phone.requestAuthorizedTransfer(data.token))throw Error('La llamada cambió; no se envió la transferencia.');
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
 window.IMPE_AVAILABILITY={watch:store,cancel,audioDiagnostic,pending:()=>Boolean(watched&&matchesCall(watched)&&['PENDIENTE','ACEPTADA','ENVIANDO','ENVIADA','TRANSFERIDA','SIN_RESPUESTA','SIN_CONFIRMAR','DEVOLUCION'].includes(watched.estado)),tick};
 window.addEventListener('pagehide',()=>{stopSound();navigator.sendBeacon?.(cfg.availabilityUrl,new URLSearchParams({accion:'PRESENCIA',disponible:'0',csrf_token:cfg.routingCsrf}));});
 window.setInterval(()=>void tick(),3000);window.setInterval(render,1000);void tick();
})();
