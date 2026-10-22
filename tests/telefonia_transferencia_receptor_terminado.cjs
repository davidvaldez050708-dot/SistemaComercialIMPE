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
 console.log('OK: TransferFlow ENTREGADA informa END de SDK exacto una sola vez y no inventa END PBX');
})().catch(e=>{console.error(e);process.exitCode=1});
