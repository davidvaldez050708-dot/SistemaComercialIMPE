/* Operación de transferencia: consulta, entrega y devolución por separado. */
(function(root){
 'use strict';
 const create=api=>{
  let tracked=null,busy=false,posting=false,external=false,delivered=false,endedReported=false,atendida=false,directRelease=false;
  // TERMINADA puede devolver el mismo registro durante varios sondeos.
  // No volver a asociarlo al diálogo SIP ya finalizado, aun si reset() lo
  // soltó. El token pertenece a una llamada; el siguiente INVITE usa otro.
  let lastClosedReceiverToken='';
  const reset=()=>{tracked=null;external=false;delivered=false;endedReported=false;atendida=false;directRelease=false;};
  const request=async(id,action)=>{
   const url=new URL(api.url,root.location.href),options={credentials:'same-origin',cache:'no-store'};
   if(action){options.method='POST';options.body=new URLSearchParams({id:String(id),accion:action,csrf_token:api.csrf});}
   else if(id)url.searchParams.set('id',String(id));
   else {url.searchParams.set('origen',api.read().destination);url.searchParams.set('sdk',api.read().callToken);if(api.read().context?.availabilityCandidate)url.searchParams.set('solicitud_id',api.read().context.availabilityCandidate);}
   const response=await fetch(url,options),data=await response.json();
   if(!response.ok||!data.ok)throw Error(data.mensaje||'No se pudo actualizar la transferencia.');
   return data.operacion;
  };
  const apply=op=>{
   if(!op||!tracked)return;
   tracked.op=op;
   const state=api.read(),source=tracked.source;
   if(!source&&!state.active)return;
   if(source){
    if(state.active && op.estado==='DEVUELTA')api.publish({message:op.modo==='DIRECTA'?'La centralita confirmó el retorno de la llamada. Retoma la conversación.':'La consulta terminó. Retoma la conversación con la persona.',context:{...state.context,transferRequest:{...state.context?.transferRequest,id:op.id,stage:op.estado,mode:op.modo,extension:op.destino_extension}}});
    else if(state.active)api.publish({context:{...state.context,transferRequest:{...state.context?.transferRequest,id:op.id,stage:op.estado,mode:op.modo,extension:op.destino_extension}}});
   }else if(op.estado==='ENTREGADA' && !external && !tracked.session?.isEnded?.()){
    external=true;tracked.token=String(op.sdk_consulta)+'-external';
    const connected=tracked.session?.isEstablished?.()===true;
    api.external(op,tracked.token,connected);
   }else if(!external && state.active){
    api.publish({context:{...state.context,consultation:op.modo==='CONSULTADA',transferPendingReceiver:op.modo==='DIRECTA',transferOperation:op.id,transferReference:op.referencia,transferFrom:op.origen_extension},transferFrom:op.origen_extension,message:op.modo==='CONSULTADA'?'Consulta con recepción. La persona está esperando; recepción debe completar la transferencia.':'Recepción está entregando la llamada.'});
   }
  };
  const tick=async()=>{
   if(busy||!api.url)return;busy=true;
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
    const operationToken=tracked.token,op=await request(tracked.op.id);
    if(api.read().callToken!==operationToken){reset();return;}
    apply(op);
    const currentSession=root.zdrmWebPhone?.webCallSession;
    // Algunos teléfonos reemplazan el diálogo SIP al entregar a la persona.
    // Sólo adoptar su sesión entrante si coincide con el origen de esta operación.
    const remote=String(currentSession?.remote_identity?.uri?.user||'').replace(/\D/g,'');
    if(!tracked.source && tracked.op.estado==='ENTREGADA' && tracked.session?.isEnded?.() &&
       currentSession && currentSession!==tracked.session && !currentSession.isEnded?.() &&
       currentSession.direction==='incoming' && [tracked.op.origen_extension,String(tracked.op.numero).replace(/\D/g,'')].includes(remote)){
     tracked.session=currentSession;endedReported=false;apply(tracked.op);
    }
    const session=tracked.session || currentSession;
    if(session)tracked.session=session;
    if(tracked.source && session?.isEnded?.() && ['DEVUELTA','FALLIDA'].includes(tracked.op.estado)){reset();return;}
    if(tracked.source && tracked.op.modo==='DIRECTA' && ['TIMBRANDO','ENTREGADA'].includes(tracked.op.estado) && !directRelease && !session?.isEnded?.()){
        directRelease=true;api.finishSdk();
    }
    if(tracked.source && session?.isEnded?.() && !delivered){
     try{const op=await request(tracked.op.id,'LIBERADA');delivered=true;apply(op);api.release(op);reset();}
     catch(e){api.release({...tracked.op,estado:'FALLIDA'});throw e;}
     return;
    }
    if(!tracked.source && session?.isEnded?.() && !endedReported){
     // Guardar el diálogo SIP y la referencia de la operación ANTES de la
     // petición: TERMINADA puede contestar con un estado distinto a ENTREGADA.
     // La API sólo registra el fin; el END SIP propio acredita el cierre local.
     const token=tracked.token,ownSession=session,operation=tracked.op;
     endedReported=true;
     try{apply(await request(operation.id,'TERMINADA'));}
     catch(e){endedReported=false;throw e;}
     // Un nuevo INVITE puede llegar mientras se espera la petición HTTP.
     // Nunca trasladar su cierre al modal de la llamada siguiente.
     if(api.read().callToken!==token){reset();return;}
     // Conservar el token de la llamada anterior fuera de reset().
     // Evita reconsultar la misma transferencia en el siguiente intervalo,
     // duplicar TERMINADA y reabrir el modal de un diálogo ya cerrado.
     lastClosedReceiverToken=token;
     if(!external)api.consultEnded(operation,token,ownSession);
     else api.receiverEnded?.(operation,token,ownSession);
     reset();return;
    }
    if(!tracked.source && tracked.op.modo==='CONSULTADA' && !external && session?.isEstablished?.() && !atendida){
     apply(await request(tracked.op.id,'ATENDIDA'));atendida=true;
    }
   }catch(e){
    if(tracked?.source && tracked.session?.isEnded?.() && api.read().active)api.release({...tracked.op,estado:'FALLIDA'});
    if(tracked&&api.read().active)api.notice(e.message);
   }
   finally{busy=false;}
  };
  return {
   start(op){reset();tracked={source:true,op,token:api.read().callToken,session:root.zdrmWebPhone?.webCallSession};apply(op);},
   async failed(op){try{await request(op.id,'FALLO');}catch(e){api.notice(e.message);}},
   async complete(){
    if(posting||!tracked?.source||tracked.op.modo!=='CONSULTADA')return;
    posting=true;
    try{
     const op=tracked.op.estado==='ENTREGANDO'?tracked.op:await request(tracked.op.id,'COMPLETAR');apply(op);
     api.notice('Entregando la llamada al analista…');api.finishSdk();
     root.setTimeout(()=>{
      if(tracked?.source && !tracked.session?.isEnded?.()){
       const s=api.read();api.publish({context:{...s.context,transferRequest:{...s.context?.transferRequest,retryReady:true}},message:'No se confirmó la entrega. Pulsa Completar transferencia para reintentar.'});
      }
     },5000);
    }catch(e){api.notice(e.message);}finally{posting=false;}
   },
   holdsIncoming(){return Boolean(tracked&&!tracked.source&&!external);},
   sourcePending(){return Boolean(tracked?.source&&!['DEVUELTA','FALLIDA','ENTREGADA'].includes(tracked.op.estado));},
   tick
  };
 };
 root.IMPE_TRANSFER_FLOW={create};
 if(typeof module!=='undefined')module.exports={create};
})(typeof window==='undefined'?globalThis:window);
