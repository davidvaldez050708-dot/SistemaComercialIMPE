'use strict';
/* Adopción de PBX tras transferencia 100->150: el token JsSIP no debe
 * convertirse en PBX ID mientras el receptor todavía habla. */
const assert=require('node:assert/strict'),fs=require('node:fs');
const code=fs.readFileSync('public/javascript/telefonia_host.js','utf8');
const start=code.indexOf('    const applyIncomingState = function (call) {');
const end=code.indexOf('\n    const pollIncoming = async function () {',start);
assert(start>=0&&end>start,'Hook applyIncomingState completo');
const src='function (call) {'+code.slice(start+'    const applyIncomingState = function (call) {'.length,end).replace(/\n    };\s*$/,'\n}');
const pbx='pbx-owner-transfer-150';
function fixture({reference=pbx,operation=77,direction='incoming',token='sdk-exact-receiver-150',pbxCallId=''}={}){
 const rt={state:{active:true,direction,phase:'in-progress',destination:'+527777885173',
  pbxCallId,callToken:token,answeredAtMs:Date.now(),requestedAt:Math.floor(Date.now()/1000),
  context:{type:'SDK_INCOMING',sdkConnected:true,transferReference:reference,
   transferOperation:operation,sdkInviteId:'rtc-own-150'}},publish:[],marked:[]};
 const build=new Function('rt','source',`
 let state=rt.state,answeredAtMs=Date.now();
 const transferFlow={holdsIncoming:()=>false};
 const config={operacionUrl:'/api'};
 const window={zdrmWebPhone:{webCallSession:{isEnded:()=>false}}};
 const retiredIds=new Set();
 const declinedInvitation=()=>false;
 const publish=x=>{state={...state,...x};rt.state=state;rt.publish.push(x)};
 const marcarPestanaEntrante=x=>rt.marked.push(x);
 const mostrarHostEntrante=()=>{};
 const finalizarEntrante=()=>{};
 const analystSdkObserver={isConnected:()=>true};
 return eval('('+source+')');
 `);
 return {rt,call:build(rt,src)};
}
const call={pbx_call_id:pbx,estado:'answered',caller_id:'+527777885173',
 duration:12,transfer_from:'100',answer_at:'',called_did:''};
const exact=fixture();
exact.call(call);
assert.equal(exact.rt.state.callToken,'sdk-exact-receiver-150',
 'La PBX no debe reemplazar sesión JsSIP propia durante una transferencia');
assert.equal(exact.rt.state.pbxCallId,pbx,'El identificador PBX sigue disponible');
assert.equal(exact.rt.state.context.transferReference,pbx,'Referencia de la operación conservada');
assert.equal(exact.rt.state.context.transferOperation,77,'ID de operación autorizado conservado');
assert.equal(exact.rt.state.context.sdkInviteId,'rtc-own-150','Identidad RTCSession intacta');
const otherRef=fixture({reference:'pbx-unrelated'});
otherRef.call(call);
assert.equal(otherRef.rt.state.callToken,pbx,'Sin correlación exacta no conservar token de otra llamada');
assert.equal(otherRef.rt.state.context.transferOperation,undefined,
 'Otro PBX no recibe operación de transferencia anterior');
const noOp=fixture({operation:0});
noOp.call(call);
assert.equal(noOp.rt.state.callToken,pbx,'Sin operación autorizada no retener SDK token');
const outbound=fixture({direction:'outgoing'});
outbound.call(call);
assert.equal(outbound.rt.state.callToken,'sdk-exact-receiver-150',
 'No se altera una saliente local por consulta entrante ajena');
assert.equal(outbound.rt.publish.length,0,'No se aplica PBX ajeno a saliente activa');
console.log('OK: adopción PBX de transferencia conserva token y operación propia sin suplantar otra llamada');
