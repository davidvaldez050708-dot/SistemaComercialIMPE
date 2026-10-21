const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(process.env.IMPE_TEST_TELEFONIA_HOST||'public/javascript/telefonia_host.js','utf8').replace(/\r\n/g,'\n');
const modernObserver = source.includes('onIdle: function (evidence={}) {');
let body='';
if(modernObserver){
const start=source.indexOf('onIdle: function (evidence={}) {',source.indexOf('const startAnalystSdkObserver'))+'onIdle: function (evidence={}) {'.length;
const end=source.indexOf('\n                }\n            }\n        ) || null;',start);
assert(start>0&&end>start);body=source.slice(start,end);
for(const sdk of [true,false]){
 const timers=[];let polls=0,cleared=0,finals=0;
 const state={active:true,direction:'incoming',phase:'in-progress',pbxCallId:sdk?'':'pbx-recovery-001',context:sdk?{transferReference:'pbx-recovery-001'}:{pbxAnswered:true}};
 const context={state,evidence:{},transferFlow:null,isSdkOnlyIncoming:()=>sdk,clearSdkOnlyIncoming:()=>cleared++,currentDuration:()=>15,answeredAtMs:100,
  publish:patch=>Object.assign(state,patch),pollIncoming:()=>polls++,finalizarEntrante:()=>finals++,window:{setTimeout:fn=>timers.push(fn)}};
 vm.runInNewContext('(function(){'+body+'})()',context);
 for(const timer of timers)timer();
 assert.equal(state.phase,'recovering');assert.equal(state.active,true);assert.equal(cleared,0);assert.equal(finals,0);assert(polls>0,'Reconsulta PBX sin fabricar un fin');
}
console.log('Recuperación PBX: negociación prolongada y pérdida de audio conservan llamada pendiente y reconsultan, sin cierre ficticio.');
} // La variante de Hostinger usa firma anterior y conserva sus propias pruebas.
const applyStart=source.indexOf('const applyCallState = function (call) {')+'const applyCallState = function (call) {'.length;
const applyEnd=source.indexOf('\n    };',applyStart);assert(applyEnd>applyStart);
const outbound={active:true,phase:'recovering',pbxCallId:'out-recovery-pbx',duration:15};let finals=0;
const env={state:outbound,lastCall:null,answeredAtMs:0,isFinal:s=>s==='completed',currentDuration:()=>15,publish:p=>Object.assign(outbound,p),finishCall:()=>finals++,Date};
vm.runInNewContext('this.apply=function(call){'+source.slice(applyStart,applyEnd)+'};',env);
env.apply({status:'in-progress',duration:20});assert.equal(outbound.phase,'recovering','PBX activa no acredita recuperación del audio');
env.apply({status:'completed',duration:20});assert.equal(finals,1,'El cierre verificado sigue liberando la llamada');
const directStart=source.indexOf('consultEnded:op=>{')+'consultEnded:op=>{'.length;
const directEnd=source.indexOf('\n        }\n    })',directStart);assert(directEnd>directStart);
const direct={active:true,phase:'answering',context:{transferPendingReceiver:true}};let requests=0;
const directEnv={state:direct,answeredAtMs:0,publish:p=>Object.assign(direct,p),pollIncoming:()=>requests++};
vm.runInNewContext('this.end=function(op){'+source.slice(directStart,directEnd)+'};',directEnv);
directEnv.end({modo:'DIRECTA',referencia:'pbx-directa-001'});assert.equal(direct.active,true);assert.equal(direct.phase,'finishing');assert.equal(requests,1);assert.equal(direct.context.transferReference,'pbx-directa-001');
directEnv.end({modo:'CONSULTADA'});assert.equal(direct.active,false,'El fin de consulta interna conserva su comportamiento');
const incomingStart=source.indexOf('const applyIncomingState = function (call) {')+'const applyIncomingState = function (call) {'.length;
const incomingEnd=source.indexOf('\n    };',incomingStart);assert(incomingEnd>incomingStart);
const recovery={active:true,direction:'incoming',phase:'recovering',pbxCallId:'',context:{transferReference:'pbx-directa-001'}};let confirmedEnds=0;
const incomingEnv={state:recovery,transferFlow:null,config:{},retiredIds:new Set(),declinedInvitation:()=>false,publish:p=>Object.assign(recovery,p),finalizarEntrante:()=>{confirmedEnds++;recovery.active=false;}};
vm.runInNewContext('this.apply=function(call){'+source.slice(incomingStart,incomingEnd)+'};',incomingEnv);
incomingEnv.apply({pbx_call_id:'pbx-otra-llamada',estado:'ended'});assert.equal(confirmedEnds,0,'Otro cierre no termina la recuperación');
incomingEnv.apply({pbx_call_id:'pbx-directa-001',estado:'ended'});assert.equal(confirmedEnds,1,'END de la referencia exacta termina la recuperación aunque el SDK aún no tuviera ID PBX');

if(modernObserver){
for(const answered of [false,true])for(const ended of [false,true]){
 const state={active:true,direction:'incoming',phase:answered?'in-progress':'incoming-ringing',pbxCallId:'',context:{type:'SDK_INCOMING',sdkInviteId:'sip-current',sdkConnected:answered}};
 let polls=0;const env={state,evidence:{sessionEnded:ended,sessionId:'sip-current'},transferFlow:null,isSdkOnlyIncoming:()=>true,retirePbxCall(){},answeredAtMs:answered?100:0,currentDuration:()=>answered?12:0,publish:p=>Object.assign(state,p),pollIncoming:()=>polls++,window:{setTimeout(){}},clearSdkOnlyIncoming(){},ocultarHostEntrante(){},marcarPestanaEntrante(){}};
 vm.runInNewContext('(function(){'+body+'})()',env);
 assert.equal(state.active,!ended);
 assert.equal(state.phase,ended?'incoming-finished':answered?'recovering':'finishing',`answered=${answered} ended=${ended} type=${state.context?.type} invite=${state.context?.sdkInviteId}`);
 if(ended&&!answered)assert.equal(state.status,'no-answer','END SIP de intento sin atender conserva No contestada');
 if(!ended)assert(polls>0);
}
console.log('Ciclo: END SIP propio libera timbrado/conversación; sin respuesta nunca recupera conversación; transporte incierto no fabrica END: OK');
}

const hs=source.indexOf('    const hangup = async function () {'),he=source.indexOf('\n    const clearFinished',hs);
(async()=>{for(const final of [false,true]){
 const state={active:true,direction:'outgoing',phase:'in-progress',status:'in-progress',answeredAtMs:1};let closes=0;
 const env={state,hangingUp:false,lastCall:null,publish:p=>Object.assign(state,p),endOutgoingWebrtc(){},sleep:async()=>{},fetchCallState:async()=>({status:final?'completed':'in-progress'}),isFinal:s=>s==='completed',finishCall(){closes++;state.active=false;},currentDuration:()=>12,console};
 vm.runInNewContext(source.slice(hs,he)+';this.run=hangup;',env);await env.run();
 assert.equal(closes,final?1:0);assert.equal(state.active,!final);
 if(!final){assert.equal(state.phase,'finishing');assert.equal(env.hangingUp,false);}
}console.log('Colgar saliente: espera agotada no fabrica éxito; sólo cierre PBX confirmado finaliza: OK');})().catch(e=>{console.error(e);process.exitCode=1;});
