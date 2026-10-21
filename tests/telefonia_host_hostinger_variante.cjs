const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),cp=require('node:child_process');
const path='tools/ventas_etapa2_b/hostinger-host-after.js';
const source=fs.readFileSync(path,'utf8').replace(/\r\n/g,'\n');
cp.execFileSync(process.execPath,['--check',path]);
cp.execFileSync(process.execPath,['tests/telefonia_recuperacion_pbx_smoke.cjs'],{env:{...process.env,IMPE_TEST_TELEFONIA_HOST:path},stdio:'inherit'});
function body(name){const anchor='const '+name+' = '+(name==='pollIncoming'?'async ':'')+'function () {';const start=source.indexOf(anchor);assert(start>=0);const end=source.indexOf('\n    };',start);assert(end>start);return source.slice(start+anchor.length,end);}
const state={active:true,direction:'incoming',phase:'answering',duration:88,context:{pbxAnswered:false}};
const context={state,answeredAtMs:Date.now()-9000,Date};
vm.runInNewContext('this.duration=function(){'+body('currentDuration')+'}',context);
assert.equal(context.duration(),0,'Conectando no hereda duración de una llamada anterior');
state.phase='incoming-ringing';assert.equal(context.duration(),0);
let polls=0,applied=0,connected=false;
Object.assign(context,{permiteEntrantes:true,incomingPollBusy:false,analystSdkObserver:{isConnected:()=>connected},publish:p=>Object.assign(state,p),fetchIncomingState:async()=>{polls++;return {estado:'answered'}},applyIncomingState:()=>applied++,console});
vm.runInNewContext('this.poll=async function(){'+body('pollIncoming')+'}',context);
(async()=>{
 state.phase='answering';await context.poll();assert.equal(state.phase,'answering','PBX sola no acredita audio SDK');
 connected=true;await context.poll();assert.equal(state.phase,'in-progress');assert.equal(state.duration,0);assert.equal(state.context.sdkConnected,true);assert.equal(state.context.verified,false);
 state.phase='recovering';await context.poll();assert.equal(state.phase,'recovering','Rescate de onConnected no sustituye recuperación de transporte');
 assert.equal(polls,3);assert.equal(applied,3);assert.equal(context.incomingPollBusy,false);
 console.log('Variante Hostinger: recuperación PBX, cero segundos en negociación y rescate SDK conservados.');
})().catch(e=>{console.error(e);process.exitCode=1});
