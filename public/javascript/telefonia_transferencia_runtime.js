/* Operación de transferencia: consulta, entrega y devolución por separado. */
(function(root){
 'use strict';
 const create=api=>{
  let tracked=null,busy=false,posting=false,external=false,delivered=false,endedReported=false,atendida=false,directRelease=false;
  // TERMINADA puede devolver el mismo registro durante varios sondeos.
  // No volver a asociarlo al diálogo SIP ya finalizado, aun si reset() lo
  // soltó. El token pertenece a una llamada; el siguiente INVITE usa otro.
  let lastClosedReceiverToken='';
  let nextPoll=0, failures=0;
  const reset=()=>{tracked=null;external=false;delivered=false;endedReported=false;atendida=false;directRelease=false;};
  const request=async(id,action,expected=null)=>{
   const generation=expected||(tracked?.op?.id==id?tracked.op:null);
   const url=new URL(api.url,root.location.href),options={credentials:'same-origin',cache:'no-store'};
   if(action){options.method='POST';options.body=new URLSearchParams({id:String(id),accion:action,csrf_token:api.csrf,intento:String(generation?.intento||0)});}
   else if(id){url.searchParams.set('id',String(id));if(generation?.intento)url.searchParams.set('intento',String(generation.intento));}
   else {url.searchParams.set('origen',api.read().destination);url.searchParams.set('sdk',api.read().callToken);if(api.read().context?.availabilityCandidate)url.searchParams.set('solicitud_id',api.read().context.availabilityCandidate);}
   if(!action)options.scope=String(api.read().callToken||'');
   const response=await root.IMPE_PHONE_HTTP.request(url,options);
   if(response.status===503){
    const error=Error('La telefonía está temporalmente ocupada. Intenta nuevamente en unos segundos.');
    error.temporal=true;throw error;
   }
   const data=await response.json();
   if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo actualizar la transferencia.');
   return data.operacion;
  };
  const apply=op=>{
   if(!op||!tracked)return;
   tracked.op=op;
   const state=api.read(),source=tracked.source;
   if(String(state.callToken||'')!==String(tracked.token||''))return;
   if(!source)api.journalTransfer?.(op,tracked.session);
   if(!source&&!state.active)return;
   if(source){
    if(state.active && op.estado==='DEVUELTA')api.publish({message:op.modo==='DIRECTA'?'La centralita confirmó el retorno de la llamada. Retoma la conversación.':'La consulta terminó. Retoma la conversación con la persona.',context:{...state.context,transferRequest:{...state.context?.transferRequest,id:op.id,stage:op.estado,mode:op.modo,extension:op.destino_extension}}});
    else if(state.active)api.publish({context:{...state.context,transferRequest:{...state.context?.transferRequest,id:op.id,stage:op.estado,mode:op.modo,extension:op.destino_extension}}});
   }else if(op.estado==='ENTREGADA' && !external && !tracked.session?.isEnded?.()){
    external=true;tracked.token=String(op.sdk_consulta)+'-external';
    const connected=tracked.session?.isEstablished?.()===true;
    api.external(op,tracked.token,connected,tracked.session);
   }else if(!external && state.active){
    api.publish({context:{...state.context,consultation:op.modo==='CONSULTADA',transferPendingReceiver:op.modo==='DIRECTA',transferOperation:op.id,transferReference:op.referencia,transferFrom:op.origen_extension},transferFrom:op.origen_extension,message:op.modo==='CONSULTADA'?'Consulta con recepción. La persona está esperando; recepción debe completar la transferencia.':'Recepción está entregando la llamada.'});
   }
  };
  const adoptExternalSession=session=>{
   const previous=tracked?.session, state=api.read();
   if(!tracked||tracked.source||tracked.op.estado!=='ENTREGADA'||!state.active||
      state.callToken!==tracked.token||!previous||!session||
      session===previous||session!==root.zdrmWebPhone?.webCallSession||session.isEnded?.()||
      session.direction!=='incoming')return false;
   // Sólo el host puede acreditar la arista Replaces del diálogo anterior.
   // Número/origen SIP y proximidad temporal no identifican un sucesor.
   if(typeof api.externalSession!=='function'||api.externalSession(tracked.op,tracked.token,session,previous)!==true)return false;
   tracked.session=session;endedReported=false;apply(tracked.op);return true;
  };
  const tick=async()=>{
   if(busy||!api.url||Date.now()<nextPoll)return;busy=true;
   let temporary=false;
   try{
    let state=api.read();
    if(!tracked && state.active && state.direction==='incoming' &&
      String(state.callToken||'')!==lastClosedReceiverToken &&
      (/^\d{3,6}$/.test(state.destination)||state.context?.availabilityCandidate) && /^sdk-/.test(state.callToken||'')){
     const op=await request(0);if(api.read().callToken!==state.callToken)return;if(op){reset();tracked={source:false,op,token:state.callToken,session:root.zdrmWebPhone?.webCallSession};apply(op);}
     else api.publish({context:{...state.context,operationChecked:true}});
    }
    if(!tracked)return;
    state=api.read();
    if(state.active && state.callToken!==tracked.token){reset();return;}
    const attempt=tracked,operationToken=tracked.token;
    const initialSession=tracked.session;
    if(initialSession?.isEnded?.() && !endedReported){
     // La sesión exacta ya acabó: liberar controles antes de esperar HTTP.
     endedReported=true;
     if(tracked.source)api.sourceEnded?.(tracked.op,operationToken,initialSession);
     else if(external)api.receiverEnded?.(tracked.op,operationToken,initialSession);
     else api.consultEnded(tracked.op,operationToken,initialSession);
    }
    const op=await request(tracked.op.id);
    if(tracked!==attempt)return;
    if(api.read().callToken!==operationToken){reset();return;}
    apply(op);
    const currentSession=root.zdrmWebPhone?.webCallSession;
    adoptExternalSession(currentSession);
    const session=tracked.session || currentSession;
    if(session)tracked.session=session;
    if(tracked.source && session?.isEnded?.() && ['DEVUELTA','FALLIDA'].includes(tracked.op.estado)){reset();return;}
    if(tracked.source && tracked.op.modo==='DIRECTA' && ['TIMBRANDO','ENTREGADA'].includes(tracked.op.estado) && !directRelease && !session?.isEnded?.()){
        directRelease=true;api.finishSdk();
    }
    if(tracked.source && session?.isEnded?.() && !delivered){
     const operation=tracked;
     const op=await request(operation.op.id,'LIBERADA');
     if(tracked!==operation)return;
     if(api.read().callToken!==operation.token){reset();return;}
     delivered=true;apply(op);api.release(op);reset();
     return;
    }
    if(!tracked.source && session?.isEnded?.()){
     // Guardar el diálogo SIP y la referencia de la operación ANTES de la
     // petición: TERMINADA puede contestar con un estado distinto a ENTREGADA.
     // La API sólo registra el fin; el END SIP propio acredita el cierre local.
     const attempt=tracked,token=tracked.token,ownSession=session,operation=tracked.op;
     endedReported=true;
     const updated=await request(operation.id,'TERMINADA');
     if(tracked!==attempt)return;
     if(api.read().callToken!==token){reset();return;}
     apply(updated);
     // Un nuevo INVITE puede llegar mientras se espera la petición HTTP.
     // Nunca trasladar su cierre al modal de la llamada siguiente.
     if(api.read().callToken!==token){reset();return;}
     // Conservar el token de la llamada anterior fuera de reset().
     // Evita reconsultar la misma transferencia en el siguiente intervalo,
     // duplicar TERMINADA y reabrir el modal de un diálogo ya cerrado.
     lastClosedReceiverToken=token;
     if(api.read().active){
      if(!external)api.consultEnded(operation,token,ownSession);
      else api.receiverEnded?.(operation,token,ownSession);
     }
     reset();return;
    }
    if(!tracked.source && tracked.op.modo==='CONSULTADA' && !external && session?.isEstablished?.() && !atendida){
     const attempt=tracked,updated=await request(tracked.op.id,'ATENDIDA');
     if(tracked!==attempt||api.read().callToken!==attempt.token)return;
     apply(updated);atendida=true;
    }
   }catch(e){
    if(e.temporal){
     temporary=true;
     nextPoll=Date.now()+Math.min(8000,2000*2**failures)+Math.floor(Math.random()*400);
     failures=Math.min(failures+1,2);
     return;
    }
    if(tracked&&api.read().active&&api.read().callToken===tracked.token)api.notice(e.message);
   }
   finally{if(!temporary)failures=0;busy=false;}
  };
  return {
   bindExternalSession:adoptExternalSession,
   ownsExternalSession(session){const state=api.read();return Boolean(tracked&&!tracked.source&&external&&
       tracked.op.estado==='ENTREGADA'&&state.active&&state.direction==='incoming'&&state.callToken===tracked.token&&
       session===tracked.session&&session===root.zdrmWebPhone?.webCallSession&&!session?.isEnded?.());},
   start(op){reset();tracked={source:true,op,token:api.read().callToken,session:root.zdrmWebPhone?.webCallSession};apply(op);},
   async failed(op){try{await request(op.id,'FALLO',op);}catch(e){api.notice(e.message);}},
   async complete(){
    if(posting||!tracked?.source||tracked.op.modo!=='CONSULTADA')return;
    posting=true;
    const attempt=tracked;
    try{
     const op=tracked.op.estado==='ENTREGANDO'?tracked.op:await request(tracked.op.id,'COMPLETAR');
     if(tracked!==attempt||api.read().callToken!==attempt.token)return;
     apply(op);
     api.notice('Entregando la llamada al analista…');api.finishSdk();
     root.setTimeout(()=>{
      if(tracked===attempt && api.read().callToken===attempt.token && !tracked.session?.isEnded?.()){
       const s=api.read();api.publish({context:{...s.context,transferRequest:{...s.context?.transferRequest,retryReady:true}},message:'No se confirmó la entrega. Pulsa Completar transferencia para reintentar.'});
      }
     },5000);
    }catch(e){if(tracked===attempt&&api.read().callToken===attempt.token)api.notice(e.message);}finally{posting=false;}
   },
   holdsIncoming(){return Boolean(tracked&&!tracked.source&&!external);},
   sourcePending(){return Boolean(tracked?.source&&!['DEVUELTA','FALLIDA','ENTREGADA'].includes(tracked.op.estado));},
   tick
  };
 };
 root.IMPE_TRANSFER_FLOW={create};
 if(typeof module!=='undefined')module.exports={create};
})(typeof window==='undefined'?globalThis:window);
