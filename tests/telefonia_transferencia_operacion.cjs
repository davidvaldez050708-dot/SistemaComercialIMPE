const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/javascript/telefonia_transferencia_runtime.js'),'utf8');
async function scenario(mode,answer,replaceDialog=false,outage=false,fastAnswer=false){
 let op={id:1,modo:mode,estado:'SOLICITADA',origen_extension:'100',destino_extension:'101',numero:'5551234567',sdk_consulta:'sdk-consulta-test-0000001'},held=true,releases=0;
 const sourceSession={direction:'outgoing',ended:false,isEnded(){return this.ended;},isEstablished:()=>true};
 const destSession={direction:'incoming',ended:false,connected:false,isEnded(){return this.ended;},isEstablished(){return this.connected;}};
 const clients=[];let failOnce=outage;
 for(const [isSource,session] of [[true,sourceSession],[false,destSession]]){
  const state={active:true,direction:isSource?'outgoing':'incoming',destination:isSource?'5551234567':'100',phase:isSource?'in-progress':'incoming-ringing',callToken:isSource?'original-call':'sdk-consulta-test-0000001',context:{}};
  const w={location:{href:'http://test/'},zdrmWebPhone:{webCallSession:session},setTimeout(){}};
  vm.runInNewContext(source,{window:w,URL,URLSearchParams,fetch:async(url,options)=>{
   if(isSource&&sourceSession.ended&&options.method!=='POST'&&failOnce){failOnce=false;throw Error('Interrupción de red simulada');}
   if(options.method==='POST'){
    const action=options.body.get('accion');
    if(action==='ATENDIDA'){assert.equal(isSource,false);op.estado='CONSULTANDO';op.destino_atendida='2026-10-18 12:00:00';}
    if(action==='COMPLETAR'){assert.equal(isSource,true);assert.equal(op.estado,'CONSULTANDO');op.estado='ENTREGANDO';}
    if(action==='LIBERADA'){assert.equal(sourceSession.ended,true);op.estado=mode==='DIRECTA'?'ENVIADA':'ENTREGADA';op.entregada_at='2026-10-18 12:00:05';held=false;}
    if(action==='TERMINADA'&&!op.entregada_at)op.estado='DEVUELTA';
   }
   return {ok:true,json:async()=>({ok:true,operacion:{...op}})};
  }});
  const flow=w.IMPE_TRANSFER_FLOW.create({url:'http://test/op',csrf:'test',read:()=>state,publish:p=>Object.assign(state,p),notice:m=>state.message=m,
   finishSdk(){sourceSession.ended=true;},release(){releases++;state.active=false;},
   external(op,token){state.callToken=token;state.destination=op.numero;state.context={};state.phase='in-progress';},consultEnded(){state.active=false;}});
  clients.push({flow,state,window:w});
 }
 const [s,d]=clients;s.flow.start({...op});await d.flow.tick();
 assert.equal(d.state.context.consultation,mode==='CONSULTADA');
 if(answer){
  destSession.connected=true;await d.flow.tick();
  if(mode==='CONSULTADA'){
   await s.flow.tick();assert.equal(s.state.context.transferRequest.stage,'CONSULTANDO');
   assert.equal(held,true,'contestar la consulta mantiene al cliente esperando');
   await s.flow.complete();
  }else{op.estado=fastAnswer?'ENTREGADA':'TIMBRANDO';await s.flow.tick();assert.equal(sourceSession.ended,true,'recepción sale aunque la respuesta llegue antes del sondeo de timbrado');}
  if(outage){await s.flow.tick();assert.equal(s.state.active,false,'una caída de red no deja una sesión finalizada en conversación');}
  await s.flow.tick();
  if(mode==='DIRECTA'){assert.equal(op.estado,'ENVIADA','el envío aún no acredita respuesta');op.estado='ENTREGADA';op.destino_atendida='2026-10-18 12:00:06';}
  if(replaceDialog){destSession.ended=true;d.window.zdrmWebPhone.webCallSession={direction:'incoming',remote_identity:{uri:{user:'100'}},isEnded:()=>false,isEstablished:()=>true};}
  await d.flow.tick();
  assert.equal(releases,outage?2:1);assert.equal(s.state.active,false,'recepción ya salió de la conversación');
  assert.equal(held,false,'se entregó el cliente');assert.equal(d.state.destination,'5551234567');
  assert.equal(d.state.callToken,'sdk-consulta-test-0000001-external','la atención externa se identifica por separado');
 }else{
  destSession.ended=true;await d.flow.tick();await s.flow.tick();
  assert.equal(s.state.active,true,'recepción conserva la conversación al no contestar');
  assert.equal(s.state.context.transferRequest.stage,'DEVUELTA');assert.match(s.state.message,/Retoma/);
 }
}
(async()=>{await scenario('CONSULTADA',true);await scenario('CONSULTADA',false);await scenario('DIRECTA',true);await scenario('DIRECTA',true,false,false,true);await scenario('CONSULTADA',true,true);await scenario('CONSULTADA',true,false,true);console.log('Operaciones: consulta atendida sin entrega prematura, entrega explícita, directa y devolución sin respuesta: OK');})().catch(e=>{console.error(e);process.exitCode=1;});
