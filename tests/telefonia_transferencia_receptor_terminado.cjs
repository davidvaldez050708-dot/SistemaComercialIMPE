'use strict';
/* Contrato entre TransferFlow y host Ventas: operación entregada,
 * cierre de RTCSession propia y notificación única; no END PBX inventado. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const runtime=fs.readFileSync('public/javascript/telefonia_transferencia_runtime.js','utf8');
(async()=>{
 let ended=false,actions=[],received=[],published=[];
 const session={direction:'incoming',id:'rtc-ventas-delivered-150',
  remote_identity:{uri:{user:'100'}},
  isEnded:()=>ended,isEstablished:()=>true};
 const state={active:true,direction:'incoming',phase:'in-progress',
  callToken:'sdk-current-150',destination:'100',pbxCallId:'',
  context:{availabilityCandidate:5}};
 const op={id:5,version:1,estado:'ENTREGADA',modo:'DIRECTA',
  referencia:'out_104f634c799a7fd246dfe5bad6e572b0fe32415e',numero:'+527777885173',
  origen_extension:'100',destino_extension:'150',sdk_consulta:'sdk-current-150'};
 const win={location:{href:'https://crm.test/index.php'},zdrmWebPhone:{webCallSession:session},
  setTimeout(){}};
 const fetch=async(url,options={})=>{
  const action=options.body?.get('accion')||'';
  actions.push(action||'CONSULTA');
  return {ok:true,json:async()=>({ok:true,operacion:{...op}})};
 };
 vm.runInNewContext(runtime,{window:win,fetch,URL,URLSearchParams});
 const flow=win.IMPE_TRANSFER_FLOW.create({
  url:'https://crm.test/op',csrf:'token',
  read:()=>state,publish:patch=>{Object.assign(state,patch);published.push(patch)},
  external:(operation,token)=>{state.callToken=token;state.context={
   ...state.context,transferReference:operation.referencia,transferOperation:operation.id};},
  receiverEnded:(operation,token,terminated)=>received.push({operation,token,terminated}),
  consultEnded:()=>{throw Error('ENTREGADA externa nunca debe ser consulta')},
  notice:()=>{},finishSdk:()=>{},release:()=>{}
 });
 await flow.tick();
 assert.equal(state.callToken,'sdk-current-150-external','El token de la transferencia cambia tras entrega');
 assert.equal(received.length,0,'No terminar sesión viva');
 ended=true;
 await flow.tick();
 assert.equal(received.length,1,'La RTCSession terminada notifica cierre propio una sola vez');
 assert.equal(received[0].token,'sdk-current-150-external','No usar el token viejo de Recepción');
 assert.equal(received[0].operation.referencia,op.referencia,'Misma PBX exacta');
 assert.equal(received[0].terminated,session,'Misma RTCSession de Ventas');
 assert(actions.includes('TERMINADA'),'Informa fin de consulta por API de operación existente');
 await flow.tick();
 assert.equal(received.length,1,'Sin doble terminación ni cierre de llamada nueva');
 // Saliente de Recepción -> Asesor: el SIP puede terminar cuando
 // la operación sigue ENVIADA. Ese estado no debe enviarse a recuperación.
 let endedBeforeDelivery=false,whenClosing=false,consultations=[],seen=[];
 let directOp={...op,estado:'ENVIADA',destino_atendida:null};
 const directSession={id:'rtc-own-outgoing-transfer-150',direction:'incoming',
  remote_identity:{uri:{user:'100'}},isEnded:()=>endedBeforeDelivery,
  isEstablished:()=>!endedBeforeDelivery};
 const directState={active:true,direction:'incoming',phase:'in-progress',
  callToken:'sdk-direct-150',destination:'100',pbxCallId:'',
  context:{type:'SDK_INCOMING',sdkConnected:true}};
 const directWin={location:{href:'https://crm.test/index.php'},zdrmWebPhone:{webCallSession:directSession},setTimeout(){}};
 const directFetch=async(url,options={})=>{
  const action=options.body?.get('accion')||'';
  seen.push(action||'CONSULTA');
  // La respuesta a TERMINADA puede llevar un estado más nuevo: la
  // referencia entregada al callback debe ser la que cerró localmente.
  return {ok:true,json:async()=>({ok:true,operacion:{...directOp,estado:action==='TERMINADA'?'SIN_CONFIRMAR':'ENVIADA'}})};
 };
 vm.runInNewContext(runtime,{window:directWin,fetch:directFetch,URL,URLSearchParams});
 const directFlow=directWin.IMPE_TRANSFER_FLOW.create({
  url:'https://crm.test/op',csrf:'token',
  read:()=>directState,
  publish:patch=>Object.assign(directState,patch),
  external:()=>{throw Error('No se confirmó entrega; no inventar recepción externa')},
  consultEnded:(operation,token,session)=>consultations.push({operation,token,session}),
  receiverEnded:()=>{throw Error('No está entregada por PBX')},
  notice:()=>{},finishSdk:()=>{},release:()=>{}
 });
 await directFlow.tick();
 assert.equal(directState.context.transferReference,directOp.referencia,
  'Referencia de operación ENVIADA se vincula a la sesión local');
 assert.equal(directState.context.transferOperation,directOp.id,
  'ID autorizado queda en el contexto propio');
 endedBeforeDelivery=true;
 await directFlow.tick();
 assert.equal(consultations.length,1,'El cierre del SIP exacto se comunica sin esperar ENTREGADA');
 assert.equal(consultations[0].operation.estado,'ENVIADA','No sustituir evidencia local por resultado tardío');
 assert.equal(consultations[0].token,'sdk-direct-150','No heredar otra sesión');
 assert.equal(consultations[0].session,directSession,'END corresponde al diálogo SIP del asesor');
 assert(seen.includes('TERMINADA'),'Se conserva registro de fin en servicio');
 await directFlow.tick();
 assert.equal(consultations.length,1,'Sin terminar dos veces una misma llamada');
 assert.equal(seen.filter(action=>action==='TERMINADA').length,1,
  'La misma sesión terminada solo puede emitir una petición TERMINADA');

 // Dos llamadas consecutivas al mismo número deben permitir un nuevo
 // cierre cuando el proveedor entregue una nueva sesión y un nuevo token.
 let secondEnded=false;
 const secondSession={id:'rtc-next-outgoing-transfer-150',direction:'incoming',
  remote_identity:{uri:{user:'100'}},isEnded:()=>secondEnded,
  isEstablished:()=>!secondEnded};
 directWin.zdrmWebPhone.webCallSession=secondSession;
 directOp={...directOp,id:6,referencia:'out_new_transfer_receiver_150',
  sdk_consulta:'sdk-next-receiver-150'};
 directState.callToken='sdk-next-receiver-150';
 directState.context={type:'SDK_INCOMING',sdkConnected:true};
 await directFlow.tick();
 assert.equal(directState.context.transferOperation,6,
  'Un nuevo INVITE se vuelve a vincular sin heredar la operación anterior');
 secondEnded=true;
 await directFlow.tick();
 assert.equal(consultations.length,2,
  'Nueva llamada tiene su propia terminación aunque use mismo número');
 assert.equal(consultations[1].token,'sdk-next-receiver-150',
  'El cierre nuevo nunca hereda el token anterior');
 assert.equal(consultations[1].session,secondSession,
  'RTCSession de segunda llamada queda correctamente identificada');
 await directFlow.tick();
 assert.equal(consultations.length,2,'Segunda llamada tampoco se termina dos veces');
 assert.equal(seen.filter(action=>action==='TERMINADA').length,2,
  'Exactamente una petición TERMINADA por cada sesión propia');

 console.log('OK: TransferFlow termina una sola vez cada sesión 100->150 y no bloquea llamadas consecutivas');
})().catch(e=>{console.error(e);process.exitCode=1});
