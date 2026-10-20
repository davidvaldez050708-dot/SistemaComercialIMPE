const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(process.env.IMPE_TEST_TELEFONIA_HOST||'public/javascript/telefonia_host.js','utf8').replace(/\r\n/g,'\n');
const start=source.indexOf('onIdle: function () {',source.indexOf('const startAnalystSdkObserver'))+'onIdle: function () {'.length;
const end=source.indexOf('\n                }\n            }\n        ) || null;',start);
assert(start>0&&end>start);const body=source.slice(start,end);
for(const sdk of [true,false]){
 const timers=[];let polls=0,cleared=0,finals=0;
 const state={active:true,direction:'incoming',phase:'answering',pbxCallId:sdk?'':'pbx-recovery-001',context:sdk?{transferReference:'pbx-recovery-001'}:{pbxAnswered:true}};
 const context={state,transferFlow:null,isSdkOnlyIncoming:()=>sdk,clearSdkOnlyIncoming:()=>cleared++,currentDuration:()=>15,answeredAtMs:100,
  publish:patch=>Object.assign(state,patch),pollIncoming:()=>polls++,finalizarEntrante:()=>finals++,window:{setTimeout:fn=>timers.push(fn)}};
 vm.runInNewContext('(function(){'+body+'})()',context);
 for(const timer of timers)timer();
 assert.equal(state.phase,'recovering');assert.equal(state.active,true);assert.equal(cleared,0);assert.equal(finals,0);assert(polls>0,'Reconsulta PBX sin fabricar un fin');
}
console.log('Recuperación PBX: negociación prolongada y pérdida de audio conservan llamada pendiente y reconsultan, sin cierre ficticio.');
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
directEnv.end({modo:'DIRECTA',referencia:'pbx-directa-001'});assert.equal(direct.active,true);assert.equal(direct.phase,'recovering');assert.equal(requests,1);assert.equal(direct.context.transferReference,'pbx-directa-001');
directEnv.end({modo:'CONSULTADA'});assert.equal(direct.active,false,'El fin de consulta interna conserva su comportamiento');
const incomingStart=source.indexOf('const applyIncomingState = function (call) {')+'const applyIncomingState = function (call) {'.length;
const incomingEnd=source.indexOf('\n    };',incomingStart);assert(incomingEnd>incomingStart);
const recovery={active:true,direction:'incoming',phase:'recovering',pbxCallId:'',context:{transferReference:'pbx-directa-001'}};let confirmedEnds=0;
const incomingEnv={state:recovery,transferFlow:null,config:{},retiredIds:new Set(),publish:p=>Object.assign(recovery,p),finalizarEntrante:()=>{confirmedEnds++;recovery.active=false;}};
vm.runInNewContext('this.apply=function(call){'+source.slice(incomingStart,incomingEnd)+'};',incomingEnv);
incomingEnv.apply({pbx_call_id:'pbx-otra-llamada',estado:'ended'});assert.equal(confirmedEnds,0,'Otro cierre no termina la recuperación');
incomingEnv.apply({pbx_call_id:'pbx-directa-001',estado:'ended'});assert.equal(confirmedEnds,1,'END de la referencia exacta termina la recuperación aunque el SDK aún no tuviera ID PBX');
